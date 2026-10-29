<?php

namespace App\Services\IaApp;

use App\Jobs\EntregarImagenIaJob;
use App\Models\Client;
use App\Models\IaImagenPedido;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * La cola de imágenes del agente del dueño (misión cruzada `app-ia-y-memoria`, 29/10/2026, plan §4 A.4):
 * el empresa-api da de alta el pedido (contrato C2.1), la PC de Lucas lo reserva, lo genera con su cuenta
 * de ChatGPT y devuelve la imagen o el fallo (contrato C3), y el admin se la entrega al empresa-api
 * (`EntregarImagenIaJob`, contrato C1.4).
 *
 * Molde de la reserva: `SupportCodexService` (lease de 15 minutos, token aleatorio de 64 guardado como
 * sha256, 423 si lo tiene otro worker, todo bajo `lockForUpdate`).
 *
 * Los desenlaces salen como `{http, cuerpo}` (los errores con `{code, message}`), así el controlador no
 * decide nada.
 *
 * 🔴 La imagen queda en el disco PRIVADO del admin (`storage/app/ia_imagenes/{client_id}/{uuid}.{ext}`),
 * nunca en `public`: es del negocio del cliente.
 */
class IaImagenesService
{
    /** Extensión de archivo según el MIME detectado de la imagen. */
    const EXTENSIONES = [
        'image/png'  => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    /* ==============================================================================================
     | C2.1 · Alta desde el empresa-api
     |============================================================================================= */

    /**
     * Da de alta un pedido, o devuelve el que ya existe con ese uuid (idempotente).
     *
     * @param Client               $client El cliente que resolvió el middleware inbound.
     * @param array<string, mixed> $datos  Ya validados: uuid, prompt, tamano, estilo, ai_message_id.
     *
     * @return array{http: int, cuerpo: array<string, mixed>}
     */
    public function alta(Client $client, array $datos)
    {
        $existente = IaImagenPedido::where('uuid', $datos['uuid'])->first();

        if ($existente !== null) {
            return $this->repetido($client, $existente);
        }

        $tope = (int) config('ia_app.imagenes.tope_diario_por_cliente', 60);

        if ($tope > 0 && IaImagenPedido::where('client_id', $client->id)->where('created_at', '>=', now()->startOfDay())->count() >= $tope) {
            return $this->error(429, 'tope_diario', 'Este sistema ya pidió ' . $tope . ' imágenes hoy.');
        }

        try {
            IaImagenPedido::create([
                'client_id'     => (int) $client->id,
                'uuid'          => (string) $datos['uuid'],
                'ai_message_id' => isset($datos['ai_message_id']) ? (int) $datos['ai_message_id'] : null,
                'prompt'        => (string) $datos['prompt'],
                'tamano'        => (string) $datos['tamano'],
                'estilo'        => isset($datos['estilo']) && trim((string) $datos['estilo']) !== '' ? trim((string) $datos['estilo']) : null,
                'estado'        => IaImagenPedido::ESTADO_PENDIENTE,
            ]);
        } catch (QueryException $e) {
            // Dos altas con el mismo uuid a la vez: la segunda choca con el índice único y devuelve la primera.
            $existente = IaImagenPedido::where('uuid', $datos['uuid'])->first();

            if ($existente === null) {
                throw $e;
            }

            return $this->repetido($client, $existente);
        }

        return ['http' => 201, 'cuerpo' => ['ok' => true, 'estado' => IaImagenPedido::ESTADO_PENDIENTE]];
    }

    /**
     * El mismo uuid otra vez: 200 con el estado actual, si es del mismo cliente.
     *
     * @param Client         $client
     * @param IaImagenPedido $pedido
     *
     * @return array{http: int, cuerpo: array<string, mixed>}
     */
    protected function repetido(Client $client, IaImagenPedido $pedido)
    {
        if ((int) $pedido->client_id !== (int) $client->id) {
            return $this->error(409, 'uuid_en_uso', 'Ese uuid ya lo usó otro sistema.');
        }

        return ['http' => 200, 'cuerpo' => ['ok' => true, 'estado' => (string) $pedido->estado]];
    }

    /* ==============================================================================================
     | C3 · La PC de Lucas
     |============================================================================================= */

    /**
     * Los pedidos para generar: pendientes, o reservados con el lease vencido; hasta 20, el más viejo primero.
     *
     * Antes de listar, vence lo que quedó colgado (ver `vencer_colgados()`).
     *
     * @return array{pedidos: array<int, array<string, mixed>>}
     */
    public function pendientes()
    {
        $this->vencer_colgados();

        $max = (int) config('ia_app.imagenes.max_intentos', 2);

        $pedidos = IaImagenPedido::with('client')
            ->where(function ($q) use ($max) {
                $q->where('estado', IaImagenPedido::ESTADO_PENDIENTE)
                    ->orWhere(function ($r) use ($max) {
                        $r->where('estado', IaImagenPedido::ESTADO_RESERVADO)
                            ->where('lease_expires_at', '<=', now())
                            ->where('intentos', '<', $max);
                    });
            })
            ->orderBy('id')
            ->limit(20)
            ->get();

        return ['pedidos' => $pedidos->map(function (IaImagenPedido $pedido) {
            return $this->payload($pedido);
        })->all()];
    }

    /**
     * Reserva un pedido para un worker (o renueva su reserva si manda su `lease_token`).
     *
     * @param int                  $id
     * @param array<string, mixed> $datos worker_id, lease_token?
     *
     * @return array{http: int, cuerpo: array<string, mixed>}
     */
    public function reservar($id, array $datos)
    {
        return DB::transaction(function () use ($id, $datos) {
            $pedido = IaImagenPedido::where('id', (int) $id)->lockForUpdate()->first();

            if ($pedido === null) {
                return $this->error(404, 'pedido_inexistente', 'No existe ese pedido de imagen.');
            }

            $worker   = (string) $datos['worker_id'];
            $token    = isset($datos['lease_token']) ? (string) $datos['lease_token'] : null;
            $renueva  = $pedido->estado === IaImagenPedido::ESTADO_RESERVADO
                && $token !== null
                && $pedido->worker_id === $worker
                && $pedido->lease_coincide($token)
                && $pedido->lease_vigente();

            if (! $renueva) {
                if ($pedido->estado === IaImagenPedido::ESTADO_RESERVADO && $pedido->lease_vigente()) {
                    return $this->error(423, 'reservado', 'Otro proceso está generando esta imagen.');
                }

                $libre = $pedido->estado === IaImagenPedido::ESTADO_PENDIENTE
                    || ($pedido->estado === IaImagenPedido::ESTADO_RESERVADO
                        && $pedido->intentos < (int) config('ia_app.imagenes.max_intentos', 2));

                if (! $libre) {
                    return $this->error(409, 'no_pendiente', 'El pedido ya no está pendiente (estado: ' . $pedido->estado . ').');
                }

                $token             = Str::random(64);
                $pedido->intentos  = (int) $pedido->intentos + 1;
                $pedido->worker_id = $worker;
                $pedido->lease_hash = hash('sha256', $token);
                $pedido->estado    = IaImagenPedido::ESTADO_RESERVADO;
            }

            $pedido->lease_expires_at = now()->addMinutes((int) config('ia_app.imagenes.lease_minutos', 15));
            $pedido->save();
            $pedido->load('client');

            return ['http' => 200, 'cuerpo' => [
                'lease_token'      => $token,
                'lease_expires_at' => $pedido->lease_expires_at->toIso8601String(),
                'pedido'           => $this->payload($pedido),
            ]];
        });
    }

    /**
     * La PC entrega la imagen generada.
     *
     * @param int          $id
     * @param string       $token
     * @param UploadedFile $imagen
     *
     * @return array{http: int, cuerpo: array<string, mixed>}
     */
    public function resultado($id, $token, UploadedFile $imagen)
    {
        return DB::transaction(function () use ($id, $token, $imagen) {
            $pedido = IaImagenPedido::where('id', (int) $id)->lockForUpdate()->first();

            if ($pedido === null) {
                return $this->error(404, 'pedido_inexistente', 'No existe ese pedido de imagen.');
            }

            // La misma entrega otra vez con el mismo lease: no se duplica nada.
            if ($pedido->resultado === IaImagenPedido::ESTADO_LISTA && $pedido->lease_coincide($token)) {
                return ['http' => 200, 'cuerpo' => [
                    'estado'        => IaImagenPedido::ESTADO_LISTA,
                    'estado_actual' => (string) $pedido->estado,
                    'duplicado'     => true,
                ]];
            }

            if ($pedido->estado !== IaImagenPedido::ESTADO_RESERVADO || ! $pedido->lease_coincide($token) || ! $pedido->lease_vigente()) {
                return $this->error(423, 'lease_invalido', 'La reserva no es válida o venció.');
            }

            $mime = (string) $imagen->getMimeType();
            $ext  = isset(self::EXTENSIONES[$mime]) ? self::EXTENSIONES[$mime] : null;

            if ($ext === null) {
                return $this->error(422, 'imagen_invalida', 'La imagen tiene que ser PNG, JPG o WEBP.');
            }

            $disco = (string) config('ia_app.imagenes.disco', 'local');
            $ruta  = 'ia_imagenes/' . (int) $pedido->client_id . '/' . $pedido->uuid . '.' . $ext;

            Storage::disk($disco)->put($ruta, file_get_contents($imagen->getRealPath()));

            $pedido->imagen_path      = $ruta;
            $pedido->imagen_mime      = $mime;
            $pedido->estado           = IaImagenPedido::ESTADO_LISTA;
            $pedido->resultado        = IaImagenPedido::ESTADO_LISTA;
            $pedido->error            = null;
            $pedido->lease_expires_at = null;
            $pedido->save();

            $this->despachar_entrega($pedido);

            return ['http' => 200, 'cuerpo' => ['estado' => IaImagenPedido::ESTADO_LISTA]];
        });
    }

    /**
     * La PC no pudo generar la imagen: vuelve a pendiente si le quedan intentos, o queda fallida y se le
     * avisa al cliente.
     *
     * @param int    $id
     * @param string $token
     * @param string $error
     *
     * @return array{http: int, cuerpo: array<string, mixed>}
     */
    public function fallo($id, $token, $error)
    {
        return DB::transaction(function () use ($id, $token, $error) {
            $pedido = IaImagenPedido::where('id', (int) $id)->lockForUpdate()->first();

            if ($pedido === null) {
                return $this->error(404, 'pedido_inexistente', 'No existe ese pedido de imagen.');
            }

            /* El mismo fallo otra vez con el mismo lease (la PC no vio la respuesta): el lease_hash se
               conserva justamente para esto, hasta que otro worker lo reserve. */
            if ($pedido->lease_coincide($token)
                && ($pedido->estado === IaImagenPedido::ESTADO_PENDIENTE || $pedido->resultado === IaImagenPedido::ESTADO_FALLIDA)) {
                return ['http' => 200, 'cuerpo' => ['estado' => (string) $pedido->estado, 'duplicado' => true]];
            }

            if ($pedido->estado !== IaImagenPedido::ESTADO_RESERVADO || ! $pedido->lease_coincide($token) || ! $pedido->lease_vigente()) {
                return $this->error(423, 'lease_invalido', 'La reserva no es válida o venció.');
            }

            $pedido->error            = mb_substr(trim((string) $error), 0, 500);
            $pedido->lease_expires_at = null;

            if ((int) $pedido->intentos < (int) config('ia_app.imagenes.max_intentos', 2)) {
                $pedido->estado    = IaImagenPedido::ESTADO_PENDIENTE;
                $pedido->worker_id = null;
                $pedido->save();

                return ['http' => 200, 'cuerpo' => ['estado' => IaImagenPedido::ESTADO_PENDIENTE]];
            }

            $this->marcar_fallida($pedido, $pedido->error);

            return ['http' => 200, 'cuerpo' => ['estado' => IaImagenPedido::ESTADO_FALLIDA]];
        });
    }

    /**
     * Da por fallidos los pedidos que no van a salir solos:
     *  - reservados con el lease vencido que ya gastaron sus intentos (la PC se cayó dos veces);
     *  - pendientes o reservados más viejos que `ia_app.imagenes.vence_horas` (la PC estuvo apagada).
     * Cada uno se le avisa al cliente como `fallida`. Lo corre `pendientes()` y el scheduler.
     *
     * @return int Cuántos se vencieron.
     */
    public function vencer_colgados()
    {
        $max    = (int) config('ia_app.imagenes.max_intentos', 2);
        $limite = now()->subHours(max(1, (int) config('ia_app.imagenes.vence_horas', 6)));

        $ids = IaImagenPedido::where(function ($q) use ($max) {
            $q->where('estado', IaImagenPedido::ESTADO_RESERVADO)
                ->where('lease_expires_at', '<=', now())
                ->where('intentos', '>=', $max);
        })->orWhere(function ($q) use ($limite) {
            $q->whereIn('estado', [IaImagenPedido::ESTADO_PENDIENTE, IaImagenPedido::ESTADO_RESERVADO])
                ->where('created_at', '<=', $limite)
                ->where(function ($r) {
                    $r->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<=', now());
                });
        })->pluck('id')->all();

        $vencidos = 0;

        foreach ($ids as $id) {
            $vencidos += (int) DB::transaction(function () use ($id, $max, $limite) {
                $pedido = IaImagenPedido::where('id', $id)->lockForUpdate()->first();

                if ($pedido === null || ! in_array($pedido->estado, [IaImagenPedido::ESTADO_PENDIENTE, IaImagenPedido::ESTADO_RESERVADO], true)
                    || $pedido->lease_vigente()) {
                    return false;
                }

                $por_intentos = $pedido->estado === IaImagenPedido::ESTADO_RESERVADO && (int) $pedido->intentos >= $max;

                $this->marcar_fallida(
                    $pedido,
                    $por_intentos
                        ? 'No se pudo generar la imagen después de ' . $max . ' intentos.'
                        : 'No se pudo generar la imagen a tiempo.'
                );

                return true;
            });
        }

        return $vencidos;
    }

    /**
     * @param IaImagenPedido $pedido
     * @param string|null    $error
     *
     * @return void
     */
    protected function marcar_fallida(IaImagenPedido $pedido, $error)
    {
        $pedido->estado           = IaImagenPedido::ESTADO_FALLIDA;
        $pedido->resultado        = IaImagenPedido::ESTADO_FALLIDA;
        $pedido->error            = $error === null || trim((string) $error) === '' ? 'No se pudo generar la imagen.' : mb_substr((string) $error, 0, 500);
        $pedido->lease_expires_at = null;
        $pedido->save();

        $this->despachar_entrega($pedido);
    }

    /**
     * Reencola la entrega de los pedidos con resultado (`lista`/`fallida`) cuyo encolado se perdió: el próximo
     * intento estaba previsto hace más de `$minutos` y nadie lo hizo (la cola falló al encolar, o un worker
     * murió con el job). Un pedido que está esperando su reintento (backoff de 1 a 240 min) tiene su
     * `entrega_proxima_en` en el futuro y no se toca.
     *
     * @param int $minutos
     *
     * @return int Cuántos se reencolaron.
     */
    public function reencolar_entregas_perdidas($minutos = 15)
    {
        $limite = now()->subMinutes(max(1, (int) $minutos));

        $pedidos = IaImagenPedido::whereIn('estado', [IaImagenPedido::ESTADO_LISTA, IaImagenPedido::ESTADO_FALLIDA])
            ->where(function ($q) use ($limite) {
                $q->whereNull('entrega_proxima_en')->orWhere('entrega_proxima_en', '<=', $limite);
            })
            ->where('updated_at', '<=', $limite)
            ->orderBy('id')
            ->limit(200)
            ->get();

        foreach ($pedidos as $pedido) {
            Log::info('IaImagenesService: se reencola una entrega perdida.', ['pedido_id' => (int) $pedido->id]);
            $this->despachar_entrega($pedido);
        }

        return $pedidos->count();
    }

    /**
     * Borra del disco las imágenes ya entregadas hace más de `$dias` (el sistema del cliente tiene su copia);
     * la fila queda, con `imagen_path` en null.
     *
     * @param int $dias
     *
     * @return int Cuántas se borraron.
     */
    public function borrar_imagenes_entregadas($dias = 7)
    {
        $disco   = (string) config('ia_app.imagenes.disco', 'local');
        $pedidos = IaImagenPedido::where('estado', IaImagenPedido::ESTADO_ENTREGADA)
            ->whereNotNull('imagen_path')
            ->where('entregado_en', '<=', now()->subDays(max(1, (int) $dias)))
            ->orderBy('id')
            ->limit(500)
            ->get();

        foreach ($pedidos as $pedido) {
            try {
                Storage::disk($disco)->delete($pedido->imagen_path);
            } catch (\Throwable $e) {
                Log::warning('IaImagenesService: no se pudo borrar una imagen entregada.', [
                    'pedido_id' => (int) $pedido->id,
                    'error'     => mb_substr($e->getMessage(), 0, 300),
                ]);

                continue;
            }

            $pedido->imagen_path = null;
            $pedido->save();
        }

        return $pedidos->count();
    }

    /**
     * Encola la entrega al empresa-api. Si la cola falla, el pedido queda en `lista`/`fallida` con
     * `entrega_proxima_en` = ahora, y `reencolar_entregas_perdidas()` lo vuelve a encolar a los 15 minutos:
     * el resultado de la PC ya está guardado y no se pierde.
     *
     * @param IaImagenPedido $pedido
     *
     * @return void
     */
    protected function despachar_entrega(IaImagenPedido $pedido)
    {
        $pedido->entrega_proxima_en = now();
        $pedido->save();

        try {
            /* Sin `afterCommit()`: la cola `database` escribe en la misma base y adentro de la misma
               transacción que el cambio de estado, así que el worker no ve el job antes que el estado. */
            app(\Illuminate\Contracts\Bus\Dispatcher::class)->dispatch(
                (new EntregarImagenIaJob((int) $pedido->id))->onConnection('database')
            );
        } catch (\Throwable $e) {
            Log::warning('IaImagenesService: no se pudo encolar la entrega de una imagen.', [
                'pedido_id' => (int) $pedido->id,
                'error'     => mb_substr($e->getMessage(), 0, 300),
            ]);
        }
    }

    /**
     * Lo que ve la PC de un pedido (contrato C3).
     *
     * @param IaImagenPedido $pedido
     *
     * @return array<string, mixed>
     */
    public function payload(IaImagenPedido $pedido)
    {
        $client = $pedido->client;

        return [
            'id'         => (int) $pedido->id,
            'uuid'       => (string) $pedido->uuid,
            'client'     => [
                'id'     => (int) $pedido->client_id,
                'slug'   => $client !== null ? $client->slug : null,
                'nombre' => $client !== null ? $client->resolve_display_name() : null,
            ],
            'prompt'     => (string) $pedido->prompt,
            'tamano'     => (string) $pedido->tamano,
            'estilo'     => $pedido->estilo,
            'intentos'   => (int) $pedido->intentos,
            'created_at' => optional($pedido->created_at)->toIso8601String(),
        ];
    }

    /**
     * @param int    $http
     * @param string $codigo
     * @param string $mensaje
     *
     * @return array{http: int, cuerpo: array<string, mixed>}
     */
    protected function error($http, $codigo, $mensaje)
    {
        return ['http' => $http, 'cuerpo' => ['code' => $codigo, 'message' => $mensaje]];
    }
}

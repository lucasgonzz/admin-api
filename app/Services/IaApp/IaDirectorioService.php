<?php

namespace App\Services\IaApp;

use App\Models\Client;
use App\Models\IaDirectorio;
use App\Services\ClientCatalogoPuenteService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * El directorio de dueños de la app `ia.comerciocity.com` (misión cruzada `app-ia-y-memoria`, 29/10/2026,
 * plan §4 A.1): con él, el login sabe en qué sistema probar un usuario sin preguntarle «¿cuál es tu
 * sistema?».
 *
 * Se llena de dos maneras:
 *  - `sincronizar($client)`: le pregunta al `empresa-api` del cliente quién es su dueño
 *    (`GET api/admin-sync/ia-app/dueno`, contrato C1.2). Lo corre el barrido nocturno
 *    (`ia:directorio-sincronizar`) y la activación de «Agentes de IA» (`InicializarAgenteIaJob`).
 *  - `aprender($client, $usuario, $nombre)`: cuando alguien entra por `negocio` y su sistema lo acepta.
 * Son filas distintas (`origen` sincronizado | aprendido, único `client_id + usuario_hash`): lo aprendido
 * nunca pisa al dueño sincronizado.
 *
 * 🔴 Del usuario se guarda SOLO un hash: HMAC-SHA256 del usuario normalizado con la APP_KEY del admin como
 * clave. El plan pedía `sha256` pelado; el HMAC es lo más cercano y no se puede revertir probando todos los
 * DNI (un sha256 pelado de un DNI se rompe por fuerza bruta en minutos). Si la APP_KEY cambia, el barrido
 * nocturno vuelve a escribir todos los hashes.
 */
class IaDirectorioService
{
    /** Ruta del contrato C1.2 en el `empresa-api` del cliente. */
    const RUTA_DUENO = 'api/admin-sync/ia-app/dueno';

    const ESTADO_OK              = 'ok';
    const ESTADO_VERSION_VIEJA   = 'version_vieja';
    const ESTADO_CLAVE_RECHAZADA = 'clave_rechazada';
    const ESTADO_SIN_CLAVE       = 'sin_clave';
    const ESTADO_SIN_FRENTE      = 'sin_frente';
    const ESTADO_SIN_CONEXION    = 'sin_conexion';

    /** @var IaEmpresaCliente */
    protected $empresa;

    /**
     * @param IaEmpresaCliente|null $empresa Inyectable para los tests.
     */
    public function __construct(?IaEmpresaCliente $empresa = null)
    {
        $this->empresa = $empresa !== null ? $empresa : new IaEmpresaCliente();
    }

    /**
     * El usuario como lo compara el directorio: sin espacios a los costados, sin puntos ni espacios
     * internos y en minúsculas («20.123.456» y «20123456» son el mismo).
     *
     * @param mixed $usuario
     *
     * @return string
     */
    public static function normalizar_usuario($usuario)
    {
        if (! is_scalar($usuario)) {
            return '';
        }

        $texto = preg_replace('/[\s.]+/u', '', trim((string) $usuario));

        return mb_strtolower((string) $texto, 'UTF-8');
    }

    /**
     * El hash con el que el directorio guarda y busca un usuario; null si el usuario queda vacío.
     *
     * @param mixed $usuario
     *
     * @return string|null
     */
    public static function hash_usuario($usuario)
    {
        $normalizado = self::normalizar_usuario($usuario);

        if ($normalizado === '') {
            return null;
        }

        return hash_hmac('sha256', $normalizado, (string) config('app.key'));
    }

    /**
     * Le pregunta al sistema del cliente quién es su dueño y actualiza su fila. Nunca lanza.
     *
     * Un 404 es una versión del `empresa-api` que todavía no tiene la ruta: no es un error y la fila no se
     * toca.
     *
     * @param Client $client
     *
     * @return array{estado: string, mensaje: string}
     */
    public function sincronizar(Client $client)
    {
        try {
            $respuesta = $this->empresa->pedir(
                $client,
                'GET',
                self::RUTA_DUENO,
                [],
                (int) config('ia_app.directorio.timeout', 15)
            );
        } catch (\Throwable $e) {
            Log::warning('IaDirectorioService: falló la sincronización del directorio.', [
                'client_id' => (int) $client->id,
                'error'     => mb_substr(ClientCatalogoPuenteService::sin_la_query_de_las_urls($e->getMessage()), 0, 300),
            ]);

            return $this->resultado(self::ESTADO_SIN_CONEXION, 'No se pudo contactar al sistema del cliente.');
        }

        if ($respuesta['status'] === null) {
            $estado = $respuesta['motivo'] === 'sin_clave'
                ? self::ESTADO_SIN_CLAVE
                : ($respuesta['motivo'] === 'sin_frente' ? self::ESTADO_SIN_FRENTE : self::ESTADO_SIN_CONEXION);

            return $this->resultado($estado, (string) $respuesta['error']);
        }

        $status = (int) $respuesta['status'];

        if ($status === 404 || $status === 405) {
            return $this->resultado(
                self::ESTADO_VERSION_VIEJA,
                'El sistema del cliente todavía no tiene la app del agente (admin-sync/ia-app/dueno no existe).'
            );
        }

        if ($status === 401 || $status === 403) {
            return $this->resultado(
                self::ESTADO_CLAVE_RECHAZADA,
                'El sistema del cliente rechazó la clave de API que tiene el admin.'
            );
        }

        $json = $respuesta['json'];

        // Un 200 solo no alcanza: el shared sirve su página genérica con 200 cuando la cuenta está saturada.
        if ($status !== 200 || ! is_array($json) || ! array_key_exists('extension_activa', $json)) {
            return $this->resultado(
                self::ESTADO_SIN_CONEXION,
                'El sistema del cliente respondió HTTP ' . $status . ' sin el cuerpo del contrato.'
            );
        }

        $doc_number = isset($json['doc_number']) && is_scalar($json['doc_number']) ? (string) $json['doc_number'] : null;
        $nombre     = isset($json['nombre']) && is_scalar($json['nombre']) ? trim((string) $json['nombre']) : null;

        $hash  = self::hash_usuario($doc_number);
        $datos = [
            'usuario_hash'     => $hash,
            'nombre_dueno'     => $nombre === null || $nombre === '' ? null : mb_substr($nombre, 0, 150),
            'extension_activa' => (bool) $json['extension_activa'],
            'sincronizado_en'  => now(),
        ];

        DB::transaction(function () use ($client, $hash, $datos) {
            // La fila del dueño según su sistema: una por cliente, y solo esta se toca acá.
            $fila = IaDirectorio::where('client_id', (int) $client->id)
                ->where('origen', IaDirectorio::ORIGEN_SINCRONIZADO)
                ->lockForUpdate()
                ->first();

            /* Si el login ya había aprendido a este mismo usuario en este cliente, esa fila se va: el dueño
               sincronizado la reemplaza (si no, chocaría con el único client_id + usuario_hash). Las otras
               filas aprendidas del cliente quedan como están. */
            if ($hash !== null) {
                IaDirectorio::where('client_id', (int) $client->id)
                    ->where('usuario_hash', $hash)
                    ->where('origen', '!=', IaDirectorio::ORIGEN_SINCRONIZADO)
                    ->delete();
            }

            if ($fila === null) {
                IaDirectorio::create(array_merge($datos, [
                    'client_id' => (int) $client->id,
                    'origen'    => IaDirectorio::ORIGEN_SINCRONIZADO,
                ]));

                return;
            }

            $fila->fill($datos)->save();
        });

        return $this->resultado(self::ESTADO_OK, 'Directorio actualizado.');
    }

    /**
     * El login entró por `negocio` y el sistema del cliente lo aceptó: se anota que ese usuario entra a ese
     * cliente (contrato C4.1, paso 5).
     *
     * 🔴 Nunca pisa al dueño sincronizado: si el usuario ya tiene fila en ese cliente (sincronizada o
     * aprendida) solo se refresca; si no, se crea una fila `aprendido` propia.
     *
     * @param Client      $client
     * @param string      $usuario Lo que tipeó la persona (se guarda solo su hash).
     * @param string|null $nombre  Nombre que devolvió el sistema del cliente.
     *
     * @return void
     */
    public function aprender(Client $client, $usuario, $nombre)
    {
        $hash = self::hash_usuario($usuario);

        if ($hash === null) {
            return;
        }

        $nombre = is_string($nombre) && trim($nombre) !== '' ? mb_substr(trim($nombre), 0, 150) : null;

        $existente = IaDirectorio::where('client_id', (int) $client->id)->where('usuario_hash', $hash)->first();

        if ($existente === null) {
            try {
                IaDirectorio::create([
                    'client_id'        => (int) $client->id,
                    'usuario_hash'     => $hash,
                    'origen'           => IaDirectorio::ORIGEN_APRENDIDO,
                    'nombre_dueno'     => $nombre,
                    'extension_activa' => true,
                ]);

                return;
            } catch (QueryException $e) {
                // Dos logins a la vez: la otra ya la creó (único client_id + usuario_hash).
                $existente = IaDirectorio::where('client_id', (int) $client->id)->where('usuario_hash', $hash)->first();

                if ($existente === null) {
                    throw $e;
                }
            }
        }

        // El sistema lo acaba de aceptar: la extensión está activa. El nombre de la sincronizada no se toca.
        $existente->extension_activa = true;

        if ($nombre !== null && $existente->origen === IaDirectorio::ORIGEN_APRENDIDO) {
            $existente->nombre_dueno = $nombre;
        }

        $existente->save();
    }

    /**
     * TODOS los clientes activos donde ese usuario tiene fila (sincronizada o aprendida), hasta `$maximo`:
     * los que tienen la extensión activa primero, después la sincronizada antes que la aprendida y la más
     * reciente primero.
     *
     * @param mixed $usuario
     * @param int   $maximo
     *
     * @return Collection<int, Client>
     */
    public function candidatos($usuario, $maximo = 5)
    {
        $hash = self::hash_usuario($usuario);

        if ($hash === null) {
            return collect();
        }

        $ids = array_values(array_unique(IaDirectorio::where('usuario_hash', $hash)
            ->orderByDesc('extension_activa')
            ->orderByRaw('CASE WHEN origen = ? THEN 0 ELSE 1 END', [IaDirectorio::ORIGEN_SINCRONIZADO])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->pluck('client_id')
            ->map(function ($id) {
                return (int) $id;
            })
            ->all()));

        if ($ids === []) {
            return collect();
        }

        $clientes = Client::whereIn('id', $ids)->where('is_active', true)->get()->keyBy('id');

        $ordenados = [];

        foreach ($ids as $id) {
            if (isset($clientes[$id])) {
                $ordenados[] = $clientes[$id];
            }

            if (count($ordenados) >= $maximo) {
                break;
            }
        }

        return collect($ordenados);
    }

    /**
     * @param string $estado
     * @param string $mensaje
     *
     * @return array{estado: string, mensaje: string}
     */
    protected function resultado($estado, $mensaje)
    {
        return ['estado' => $estado, 'mensaje' => $mensaje];
    }
}

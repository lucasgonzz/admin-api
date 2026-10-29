<?php

namespace App\Jobs;

use App\Models\IaImagenPedido;
use App\Services\IaApp\IaEmpresaCliente;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Le entrega al `empresa-api` del cliente la imagen que generó la PC de Lucas, o el fallo (contrato C1.4 de
 * la misión cruzada `app-ia-y-memoria`, 29/10/2026):
 *
 *   POST {api del cliente}/api/admin-sync/ia-imagenes/{uuid}/resultado   (multipart)
 *     estado = lista | fallida · imagen (si lista) · error (opcional)
 *
 * Header `X-Admin-Api-Key` = `clients.api_key`, sin seguir redirecciones (ver `IaEmpresaCliente`).
 *
 * Reintentos: la primera vuelta y cinco más, a 1, 5, 15, 60 y 240 minutos (plan §4 A.4), por la cola y no
 * con `->retry()` (un reintento en caliente no sirve si el sistema del cliente está caído un rato). Lo que no
 * se arregla insistiendo —404 (versión vieja o pedido que el cliente no conoce), 409, 422— corta enseguida.
 * Desenlace: `entregada` o `entrega_fallida`.
 *
 * 🔴 Se despacha con `->onConnection('database')`; lo levanta el `queue:work database --stop-when-empty` del
 * scheduler (cola `default`). El C1.4 del empresa-api es idempotente, así que una entrega repetida no duplica.
 */
class EntregarImagenIaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Ruta del contrato C1.4 (sin el uuid). */
    const RUTA = 'api/admin-sync/ia-imagenes/';

    /** Minutos de espera antes de cada reintento (1, 5, 15, 60, 240). */
    const ESPERAS_EN_SEGUNDOS = [60, 300, 900, 3600, 14400];

    /**
     * La primera vuelta + los cinco reintentos.
     *
     * @var int
     */
    public $tries = 6;

    /**
     * Segundos máximos de una vuelta (sube hasta 15 MB).
     *
     * @var int
     */
    public $timeout = 150;

    /**
     * @var int
     */
    private $pedido_id;

    /**
     * @param IaImagenPedido|int $pedido
     */
    public function __construct($pedido)
    {
        $this->pedido_id = (int) ($pedido instanceof IaImagenPedido ? $pedido->id : $pedido);
    }

    /**
     * @return int
     */
    public function pedido_id()
    {
        return $this->pedido_id;
    }

    /**
     * @param IaEmpresaCliente $empresa
     *
     * @return void
     */
    public function handle(IaEmpresaCliente $empresa)
    {
        $pedido = IaImagenPedido::with('client')->find($this->pedido_id);

        if ($pedido === null || $pedido->client === null) {
            Log::warning('EntregarImagenIaJob: el pedido o su cliente ya no existen.', ['pedido_id' => $this->pedido_id]);

            return;
        }

        // Solo se entrega lo que tiene resultado y todavía no se entregó.
        if (! in_array($pedido->estado, [IaImagenPedido::ESTADO_LISTA, IaImagenPedido::ESTADO_FALLIDA], true)) {
            return;
        }

        $cuerpo  = ['estado' => $pedido->resultado === IaImagenPedido::ESTADO_LISTA ? 'lista' : 'fallida'];
        $adjunto = [];

        if ($cuerpo['estado'] === 'lista') {
            $disco = (string) config('ia_app.imagenes.disco', 'local');

            if ($pedido->imagen_path === null || ! Storage::disk($disco)->exists($pedido->imagen_path)) {
                // La imagen se perdió del disco: se le avisa al cliente como fallo en vez de dejarlo esperando.
                $cuerpo = ['estado' => 'fallida', 'error' => 'La imagen generada no se encontró en el servidor.'];
            } else {
                $adjunto = [
                    'campo'     => 'imagen',
                    'contenido' => Storage::disk($disco)->get($pedido->imagen_path),
                    'nombre'    => basename($pedido->imagen_path),
                ];
            }
        }

        if ($cuerpo['estado'] === 'fallida' && ! isset($cuerpo['error'])) {
            $cuerpo['error'] = mb_substr((string) ($pedido->error !== null ? $pedido->error : 'No se pudo generar la imagen.'), 0, 500);
        }

        $pedido->entrega_intentos = (int) $pedido->entrega_intentos + 1;
        $pedido->save();

        $respuesta = $empresa->pedir(
            $pedido->client,
            'POST',
            self::RUTA . rawurlencode((string) $pedido->uuid) . '/resultado',
            $cuerpo,
            (int) config('ia_app.imagenes.timeout_entrega', 60),
            $adjunto
        );

        $status = $respuesta['status'];

        Log::info('EntregarImagenIaJob: entrega al sistema del cliente.', [
            'pedido_id' => (int) $pedido->id,
            'client_id' => (int) $pedido->client_id,
            'estado'    => $cuerpo['estado'],
            'status'    => $status,
            'motivo'    => $respuesta['motivo'],
            'intento'   => $this->attempts(),
        ]);

        if ($status === 200 && is_array($respuesta['json']) && ! empty($respuesta['json']['ok'])) {
            $pedido->estado        = IaImagenPedido::ESTADO_ENTREGADA;
            $pedido->entregado_en  = now();
            $pedido->entrega_error = null;
            $pedido->save();

            return;
        }

        if ($status === 404 || $status === 405 || $status === 409 || $status === 422) {
            $codigo = IaEmpresaCliente::codigo($respuesta['json']);

            $this->entrega_fallida($pedido, 'El sistema del cliente respondió HTTP ' . $status
                . ($codigo !== '' ? ' (' . mb_substr($codigo, 0, 40) . ')' : '') . '.');

            return;
        }

        // 401/403, 5xx, 3xx, un 200 sin el contrato o sin conexión: se reintenta más tarde.
        $detalle = $status === null
            ? (string) $respuesta['motivo']
            : 'HTTP ' . $status;

        if ($this->attempts() < $this->tries) {
            $pedido->entrega_error = mb_substr('Reintentando: ' . $detalle, 0, 500);
            $pedido->save();

            $this->release(self::ESPERAS_EN_SEGUNDOS[min(max($this->attempts(), 1) - 1, count(self::ESPERAS_EN_SEGUNDOS) - 1)]);

            return;
        }

        $this->entrega_fallida($pedido, 'No se pudo entregar después de ' . $this->tries . ' intentos (' . $detalle . ').');
    }

    /**
     * Si el job se cae por una excepción y se queda sin vueltas.
     *
     * @param \Throwable $e
     *
     * @return void
     */
    public function failed($e)
    {
        $pedido = IaImagenPedido::find($this->pedido_id);

        if ($pedido !== null && in_array($pedido->estado, [IaImagenPedido::ESTADO_LISTA, IaImagenPedido::ESTADO_FALLIDA], true)) {
            $this->entrega_fallida($pedido, mb_substr('Excepción al entregar: ' . ($e instanceof \Throwable ? get_class($e) : ''), 0, 500));
        }
    }

    /**
     * @param IaImagenPedido $pedido
     * @param string         $motivo
     *
     * @return void
     */
    protected function entrega_fallida(IaImagenPedido $pedido, $motivo)
    {
        $pedido->estado        = IaImagenPedido::ESTADO_ENTREGA_FALLIDA;
        $pedido->entrega_error = mb_substr($motivo, 0, 500);
        $pedido->save();

        Log::warning('EntregarImagenIaJob: la entrega quedó fallida.', [
            'pedido_id' => (int) $pedido->id,
            'client_id' => (int) $pedido->client_id,
            'motivo'    => $pedido->entrega_error,
        ]);
    }
}

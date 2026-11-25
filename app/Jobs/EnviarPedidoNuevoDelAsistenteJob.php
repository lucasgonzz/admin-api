<?php

namespace App\Jobs;

use App\Models\Client;
use App\Services\AsistenteRecordatoriosService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Manda ahora el aviso de pedido nuevo de la tienda al WhatsApp del asistente del dueño
 * (misión cruzada whatsapp-aviso-pedido, 25/11/2026, contrato C3).
 *
 * Lo encola `InboundAsistentePedidoNuevoController` cuando el `empresa-api` del cliente avisa que
 * llegó un pedido. No arma ningún mensaje por su cuenta: corre el MISMO envío que la consulta de
 * respaldo de cada 5 minutos (`AsistenteRecordatoriosService::enviar_a`), así que la ventana de
 * 24 hs, la plantilla de tareas, el reclamo atómico del ítem y el sello `recordatorios/avisado`
 * son los de siempre. Con eso, el empuje y el respaldo ven el mismo ítem y sale un solo WhatsApp.
 *
 * 🔴 Se despacha SIEMPRE con `onConnection('database')` y `onQueue('asistente')`: la conexión por
 * defecto del admin es `sync` y correría el envío dentro del pedido HTTP del `empresa-api`, que
 * está esperando la respuesta.
 */
class EnviarPedidoNuevoDelAsistenteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Un solo intento: los reintentos (hasta 3) y el sello son del servicio de recordatorios,
     * no de la cola. Reintentar acá duplicaría esa lógica.
     *
     * @var int
     */
    public $tries = 1;

    /**
     * Tope de segundos del job (una consulta al empresa-api y un envío a Meta).
     *
     * @var int
     */
    public $timeout = 60;

    /**
     * Id del cliente al que se le avisa (el de la clave inbound, nunca el del body).
     *
     * @var int
     */
    public $client_id;

    /**
     * @param int $client_id Id del cliente dueño del pedido.
     */
    public function __construct(int $client_id)
    {
        $this->client_id = $client_id;
    }

    /**
     * Corre el envío de recordatorios de ese cliente.
     *
     * Si el cliente ya no existe o se quedó sin canal entre el encolado y el worker, no sale a la red.
     *
     * @param AsistenteRecordatoriosService $servicio Servicio de recordatorios del asistente.
     *
     * @return void
     */
    public function handle(AsistenteRecordatoriosService $servicio): void
    {
        $client = Client::find($this->client_id);
        if (!$client) {
            return;
        }

        // El canal pudo apagarse mientras el job esperaba en la cola.
        if (!AsistenteRecordatoriosService::cliente_recibe_recordatorios($client)) {
            Log::info('[asistente:pedido-nuevo] el cliente ya no tiene canal; no se envía', ['client_id' => $client->id]);
            return;
        }

        $resultado = $servicio->enviar_a($client);

        // Sin teléfono ni texto del mensaje: sólo el estado del envío.
        Log::info('[asistente:pedido-nuevo] envío corrido', [
            'client_id'     => $client->id,
            'estado'        => $resultado['estado'] ?? null,
            'recordatorios' => $resultado['recordatorios'] ?? null,
            'enviados'      => $resultado['enviados'] ?? null,
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\EnviarPedidoNuevoDelAsistenteJob;
use App\Services\AsistenteRecordatoriosService;
use App\Services\AsistenteWhatsappService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Empuje del aviso de pedido nuevo de la tienda (misión cruzada whatsapp-aviso-pedido, contrato C3).
 *
 * El `empresa-api` del cliente le pega acá cuando llega un pedido, para que el aviso por WhatsApp
 * salga en el momento y no espere la consulta de cada 5 minutos (que queda como respaldo).
 *
 * El cliente se resuelve por la clave `X-Admin-Api-Key` (middleware `admin.inbound.strict`, que lo deja
 * en `sync_client`); nada del body decide a quién se le avisa. Este controlador no envía nada: encola
 * un job en `database`/`asistente` y responde 202 enseguida, porque el `empresa-api` espera la respuesta.
 */
class InboundAsistentePedidoNuevoController extends Controller
{
    /**
     * Encola el envío del aviso de un pedido.
     *
     * @param Request $request Body `{order_id: int > 0}`.
     *
     * @return \Illuminate\Http\JsonResponse 202 `{encolado: bool, motivo?: string}` o 422 sin `order_id`.
     */
    public function store(Request $request)
    {
        // Cliente de la clave (nunca del body).
        $client = $request->attributes->get('sync_client');

        // `order_id` tiene que ser un entero positivo (acepta "78" pero no "abc", 0 ni negativos).
        $order_id = filter_var($request->input('order_id'), FILTER_VALIDATE_INT);
        if ($order_id === false || $order_id <= 0) {
            return response()->json(['message' => 'order_id debe ser un entero mayor a 0.'], 422);
        }

        // Sin canal no se encola ni se marca el pedido (cuando el canal se conecte, el respaldo lo cubre).
        if (!AsistenteRecordatoriosService::cliente_recibe_recordatorios($client)) {
            Log::info('[asistente:pedido-nuevo] cliente sin canal; no se encola', ['client_id' => $client->id, 'order_id' => $order_id]);
            return response()->json(['encolado' => false, 'motivo' => 'canal_no_conectado'], 202);
        }

        // Marca atómica por cliente y pedido: dos empujes del mismo pedido encolan un solo job.
        if (!Cache::add('asistente:pedido_nuevo:' . $client->id . ':' . $order_id, 1, now()->addHour())) {
            return response()->json(['encolado' => false, 'motivo' => 'ya_encolado'], 202);
        }

        EnviarPedidoNuevoDelAsistenteJob::dispatch($client->id)
            ->onConnection('database')
            ->onQueue(AsistenteWhatsappService::COLA_DEL_ASISTENTE);

        return response()->json(['encolado' => true], 202);
    }
}

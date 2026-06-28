<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\RecallConfig;
use App\Services\CallSummaryService;
use App\Services\RecallService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Recibe los eventos de webhook de Recall.ai cuando el bot termina una reunión.
 *
 * Recall envía distintos tipos de eventos durante el ciclo de vida del bot.
 * Este controller solo procesa `bot.done`, que indica que la grabación y
 * transcripción están completas y disponibles para descarga.
 *
 * El endpoint responde 200 OK inmediatamente en todos los casos para que Recall
 * no reintente el envío. Los errores se logean internamente.
 */
class RecallWebhookController extends Controller
{
    /**
     * Recibe el evento de Recall.ai y procesa la transcripción si corresponde.
     *
     * Flujo:
     * 1. Validar firma HMAC si hay webhook_secret configurado.
     * 2. Ignorar eventos que no sean `bot.done`.
     * 3. Buscar el lead por recall_bot_id.
     * 4. Obtener la transcripción de Recall y formatearla.
     * 5. Delegar a CallSummaryService para extraer el resumen y notificar al equipo.
     *
     * @param Request             $request              Petición entrante de Recall.ai.
     * @param RecallService       $recall_service       Servicio de comunicación con Recall.ai.
     * @param CallSummaryService  $call_summary_service Servicio de extracción de resumen con Claude.
     *
     * @return JsonResponse Siempre 200 para evitar reintentos de Recall.
     */
    public function receive(Request $request, RecallService $recall_service, CallSummaryService $call_summary_service): JsonResponse
    {

        Log::channel('daily')->info('[RECALL_WEBHOOK] Payload completo.', [
            'payload' => $request->all(),
        ]);
        /* Validar firma HMAC si hay webhook_secret configurado en la BD. */
        $config = RecallConfig::where('is_active', true)->first();
        if ($config && $config->webhook_secret) {
            $signature = (string) ($request->header('X-Recall-Signature') ?? '');
            $expected  = hash_hmac('sha256', $request->getContent(), $config->webhook_secret);
            if (!hash_equals($expected, $signature)) {
                Log::channel('daily')->warning('[RECALL_WEBHOOK] Firma HMAC inválida.');
                return response()->json(['error' => 'Firma inválida'], 401);
            }
        }

        /* Extraer tipo de evento e ID del bot de la petición. */
        $event  = $request->input('event');
        $bot_id = $request->input('data.bot.id');

        Log::channel('daily')->info('[RECALL_WEBHOOK] Evento recibido.', [
            'event'  => $event,
            'bot_id' => $bot_id,
        ]);

        /*
         * Solo procesar el evento bot.done, que indica que la transcripción
         * está completa y disponible para ser descargada.
         */
        if ($event !== 'bot.done') {
            return response()->json(['ok' => true]);
        }

        /* Verificar que el evento venga con un bot_id válido. */
        if (!$bot_id) {
            Log::channel('daily')->warning('[RECALL_WEBHOOK] Evento bot.done sin bot_id.');
            return response()->json(['ok' => true]);
        }

        /* Buscar el lead que tiene asignado este bot de Recall. */
        $lead = Lead::where('recall_bot_id', $bot_id)->first();
        if (!$lead) {
            Log::channel('daily')->warning('[RECALL_WEBHOOK] No se encontró lead para el bot_id.', [
                'bot_id' => $bot_id,
            ]);
            return response()->json(['ok' => true]);
        }

        /* Obtener la transcripción cruda de Recall.ai. */
        $utterances = $recall_service->get_transcript($bot_id);
        if (empty($utterances)) {
            Log::channel('daily')->warning('[RECALL_WEBHOOK] Transcripción vacía o nula.', [
                'lead_id' => $lead->id,
                'bot_id'  => $bot_id,
            ]);
            return response()->json(['ok' => true]);
        }

        /* Formatear las utterances en texto plano para enviarlo a Claude. */
        $transcript_text = $recall_service->format_transcript($utterances);

        /* Procesar la transcripción: extrae el resumen con Claude y notifica al equipo. */
        $call_summary_service->process_transcript_for_lead($lead, $transcript_text);

        return response()->json(['ok' => true]);
    }
}

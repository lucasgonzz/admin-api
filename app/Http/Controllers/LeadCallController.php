<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\LeadCall;
use App\Services\LeadCallService;
use App\Services\RecallService;

/**
 * Endpoints JSON del ciclo de llamadas del closer con un lead (unirse/crear/mandar bot),
 * consumidos por el panel del closer en admin-spa (grupo 118).
 *
 * Controller separado de LeadController (que ya es enorme) para mantenerlo enfocado
 * exclusivamente en el ciclo de vida de las llamadas (LeadCall).
 */
class LeadCallController extends Controller
{
    /**
     * Unirse a Meet: obtiene o crea la llamada pendiente del lead (reutilizando el Meet del
     * agendamiento la primera vez) y manda el bot de Recall.ai automáticamente si la llamada
     * todavía no tiene uno asignado.
     *
     * Es el botón de la columna "Listos para la llamada" cuando el lead YA tiene una llamada
     * agendada por el agente (grupo 307): reusa esa fila y le estampa `started_at`, que es lo
     * que lo mueve a "En seguimiento".
     *
     * @param int|string          $lead_id
     * @param LeadCallService     $call_service
     * @param RecallService       $recall_service
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function join_json($lead_id, LeadCallService $call_service, RecallService $recall_service)
    {
        // Lead sobre el cual se busca/crea la llamada pendiente.
        $lead = Lead::findOrFail($lead_id);
        // Llamada pendiente reutilizada o recién creada (idempotente mientras siga pendiente).
        $call = $call_service->get_or_create_pending_call_for_lead($lead);

        // Envío automático del bot solo si todavía no se le mandó uno y ya tiene link de Meet.
        if (empty($call->recall_bot_id) && ! empty($call->meet_url)) {
            $recall_service->send_bot_for_call($call);
            $call->refresh();
        }

        $this->promote_to_closer_activo($lead);

        return response()->json(['call' => $call], 200);
    }

    /**
     * Nueva reunión (ad-hoc): crea SIEMPRE una llamada nueva con evento
     * para ahora + la duración de llamada configurada, y manda el bot automáticamente.
     *
     * @param int|string      $lead_id
     * @param LeadCallService $call_service
     * @param RecallService   $recall_service
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function create_new_json($lead_id, LeadCallService $call_service, RecallService $recall_service)
    {
        // Lead para el cual se fuerza una llamada nueva (no reutiliza pendientes).
        $lead = Lead::findOrFail($lead_id);
        // Llamada ad-hoc nueva, con evento de Google Calendar recién creado si el closer lo tiene conectado.
        $call = $call_service->create_new_call_now($lead);

        // Envío automático del bot solo si todavía no se le mandó uno y ya tiene link de Meet.
        if (empty($call->recall_bot_id) && ! empty($call->meet_url)) {
            $recall_service->send_bot_for_call($call);
            $call->refresh();
        }

        $this->promote_to_closer_activo($lead);

        return response()->json(['call' => $call], 200);
    }

    /**
     * Pasa el lead a `closer_activo` cuando arranca una llamada desde el panel y todavía estaba
     * en `demo_realizada`.
     *
     * Es el caso del lead que terminó la demo y que nadie llegó a preguntarle si quería avanzar
     * (o que cayó ahí por el vencimiento de `demo_pendiente_de_terminar`): si el closer se sube a
     * una videollamada con él, el lead ya está en manos del closer y el estado tiene que decirlo.
     * `CallSummaryService` hace lo mismo cuando llega la transcripción; acá se adelanta al momento
     * en que la llamada arranca, que es cuando el hecho ocurre.
     *
     * No toca ningún otro estado: un lead en `closer_activo` ya está donde corresponde, y uno en
     * cualquier otro estado (por ejemplo `cerrado_ganado` al que se le hace una llamada más) no
     * tiene por qué retroceder.
     *
     * @param Lead $lead Lead cuya llamada acaba de arrancar.
     *
     * @return void
     */
    private function promote_to_closer_activo(Lead $lead)
    {
        if ((string) $lead->status !== 'demo_realizada') {
            return;
        }

        $lead->update(['status' => 'closer_activo']);
    }

    /**
     * Manda el bot de Recall.ai a una llamada puntual, sin importar si ya tenía uno asignado
     * (respaldo manual: reintento si el envío automático falló o el bot no llegó a entrar).
     *
     * @param int|string    $lead_id
     * @param int|string    $call_id
     * @param RecallService $recall_service
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function send_bot_json($lead_id, $call_id, RecallService $recall_service)
    {
        // Validar que la llamada pertenezca al lead indicado (evita mandar el bot a la
        // llamada de otro lead por un id mal armado desde el frontend).
        $call = LeadCall::where('lead_id', $lead_id)->where('id', $call_id)->firstOrFail();

        if (empty($call->meet_url)) {
            return response()->json(['message' => 'Esta llamada todavía no tiene un link de Meet.'], 422);
        }

        $recall_service->send_bot_for_call($call);
        $call->refresh();

        return response()->json(['call' => $call], 200);
    }
}

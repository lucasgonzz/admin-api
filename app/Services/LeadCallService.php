<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadCall;
use Carbon\Carbon;

/**
 * Gestiona la creación/reutilización de llamadas del closer con un lead (`LeadCall`), decidiendo
 * cuándo reusar el Meet del agendamiento original y cuándo crear uno nuevo ad-hoc.
 */
class LeadCallService
{
    /** @var CloserGoogleCalendarEventService */
    private $calendar_service;

    /**
     * @param CloserGoogleCalendarEventService $calendar_service Servicio de calendario del closer,
     *                                                             usado para crear eventos ad-hoc.
     */
    public function __construct(CloserGoogleCalendarEventService $calendar_service)
    {
        $this->calendar_service = $calendar_service;
    }

    /**
     * Devuelve la llamada "pendiente" (sin terminar) del lead si ya existe, o crea una:
     * la primera vez reutiliza el Meet del agendamiento original del lead si lo tiene;
     * si no, crea un evento ad-hoc para ahora. Idempotente: tocar el botón "Unirse a Meet"
     * varias veces seguidas devuelve siempre la misma llamada mientras siga pendiente.
     *
     * @param Lead $lead Lead para el cual conseguir/crear la llamada.
     *
     * @return LeadCall
     */
    public function get_or_create_pending_call_for_lead(Lead $lead): LeadCall
    {
        // Si ya hay una llamada pendiente (sin terminar) para este lead, se reutiliza esa misma
        // fila para que el botón sea idempotente y no dispare llamadas duplicadas.
        $pending = $lead->calls()->where('estado', 'pendiente')->orderByDesc('id')->first();
        if ($pending) {
            return $pending;
        }

        // Determina si esta sería la primerísima llamada del lead (nunca tuvo ninguna fila en
        // LeadCall todavía). Solo en ese caso corresponde reutilizar el Meet del agendamiento
        // original en lugar de crear uno ad-hoc nuevo.
        $ya_tiene_llamadas = $lead->calls()->exists();

        // Solo en la primerísima llamada del lead reutilizamos el Meet del agendamiento original.
        if (! $ya_tiene_llamadas && ! empty($lead->meet_url)) {
            return LeadCall::create([
                'lead_id'         => $lead->id,
                'meet_url'        => $lead->meet_url,
                'google_event_id' => $lead->google_event_id,
                'estado'          => 'pendiente',
                'started_at'      => now(),
            ]);
        }

        // No hay Meet propio para reutilizar (demo flexible o el lead nunca pasó por el flujo
        // normal de agendamiento) o ya tiene llamadas previas: se crea una llamada ad-hoc nueva.
        return $this->create_new_call_now($lead);
    }

    /**
     * Crea SIEMPRE una llamada nueva, con un evento ad-hoc para ahora
     * (duración = LeadDemoSettings::get_duracion_llamada_closer_minutos()).
     * Usado por el botón "Nueva reunión" en Seguimiento, donde no corresponde
     * reutilizar Meets anteriores.
     *
     * @param Lead $lead Lead para el cual crear la llamada.
     *
     * @return LeadCall
     */
    public function create_new_call_now(Lead $lead): LeadCall
    {
        // Crea el evento ad-hoc en el Google Calendar del closer (puede devolver null si el
        // closer no tiene calendario conectado o falla la llamada a Google).
        $result = $this->calendar_service->create_ad_hoc_meet_now($lead);

        // Best-effort: si Google falla (closer sin calendario conectado, error de red), igual
        // se crea la llamada sin Meet — el frontend debe mostrar que falta el link y permitir
        // reintentar (el botón "Unirse a Meet"/"Nueva reunión" queda deshabilitado sin meet_url).
        return LeadCall::create([
            'lead_id'         => $lead->id,
            'meet_url'        => $result['meet_url'] ?? null,
            'google_event_id' => $result['google_event_id'] ?? null,
            'estado'          => 'pendiente',
            'started_at'      => now(),
        ]);
    }

    /**
     * Crea (o reutiliza, si ya hay una pendiente) la LeadCall de la llamada agendada con el closer
     * por el propio agente de IA (grupo 307, prompt 03) -- distinta del flujo de "Unirse a
     * Meet"/"Nueva reunión" del panel, que arranca la llamada YA: acá se guarda `scheduled_at`
     * porque la llamada todavía no arrancó, y deliberadamente NO se toca `started_at` (queda null
     * hasta que la llamada efectivamente arranque, ver el resto del ciclo de vida de LeadCall).
     *
     * Idempotente: si el lead ya tiene una LeadCall pendiente, la actualiza en vez de crear una
     * segunda -- un lead que confirma "dale" varias veces no puede terminar con varias reuniones
     * en el calendario del closer (el llamador ya filtra este caso antes de invocar, pero el
     * método queda seguro igual si se llama dos veces).
     *
     * @param Lead        $lead
     * @param Carbon      $scheduled_at    Momento acordado con el lead.
     * @param string|null $google_event_id Id del evento de Google (hold promovido o evento nuevo).
     * @param string|null $meet_url        Link de Meet, si Google lo devolvió.
     *
     * @return LeadCall
     */
    public function schedule_closer_call(Lead $lead, Carbon $scheduled_at, ?string $google_event_id, ?string $meet_url): LeadCall
    {
        $pending = $lead->calls()->where('estado', 'pendiente')->orderByDesc('id')->first();

        if ($pending) {
            $pending->update([
                'scheduled_at'    => $scheduled_at,
                'google_event_id' => $google_event_id,
                'meet_url'        => $meet_url,
            ]);

            return $pending->fresh();
        }

        return LeadCall::create([
            'lead_id'         => $lead->id,
            'scheduled_at'    => $scheduled_at,
            'google_event_id' => $google_event_id,
            'meet_url'        => $meet_url,
            'estado'          => 'pendiente',
        ]);
    }
}

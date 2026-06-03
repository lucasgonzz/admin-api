<?php

namespace App\Services;

use App\Models\FollowupRule;
use App\Models\Lead;
use App\Models\LeadMessage;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Evalúa leads activos y dispara seguimientos automáticos vía {@see LeadAiService}
 * o pausa el lead si se agotaron los intentos.
 */
class LeadFollowupService
{
    /**
     * Procesa todos los leads que no están cerrados / en pausa final.
     *
     * @return array{processed:int,suggestions:int,paused:int,errors:int}
     */
    public function process_all_active_leads(): array
    {
        $stats = ['processed' => 0, 'suggestions' => 0, 'paused' => 0, 'errors' => 0];
        $rules = FollowupRule::query()->where('activa', true)->get()->keyBy('estado');

        $leads = Lead::query()
            ->whereNotIn('status', ['cerrado_ganado', 'cerrado_perdido', 'en_pausa'])
            ->get();

        foreach ($leads as $lead) {
            $stats['processed']++;
            try {
                $result = $this->process_lead($lead, $rules);
                if ($result === 'suggestion') {
                    $stats['suggestions']++;
                }
                if ($result === 'paused') {
                    $stats['paused']++;
                }
            } catch (\Throwable $e) {
                $stats['errors']++;
                Log::error('LeadFollowupService error', ['lead_id' => $lead->id, 'msg' => $e->getMessage()]);
            }
        }

        return $stats;
    }

    /**
     * Punto de entrada público para procesar un único lead.
     *
     * Carga las reglas activas y delega en {@see process_lead}.
     * Diseñado para uso desde comandos de testing local que necesitan
     * disparar el seguimiento sin esperar el cron.
     *
     * @param Lead $lead Lead a procesar (con o sin mensajes precargados).
     *
     * @return string|null 'suggestion' si Claude generó sugerencia, 'paused' si se pausó, null si se omitió.
     */
    public function process_single_lead(Lead $lead): ?string
    {
        /* Cargar todas las reglas activas indexadas por estado para la evaluación */
        $rules = FollowupRule::query()->where('activa', true)->get()->keyBy('estado');

        return $this->process_lead($lead, $rules);
    }

    /**
     * @param Lead                                       $lead
     * @param \Illuminate\Support\Collection $rules_by_estado
     *
     * @return string|null suggestion|paused|null
     */
    protected function process_lead(Lead $lead, $rules_by_estado): ?string
    {
        if ($lead->tiene_sugerencia_pendiente) {
            return null;
        }
        if (! $rules_by_estado->has($lead->status)) {
            return null;
        }

        /** @var FollowupRule $rule */
        $rule = $rules_by_estado->get($lead->status);
        $last_at = $this->last_message_at($lead);
        $hours = $last_at->diffInHours(Carbon::now());
        if ($hours < (int) $rule->horas_espera) {
            return null;
        }

        $followups = LeadMessage::query()
            ->where('lead_id', $lead->id)
            ->where('is_followup', true)
            ->where('status', '!=', 'rechazado')
            ->count();

        if ($followups >= (int) $rule->max_followups) {
            $this->pause_lead($lead);

            return 'paused';
        }

        $fresh = Lead::query()->with('messages')->where('id', $lead->id)->first();
        if (! $fresh) {
            return null;
        }
        app(LeadAiService::class)->generate_suggestion($fresh, true);

        return 'suggestion';
    }

    /**
     * Timestamp del último mensaje no rechazado, o creación del lead.
     *
     * @param Lead $lead
     *
     * @return Carbon
     */
    protected function last_message_at(Lead $lead): Carbon
    {
        $m = LeadMessage::query()
            ->where('lead_id', $lead->id)
            ->where('status', '!=', 'rechazado')
            ->orderByDesc('id')
            ->first();
        if ($m && $m->created_at) {
            return Carbon::parse($m->created_at);
        }

        return $lead->created_at ? Carbon::parse($lead->created_at) : Carbon::now();
    }

    /**
     * Pasa el lead a en_pausa y registra mensaje de sistema.
     *
     * @param Lead $lead
     *
     * @return void
     */
    protected function pause_lead(Lead $lead): void
    {
        $lead->status = 'en_pausa';
        $lead->requiere_seguimiento = false;
        $lead->tiene_sugerencia_pendiente = false;
        $lead->tiene_seguimiento_sin_ver = false;
        $lead->save();

        LeadMessage::create([
            'lead_id' => $lead->id,
            'sender' => 'sistema',
            'content' => 'Lead pasado a En Pausa automáticamente por inactividad.',
            'status' => 'enviado',
            'is_followup' => false,
            'requiere_verificacion' => false,
        ]);
    }
}

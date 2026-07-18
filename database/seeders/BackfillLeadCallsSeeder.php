<?php

namespace Database\Seeders;

use App\Models\Lead;
use App\Models\LeadCall;
use App\Models\LeadPartner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * Backfill de UNA SOLA CORRIDA (refactor "múltiples llamadas por lead", grupo 115, prompt 485).
 *
 * Antes del refactor del prompt 484, cada lead tenía a lo sumo una llamada del closer, con los
 * datos sueltos en columnas propias de la tabla `leads` (meet_url, google_event_id, recall_bot_id,
 * call_summary). Este seeder mueve esa historia a la tabla nueva `lead_calls`: por cada lead que
 * ya tuvo llamada, crea una fila en `lead_calls` con esos datos y reasigna los `lead_partners`
 * de ese lead (que hoy cuelgan directo de `lead_id`) a la llamada recién creada.
 *
 * Idempotente: si el lead ya tiene alguna fila en `lead_calls`, se saltea (no duplica la llamada
 * ni vuelve a tocar sus socios). Se puede correr más de una vez sin efectos secundarios.
 *
 * IMPORTANTE: no forma parte del seed de instalación (no se registra en DatabaseSeeder). En una
 * instalación nueva no hay historia que migrar. Se corre una única vez en producción con:
 *
 *   php artisan db:seed --class=BackfillLeadCallsSeeder
 *
 * Nota sobre la transcripción histórica: `lead_calls.transcript` queda `null` para las llamadas
 * migradas acá, porque la transcripción cruda nunca se guardó (solo se conservaba el resumen
 * generado a partir de ella). Es una limitación de los datos de origen, no un bug del seeder.
 */
class BackfillLeadCallsSeeder extends Seeder
{
    /**
     * Ejecuta el backfill: recorre los leads con historia de llamada, crea su `LeadCall`
     * correspondiente (si todavía no existe) y reasigna los socios sueltos del lead a esa llamada.
     *
     * @return void
     */
    public function run()
    {
        // Contadores para el resumen final (procesados / creados / saltados por idempotencia).
        $procesados = 0;
        $creados    = 0;
        $saltados   = 0;

        // Leads con al menos un rastro de llamada histórica en las columnas viejas de `leads`.
        $leads = Lead::query()
            ->where(function ($q) {
                $q->whereNotNull('call_summary')
                  ->orWhereNotNull('meet_url')
                  ->orWhereNotNull('google_event_id')
                  ->orWhereNotNull('recall_bot_id');
            })
            ->get();

        foreach ($leads as $lead) {
            $procesados++;

            // Idempotencia: si el lead ya tiene alguna llamada migrada/creada, no volver a crearla
            // ni reasignar socios de nuevo (evita duplicados en corridas repetidas del seeder).
            if ($lead->calls()->exists()) {
                $saltados++;

                continue;
            }

            // `call_summary` ya viene casteado a array (o null) por el modelo Lead ($casts), no
            // hace falta json_decode manual acá.
            $call_summary = $lead->call_summary;

            // Estado de la llamada histórica: 'completada' si llegó a tener resumen, 'pendiente'
            // si solo quedó el Meet/bot agendado pero nunca llegó transcripción/resumen.
            $estado = (! empty($call_summary)) ? 'completada' : 'pendiente';

            // Fecha de la llamada: no hay timestamp exacto histórico, se usa updated_at del lead
            // como aproximación razonable (o now() si el lead no tuviera updated_at por algún motivo).
            $started_at = $lead->updated_at ? $lead->updated_at : now();

            // Crea la llamada nueva con los datos que hoy vivían sueltos en `leads`.
            $call = LeadCall::create([
                'lead_id'         => $lead->id,
                'meet_url'        => $lead->meet_url,
                'google_event_id' => $lead->google_event_id,
                'recall_bot_id'   => $lead->recall_bot_id,
                // La transcripción cruda histórica nunca se persistió (solo el resumen generado a
                // partir de ella), así que queda null para las llamadas migradas.
                'transcript'      => null,
                'call_summary'    => (! empty($call_summary)) ? $call_summary : null,
                'estado'          => $estado,
                'started_at'      => $started_at,
            ]);

            $creados++;

            // Reasigna a esta llamada los socios del lead que todavía no tengan lead_call_id
            // (socios cargados antes del refactor, colgando solo de lead_id).
            LeadPartner::query()
                ->where('lead_id', $lead->id)
                ->whereNull('lead_call_id')
                ->update(['lead_call_id' => $call->id]);
        }

        // Resumen legible en consola (además del log persistente en el canal daily).
        $resumen = "BackfillLeadCallsSeeder: {$procesados} leads procesados, {$creados} llamadas creadas, {$saltados} saltados por idempotencia.";

        if ($this->command) {
            $this->command->info($resumen);
        }

        Log::channel('daily')->info($resumen);
    }
}

<?php

namespace App\Observers;

use App\Models\Lead;
use App\Services\MetaConversionesService;
use Illuminate\Support\Facades\Log;

/**
 * Registra las conversiones a Meta cuando un lead de anuncio cambia de etapa (misión
 * origen-del-lead-y-conversiones-meta, 13/10/2026).
 *
 * Mira solo los cambios que pueden hacer alcanzar un evento: `status`, `promoted_client_id` y
 * `ctwa_clid`. El envío no sale de acá: se registra la fila y se encola `EnviarEventoMetaJob`.
 *
 * ⚠️ No ve los cambios hechos con `Lead::query()->...->update()` (la demo directa escribe
 * `status='demo_agendada'` así). Esos los levanta el conciliador cada diez minutos.
 *
 * 🔴 No puede tirar abajo un `save()`: todo adentro de un try/catch que solo loguea.
 */
class ConversionesMetaObserver
{
    /**
     * @param Lead $lead Recién guardado.
     *
     * @return void
     */
    public function saved(Lead $lead)
    {
        try {
            /* El filtro barato primero, sin tocar la base: casi ningún lead tiene ctwa_clid. */
            if (trim((string) $lead->ctwa_clid) === '') {
                return;
            }

            if (! $lead->wasRecentlyCreated && ! $lead->wasChanged(['status', 'promoted_client_id', 'ctwa_clid'])) {
                return;
            }

            app(MetaConversionesService::class)->registrar_alcanzados($lead);
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('Conversiones Meta: no se pudieron registrar los eventos del lead.', [
                'lead_id' => $lead->id,
                'error'   => app(MetaConversionesService::class)->sin_token($exception->getMessage()),
            ]);
        }
    }
}

<?php

namespace App\Observers;

use App\Models\Lead;
use App\Services\MetaConversionesService;
use Illuminate\Support\Facades\Log;

/**
 * Registra las conversiones a Meta cuando un lead con canal cambia de etapa (misión
 * origen-del-lead-y-conversiones-meta, 13/10/2026): los de anuncio (con `ctwa_clid`, por WhatsApp)
 * y, desde la segunda tanda, los que vinieron de la web (`origen = landing`, al dataset del píxel).
 *
 * Mira solo los cambios que pueden hacer alcanzar un evento o cambiar el canal: `status`,
 * `promoted_client_id`, `ctwa_clid` y `origen`. El envío no sale de acá: se registra la fila y se
 * encola `EnviarEventoMetaJob`.
 *
 * ⚠️ No ve los cambios hechos con `Lead::query()->...->update()` (la demo directa escribe
 * `status='demo_agendada'` así, y el origen se escribe así también). Esos los levanta el
 * conciliador cada diez minutos.
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
            /* El filtro barato primero, sin tocar la base: casi ningún lead tiene canal. */
            if (MetaConversionesService::canal_del_lead($lead) === null) {
                return;
            }

            if (! $lead->wasRecentlyCreated && ! $lead->wasChanged(['status', 'promoted_client_id', 'ctwa_clid', 'origen'])) {
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

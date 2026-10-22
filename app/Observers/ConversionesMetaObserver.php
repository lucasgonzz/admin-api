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
            /* El filtro barato primero, sin tocar la base: un lead sin ctwa_clid y que no vino de la
               web no tiene canal. El canal definitivo (pegajoso) lo decide el servicio.
               Lo único que se reporta de un lead sin canal es la compra (misión
               purchase-capi-al-promover, 22/10/2026), y SOLO en el save() que lo promueve: un
               promovido de hace meses al que le editan el status no manda un Purchase con la fecha
               de hoy. Los ya promovidos los informa `leads:meta-capi-compras`. */
            if (MetaConversionesService::canal_natural($lead) === null) {
                if (self::recien_promovido($lead)) {
                    app(MetaConversionesService::class)->registrar_compra($lead);
                }

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

    /**
     * ¿Este save() es el que lo promovió? `promoted_client_id` pasó de vacío a un cliente en un
     * update. Un lead que nace ya promovido (no pasa en la promoción real, que es un update) no
     * cuenta: en el alta `getChanges()` está vacío.
     *
     * ⚠️ No sirve `wasRecentlyCreated`: Laravel lo deja en true para toda la vida de la instancia,
     * también en los updates que siguen al alta. Y `getChanges()` no se limpia en un save() sin
     * cambios: por eso además se pide que el original (que en `saved` todavía es el de antes del
     * save) esté vacío.
     *
     * @param Lead $lead
     *
     * @return bool
     */
    private static function recien_promovido(Lead $lead): bool
    {
        if (! $lead->wasChanged('promoted_client_id')) {
            return false;
        }

        $antes = $lead->getOriginal('promoted_client_id');

        return ($antes === null || (string) $antes === '')
            && $lead->promoted_client_id !== null && (string) $lead->promoted_client_id !== '';
    }
}

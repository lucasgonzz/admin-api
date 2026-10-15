<?php

namespace App\Console\Commands;

use App\Models\LeadEventoMeta;
use App\Services\MetaConversionesService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * La red de seguridad de las conversiones a Meta (misión origen-del-lead-y-conversiones-meta,
 * 13/10/2026). Corre cada diez minutos desde el scheduler, con `withoutOverlapping()`.
 *
 * Registra los eventos que ningún observer vio (la demo directa cambia el estado con un UPDATE
 * directo), libera los envíos colgados, reencola los `pendiente` huérfanos y los errores (hasta tres
 * intentos, y solo cuando llevan más de quince minutos quietos) y, cuando la configuración aparece,
 * encola lo que quedó `sin_configurar`. El detalle está en `MetaConversionesService::conciliar()`.
 *
 * Desde la segunda tanda (13/10/2026) mira los dos canales: los leads de anuncio (WhatsApp) y los
 * que vinieron de la web (dataset del píxel).
 *
 * Encola, no manda: el envío lo hace el worker de la cola `database`.
 */
class ConciliarConversionesMetaCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'leads:meta-capi-conciliar';

    /**
     * @var string
     */
    protected $description = 'Registra y reintenta las conversiones a Meta (CAPI) de los leads de anuncio y de la web que ningún observer vio o que fallaron';

    /**
     * @param MetaConversionesService $service Inyectado por el contenedor.
     *
     * @return int
     */
    public function handle(MetaConversionesService $service): int
    {
        try {
            $resumen = $service->conciliar();
        } catch (\Throwable $exception) {
            $motivo = $service->sin_token($exception->getMessage());

            Log::channel('daily')->error('leads:meta-capi-conciliar: se cayó la corrida.', ['error' => $motivo]);
            $this->error('Se cayó la corrida: ' . $motivo);

            return 1;
        }

        $this->table(['Qué', 'Cuántos'], [
            ['Eventos registrados', $resumen['registrados']],
            ['Envíos colgados liberados', $resumen['colgados_liberados']],
            ['Pendientes huérfanos reencolados', $resumen['pendientes_reencolados']],
            ['Errores reencolados', $resumen['reintentos']],
            ['Sin configurar reencolados', $resumen['sin_configurar_reenviados']],
        ]);

        foreach (LeadEventoMeta::CANALES as $canal) {
            if (! MetaConversionesService::config_completa($canal)) {
                $this->warn('Canal ' . $canal . ': falta ' . MetaConversionesService::config_que_falta($canal) . '. No sale ningún evento de ese canal hasta que estén en el .env.');
            }
        }

        return 0;
    }
}

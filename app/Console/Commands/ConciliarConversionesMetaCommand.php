<?php

namespace App\Console\Commands;

use App\Services\MetaConversionesService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * La red de seguridad de las conversiones a Meta (misión origen-del-lead-y-conversiones-meta,
 * 13/10/2026). Corre cada diez minutos desde el scheduler, con `withoutOverlapping()`.
 *
 * Registra los eventos que ningún observer vio (la demo directa cambia el estado con un UPDATE
 * directo), libera los envíos colgados, reintenta los errores (hasta tres intentos) y, cuando la
 * configuración aparece, encola lo que quedó `sin_configurar` en los últimos siete días. El detalle
 * está en `MetaConversionesService::conciliar()`.
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
    protected $description = 'Registra y reintenta las conversiones a Meta (CAPI) de los leads de anuncio que ningún observer vio o que fallaron';

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
            ['Errores reencolados', $resumen['reintentos']],
            ['Sin configurar reencolados', $resumen['sin_configurar_reenviados']],
        ]);

        if (! MetaConversionesService::config_completa()) {
            $this->warn('Falta META_CAPI_TOKEN o META_CAPI_DATASET_ID: no sale ningún evento hasta que estén en el .env.');
        }

        return 0;
    }
}

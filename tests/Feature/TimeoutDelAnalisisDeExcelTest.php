<?php

namespace Tests\Feature;

use App\Jobs\ProcessImplementationStage4Import;
use Tests\TestCase;

/**
 * Los tres umbrales del análisis de Excel de la implementación (misión modelos-ia-por-cliente,
 * 30/9/2026), en el orden en que tienen que estar:
 *
 *   1. `services.client_api.excel_analyze_timeout` — cuánto espera el admin a que el cliente
 *      analice UN archivo. Con DeepSeek Pro razonando, no menos de 180 s.
 *   2. `ProcessImplementationStage4Import::$timeout` — cuándo el worker mata el job. Tiene que
 *      alcanzar para más de un archivo con su reintento; si no, el techo 1 es letra muerta.
 *   3. `queue.connections.database.retry_after` — cuándo la cola da el job por perdido y lo vuelve
 *      a correr. Tiene que ser MAYOR que 2, o el análisis corre dos veces en paralelo.
 */
class TimeoutDelAnalisisDeExcelTest extends TestCase
{
    /**
     * @return void
     */
    public function test_los_tres_umbrales_del_analisis_estan_en_orden(): void
    {
        $techo_http = (int) config('services.client_api.excel_analyze_timeout');
        $reintentos = max(1, (int) config('services.client_api.retries', 2));
        $techo_job  = (new ProcessImplementationStage4Import(1))->timeout;
        $retry      = (int) config('queue.connections.database.retry_after');

        $this->assertGreaterThanOrEqual(180, $techo_http, 'El análisis con DeepSeek Pro necesita al menos 180 s.');

        // Al menos dos archivos completos, cada uno con todos sus intentos.
        $this->assertGreaterThan(2 * $reintentos * $techo_http, $techo_job);

        $this->assertGreaterThan($techo_job, $retry);
    }
}

<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Lead;
use App\Models\TrialInstance;
use App\Services\Prueba\PruebaContextoSoporteService;
use Illuminate\Support\Facades\Log;

/**
 * Lo que Martín (el soporte de Codex) necesita saber de un negocio en PRUEBA GRATUITA además de lo que
 * trae el bloque base (misión cruzada prueba-martin-importacion, 3/11/2026).
 *
 * El bloque `prueba` del contexto de un ticket lo arma `PruebaContextoSoporteService` (misión
 * prueba-alta-instancia): estado, vencimiento, días restantes, URL, instancia y los hitos que el admin
 * registró de los eventos de C2. Este servicio NO arma otro bloque: se registra como EXTENSIÓN de ese
 * (`PruebaContextoSoporteService::extender()`, una vez por proceso desde `registrar()`) y le suma las claves
 * con las que Martín lee `prueba.md`:
 *
 *  - `dias`, `dia_actual`: cuánto dura la prueba y en qué día va (desde 1).
 *  - `hitos_fuente`, `proximo_hito`, `proximo_hito_label`, `semilla_presente`: la lectura EN VIVO del sistema
 *    del negocio (C3, `GET admin-sync/prueba/estado`, por el puente del admin con la clave del cliente);
 *    si la instancia no contesta, lo mismo armado con los eventos de C2 (`hitos_fuente = admin`).
 *  - `bienvenida_enviada`, `bienvenida_en_cola`, `ultimo_empujon_at`: qué WhatsApps de la prueba ya salieron,
 *    para que Martín se presente una sola vez.
 *
 * Reglas:
 *  - **Nunca lanza.** El contexto se arma en cada `claim` y en cada `GET tickets/{id}`; ante un error las
 *    claves de este servicio vienen en null y el bloque lo DICE (`error`), sin tumbar la base de M2.
 *  - Sin datos personales de más: ni teléfonos ni claves. Los días y los hitos alcanzan.
 */
class SupportPruebaContextService
{
    /** @var bool Si la extensión ya quedó registrada en este proceso (se registra una sola vez). */
    protected static $registrada = false;

    /** @var PruebaDelClienteService */
    protected $pruebas;

    /**
     * @param PruebaDelClienteService|null $pruebas
     */
    public function __construct(?PruebaDelClienteService $pruebas = null)
    {
        $this->pruebas = $pruebas === null ? app(PruebaDelClienteService::class) : $pruebas;
    }

    /**
     * Registra la extensión en `PruebaContextoSoporteService` (desde `AppServiceProvider::boot()`). Idempotente
     * por proceso; `$forzar` la vuelve a registrar (los tests, que vacían las extensiones entre casos).
     *
     * @param bool $forzar
     *
     * @return void
     */
    public static function registrar(bool $forzar = false): void
    {
        /* `prueba.videos` (misión prueba-ayuda-unificada-en-soporte, 10/11/2026): otra extensión, con su propio
         * try, para que una falla del catálogo no anule las claves de seguimiento de acá (ni al revés). */
        \App\Services\Prueba\PruebaVideoSoporteService::registrar($forzar);

        if (self::$registrada && ! $forzar) {
            return;
        }

        PruebaContextoSoporteService::extender(function (array $base, Client $client, ?Lead $lead, ?TrialInstance $instancia) {
            return app(SupportPruebaContextService::class)->extension($base, $client, $lead, $instancia);
        });

        self::$registrada = true;
    }

    /**
     * Las claves que se le suman al bloque base. Nunca lanza.
     *
     * @param array<string, mixed> $base      El bloque de `PruebaContextoSoporteService` (no se pisa ninguna de sus claves).
     * @param Client               $client
     * @param Lead|null            $lead
     * @param TrialInstance|null   $instancia
     *
     * @return array<string, mixed>
     */
    public function extension(array $base, Client $client, ?Lead $lead, ?TrialInstance $instancia = null): array
    {
        try {
            $lead    = $lead !== null ? $lead : $this->pruebas->lead_del_cliente($client);
            $resumen = $this->pruebas->resumen($client, $lead, true);

            return [
                'dias'               => $resumen['dias'],
                'dia_actual'         => $resumen['dia_actual'],
                'semilla_presente'   => $resumen['semilla_presente'],
                'hitos_fuente'       => $resumen['hitos_fuente'],
                'proximo_hito'       => $resumen['proximo_hito'],
                'proximo_hito_label' => $resumen['proximo_hito_label'],
                'bienvenida_enviada' => $resumen['bienvenida_enviada'],
                'bienvenida_en_cola' => $resumen['bienvenida_en_cola'],
                'ultimo_empujon_at'  => $resumen['ultimo_empujon_at'],
            ];
        } catch (\Throwable $e) {
            Log::channel('daily')->warning('SupportPruebaContextService: no se pudo armar el seguimiento de la prueba.', [
                'client_id' => (int) $client->id,
                'error'     => $e->getMessage(),
            ]);

            return [
                'dias'               => null,
                'dia_actual'         => null,
                'semilla_presente'   => null,
                'hitos_fuente'       => null,
                'proximo_hito'       => null,
                'proximo_hito_label' => null,
                'bienvenida_enviada' => null,
                'bienvenida_en_cola' => null,
                'ultimo_empujon_at'  => null,
                'error'              => 'No se pudo leer el seguimiento de la prueba en esta consulta (los días y la URL siguen valiendo).',
            ];
        }
    }
}

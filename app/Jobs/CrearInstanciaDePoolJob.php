<?php

namespace App\Jobs;

use App\Models\TrialInstance;
use App\Services\Prueba\PruebaPoolService;
use App\Services\Prueba\PruebaDnsService;
use App\Services\Prueba\PruebaEnvService;
use App\Services\Prueba\PruebaVpsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Arma una instancia del pool en el VPS (decisiones D1 y D3 del plan de `prueba-alta-instancia`,
 * 3/11/2026), en segundo plano y por etapas:
 *
 *   dns (10 %)        → los 4 A records de `prueba-NN` (para el certificado del pool, que es el que usa el
 *                       demo setup antes de que el alias tenga el suyo).
 *   crear (20 %)      → `pool-crear.sh`: 4 sitios de CloudPanel PHP 8.4, base, copia de las plantillas,
 *                       marcador `vps-php84-v1`, vhosts de una capa, pool de FPM, supervisor. Devuelve la
 *                       contraseña de la base UNA vez.
 *   env (50 %)        → los dos `.env` (ERP y tienda) desde `env_templates` y los dos `config.js`, escritos
 *                       por el admin (`PruebaEnvService` + `PruebaVpsService::escribir_archivo()`).
 *   configurar (70 %) → `pool-configurar.sh`: `package:discover`, `storage`, `version.json` de la tienda, tokens
 *                       del `index.html` de la tienda, reinicios y SSL de los 4 hosts del pool.
 *   libre (100 %).
 *
 * Una falla deja la fila en `fallida` con el error; se reintenta desde el panel (`pruebas:mantener-pool`
 * no la cuenta como libre y arma otra). Todas las etapas son idempotentes del lado del VPS.
 *
 * Techo de una hora: `composer` ya corrió al armar la plantilla, pero el SSL de cuatro hosts puede esperar
 * el DNS hasta 3 minutos cada uno.
 */
class CrearInstanciaDePoolJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    const ETAPAS = ['dns', 'crear', 'env', 'configurar', 'libre'];

    const PROGRESO = ['dns' => 10, 'crear' => 20, 'env' => 50, 'configurar' => 70, 'libre' => 100];

    public $tries = 1;

    public $timeout = 3600;

    /**
     * Conexión y cola propias de los jobs de la prueba (4/11/2026).
     *
     * 🔴 Por qué no `default`. Estos jobs duran de 25 a 60 minutos y el admin tiene UN worker en `default`
     * (`api_admin_queue`, numprocs 1): mientras armaban una instancia dejaban sin atender todo lo demás
     * (el WhatsApp de Martín, avisos de soporte, demo setups). Por eso salen por la conexión `database_prueba`
     * —misma tabla `jobs`, `retry_after` de 4200 s— y los atienden sus propios programas de supervisor. Dos colas,
     * un worker cada una: `prueba-pool` (este job, el pool que se arma de a poco) y `prueba` (`ArmarPruebaJob`, el
     * alta de un prospecto real, que no puede esperar detrás de un pool de 60 min). Programas
     * `api_admin_queue_prueba_pool` y `api_admin_queue_prueba`.
     *
     * Y no `database`, porque su `retry_after` es 2400 (`config/queue.php`, fijado por los deployments) y este job
     * dura hasta 3600: pasados los 40 minutos la cola lo daría por perdido aunque siga vivo. El worker de `default`
     * NO mira esta cola; si el programa de supervisor no está, el pool no se llena (y nada más se afecta).
     */
    const CONEXION_DE_COLA = 'database_prueba';

    /** Cola del pool pre-armado: jobs de hasta 60 min que no pueden hacer esperar el alta de un prospecto. */
    const COLA = 'prueba-pool';

    /** @var int */
    public $trial_instance_id;

    public function __construct(int $trial_instance_id)
    {
        $this->trial_instance_id = $trial_instance_id;

        /* En el constructor y no en cada ::dispatch(): hay cuatro sitios que lo despachan y así ninguno se olvida. */
        $this->onConnection(self::CONEXION_DE_COLA)->onQueue(self::COLA);
    }

    public function handle(): void
    {
        $instancia = TrialInstance::find($this->trial_instance_id);
        if ($instancia === null) {
            return;
        }

        if (! in_array($instancia->estado, [TrialInstance::ESTADO_CREANDO, TrialInstance::ESTADO_FALLIDA], true) || $instancia->client_id) {
            Log::info('CrearInstanciaDePoolJob: la instancia no está para armar.', ['instancia' => $instancia->nombre, 'estado' => $instancia->estado]);

            return;
        }

        $instancia->estado = TrialInstance::ESTADO_CREANDO;
        $instancia->error  = null;
        $instancia->save();

        /* Un reintento después de un fallo en `dns` vuelve sin bloque (se liberó): se reserva uno de nuevo. */
        app(PruebaPoolService::class)->asegurar_bloque($instancia);

        try {
            $this->marcar($instancia, 'dns');
            app(PruebaDnsService::class)->crear_registros($instancia->nombre, $instancia);

            $this->marcar($instancia, 'crear');
            $resultado = app(PruebaVpsService::class)->pool_crear(
                $instancia->nombre,
                (string) $instancia->version,
                (string) $instancia->ecommerce_version,
                (int) $instancia->user_id
            );
            $db = $this->base_del_resultado($resultado);

            $this->marcar($instancia, 'env');
            $this->escribir_configuracion($instancia, $db);

            $this->marcar($instancia, 'configurar');
            app(PruebaVpsService::class)->pool_configurar($instancia->nombre);

            $this->marcar($instancia, 'libre');
            $instancia->estado = TrialInstance::ESTADO_LIBRE;
            $instancia->save();

            Log::info('CrearInstanciaDePoolJob: instancia libre.', ['instancia' => $instancia->nombre, 'version' => $instancia->version]);
        } catch (\Throwable $e) {
            $instancia->estado = TrialInstance::ESTADO_FALLIDA;
            $instancia->error  = mb_substr($e->getMessage(), 0, 4000);
            $instancia->save();

            if ((string) $instancia->etapa === 'dns') {
                /* Falló antes de tocar el VPS: el bloque de user_id vuelve a estar disponible (chequeo C1). */
                app(PruebaPoolService::class)->liberar_bloque($instancia);
            }

            Log::error('CrearInstanciaDePoolJob: falló.', ['instancia' => $instancia->nombre, 'etapa' => $instancia->etapa, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Los dos `.env` y los dos `config.js`, a nombre del usuario de cada sitio.
     *
     * @param array<string, string> $db
     */
    private function escribir_configuracion(TrialInstance $instancia, array $db): void
    {
        $env = app(PruebaEnvService::class);
        $vps = app(PruebaVpsService::class);

        $app_key     = PruebaEnvService::generar_app_key();
        $vars_erp    = $env->variables_erp($instancia, $db, $app_key);
        $vars_tienda = $env->variables_tienda($instancia, $db, $vars_erp);
        $secretos    = [(string) $db['pass'], $app_key, (string) $vars_erp['ADMIN_API_INBOUND_KEY'], (string) ($vars_erp['DEEPSEEK_API_KEY'] ?? '')];

        $vps->escribir_archivo($instancia->ruta_erp() . '/.env', $env->contenido_env($vars_erp), 'api-' . $instancia->erp_vps_path, $secretos);
        $vps->escribir_archivo($instancia->ruta_tienda() . '/.env', $env->contenido_env($vars_tienda), 'api-' . $instancia->ecommerce_vps_path, $secretos);
        $vps->escribir_archivo($instancia->docroot_spa_erp() . '/config.js', $env->config_js_erp($instancia), $instancia->erp_vps_path);
        $vps->escribir_archivo($instancia->docroot_spa_tienda() . '/config.js', $env->config_js_tienda($instancia), $instancia->ecommerce_vps_path);
    }

    /**
     * @param array<string, mixed> $resultado JSON de `pool-crear.sh`.
     *
     * @return array<string, string> `['name', 'user', 'pass']`
     */
    private function base_del_resultado(array $resultado): array
    {
        $db = isset($resultado['db']) && is_array($resultado['db']) ? $resultado['db'] : [];

        foreach (['name', 'user', 'pass'] as $clave) {
            if (! isset($db[$clave]) || trim((string) $db[$clave]) === '') {
                throw new \RuntimeException('pool-crear.sh no devolvió db.' . $clave . '.');
            }
        }

        return ['name' => (string) $db['name'], 'user' => (string) $db['user'], 'pass' => (string) $db['pass']];
    }

    private function marcar(TrialInstance $instancia, string $etapa): void
    {
        $instancia->etapa    = $etapa;
        $instancia->progreso = self::PROGRESO[$etapa];
        $instancia->save();
    }
}

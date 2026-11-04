<?php

namespace App\Jobs;

use App\Models\Client;
use App\Models\Lead;
use App\Models\TrialInstance;
use App\Services\Prueba\PruebaDemoSetupService;
use App\Services\Prueba\PruebaDnsService;
use App\Services\Prueba\PruebaEnvService;
use App\Services\Prueba\PruebaVpsService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Asigna una instancia del pool a un negocio que acaba de pedir su prueba (decisión D1 del plan de
 * `prueba-alta-instancia`, 3/11/2026): lo lento del alta, en segundo plano y por etapas que la landing
 * lee por C6.
 *
 *   dns (10 %)        → los 4 A records del slug (`PruebaDnsService`, TTL 300).
 *   env (25 %)        → las claves del cliente en el `.env` del ERP (`ADMIN_API_*`, `FOR_USER=prueba`).
 *   alias (40 %)      → `prueba-asignar.sh --sin-ssl`: `server_name` del slug en los 4 vhosts, URLs del slug
 *                       en los `.env` y en los `config.js`, `USER_ID`, prefijos, reinicios.
 *   demo_setup (55 %) → C1: el demo setup con `password`, `prueba` y `rubro`, al host del pool (~80 s).
 *   ssl (90 %)        → `prueba-ssl.sh`: los 4 certificados del slug (el DNS ya propagó mientras corría
 *                       el demo setup).
 *   lista (100 %)     → la instancia queda `asignada`, el lead con `demo_setup_status = exitoso`, la
 *                       contraseña cifrada se borra.
 *
 * Una falla deja la instancia en `fallida` con la etapa y el error; la landing muestra `fallida` y el
 * panel del pool tiene «Reintentar», que vuelve a despachar este job y arranca desde la etapa que falló
 * (todas son idempotentes; el demo setup se puede repetir mientras la prueba no esté lista).
 *
 * Techo de 25 minutos: DNS (hasta 3 min de espera por host en el SSL) + demo setup (hasta 15 min).
 */
class ArmarPruebaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    const ETAPAS = ['dns', 'env', 'alias', 'demo_setup', 'ssl', 'lista'];

    const PROGRESO = ['dns' => 10, 'env' => 25, 'alias' => 40, 'demo_setup' => 55, 'ssl' => 90, 'lista' => 100];

    public $tries = 1;

    public $timeout = 1500;

    /** @var int */
    public $trial_instance_id;

    public function __construct(int $trial_instance_id)
    {
        $this->trial_instance_id = $trial_instance_id;

        /* Misma conexión y cola que el job del pool (ver CrearInstanciaDePoolJob::CONEXION_DE_COLA). */
        $this->onConnection(CrearInstanciaDePoolJob::CONEXION_DE_COLA)->onQueue(CrearInstanciaDePoolJob::COLA);
    }

    public function handle(): void
    {
        $instancia = TrialInstance::find($this->trial_instance_id);
        if ($instancia === null) {
            Log::warning('ArmarPruebaJob: la instancia no existe.', ['id' => $this->trial_instance_id]);

            return;
        }

        if (! in_array($instancia->estado, [TrialInstance::ESTADO_ASIGNANDO, TrialInstance::ESTADO_FALLIDA], true)) {
            Log::info('ArmarPruebaJob: la instancia no está para armar.', ['instancia' => $instancia->nombre, 'estado' => $instancia->estado]);

            return;
        }

        $lead   = $instancia->lead_id ? Lead::find($instancia->lead_id) : null;
        $client = $instancia->client_id ? Client::find($instancia->client_id) : null;

        if ($lead === null || $client === null || trim((string) $instancia->alias_slug) === '') {
            $this->fallar($instancia, 'La instancia no tiene lead, cliente o slug: no se puede armar.');

            return;
        }

        $desde = $this->etapa_desde_la_que_arrancar($instancia);
        $instancia->estado = TrialInstance::ESTADO_ASIGNANDO;
        $instancia->error  = null;
        $instancia->save();

        try {
            foreach (self::ETAPAS as $etapa) {
                if (array_search($etapa, self::ETAPAS, true) < array_search($desde, self::ETAPAS, true)) {
                    continue;
                }
                $this->marcar($instancia, $etapa);
                $this->correr_etapa($etapa, $instancia, $lead, $client);
            }
        } catch (\Throwable $e) {
            $this->fallar($instancia, $e->getMessage());
        }
    }

    private function correr_etapa(string $etapa, TrialInstance $instancia, Lead $lead, Client $client): void
    {
        $slug = (string) $instancia->alias_slug;

        switch ($etapa) {
            case 'dns':
                app(PruebaDnsService::class)->crear_registros($slug, $instancia);
                break;

            case 'env':
                $vars = app(PruebaEnvService::class)->variables_del_cliente($client);
                app(PruebaVpsService::class)->escribir_variables_env(
                    $instancia->ruta_erp() . '/.env',
                    $vars,
                    [$vars['ADMIN_API_INBOUND_KEY'], $vars['ADMIN_API_OUTBOUND_KEY']]
                );
                break;

            case 'alias':
                app(PruebaVpsService::class)->prueba_asignar($instancia->nombre, $slug, (int) $instancia->user_id, false);
                break;

            case 'demo_setup':
                $this->demo_setup($instancia, $lead, $client);
                break;

            case 'ssl':
                app(PruebaVpsService::class)->prueba_ssl($instancia->nombre, $slug);
                break;

            case 'lista':
                $this->terminar($instancia, $lead);
                break;
        }
    }

    private function demo_setup(TrialInstance $instancia, Lead $lead, Client $client): void
    {
        $cifrada = (string) $instancia->password_cifrada;
        if ($cifrada === '') {
            throw new \RuntimeException('La instancia ya no tiene la contraseña del dueño: no se puede volver a correr el demo setup. Pedile al negocio que vuelva a cargar la prueba.');
        }

        Lead::query()->whereKey($lead->id)->update(['demo_setup_status' => 'ejecutandose', 'demo_setup_last_run_at' => now(), 'demo_setup_last_error' => null]);

        $password  = Crypt::decryptString($cifrada);
        $resultado = app(PruebaDemoSetupService::class)->disparar($instancia, $lead, $client, $password);
        $password  = null;

        if ($resultado['resultado'] === PruebaDemoSetupService::RESULTADO_OK) {
            $instancia->progreso = 85;
            $instancia->save();

            return;
        }

        if ($resultado['resultado'] === PruebaDemoSetupService::RESULTADO_SIN_CONFIRMAR) {
            /* El ERP no contestó a tiempo pero la corrida puede seguir viva: se sigue con el SSL y se deja la
               marca en el lead. El evento `demo.setup.completado` (si llega) lo pone en exitoso. */
            Lead::query()->whereKey($lead->id)->update(['demo_setup_status' => 'sin_confirmar', 'demo_setup_last_error' => $resultado['mensaje']]);
            Log::warning('ArmarPruebaJob: demo setup sin confirmar; se sigue.', ['instancia' => $instancia->nombre, 'mensaje' => $resultado['mensaje']]);
            $instancia->progreso = 85;
            $instancia->save();

            return;
        }

        Lead::query()->whereKey($lead->id)->update(['demo_setup_status' => 'fallido', 'demo_setup_last_error' => $resultado['mensaje']]);

        throw new \RuntimeException('El demo setup falló: ' . $resultado['mensaje']);
    }

    private function terminar(TrialInstance $instancia, Lead $lead): void
    {
        $instancia->estado           = TrialInstance::ESTADO_ASIGNADA;
        $instancia->etapa            = 'lista';
        $instancia->progreso         = 100;
        $instancia->error            = null;
        $instancia->lista_at         = Carbon::now();
        $instancia->password_cifrada = null;
        $instancia->save();

        $lead_fresco = $lead->fresh();
        if ($lead_fresco !== null && (string) $lead_fresco->demo_setup_status === 'ejecutandose') {
            Lead::query()->whereKey($lead->id)->update(['demo_setup_status' => 'exitoso', 'demo_setup_last_error' => null]);
        }

        Log::info('ArmarPruebaJob: prueba lista.', ['instancia' => $instancia->nombre, 'slug' => $instancia->alias_slug, 'lead_id' => $lead->id]);
    }

    private function marcar(TrialInstance $instancia, string $etapa): void
    {
        $instancia->etapa    = $etapa;
        $instancia->progreso = self::PROGRESO[$etapa];
        $instancia->save();
    }

    private function fallar(TrialInstance $instancia, string $error): void
    {
        $instancia->estado = TrialInstance::ESTADO_FALLIDA;
        $instancia->error  = mb_substr($error, 0, 4000);
        $instancia->save();

        Log::error('ArmarPruebaJob: falló.', ['instancia' => $instancia->nombre, 'etapa' => $instancia->etapa, 'error' => $error]);
    }

    /** Un reintento arranca desde la etapa que falló; un armado nuevo, desde el DNS. */
    private function etapa_desde_la_que_arrancar(TrialInstance $instancia): string
    {
        if ($instancia->estado === TrialInstance::ESTADO_FALLIDA && in_array((string) $instancia->etapa, self::ETAPAS, true)) {
            return (string) $instancia->etapa;
        }

        return 'dns';
    }
}

<?php

namespace App\Jobs;

use App\Models\Client;
use App\Models\Lead;
use App\Models\TrialInstance;
use App\Services\Prueba\PruebaDemoSetupService;
use App\Services\Prueba\PruebaDnsService;
use App\Services\Prueba\PruebaEnvService;
use App\Services\Prueba\PruebaPoolService;
use App\Services\Prueba\PruebaVpsService;
use App\Services\PruebaWhatsappService;
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

    /** Cola del alta de un prospecto (el pool va por `prueba-pool`). */
    const COLA = 'prueba';

    /** @var int */
    public $trial_instance_id;

    /**
     * Etapa desde la que arranca un job re-encolado por una espera (G2) o un reintento del demo setup (G3). Null en un
     * despacho normal (el alta o «Reintentar» del panel): arranca como siempre.
     *
     * Las esperas y los reintentos son jobs NUEVOS con demora y no `release()`: con `$tries = 1` un `release()` cuenta
     * como intento y el worker daría el job por fallido (MaxAttemptsExceeded) al tomarlo de nuevo. Subir `$tries` haría
     * además que un worker muerto a mitad del armado lo vuelva a correr solo, cosa que hoy no pasa.
     *
     * @var string|null
     */
    public $desde_etapa = null;

    /** @var int Cuántas veces ya se esperó a que termine una creación del pool antes del demo setup (G2). */
    public $esperas_por_creacion = 0;

    /** @var int Cuántos cortes de infraestructura (HTTP 502/503/504) ya tuvo el demo setup de este armado (G3). */
    public $cortes_demo_setup = 0;

    public function __construct(int $trial_instance_id)
    {
        $this->trial_instance_id = $trial_instance_id;

        /* Misma conexión que el job del pool, pero cola propia: un prospecto real no espera detrás de un pool de 60 min
           (ver CrearInstanciaDePoolJob::CONEXION_DE_COLA). */
        $this->onConnection(CrearInstanciaDePoolJob::CONEXION_DE_COLA)->onQueue(self::COLA);
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
                if (! $this->correr_etapa($etapa, $instancia, $lead, $client)) {
                    /* Se re-encoló (G2 o G3): la instancia sigue `asignando` en esta etapa y la landing sigue en
                       «Cargando datos de ejemplo de tu rubro», sin error. */
                    return;
                }
            }
        } catch (\Throwable $e) {
            $this->fallar($instancia, $e->getMessage());
        }
    }

    /** @return bool false si el job se re-encoló y el armado sigue en otro job. */
    private function correr_etapa(string $etapa, TrialInstance $instancia, Lead $lead, Client $client): bool
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
                return $this->demo_setup($instancia, $lead, $client);

            case 'ssl':
                app(PruebaVpsService::class)->prueba_ssl($instancia->nombre, $slug);
                break;

            case 'lista':
                $this->terminar($instancia, $lead, $client);
                break;
        }

        return true;
    }

    /** @return bool false si se re-encoló (G2: espera una creación del pool; G3: reintento tras un corte). */
    private function demo_setup(TrialInstance $instancia, Lead $lead, Client $client): bool
    {
        $cifrada = (string) $instancia->password_cifrada;
        if ($cifrada === '') {
            throw new \RuntimeException('La instancia ya no tiene la contraseña del dueño: no se puede volver a correr el demo setup. Pedile al negocio que vuelva a cargar la prueba.');
        }

        if ($this->esperar_si_hay_una_creacion($instancia)) {
            return false;
        }

        Lead::query()->whereKey($lead->id)->update(['demo_setup_status' => 'ejecutandose', 'demo_setup_last_run_at' => now(), 'demo_setup_last_error' => null]);

        $password  = Crypt::decryptString($cifrada);
        $resultado = app(PruebaDemoSetupService::class)->disparar($instancia, $lead, $client, $password);
        $password  = null;

        if ($resultado['resultado'] === PruebaDemoSetupService::RESULTADO_OK) {
            $instancia->progreso = 85;
            $instancia->save();

            return true;
        }

        if ($resultado['resultado'] === PruebaDemoSetupService::RESULTADO_SIN_CONFIRMAR) {
            /* El ERP no contestó a tiempo pero la corrida puede seguir viva: se sigue con el SSL y se deja la
               marca en el lead. El evento `demo.setup.completado` (si llega) lo pone en exitoso. */
            Lead::query()->whereKey($lead->id)->update(['demo_setup_status' => 'sin_confirmar', 'demo_setup_last_error' => $resultado['mensaje']]);
            Log::warning('ArmarPruebaJob: demo setup sin confirmar; se sigue.', ['instancia' => $instancia->nombre, 'mensaje' => $resultado['mensaje']]);
            $instancia->progreso = 85;
            $instancia->save();

            return true;
        }

        $mensaje = (string) $resultado['mensaje'];
        $prefijo = 'El demo setup falló: ';
        if (PruebaDemoSetupService::es_corte_de_infraestructura($resultado)) {
            $reintentos = max(0, (int) config('services.prueba.demo_setup_reintentos', 2));
            if ($this->cortes_demo_setup < $reintentos) {
                $this->reintentar_demo_setup($instancia, $lead, $mensaje);

                return false;
            }
            if ($this->cortes_demo_setup > 0) {
                $mensaje = 'Después de ' . ($this->cortes_demo_setup + 1) . ' intentos, todos cortados por el servidor (HTTP 502/503/504). Último: ' . $mensaje;
                $prefijo = 'El demo setup falló. ';
            }
        }

        Lead::query()->whereKey($lead->id)->update(['demo_setup_status' => 'fallido', 'demo_setup_last_error' => mb_substr($mensaje, 0, 4000)]);

        throw new \RuntimeException($prefijo . $mensaje);
    }

    /**
     * G3 (15/11/2026): el demo setup volvió con un corte de infraestructura (HTTP 502/503/504; medido el 15/11 02:51: una
     * recarga de php8.4-fpm de la reposición del pool le cortó el PHP). Volver a correrlo desde cero es seguro: arranca
     * con `migrate:fresh` y su candado es un `flock` que el sistema operativo suelta al morir el proceso. Si la corrida
     * anterior siguiera viva, el reintento recibe 409 y queda `sin_confirmar`, como hoy.
     *
     * Se encola un job nuevo con espera creciente (20 s y 40 s por default) que arranca en `demo_setup`. La instancia
     * sigue `asignando` en `demo_setup` y el lead `ejecutandose`, con el corte anotado en `demo_setup_last_error`.
     */
    private function reintentar_demo_setup(TrialInstance $instancia, Lead $lead, string $mensaje): void
    {
        $base     = max(1, (int) config('services.prueba.demo_setup_reintento_espera_segundos', 20));
        $segundos = $base * (int) pow(2, $this->cortes_demo_setup);
        $intento  = $this->cortes_demo_setup + 1;

        Lead::query()->whereKey($lead->id)->update([
            'demo_setup_last_error' => mb_substr('Corte en el intento ' . $intento . ' (' . $mensaje . '); se reintenta en ' . $segundos . ' s.', 0, 2000),
        ]);
        $instancia->touch();

        Log::warning('ArmarPruebaJob: el demo setup volvió con un corte de infraestructura; se reintenta.', [
            'instancia' => $instancia->nombre,
            'intento'   => $intento,
            'segundos'  => $segundos,
            'mensaje'   => mb_substr($mensaje, 0, 300),
        ]);

        $siguiente                    = $this->siguiente();
        $siguiente->cortes_demo_setup = $intento;
        dispatch($siguiente->delay(now()->addSeconds($segundos)));
    }

    /**
     * G2 (15/11/2026): si hay una instancia del pool creándose en el VPS (sus scripts recargan php8.4-fpm y cortan el
     * demo setup), este armado se re-encola con una espera corta hasta un tope total; después dispara igual. La
     * instancia sigue `asignando` en `demo_setup` y la landing sigue mostrando «Cargando datos de ejemplo de tu rubro».
     *
     * @return bool true si se re-encoló.
     */
    private function esperar_si_hay_una_creacion(TrialInstance $instancia): bool
    {
        $creacion = PruebaPoolService::creacion_en_curso();
        if ($creacion === null) {
            return false;
        }

        $segundos = max(1, (int) config('services.prueba.armar_espera_creacion_segundos', 20));
        $tope     = (int) floor(max(0, (int) config('services.prueba.armar_espera_creacion_max_segundos', 180)) / $segundos);

        if ($this->esperas_por_creacion >= $tope) {
            Log::warning('ArmarPruebaJob: se agotó la espera por una creación del pool en curso; se dispara el demo setup igual.', ['instancia' => $instancia->nombre, 'creacion' => $creacion->nombre, 'esperas' => $this->esperas_por_creacion]);

            return false;
        }

        if ($this->esperas_por_creacion === 0) {
            Log::info('ArmarPruebaJob: hay una creación del pool en curso; el demo setup espera.', ['instancia' => $instancia->nombre, 'creacion' => $creacion->nombre, 'etapa' => $creacion->etapa, 'segundos' => $segundos]);
        }

        /* Se toca la fila: el armado se sigue «moviendo» para G1 y la reposición no arranca mientras se espera. */
        $instancia->touch();

        $siguiente                       = $this->siguiente();
        $siguiente->esperas_por_creacion = $this->esperas_por_creacion + 1;
        dispatch($siguiente->delay(now()->addSeconds($segundos)));

        return true;
    }

    /** El job que sigue este armado desde `demo_setup`, con los contadores de este. */
    private function siguiente(): self
    {
        $siguiente                       = new self($this->trial_instance_id);
        $siguiente->desde_etapa          = 'demo_setup';
        $siguiente->esperas_por_creacion = $this->esperas_por_creacion;
        $siguiente->cortes_demo_setup    = $this->cortes_demo_setup;

        return $siguiente;
    }

    private function terminar(TrialInstance $instancia, Lead $lead, Client $client): void
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
            $lead_fresco->update(['demo_setup_status' => 'exitoso', 'demo_setup_last_error' => null]);
        }

        Log::info('ArmarPruebaJob: prueba lista.', ['instancia' => $instancia->nombre, 'slug' => $instancia->alias_slug, 'lead_id' => $lead->id]);

        if ($lead_fresco !== null && (string) $lead_fresco->demo_setup_status === 'exitoso') {
            try {
                app(PruebaWhatsappService::class)->enviar_bienvenida($lead_fresco, $client);
            } catch (\Throwable $e) {
                // La instancia está lista. El barrido habitual retoma la bienvenida si falló el envío.
                Log::warning('ArmarPruebaJob: no se pudo iniciar la bienvenida por WhatsApp.', ['lead_id' => $lead->id]);
            }
        }
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

    /**
     * Un reintento arranca desde la etapa que falló; un job re-encolado por G2/G3, desde la suya (si la instancia sigue
     * ahí); un armado nuevo, desde el DNS.
     */
    private function etapa_desde_la_que_arrancar(TrialInstance $instancia): string
    {
        if ($this->desde_etapa !== null && in_array($this->desde_etapa, self::ETAPAS, true)
            && $instancia->estado === TrialInstance::ESTADO_ASIGNANDO && (string) $instancia->etapa === $this->desde_etapa) {
            return $this->desde_etapa;
        }

        if ($instancia->estado === TrialInstance::ESTADO_FALLIDA && in_array((string) $instancia->etapa, self::ETAPAS, true)) {
            return (string) $instancia->etapa;
        }

        return 'dns';
    }
}

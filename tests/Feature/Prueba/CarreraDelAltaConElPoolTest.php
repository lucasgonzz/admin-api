<?php

namespace Tests\Feature\Prueba;

use App\Jobs\ArmarPruebaJob;
use App\Jobs\CrearInstanciaDePoolJob;
use App\Models\Lead;
use App\Models\TrialInstance;
use App\Services\Prueba\PruebaPoolService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * La carrera entre el alta de una prueba y la reposición del pool (15/11/2026, medido en producción a las 02:50): crear
 * una instancia recarga php8.4-fpm y le corta el demo setup al alta en curso (HTTP 502 → prueba `fallida`).
 *
 *  G1. La reposición espera mientras se arma una prueba (en `mantener()` y al arrancar `CrearInstanciaDePoolJob`).
 *  G2. El armado espera antes del demo setup si hay una creación del pool en curso.
 *  G3. El demo setup se reintenta solo ante un corte de infraestructura (502/503/504).
 */
class CarreraDelAltaConElPoolTest extends BaseDePruebaGratuita
{
    const SLUG_HOSTS = ['prueba-test', 'api-prueba-test', 'tienda-prueba-test', 'api-tienda-prueba-test'];

    /** Un alta hecha: la instancia `asignando`, con lead, y el `ArmarPruebaJob` del alta encolado (la cola está falseada). */
    private function alta(): TrialInstance
    {
        $instancia = $this->crear_instancia_libre('prueba-01', 900100);
        $this->postear_alta()->assertStatus(201);

        return $instancia->fresh();
    }

    /** Una instancia del pool en creación, en la etapa dada (movida ahora). */
    private function creacion_en_etapa(string $etapa): TrialInstance
    {
        $instancia        = app(PruebaPoolService::class)->crear_fila('4.3.99', '1.9.99');
        $instancia->etapa = $etapa;
        $instancia->save();

        return $instancia;
    }

    /** @return \Illuminate\Support\Collection Los `ArmarPruebaJob` re-encolados por G2/G3 (no el del alta). */
    private function armados_reencolados()
    {
        return Queue::pushed(ArmarPruebaJob::class, function (ArmarPruebaJob $job) {
            return $job->desde_etapa !== null;
        })->values();
    }

    /** Segundos de demora con los que quedó encolado un job. */
    private function demora($job): int
    {
        $this->assertNotNull($job->delay, 'El job re-encolado lleva demora.');

        return (int) Carbon::now()->diffInSeconds($job->delay);
    }

    /* ---------------------------------------------------------------- G1 */

    public function test_g1_con_un_armado_en_curso_mantener_pool_no_encola_y_sin_armado_encola_como_hoy(): void
    {
        $instancia = $this->alta();
        $this->assertSame(TrialInstance::ESTADO_ASIGNANDO, $instancia->estado);

        $this->artisan('pruebas:mantener-pool')->assertExitCode(0);
        $this->assertSame([], app(PruebaPoolService::class)->mantener());

        Queue::assertNotPushed(CrearInstanciaDePoolJob::class);
        $this->assertSame(0, TrialInstance::where('estado', TrialInstance::ESTADO_CREANDO)->count(), 'No se creó ninguna fila del pool.');

        /* El armado terminó (lista): la reposición arranca como siempre. */
        $instancia->estado = TrialInstance::ESTADO_ASIGNADA;
        $instancia->etapa  = 'lista';
        $instancia->save();

        $this->artisan('pruebas:mantener-pool')->assertExitCode(0);
        Queue::assertPushed(CrearInstanciaDePoolJob::class, 2);
    }

    public function test_g1_un_armado_que_fallo_no_frena_la_reposicion(): void
    {
        $instancia         = $this->alta();
        $instancia->estado = TrialInstance::ESTADO_FALLIDA;
        $instancia->save();

        $this->artisan('pruebas:mantener-pool')->assertExitCode(0);

        Queue::assertPushed(CrearInstanciaDePoolJob::class, 2);
    }

    public function test_g1_un_armado_colgado_fuera_de_la_ventana_no_frena_la_reposicion(): void
    {
        $instancia = $this->alta();
        DB::table('trial_instances')->where('id', $instancia->id)->update(['updated_at' => Carbon::now()->subMinutes(16)]);

        $this->artisan('pruebas:mantener-pool')->assertExitCode(0);

        Queue::assertPushed(CrearInstanciaDePoolJob::class, 2);
    }

    public function test_g1_el_job_de_creacion_que_arranca_con_un_armado_en_curso_se_reencola_sin_tocar_nada(): void
    {
        $this->alta();
        $creacion = app(PruebaPoolService::class)->crear_fila('4.3.99', '1.9.99');

        (new CrearInstanciaDePoolJob($creacion->id))->handle();

        $creacion->refresh();
        $this->assertSame(TrialInstance::ESTADO_CREANDO, $creacion->estado);
        $this->assertSame('encolada', $creacion->etapa, 'No arrancó ninguna etapa.');
        $this->assertSame([], $this->vps->comandos, 'Nada tocó el VPS.');
        $this->assertSame([], $this->hostinger->llamadas, 'Nada tocó el DNS.');

        $reencolados = Queue::pushed(CrearInstanciaDePoolJob::class)->values();
        $this->assertCount(1, $reencolados);
        $this->assertSame($creacion->id, $reencolados[0]->trial_instance_id);
        $this->assertSame(1, $reencolados[0]->esperas_por_armado);
        $this->assertSame(30, $this->demora($reencolados[0]));
        $this->assertSame('prueba-pool', $reencolados[0]->queue, 'Sigue en su cola.');
        $this->assertSame('database_prueba', $reencolados[0]->connection);
    }

    public function test_g1_con_el_tope_de_esperas_agotado_la_creacion_arranca_igual(): void
    {
        $this->alta();
        $creacion = app(PruebaPoolService::class)->crear_fila('4.3.99', '1.9.99');
        $this->zona_antes_y_despues(['prueba-02', 'api-prueba-02', 'tienda-prueba-02', 'api-tienda-prueba-02']);
        $this->vps->responder('pool-crear.sh', ['ok' => true, 'nombre' => $creacion->nombre, 'db' => ['name' => 'p', 'user' => 'p', 'pass' => 'Clave-De-Prueba-02'], 'hosts' => []]);

        $job                     = new CrearInstanciaDePoolJob($creacion->id);
        $job->esperas_por_armado = 30;
        $job->handle();

        Queue::assertNotPushed(CrearInstanciaDePoolJob::class);
        $this->assertContains('pool-crear.sh', $this->vps->scripts_corridos());
    }

    /* ---------------------------------------------------------------- G2 */

    public function test_g2_el_armado_espera_a_la_creacion_en_curso_y_despues_dispara(): void
    {
        $instancia = $this->alta();
        $lead      = Lead::find($instancia->lead_id);
        $this->zona_antes_y_despues(self::SLUG_HOSTS);
        $creacion = $this->creacion_en_etapa('configurar');

        (new ArmarPruebaJob($instancia->id))->handle();

        $instancia->refresh();
        $this->assertSame(TrialInstance::ESTADO_ASIGNANDO, $instancia->estado, (string) $instancia->error);
        $this->assertSame('demo_setup', $instancia->etapa);
        $this->assertNull($instancia->error);
        $this->assertSame([], $this->pedidos_al_erp_con('admin-sync/demo-setup'), 'No disparó el demo setup.');
        $this->assertNotSame('fallido', (string) Lead::find($lead->id)->demo_setup_status);

        $reencolados = $this->armados_reencolados();
        $this->assertCount(1, $reencolados);
        $this->assertSame('demo_setup', $reencolados[0]->desde_etapa);
        $this->assertSame(1, $reencolados[0]->esperas_por_creacion);
        $this->assertSame(20, $this->demora($reencolados[0]));
        $this->assertSame('prueba', $reencolados[0]->queue);

        /* La pantalla de seguimiento sigue en «Cargando datos…», sin error. */
        $this->getJson('/api/landing/prueba/' . $instancia->seguimiento_uuid . '/estado')
            ->assertJson(['estado' => 'creando', 'etapa' => 'Cargando datos de ejemplo de tu rubro', 'mensaje' => null]);

        /* Mientras tanto la reposición tampoco arranca (G1 ve el armado moverse). */
        $this->assertNotNull(PruebaPoolService::armado_en_curso());

        /* La creación terminó: el job re-encolado dispara, sin rehacer DNS ni alias. */
        $creacion->estado = TrialInstance::ESTADO_LIBRE;
        $creacion->etapa  = 'libre';
        $creacion->save();
        $this->vps->comandos = [];

        $reencolados[0]->handle();

        $instancia->refresh();
        $this->assertSame(TrialInstance::ESTADO_ASIGNADA, $instancia->estado, (string) $instancia->error);
        $this->assertCount(1, $this->pedidos_al_erp_con('admin-sync/demo-setup'));
        $this->assertSame(['prueba-ssl.sh'], $this->vps->scripts_corridos());
        $this->assertSame('exitoso', Lead::find($lead->id)->demo_setup_status);
    }

    public function test_g2_una_creacion_encolada_que_todavia_no_arranco_no_hace_esperar(): void
    {
        $instancia = $this->alta();
        $this->zona_antes_y_despues(self::SLUG_HOSTS);
        $this->creacion_en_etapa('encolada');

        (new ArmarPruebaJob($instancia->id))->handle();

        $this->assertSame(TrialInstance::ESTADO_ASIGNADA, $instancia->fresh()->estado);
        $this->assertCount(0, $this->armados_reencolados());
    }

    public function test_g2_con_el_tope_de_espera_agotado_dispara_igual(): void
    {
        $instancia = $this->alta();
        $this->zona_antes_y_despues(self::SLUG_HOSTS);
        $this->creacion_en_etapa('crear');

        $job                       = new ArmarPruebaJob($instancia->id);
        $job->esperas_por_creacion = 9; /* 9 x 20 s = 180 s, el tope por default. */
        $job->handle();

        $this->assertSame(TrialInstance::ESTADO_ASIGNADA, $instancia->fresh()->estado);
        $this->assertCount(1, $this->pedidos_al_erp_con('admin-sync/demo-setup'));
        $this->assertCount(0, $this->armados_reencolados());
    }

    /* ---------------------------------------------------------------- G3 */

    /** El ERP contesta en orden las respuestas dadas (la última se repite). */
    private function erp_en_secuencia(array $respuestas): void
    {
        $this->erp['admin-sync/demo-setup'] = function () use (&$respuestas) {
            $respuesta = count($respuestas) > 1 ? array_shift($respuestas) : $respuestas[0];

            return $respuesta;
        };
    }

    public function test_g3_un_502_seguido_de_un_200_termina_ok_con_dos_llamadas(): void
    {
        $instancia = $this->alta();
        $lead      = Lead::find($instancia->lead_id);
        $this->zona_antes_y_despues(self::SLUG_HOSTS);
        $this->erp_en_secuencia([Http::response('<html>502 Bad Gateway</html>', 502), Http::response(['ok' => true], 200)]);

        (new ArmarPruebaJob($instancia->id))->handle();

        $instancia->refresh();
        $this->assertSame(TrialInstance::ESTADO_ASIGNANDO, $instancia->estado, (string) $instancia->error);
        $this->assertSame('demo_setup', $instancia->etapa);
        $this->assertSame('ejecutandose', Lead::find($lead->id)->demo_setup_status, 'Un corte no es «fallido».');
        $this->assertStringContainsString('HTTP 502', (string) Lead::find($lead->id)->demo_setup_last_error);
        $this->getJson('/api/landing/prueba/' . $instancia->seguimiento_uuid . '/estado')
            ->assertJson(['estado' => 'creando', 'etapa' => 'Cargando datos de ejemplo de tu rubro', 'mensaje' => null]);

        $reencolados = $this->armados_reencolados();
        $this->assertCount(1, $reencolados);
        $this->assertSame(1, $reencolados[0]->cortes_demo_setup);
        $this->assertSame(20, $this->demora($reencolados[0]));

        $reencolados[0]->handle();

        $instancia->refresh();
        $this->assertSame(TrialInstance::ESTADO_ASIGNADA, $instancia->estado, (string) $instancia->error);
        $this->assertCount(2, $this->pedidos_al_erp_con('admin-sync/demo-setup'));
        $this->assertSame('exitoso', Lead::find($lead->id)->demo_setup_status);
        $this->assertSame(['prueba-asignar.sh', 'prueba-ssl.sh'], $this->vps->scripts_corridos(), 'El alias corrió una sola vez.');
    }

    public function test_g3_tres_cortes_seguidos_dejan_la_prueba_fallida_con_los_intentos_en_el_mensaje(): void
    {
        $instancia = $this->alta();
        $lead      = Lead::find($instancia->lead_id);
        $this->zona_antes_y_despues(self::SLUG_HOSTS);
        $this->erp_en_secuencia([Http::response('<html>502 Bad Gateway</html>', 502), Http::response('<html>503</html>', 503), Http::response('<html>504</html>', 504)]);

        (new ArmarPruebaJob($instancia->id))->handle();
        $primero = $this->armados_reencolados()->last();
        $this->assertSame(20, $this->demora($primero));

        $primero->handle();
        $this->assertCount(2, $this->armados_reencolados());
        $segundo = $this->armados_reencolados()->last();
        $this->assertSame(2, $segundo->cortes_demo_setup);
        $this->assertSame(40, $this->demora($segundo));

        $segundo->handle();

        $instancia->refresh();
        $this->assertCount(3, $this->pedidos_al_erp_con('admin-sync/demo-setup'));
        $this->assertCount(2, $this->armados_reencolados(), 'Agotados los reintentos no se encola otro.');
        $this->assertSame(TrialInstance::ESTADO_FALLIDA, $instancia->estado);
        $this->assertSame('demo_setup', $instancia->etapa);
        $this->assertStringContainsString('3 intentos', (string) $instancia->error);
        $this->assertStringContainsString('HTTP 504', (string) $instancia->error);
        $this->assertSame('fallido', Lead::find($lead->id)->demo_setup_status);
        $this->assertNotNull($instancia->password_cifrada, 'Se conserva para «Reintentar».');
        $this->getJson('/api/landing/prueba/' . $instancia->seguimiento_uuid . '/estado')->assertJson(['estado' => 'fallida']);
    }

    public function test_g3_un_500_falla_a_la_primera_sin_reintentar(): void
    {
        $instancia = $this->alta();
        $this->zona_antes_y_despues(self::SLUG_HOSTS);
        $this->erp['admin-sync/demo-setup'] = Http::response('Fatal error: no anda', 500);

        (new ArmarPruebaJob($instancia->id))->handle();

        $instancia->refresh();
        $this->assertSame(TrialInstance::ESTADO_FALLIDA, $instancia->estado);
        $this->assertStringContainsString('El demo setup falló: HTTP 500', (string) $instancia->error);
        $this->assertCount(1, $this->pedidos_al_erp_con('admin-sync/demo-setup'));
        $this->assertCount(0, $this->armados_reencolados());
    }

    public function test_g3_un_422_falla_a_la_primera_sin_reintentar(): void
    {
        $instancia = $this->alta();
        $this->zona_antes_y_despues(self::SLUG_HOSTS);
        $this->erp['admin-sync/demo-setup'] = Http::response(['error' => 'business_type is required'], 422);

        (new ArmarPruebaJob($instancia->id))->handle();

        $this->assertSame(TrialInstance::ESTADO_FALLIDA, $instancia->fresh()->estado);
        $this->assertCount(0, $this->armados_reencolados());
    }

    public function test_g3_un_409_despues_de_un_502_queda_sin_confirmar_y_sigue(): void
    {
        $instancia = $this->alta();
        $lead      = Lead::find($instancia->lead_id);
        $this->zona_antes_y_despues(self::SLUG_HOSTS);
        $this->erp_en_secuencia([
            Http::response('<html>502 Bad Gateway</html>', 502),
            Http::response(['error' => 'Ya hay un demo setup corriendo en esta instancia. Esperá a que termine.', 'en_curso' => true], 409),
        ]);

        (new ArmarPruebaJob($instancia->id))->handle();
        $this->armados_reencolados()->last()->handle();

        $instancia->refresh();
        $this->assertSame(TrialInstance::ESTADO_ASIGNADA, $instancia->estado, (string) $instancia->error);
        $this->assertSame('sin_confirmar', Lead::find($lead->id)->demo_setup_status);
        $this->assertCount(2, $this->pedidos_al_erp_con('admin-sync/demo-setup'));
        $this->assertCount(1, $this->armados_reencolados());
    }
}

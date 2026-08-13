<?php

namespace Tests\Feature;

use App\Helpers\AppTime;
use App\Jobs\RunDemoSetupJob;
use App\Models\AdminSetting;
use App\Models\Demo;
use App\Models\DemoMedia;
use App\Models\Lead;
use App\Services\LeadDemoSettings;
use App\Services\RunDemoSetupService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El camino crítico de la misión 46: cuándo se dispara el demo setup y cuándo se habilita el
 * ingreso a la demo.
 *
 * Los tres bugs que esta misión vino a arreglar tienen su test acá, y los tres son de la clase que
 * el gate diferencial del carril NO puede ver — no son regresiones, son cosas que nunca
 * funcionaron:
 *
 *  - el setup corría ANTES de que el lead completara el formulario, así que la instancia se armaba
 *    con los defaults del catálogo;
 *  - el lead que tardaba en completarlo dejaba de ser candidato apenas pasaba su hora, y no entraba
 *    nunca;
 *  - el video de introducción no frenaba nada.
 *
 * Y el cuarto, el que protege producción: un lead de la dinámica ACTUAL tiene que comportarse
 * exactamente como antes de esta misión.
 */
class DemoEnCincoMinutosYGateDelVideoTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Deja el reloj real (los tests fijan `now` con Carbon::setTestNow) y el HTTP sustituido.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Ningún test de este archivo puede salir a la red: el setup remoto se sustituye entero.
        Http::fake([
            '*' => Http::response(['ok' => true], 200),
        ]);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Demo con las cuatro URLs que el modelo necesita.
     *
     * @return Demo
     */
    private function crear_demo(): Demo
    {
        $demo = new Demo();
        $demo->uuid              = (string) Str::uuid();
        $demo->erp_spa_url       = 'https://demo-erp.test';
        $demo->erp_api_url       = 'https://demo-erp-api.test';
        $demo->ecommerce_spa_url = 'https://demo-tienda.test';
        $demo->ecommerce_api_url = 'https://demo-tienda-api.test';
        $demo->save();

        return $demo;
    }

    /**
     * Lead agendado, listo para que lo levante el comando.
     *
     * @param string      $experiencia    Lead::EXPERIENCIA_NUEVA | Lead::EXPERIENCIA_ACTUAL.
     * @param Carbon      $inicio         Momento de inicio del turno.
     * @param Carbon|null $form_completado Marca de envío del formulario; null = sin completar.
     *
     * @return Lead
     */
    private function crear_lead(string $experiencia, Carbon $inicio, ?Carbon $form_completado = null): Lead
    {
        $demo = $this->crear_demo();

        $lead = new Lead();
        $lead->uuid                    = (string) Str::uuid();
        $lead->contact_name            = 'Lead de prueba';
        $lead->company_name            = 'Empresa de prueba';
        $lead->status                  = 'demo_agendada';
        $lead->demo_id                 = $demo->id;
        $lead->demo_date               = $inicio->copy()->format('Y-m-d');
        $lead->demo_start_time         = $inicio->copy()->format('H:i');
        $lead->demo_end_time           = $inicio->copy()->addMinutes(60)->format('H:i');
        $lead->demo_setup_status       = 'pendiente';
        $lead->demo_form_completado_at = $form_completado;
        $lead->save();

        // Después del save: el hook `creating` del modelo estampa la dinámica por defecto, así que
        // asignarla antes la pisaría.
        $lead->demo_experiencia = $experiencia;
        $lead->save();

        return $lead->refresh();
    }

    /**
     * Fija el reloj de la aplicación. AppTime::now() sale de Carbon::now() salvo en `local` con
     * tiempo virtual, así que con esto alcanza para mover el reloj de todo el ciclo.
     *
     * @param Carbon $momento
     *
     * @return void
     */
    private function fijar_reloj(Carbon $momento): void
    {
        Carbon::setTestNow($momento->copy());
    }

    /**
     * Momento base de todos los tests: un día cualquiera a media mañana, en la timezone del ciclo
     * de demo. Fijo y no "ahora" para que la suite no cambie de comportamiento según la hora a la
     * que corra.
     *
     * @return Carbon
     */
    private function momento_base(): Carbon
    {
        return Carbon::parse('2026-08-20 10:00:00', RunDemoSetupService::TZ);
    }

    /**
     * 1. Lead de la dinámica nueva, turno a ahora+5, sin completar el formulario: el comando NO
     *    dispara. Es el bug de origen — antes caía dentro de la ventana de 15 minutos en el mismo
     *    instante en que se agendaba.
     *
     * @return void
     */
    public function test_sin_formulario_el_comando_no_dispara_el_setup(): void
    {
        $ahora = $this->momento_base();
        $this->fijar_reloj($ahora);

        $lead = $this->crear_lead(Lead::EXPERIENCIA_NUEVA, $ahora->copy()->addMinutes(5));

        $this->artisan('leads:run-demo-setup')->assertExitCode(0);

        $this->assertSame('pendiente', $lead->refresh()->demo_setup_status);
    }

    /**
     * 2. El mismo lead completa el formulario: el setup queda `exitoso` y el payload que viajó a
     *    empresa-api trae SUS respuestas, no los defaults del catálogo.
     *
     * @return void
     */
    public function test_al_completar_el_formulario_el_setup_corre_con_las_respuestas_del_lead(): void
    {
        $ahora = $this->momento_base();
        $this->fijar_reloj($ahora);

        $lead = $this->crear_lead(Lead::EXPERIENCIA_NUEVA, $ahora->copy()->addMinutes(5));

        // El default del catálogo para `usa_ecommerce` es true: se manda false para que la
        // diferencia entre "lo que contestó" y "el default" sea observable.
        $respuesta = $this->postJson('/api/demo-experiencia/' . $lead->uuid . '/formulario', [
            'tipo_precios'  => 'listas',
            'usa_ecommerce' => false,
        ]);

        $respuesta->assertStatus(200);

        // El disparo va con ->afterResponse(), que en la suite no se ejecuta solo: se corre el
        // comando, que es el otro disparador de la MISMA condición.
        $this->artisan('leads:run-demo-setup')->assertExitCode(0);

        $this->assertSame('exitoso', $lead->refresh()->demo_setup_status);

        $enviado = null;
        Http::assertSent(function ($request) use (&$enviado) {
            if (strpos($request->url(), '/api/admin-sync/demo-setup') === false) {
                return false;
            }
            $enviado = $request->data();

            return true;
        });

        $this->assertNotNull($enviado, 'El setup no llegó a llamar al empresa-api de la demo.');
        $this->assertTrue($enviado['demo_form_completado']);
        $this->assertSame('listas', $enviado['respuestas_formulario']['tipo_precios']);
        $this->assertFalse((bool) $enviado['respuestas_formulario']['usa_ecommerce']);
        // El legado recalculado desde la misma respuesta, no desde la columna.
        $this->assertTrue((bool) $enviado['use_price_lists']);
    }

    /**
     * 3. El turno empezó hace 20 minutos y el lead recién ahora completa el formulario: el setup
     *    dispara igual. Es la regresión que la misión vino a arreglar — antes el
     *    `if ($demo_datetime->lt($now)) continue;` lo sacaba de candidato para siempre.
     *
     * @return void
     */
    public function test_el_turno_ya_empezado_sigue_disparando_cuando_llega_el_formulario(): void
    {
        $ahora = $this->momento_base();
        $this->fijar_reloj($ahora);

        $lead = $this->crear_lead(
            Lead::EXPERIENCIA_NUEVA,
            $ahora->copy()->subMinutes(20),
            $ahora->copy()
        );

        $this->artisan('leads:run-demo-setup')->assertExitCode(0);

        $this->assertSame('exitoso', $lead->refresh()->demo_setup_status);
    }

    /**
     * 4. 🔴 El test que protege producción: un lead de la dinámica ACTUAL, con el turno dentro de
     *    la ventana y sin formulario, dispara exactamente como antes de esta misión. Esos leads no
     *    tienen página inmersiva y nunca van a tener `demo_form_completado_at`.
     *
     * @return void
     */
    public function test_la_dinamica_actual_dispara_igual_que_siempre(): void
    {
        $ahora = $this->momento_base();
        $this->fijar_reloj($ahora);

        $lead = $this->crear_lead(Lead::EXPERIENCIA_ACTUAL, $ahora->copy()->addMinutes(10));

        $this->assertNull($lead->demo_form_completado_at);

        $this->artisan('leads:run-demo-setup')->assertExitCode(0);

        $this->assertSame('exitoso', $lead->refresh()->demo_setup_status);
    }

    /**
     * 4bis. La otra mitad del test 4, y es la que fija la ASIMETRÍA: para un lead `actual` el turno
     *       ya empezado **no** dispara, porque para esa dinámica el tope superior sigue existiendo.
     *       Sin este test, el 4 solo prueba que la dinámica actual sigue disparando y no que sigue
     *       disparando *igual*: alguien podría sacarle el tope a las dos y el verde no cambiaría.
     *
     * @return void
     */
    public function test_la_dinamica_actual_conserva_el_tope_superior(): void
    {
        $ahora = $this->momento_base();
        $this->fijar_reloj($ahora);

        // Exactamente el caso del test 3, que en la dinámica nueva SÍ dispara.
        $lead = $this->crear_lead(
            Lead::EXPERIENCIA_ACTUAL,
            $ahora->copy()->subMinutes(20),
            $ahora->copy()
        );

        $this->artisan('leads:run-demo-setup')->assertExitCode(0);

        $this->assertSame('pendiente', $lead->refresh()->demo_setup_status);
        // Y no disparó por el motivo correcto: si el lead hubiera dejado de ser candidato por
        // cualquier otra razón, el estado también quedaría en `pendiente` y el test pasaría sin
        // probar nada. Que no haya salido ninguna llamada al empresa-api lo cierra.
        Http::assertNothingSent();
    }

    /**
     * 5. Con el setup exitoso, el intro en 40% rebota con `intro_pendiente`; en 95% entra.
     *
     * @return void
     */
    public function test_el_intro_frena_el_ingreso_hasta_llegar_al_umbral(): void
    {
        $ahora = $this->momento_base();
        $this->fijar_reloj($ahora);

        $lead = $this->crear_lead(Lead::EXPERIENCIA_NUEVA, $ahora->copy()->subMinutes(5), $ahora->copy());
        $this->preparar_lead_listo_para_ingresar($lead);
        $this->cargar_video_intro();

        AdminSetting::set(LeadDemoSettings::KEY_DEMO_INTRO_UMBRAL_PCT, '90');

        $lead->intro_visto_pct = 40;
        $lead->save();

        $this->postJson('/api/demo-experiencia/' . $lead->uuid . '/ingresar')
            ->assertStatus(409)
            ->assertJson(['motivo' => 'intro_pendiente']);

        $lead->intro_visto_pct = 95;
        $lead->save();

        $respuesta = $this->postJson('/api/demo-experiencia/' . $lead->uuid . '/ingresar');
        $respuesta->assertStatus(200);
        $this->assertNotEmpty($respuesta->json('url'));
    }

    /**
     * 6. En entorno `local` el gate del intro no aplica: es el camino con el que Lucas prueba.
     *
     * @return void
     */
    public function test_en_entorno_local_se_entra_sin_haber_visto_el_intro(): void
    {
        $ahora = $this->momento_base();
        $this->fijar_reloj($ahora);

        $lead = $this->crear_lead(Lead::EXPERIENCIA_NUEVA, $ahora->copy()->subMinutes(5), $ahora->copy());
        $this->preparar_lead_listo_para_ingresar($lead);
        $this->cargar_video_intro();

        config(['app.env' => 'local']);

        $respuesta = $this->postJson('/api/demo-experiencia/' . $lead->uuid . '/ingresar');
        $respuesta->assertStatus(200);
        $this->assertNotEmpty($respuesta->json('url'));
    }

    /**
     * 7. Sin URL de intro cargada el gate NO aplica. Es la salvaguarda que evita que, mientras el
     *    video no esté subido, nadie pueda entrar a ninguna demo — que es el estado real del
     *    sistema hoy.
     *
     * @return void
     */
    public function test_sin_video_cargado_el_gate_no_deja_a_nadie_afuera(): void
    {
        $ahora = $this->momento_base();
        $this->fijar_reloj($ahora);

        $lead = $this->crear_lead(Lead::EXPERIENCIA_NUEVA, $ahora->copy()->subMinutes(5), $ahora->copy());
        $this->preparar_lead_listo_para_ingresar($lead);

        // Sin fila en demo_media para el slot `intro`.
        $payload = $this->getJson('/api/demo-experiencia/' . $lead->uuid)->json();

        $this->assertSame(0, $payload['intro']['visto_pct']);
        $this->assertFalse($payload['intro']['obligatorio']);
        $this->assertTrue($payload['puede_ingresar']);
    }

    /**
     * 8. El endpoint de progreso es monótono: un pct menor al guardado no baja el valor.
     *
     * @return void
     */
    public function test_el_progreso_del_intro_nunca_baja(): void
    {
        $ahora = $this->momento_base();
        $this->fijar_reloj($ahora);

        $lead = $this->crear_lead(Lead::EXPERIENCIA_NUEVA, $ahora->copy()->addMinutes(30));

        $this->postJson('/api/demo-experiencia/' . $lead->uuid . '/intro-progreso', ['pct' => 70])
            ->assertStatus(200);
        $this->assertSame(70, (int) $lead->refresh()->intro_visto_pct);

        $this->postJson('/api/demo-experiencia/' . $lead->uuid . '/intro-progreso', ['pct' => 30])
            ->assertStatus(200);
        $this->assertSame(70, (int) $lead->refresh()->intro_visto_pct);
    }

    /**
     * 8bis. El progreso del intro PERSISTE entre visitas. La misión pide verificarlo explícitamente
     *       y no darlo por sentado: es lo que evita que el lead que miró el video hoy tenga que
     *       volver a mirarlo cuando llegue su turno mañana. Sale de que el valor viva en la columna
     *       y no en el navegador, así que se comprueba pidiendo el payload de nuevo, como haría una
     *       visita posterior.
     *
     * @return void
     */
    public function test_el_progreso_del_intro_persiste_entre_visitas(): void
    {
        $ahora = $this->momento_base();
        $this->fijar_reloj($ahora);

        // Turno para mañana: la visita de hoy es anterior al turno, igual que el caso real.
        $lead = $this->crear_lead(Lead::EXPERIENCIA_NUEVA, $ahora->copy()->addDay());

        $this->postJson('/api/demo-experiencia/' . $lead->uuid . '/intro-progreso', ['pct' => 100])
            ->assertStatus(200);

        // "Otra visita": un GET nuevo, sin nada del lado del cliente.
        $payload = $this->getJson('/api/demo-experiencia/' . $lead->uuid)->json();

        $this->assertSame(100, $payload['intro']['visto_pct']);
        $this->assertNotNull($lead->refresh()->intro_visto_at);
    }

    /**
     * 9. Doble disparo: el job y el comando sobre el mismo lead. El setup corre UNA sola vez —
     *    el claim condicional devuelve 0 filas en el segundo.
     *
     * @return void
     */
    public function test_el_claim_impide_que_el_setup_corra_dos_veces(): void
    {
        $ahora = $this->momento_base();
        $this->fijar_reloj($ahora);

        $lead = $this->crear_lead(Lead::EXPERIENCIA_NUEVA, $ahora->copy()->subMinutes(2), $ahora->copy());

        // Primer disparador: el job, igual que lo despacha el POST del formulario.
        (new RunDemoSetupJob($lead->id))->handle(new RunDemoSetupService());
        $this->assertSame('exitoso', $lead->refresh()->demo_setup_status);

        // Segundo disparador: el comando, en el tick siguiente. Ya no lo encuentra `pendiente`.
        $this->artisan('leads:run-demo-setup')->assertExitCode(0);

        $llamadas = 0;
        Http::assertSent(function ($request) use (&$llamadas) {
            if (strpos($request->url(), '/api/admin-sync/demo-setup') !== false) {
                $llamadas++;
            }

            return true;
        });

        $this->assertSame(1, $llamadas, 'El demo setup remoto se ejecutó más de una vez.');
    }

    /**
     * 10. Turno vencido (pasado el fin + la gracia): el comando no dispara y `puede_ingresar` es
     *     false.
     *
     * @return void
     */
    public function test_el_turno_vencido_no_dispara_ni_deja_entrar(): void
    {
        $ahora = $this->momento_base();
        $this->fijar_reloj($ahora);

        // Turno de 60 minutos que arrancó hace 3 horas: vencido con cualquier gracia razonable.
        $lead = $this->crear_lead(
            Lead::EXPERIENCIA_NUEVA,
            $ahora->copy()->subHours(3),
            $ahora->copy()->subHours(3)
        );

        $this->artisan('leads:run-demo-setup')->assertExitCode(0);
        $this->assertSame('pendiente', $lead->refresh()->demo_setup_status);

        // Y aunque el setup hubiera salido bien en su momento, tampoco se entra.
        $this->preparar_lead_listo_para_ingresar($lead);

        $payload = $this->getJson('/api/demo-experiencia/' . $lead->uuid)->json();
        $this->assertFalse($payload['puede_ingresar']);

        $this->postJson('/api/demo-experiencia/' . $lead->uuid . '/ingresar')
            ->assertStatus(409)
            ->assertJson(['motivo' => 'vencido']);
    }

    /**
     * Deja al lead con el setup terminado y el token de ingreso vigente: lo que dejaría
     * RunDemoSetupService tras una corrida exitosa.
     *
     * @param Lead $lead
     *
     * @return void
     */
    private function preparar_lead_listo_para_ingresar(Lead $lead): void
    {
        $lead->demo_setup_status            = 'exitoso';
        $lead->demo_ingreso_token           = Str::random(64);
        $lead->demo_ingreso_token_expira_at = AppTime::now()->copy()->addHours(4);
        $lead->demo_ingreso_token_revocado_at = null;
        $lead->save();
    }

    /**
     * Carga una URL para el slot `intro`, que es lo que vuelve obligatorio el gate del video.
     *
     * @return void
     */
    private function cargar_video_intro(): void
    {
        DemoMedia::updateOrCreate(
            ['slot_id' => 'intro'],
            ['url' => 'https://videos.test/intro.mp4']
        );
    }
}

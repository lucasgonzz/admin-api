<?php

namespace Tests\Feature;

use App\Models\Demo;
use App\Models\DemoEventoRecibido;
use App\Models\Lead;
use App\Models\LeadDemoHito;
use App\Jobs\RunDemoSetupJob;
use App\Services\LeadDemoSettings;
use App\Services\RunDemoSetupService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El demo setup sale del ciclo de vida del request y `ejecutandose` deja de ser un pozo (misión 60).
 *
 * Lo que estos tests protegen, con lo que se midió el 14/8/2026 detrás:
 *
 *  - El POST del formulario **encola** el trabajo en vez de correrlo adentro del request. Antes se
 *    despachaba con `afterResponse()`, que bajo mod_php corre inline igual: el lead esperaba 124
 *    segundos y a los 120 Apache mataba el proceso. Un fatal por tiempo no es capturable, así que
 *    el lead quedaba en `ejecutandose` con el error en NULL, y de ahí no salía nunca — el comando
 *    de cada minuto sólo miraba `pendiente`. Tres leads colgados y cero exitosos en dos meses.
 *  - Un setup colgado **vence** y pasa a `fallido` con un motivo que dice que no se sabe cómo
 *    terminó, que es distinto de decir que falló.
 *  - Ese `fallido` se reintenta **una sola vez**, y nunca con alguien adentro de la demo: el
 *    reintento dispara un `migrate:fresh` sobre la instancia.
 *  - Y la dinámica ACTUAL no se entera de nada de esto.
 */
class DemoSetupFueraDelRequestTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Libera el reloj fijado por los tests que lo mueven.
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Momento base fijo: la suite no puede cambiar de comportamiento según la hora a la que corra.
     *
     * @return Carbon
     */
    private function momento_base(): Carbon
    {
        return Carbon::parse('2026-08-20 10:00:00', RunDemoSetupService::TZ);
    }

    /**
     * Demo con las cuatro URLs que el modelo necesita.
     *
     * @return Demo
     */
    private function crear_demo(): Demo
    {
        $demo                    = new Demo();
        $demo->uuid              = (string) Str::uuid();
        $demo->erp_spa_url       = 'https://demo-erp.test';
        $demo->erp_api_url       = 'https://demo-erp-api.test';
        $demo->ecommerce_spa_url = 'https://demo-tienda.test';
        $demo->ecommerce_api_url = 'https://demo-tienda-api.test';
        $demo->save();

        return $demo;
    }

    /**
     * Lead agendado con la dinámica y el estado de setup que pida cada caso.
     *
     * @param string      $experiencia Lead::EXPERIENCIA_NUEVA | Lead::EXPERIENCIA_ACTUAL.
     * @param Carbon      $inicio      Momento de inicio del turno.
     * @param string      $setup       Valor de `demo_setup_status`.
     * @param Carbon|null $last_run    Valor de `demo_setup_last_run_at`.
     *
     * @return Lead
     */
    private function crear_lead(string $experiencia, Carbon $inicio, string $setup = 'pendiente', ?Carbon $last_run = null): Lead
    {
        $demo = $this->crear_demo();

        $lead                          = new Lead();
        $lead->uuid                    = (string) Str::uuid();
        $lead->contact_name            = 'Lead de prueba';
        $lead->company_name            = 'Empresa de prueba';
        $lead->status                  = 'demo_agendada';
        $lead->demo_id                 = $demo->id;
        $lead->demo_date               = $inicio->copy()->format('Y-m-d');
        $lead->demo_start_time         = $inicio->copy()->format('H:i');
        $lead->demo_end_time           = $inicio->copy()->addMinutes(60)->format('H:i');
        $lead->demo_setup_status       = $setup;
        $lead->demo_setup_last_run_at  = $last_run;
        $lead->save();

        // Después del save: el hook `creating` del modelo estampa la dinámica por defecto, así que
        // asignarla antes la pisaría.
        $lead->demo_experiencia = $experiencia;
        $lead->save();

        return $lead->refresh();
    }

    /**
     * Cuerpo mínimo del formulario de la página inmersiva.
     *
     * @return array<string, mixed>
     */
    private function respuestas_formulario(): array
    {
        return [
            'tipo_precios'                    => 'listas',
            'usa_depositos'                   => false,
            'usa_cuentas_corrientes_clientes' => true,
            'usa_ecommerce'                   => false,
        ];
    }

    /**
     * 1. El POST del formulario ENCOLA el trabajo: no lo corre adentro del request.
     *
     * Es el corazón de la misión. `Queue::fake()` prueba las dos mitades a la vez: que el job se
     * despachó, y que NO se ejecutó inline —si corriera inline, el fake no lo habría interceptado y
     * el lead habría cambiado de estado—.
     */
    public function test_el_formulario_encola_el_setup_y_no_lo_corre_adentro_del_request(): void
    {
        Queue::fake();
        Carbon::setTestNow($this->momento_base());

        // Turno arrancando: cae dentro de la ventana de disparo con el formulario completo.
        $lead = $this->crear_lead(Lead::EXPERIENCIA_NUEVA, $this->momento_base()->copy()->addMinutes(5));

        $respuesta = $this->postJson('/api/demo-experiencia/' . $lead->uuid . '/formulario', $this->respuestas_formulario());

        $respuesta->assertStatus(200);

        /* 🔴 Se afirma la CONEXIÓN, no sólo que se despachó, y lo corrigió la verificación de esta
         * misión. `QueueFake::connection()` devuelve `$this` sin mirar el nombre, así que un
         * `assertPushed` pelado pasa igual con un `dispatch()` sin `onConnection` — o sea que no
         * protegía justamente el requisito de la pieza 1, que es la regresión más probable acá. */
        Queue::assertPushed(RunDemoSetupJob::class, function ($job) {
            return $job->connection === 'database';
        });

        // El estado NO se movió: el trabajo quedó encolado, no corrió. Si el job se hubiera
        // ejecutado inline, acá habría `ejecutandose`, `exitoso` o `fallido`.
        $this->assertSame('pendiente', (string) $lead->refresh()->demo_setup_status);
    }

    /**
     * 2. Garantía de no regresión de la dinámica ACTUAL: el mismo POST no encola nada.
     *
     * Un lead 'actual' no tiene página inmersiva, así que llegar acá ya sería raro — pero el
     * endpoint es público y resuelve por uuid sin mirar la dinámica, que es exactamente el camino
     * por el que una misión anterior sí la alcanzó.
     */
    public function test_la_dinamica_actual_no_encola_ningun_setup(): void
    {
        Queue::fake();
        Carbon::setTestNow($this->momento_base());

        $lead = $this->crear_lead(Lead::EXPERIENCIA_ACTUAL, $this->momento_base()->copy()->addMinutes(5));

        $this->postJson('/api/demo-experiencia/' . $lead->uuid . '/formulario', $this->respuestas_formulario())
            ->assertStatus(200);

        Queue::assertNothingPushed();
        $this->assertSame('pendiente', (string) $lead->refresh()->demo_setup_status);
    }

    /**
     * 3. Un setup colgado en `ejecutandose` por encima del umbral pasa a `fallido` con su motivo.
     */
    public function test_el_setup_colgado_vence_y_queda_fallido_con_motivo(): void
    {
        Carbon::setTestNow($this->momento_base());

        $timeout = LeadDemoSettings::get_setup_timeout_minutos();

        $lead = $this->crear_lead(
            Lead::EXPERIENCIA_NUEVA,
            $this->momento_base()->copy()->addMinutes(5),
            'ejecutandose',
            $this->momento_base()->copy()->subMinutes($timeout + 5)
        );

        $this->artisan('leads:vencer-demo-setups-colgados')->assertExitCode(0);

        $lead->refresh();

        $this->assertSame('fallido', (string) $lead->demo_setup_status);
        $this->assertNotEmpty((string) $lead->demo_setup_last_error);
        // El texto tiene que decir que no se sabe cómo terminó, no que falló.
        $this->assertStringContainsString('no reportó resultado', (string) $lead->demo_setup_last_error);
    }

    /**
     * 4. El mismo comando NO toca un `ejecutandose` reciente: puede estar corriendo bien.
     *
     * Es la mitad que importa del punto 3. Un umbral demasiado corto marcaría como fallido un
     * armado sano y habilitaría el reintento, que dispara otro `migrate:fresh`.
     */
    public function test_el_setup_reciente_no_se_toca(): void
    {
        Carbon::setTestNow($this->momento_base());

        $lead = $this->crear_lead(
            Lead::EXPERIENCIA_NUEVA,
            $this->momento_base()->copy()->addMinutes(5),
            'ejecutandose',
            $this->momento_base()->copy()->subMinute()
        );

        $this->artisan('leads:vencer-demo-setups-colgados')->assertExitCode(0);

        $this->assertSame('ejecutandose', (string) $lead->refresh()->demo_setup_status);
    }

    /**
     * 5. El reintento automático es UNO solo: la segunda corrida ya no lo toca.
     */
    public function test_el_reintento_automatico_corre_una_sola_vez(): void
    {
        Carbon::setTestNow($this->momento_base());
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        // Turno ya arrancado: `evaluar_disparo` da verdadero por el fallback sin formulario.
        $lead = $this->crear_lead(
            Lead::EXPERIENCIA_NUEVA,
            $this->momento_base()->copy()->subMinutes(10),
            'fallido'
        );

        $this->artisan('leads:run-demo-setup')->assertExitCode(0);

        $lead->refresh();
        $this->assertSame(1, (int) $lead->demo_setup_intentos, 'El primer reintento tiene que consumir el único intento.');
        $this->assertSame('exitoso', (string) $lead->demo_setup_status);

        // Se lo vuelve a dejar en `fallido` a mano, como si el reintento hubiera salido mal: el
        // contador ya está en 1, así que la corrida siguiente NO tiene que tocarlo.
        $lead->demo_setup_status = 'fallido';
        $lead->save();

        $this->artisan('leads:run-demo-setup')->assertExitCode(0);

        $lead->refresh();
        $this->assertSame(1, (int) $lead->demo_setup_intentos, 'El contador no puede subir: el intento ya se usó.');
        $this->assertSame('fallido', (string) $lead->demo_setup_status);
    }

    /**
     * 5bis. 🔴 Con el lead adentro de la demo NO se reintenta, ni con intentos disponibles.
     *
     * Es la guarda que evita el único daño irreversible de esta misión: reintentar dispara un
     * `migrate:fresh` sobre la instancia, y hacerlo con alguien adentro le vacía la base en medio
     * de la demo. Se prueban las dos señales por separado porque alcanza con que UNA diga que hay
     * alguien.
     */
    public function test_no_se_reintenta_si_el_lead_esta_adentro_de_la_demo(): void
    {
        Carbon::setTestNow($this->momento_base());
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        // (a) Ingreso confirmado por el agente.
        $con_ingreso = $this->crear_lead(
            Lead::EXPERIENCIA_NUEVA,
            $this->momento_base()->copy()->subMinutes(10),
            'fallido'
        );
        $con_ingreso->demo_ingreso_confirmado = true;
        $con_ingreso->save();

        // (b) Sin ingreso confirmado, pero la instancia ya reportó un evento del lead.
        $con_eventos = $this->crear_lead(
            Lead::EXPERIENCIA_NUEVA,
            $this->momento_base()->copy()->subMinutes(10),
            'fallido'
        );
        $evento              = new DemoEventoRecibido();
        $evento->lead_id     = $con_eventos->id;
        $evento->uuid        = (string) Str::uuid();
        $evento->nombre      = 'clip.terminado';
        $evento->clip_id     = '1.1';
        $evento->ocurrido_at = $this->momento_base()->copy();
        $evento->save();

        $this->artisan('leads:run-demo-setup')->assertExitCode(0);

        $this->assertSame('fallido', (string) $con_ingreso->refresh()->demo_setup_status);
        $this->assertSame(0, (int) $con_ingreso->demo_setup_intentos);

        $this->assertSame('fallido', (string) $con_eventos->refresh()->demo_setup_status);
        $this->assertSame(0, (int) $con_eventos->demo_setup_intentos);
    }

    /**
     * 5quater. La tercera señal: el lead que pulsó "Entrar a la demo" tampoco recibe un disparo
     * automático, y esto vale también para un lead en `pendiente`, no sólo para el reintento.
     *
     * Lo agregó la verificación de la misión 60. Es la señal más importante de las tres porque es
     * la única local de admin-api: las otras dos dependen de que conteste el lead o de que la
     * instancia pueda alcanzar el canal de eventos.
     */
    public function test_no_se_dispara_el_setup_si_el_lead_ya_pulso_el_ingreso(): void
    {
        Carbon::setTestNow($this->momento_base());
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        // En `pendiente`, no en `fallido`: el agujero que cerró la verificación era justamente que
        // la guarda colgaba del reintento y este camino no la tocaba.
        $lead = $this->crear_lead(
            Lead::EXPERIENCIA_NUEVA,
            $this->momento_base()->copy()->subMinutes(10),
            'pendiente'
        );

        $hito                   = new LeadDemoHito();
        $hito->lead_id          = $lead->id;
        $hito->tipo             = LeadDemoHito::TIPO_INGRESO;
        $hito->orden            = 0;
        $hito->titulo           = 'Entrar a la demo';
        $hito->tutorial_visto_at = $this->momento_base()->copy();
        $hito->save();

        $this->artisan('leads:run-demo-setup')->assertExitCode(0);

        $this->assertSame('pendiente', (string) $lead->refresh()->demo_setup_status);
    }

    /**
     * 6. El comando de vencimiento tampoco toca a la dinámica ACTUAL.
     *
     * También de la verificación: la primera versión no filtraba por dinámica, así que le pisaba el
     * estado y el error a un lead 'actual' colgado. No destruía nada, pero el criterio de
     * aceptación de la misión dice *ningún* camino.
     */
    public function test_el_vencimiento_no_toca_a_la_dinamica_actual(): void
    {
        Carbon::setTestNow($this->momento_base());

        $timeout = LeadDemoSettings::get_setup_timeout_minutos();

        $lead = $this->crear_lead(
            Lead::EXPERIENCIA_ACTUAL,
            $this->momento_base()->copy()->addMinutes(5),
            'ejecutandose',
            $this->momento_base()->copy()->subMinutes($timeout + 5)
        );

        $this->artisan('leads:vencer-demo-setups-colgados')->assertExitCode(0);

        $this->assertSame('ejecutandose', (string) $lead->refresh()->demo_setup_status);
        $this->assertEmpty((string) $lead->demo_setup_last_error);
    }

    /**
     * 5ter. Y la dinámica ACTUAL no entra nunca al reintento, esté como esté su setup.
     */
    public function test_la_dinamica_actual_no_se_reintenta(): void
    {
        Carbon::setTestNow($this->momento_base());
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $lead = $this->crear_lead(
            Lead::EXPERIENCIA_ACTUAL,
            $this->momento_base()->copy()->addMinutes(5),
            'fallido'
        );

        $this->artisan('leads:run-demo-setup')->assertExitCode(0);

        $lead->refresh();
        $this->assertSame('fallido', (string) $lead->demo_setup_status);
        $this->assertSame(0, (int) $lead->demo_setup_intentos);
    }
}

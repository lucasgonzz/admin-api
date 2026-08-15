<?php

namespace Tests\Feature;

use App\Models\Demo;
use App\Models\DemoEventoRecibido;
use App\Models\Lead;
use App\Models\LeadDemoHito;
use App\Http\Controllers\DemoEventosController;
use App\Services\DemoHitosService;
use App\Services\RunDemoSetupService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El aviso de vuelta de la instancia: `demo.setup.completado` (misión cruzada demo-v2-conexion-admin-empresa, 14/8/2026).
 *
 * Hasta ahora admin se enteraba del resultado del demo setup SOLO por la respuesta HTTP del POST a
 * `/api/admin-sync/demo-setup`, que se espera hasta 300 segundos adentro del RunDemoSetupJob. Si
 * esa conexión se corta con el setup terminando bien del otro lado, `mark_failed()` dejaba al lead
 * en `fallido` con la instancia perfectamente armada y el botón de comenzar la demo no le aparecía
 * nunca.
 *
 * Estos tests cubren las dos mitades del arreglo, que sólo sirven juntas:
 *
 *  - el evento mueve el setup a `exitoso` (incluso desde `fallido`) y no toca el roadmap;
 *  - `mark_failed()` ya no pisa un `exitoso`, así que el orden "llega el evento → después vence el
 *    POST" no vuelve a romper el caso.
 *
 * Y la quinta pata, que es requisito duro: la dinámica ACTUAL no cambia de comportamiento por
 * ningún camino.
 */
class DemoSetupCompletadoAvisadoPorLaInstanciaTest extends TestCase
{
    use DatabaseTransactions;

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
     * Lead con su canal de eventos emitido, en la dinámica y el estado de setup que pida el caso.
     *
     * @param string    $dinamica  Lead::EXPERIENCIA_NUEVA | Lead::EXPERIENCIA_ACTUAL.
     * @param string    $setup     Valor de `demo_setup_status`.
     * @param Demo|null $demo      Demo asignada; null = lead sin demo (camino de mark_failed).
     * @param string    $error     Valor de `demo_setup_last_error`.
     *
     * @return Lead
     */
    private function crear_lead(string $dinamica, string $setup, ?Demo $demo = null, string $error = ''): Lead
    {
        $lead                          = new Lead();
        $lead->uuid                    = (string) Str::uuid();
        $lead->contact_name            = 'Juana Pérez';
        $lead->company_name            = 'Empresa de prueba';
        $lead->demo_id                 = $demo !== null ? $demo->id : null;
        $lead->demo_setup_status       = $setup;
        $lead->demo_setup_last_error   = $error !== '' ? $error : null;
        $lead->demo_eventos_token      = Str::random(64);
        $lead->save();

        // Después del save: el hook `creating` del modelo estampa la dinámica por defecto, así que
        // asignarla antes la pisaría.
        $lead->demo_experiencia = $dinamica;
        $lead->save();

        return $lead->refresh();
    }

    /**
     * Los dos hitos de siempre: el de ingreso y el del clip 1.1.
     *
     * @param Lead $lead
     */
    private function crear_hitos(Lead $lead): void
    {
        LeadDemoHito::create([
            'lead_id' => $lead->id, 'orden' => 1, 'tipo' => LeadDemoHito::TIPO_INGRESO,
            'seccion' => null, 'clip_id' => null, 'titulo' => 'Entrar a la demo',
            'evento_esperado' => DemoHitosService::EVENTO_INGRESO,
            'estado' => LeadDemoHito::ESTADO_PENDIENTE,
        ]);

        LeadDemoHito::create([
            'lead_id' => $lead->id, 'orden' => 2, 'tipo' => LeadDemoHito::TIPO_TUTORIAL,
            'seccion' => 'S1 - Listado', 'clip_id' => '1.1', 'titulo' => 'Crear un articulo',
            'evento_esperado' => 'articulo.creado',
            'estado' => LeadDemoHito::ESTADO_PENDIENTE,
        ]);
    }

    /**
     * Postea el evento `demo.setup.completado` por el canal del lead.
     *
     * @param Lead $lead
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function postear_setup_completado(Lead $lead)
    {
        return $this->postJson(
            '/api/demo-eventos',
            ['eventos' => [[
                'uuid'        => (string) Str::uuid(),
                'nombre'      => DemoEventosController::EVENTO_SETUP_COMPLETADO,
                'clip_id'     => null,
                'ocurrido_at' => '2026-08-14T21:40:00-03:00',
                'datos'       => ['user_id' => 1],
            ]]],
            ['X-Demo-Eventos-Key' => $lead->demo_eventos_token]
        );
    }

    /**
     * 1. El evento mueve el setup a `exitoso` desde `ejecutandose`, que es el camino feliz: el
     *    setup terminó antes de que el POST volviera.
     */
    public function test_el_evento_mueve_el_setup_a_exitoso_desde_ejecutandose(): void
    {
        $lead = $this->crear_lead(Lead::EXPERIENCIA_NUEVA, 'ejecutandose');

        $respuesta = $this->postear_setup_completado($lead)->assertStatus(200);

        $this->assertSame(1, $respuesta->json('guardados'));
        $this->assertSame('exitoso', (string) $lead->refresh()->demo_setup_status);
        $this->assertNull($lead->demo_setup_last_error);

        // Se sigue persistiendo como evento recibido, igual que cualquier otro.
        $this->assertSame(1, DemoEventoRecibido::where('lead_id', $lead->id)
            ->where('nombre', DemoEventosController::EVENTO_SETUP_COMPLETADO)->count());
    }

    /**
     * 2. Y también desde `fallido`, que es el caso que la misión vino a arreglar: el POST se cortó,
     *    mark_failed() marcó fallido, y la instancia estaba perfectamente armada. El evento lo
     *    prueba y además limpia el error viejo, que ya no describe nada real.
     */
    public function test_el_evento_mueve_el_setup_a_exitoso_desde_fallido_y_limpia_el_error(): void
    {
        $lead = $this->crear_lead(Lead::EXPERIENCIA_NUEVA, 'fallido', null, 'Excepción: cURL error 28: Operation timed out');

        $this->postear_setup_completado($lead)->assertStatus(200);

        $lead->refresh();

        $this->assertSame('exitoso', (string) $lead->demo_setup_status);
        $this->assertNull($lead->demo_setup_last_error);
    }

    /**
     * 3. El evento NO mueve ningún hito del roadmap: que la instancia se haya armado no es algo que
     *    el lead hizo. Se mira el conteo que devuelve el canal y también los hitos en la base.
     */
    public function test_el_evento_no_mueve_ningun_hito_del_roadmap(): void
    {
        $lead = $this->crear_lead(Lead::EXPERIENCIA_NUEVA, 'ejecutandose');
        $this->crear_hitos($lead);

        $respuesta = $this->postear_setup_completado($lead)->assertStatus(200);

        $this->assertSame(0, $respuesta->json('hitos'));

        foreach (LeadDemoHito::where('lead_id', $lead->id)->get() as $hito) {
            $this->assertSame(LeadDemoHito::ESTADO_PENDIENTE, $hito->estado);
            $this->assertNull($hito->tutorial_visto_at);
            $this->assertNull($hito->accion_hecha_at);
        }
    }

    /**
     * 4a. `mark_failed()` no pisa un `exitoso` en la dinámica nueva. Camino directo: el lead ya está
     *     en `exitoso` (se lo dejó el evento) y run() falla antes de tocar el estado, porque no hay
     *     demo asignada.
     */
    public function test_mark_failed_no_pisa_un_exitoso_en_la_dinamica_nueva(): void
    {
        $lead = $this->crear_lead(Lead::EXPERIENCIA_NUEVA, 'exitoso');

        (new RunDemoSetupService())->run($lead);

        $lead->refresh();

        $this->assertSame('exitoso', (string) $lead->demo_setup_status);
        $this->assertNull($lead->demo_setup_last_error);
    }

    /**
     * 4b. Y el orden real que rompía todo: el POST está en vuelo, llega el evento y lo deja en
     *     `exitoso`, y RECIÉN DESPUÉS la conexión del POST se cae. El estado en memoria de run() ya
     *     está viejo — por eso la guarda va adentro del UPDATE y no en un `if`.
     */
    public function test_el_evento_gana_aunque_el_post_se_corte_despues(): void
    {
        $demo = $this->crear_demo();
        $lead = $this->crear_lead(Lead::EXPERIENCIA_NUEVA, 'pendiente', $demo);

        // El evento llega mientras el POST está en vuelo: se escribe en la base por afuera de la
        // instancia que run() tiene en memoria.
        Http::fake(function () use ($lead) {
            Lead::where('id', $lead->id)->update([
                'demo_setup_status'     => 'exitoso',
                'demo_setup_last_error' => null,
            ]);

            return Http::response('Se cortó la conexión', 500);
        });

        (new RunDemoSetupService())->run($lead);

        $lead->refresh();

        $this->assertSame('exitoso', (string) $lead->demo_setup_status);
        $this->assertNull($lead->demo_setup_last_error);
    }

    /**
     * 5. La dinámica ACTUAL no cambia de comportamiento por ningún camino.
     *
     *  - El canal de eventos la rechaza con 401 aunque tenga token, así que el evento nuevo no la
     *    alcanza ni por accidente.
     *  - Y `mark_failed()` le sigue pisando el `exitoso` exactamente como antes de esta misión: es
     *    lo que necesita el botón "Correr demo setup ahora" del panel, que se pulsa para re-correr
     *    el setup a propósito y tiene que poder registrar que esta vez falló.
     */
    public function test_la_dinamica_actual_no_cambia_de_comportamiento(): void
    {
        $lead = $this->crear_lead(Lead::EXPERIENCIA_ACTUAL, 'exitoso');

        $this->postear_setup_completado($lead)->assertStatus(401);

        $this->assertSame(0, DemoEventoRecibido::where('lead_id', $lead->id)->count());
        $this->assertSame('exitoso', (string) $lead->refresh()->demo_setup_status);

        // Y el camino de mark_failed sigue siendo el de siempre: pisa el exitoso.
        (new RunDemoSetupService())->run($lead);

        $lead->refresh();

        $this->assertSame('fallido', (string) $lead->demo_setup_status);
        $this->assertStringContainsString('no tiene demo asignada', (string) $lead->demo_setup_last_error);
    }

    /**
     * Y la dinámica nula o desconocida se comporta como la actual, que es el fallback duro de
     * `demo_experiencia_efectiva()` — el que protege a los leads reales de producción.
     */
    public function test_la_dinamica_nula_tampoco_cambia_de_comportamiento(): void
    {
        $lead = $this->crear_lead(Lead::EXPERIENCIA_ACTUAL, 'exitoso');
        $lead->demo_experiencia = null;
        $lead->save();

        (new RunDemoSetupService())->run($lead);

        $this->assertSame('fallido', (string) $lead->refresh()->demo_setup_status);
    }
}

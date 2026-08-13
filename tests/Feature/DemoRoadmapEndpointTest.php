<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Lead;
use App\Models\LeadDemoHito;
use App\Services\DemoHitosService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `GET /api/lead/{id}/demo-roadmap` — el recorrido de la demo que consume el panel del lead
 * (misión 49, pieza 1).
 *
 * Se le pega al endpoint real, autenticado como lo hace el panel, y se mira el payload: es un
 * contrato explícito, no el modelo serializado, y eso es justamente lo que hay que verificar.
 */
class DemoRoadmapEndpointTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Admin autenticado, igual que el resto del panel.
     *
     * @return Admin
     */
    private function autenticar(): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Admin de prueba';
        $admin->email    = 'roadmap-' . Str::random(8) . '@test.local';
        $admin->password = bcrypt('secret');
        $admin->save();

        Sanctum::actingAs($admin);

        return $admin;
    }

    /**
     * Lead con la dinámica nueva. Con `$con_plan` se le congela un plan de dos secciones.
     *
     * @param bool $con_plan
     *
     * @return Lead
     */
    private function crear_lead(bool $con_plan): Lead
    {
        $lead                     = new Lead();
        $lead->uuid               = (string) Str::uuid();
        $lead->contact_name       = 'Juana Pérez';
        $lead->demo_experiencia   = Lead::EXPERIENCIA_NUEVA;
        $lead->demo_ingreso_token = Str::random(64);
        $lead->demo_eventos_token = Str::random(64);

        if ($con_plan) {
            $lead->demo_plan = [
                'version_catalogo' => 2,
                'resuelto_at'      => '2026-08-13 14:22:00',
                'respuestas'       => ['tipo_precios' => 'unico', 'usa_ecommerce' => true],
                'secciones'        => [
                    ['id' => 'S1 - Listado', 'orden' => 1, 'clips' => [
                        ['id' => '1.1', 'orden' => 1, 'titulo' => 'Crear un articulo', 'tipo' => 'nucleo', 'practica' => true, 'evento_esperado' => 'articulo.creado'],
                        ['id' => '1.6', 'orden' => 2, 'titulo' => 'Actualizacion masiva', 'tipo' => 'nucleo', 'practica' => true, 'evento_esperado' => null],
                    ]],
                    ['id' => 'S2 - Vender', 'orden' => 2, 'clips' => [
                        ['id' => '2.1', 'orden' => 1, 'titulo' => 'Armar una venta', 'tipo' => 'nucleo', 'practica' => true, 'evento_esperado' => 'venta.creada'],
                    ]],
                ],
                'condiciones_invalidas' => [],
                'totales'               => ['secciones' => 2, 'clips_nucleo' => 3, 'clips_biblioteca' => 0],
            ];
            $lead->demo_plan_congelado_at = now();
        }

        $lead->save();

        if ($con_plan) {
            DemoHitosService::generar($lead);
        }

        return $lead;
    }

    /**
     * Caso 1: un lead que todavía no completó el formulario no tiene plan, y eso no es un error.
     */
    public function test_un_lead_sin_plan_devuelve_tiene_plan_false(): void
    {
        $this->autenticar();
        $lead = $this->crear_lead(false);

        $r = $this->getJson('/api/admin/lead/' . $lead->id . '/demo-roadmap')->assertStatus(200);

        $this->assertFalse($r->json('tiene_plan'));
        $this->assertNull($r->json('congelado_at'));
        $this->assertSame([], $r->json('hitos'));
        $this->assertSame(0, $r->json('progreso.total'));
    }

    /**
     * Caso 2: recién congelado, todos los hitos en `pendiente` y el progreso en 0 sobre el total.
     */
    public function test_un_plan_recien_congelado_devuelve_todo_pendiente(): void
    {
        $this->autenticar();
        $lead = $this->crear_lead(true);

        $r = $this->getJson('/api/admin/lead/' . $lead->id . '/demo-roadmap')->assertStatus(200);

        $this->assertTrue($r->json('tiene_plan'));
        $this->assertNotNull($r->json('congelado_at'));

        // Ingreso + los tres clips de núcleo.
        $this->assertSame(4, $r->json('progreso.total'));
        $this->assertSame(0, $r->json('progreso.completos'));
        $this->assertSame(0, $r->json('progreso.parciales'));

        foreach ($r->json('hitos') as $hito) {
            $this->assertSame(LeadDemoHito::ESTADO_PENDIENTE, $hito['estado']);
        }

        // El primero es el de ingreso, y viene sin sección.
        $primero = $r->json('hitos.0');
        $this->assertSame(LeadDemoHito::TIPO_INGRESO, $primero['tipo']);
        $this->assertNull($primero['seccion']);

        // Y los tutoriales traen su sección, que es lo que el panel usa para agrupar.
        $this->assertSame('S1 - Listado', $r->json('hitos.1.seccion'));
    }

    /**
     * Caso 3: con avance real, `progreso` cuenta completos y parciales por separado.
     */
    public function test_el_progreso_cuenta_completos_y_parciales_por_separado(): void
    {
        $this->autenticar();
        $lead = $this->crear_lead(true);

        // Entró de verdad: el hito de ingreso queda completo.
        DemoHitosService::aplicar($lead, ['nombre' => DemoHitosService::EVENTO_INGRESO, 'clip_id' => null, 'ocurrido_at' => '2026-08-13 14:31:02']);
        // Vio dos tutoriales y no hizo ninguna de las dos acciones.
        DemoHitosService::aplicar($lead, ['nombre' => 'clip.terminado', 'clip_id' => '1.1', 'ocurrido_at' => '2026-08-13 14:35:00']);
        DemoHitosService::aplicar($lead, ['nombre' => 'clip.terminado', 'clip_id' => '2.1', 'ocurrido_at' => '2026-08-13 14:40:00']);

        $r = $this->getJson('/api/admin/lead/' . $lead->id . '/demo-roadmap')->assertStatus(200);

        $this->assertSame(1, $r->json('progreso.completos'));
        $this->assertSame(2, $r->json('progreso.parciales'));
        $this->assertSame(4, $r->json('progreso.total'));

        // Y la hora de la acción viaja, que es lo que la tarjeta muestra en el hito completo.
        $this->assertSame('2026-08-13 14:31:02', $r->json('hitos.0.accion_hecha_at'));
    }

    /**
     * Caso 4: ningún token del lead viaja en el payload. El panel ya tiene controles propios para
     * el token de ingreso, y el de eventos no lo necesita nadie del lado del navegador.
     */
    public function test_el_payload_no_expone_ningun_token_del_lead(): void
    {
        $this->autenticar();
        $lead = $this->crear_lead(true);

        $r = $this->getJson('/api/admin/lead/' . $lead->id . '/demo-roadmap')->assertStatus(200);

        $cuerpo = $r->getContent();
        $this->assertStringNotContainsString($lead->demo_eventos_token, $cuerpo);
        $this->assertStringNotContainsString($lead->demo_ingreso_token, $cuerpo);
        $this->assertStringNotContainsString('demo_eventos_token', $cuerpo);
        $this->assertStringNotContainsString('demo_ingreso_token', $cuerpo);
    }

    /**
     * Caso 5: sin autenticar, 401 — como el resto del grupo del panel.
     */
    public function test_sin_autenticar_devuelve_401(): void
    {
        $lead = $this->crear_lead(true);

        $this->getJson('/api/admin/lead/' . $lead->id . '/demo-roadmap')->assertStatus(401);
    }

    /**
     * Caso 6: las condiciones inválidas del catálogo llegan al panel. Es un typo del repo que se
     * sincronizó a producción; que muera en un log es exactamente lo que no puede pasar.
     */
    public function test_las_condiciones_invalidas_llegan_al_panel(): void
    {
        $this->autenticar();
        $lead = $this->crear_lead(true);

        $plan = $lead->demo_plan;
        $plan['condiciones_invalidas'] = [
            ['tipo' => 'clip', 'id' => '9.1', 'condicion' => 'usa_ecommerce || registra_compras'],
        ];
        $lead->demo_plan = $plan;
        $lead->save();

        $r = $this->getJson('/api/admin/lead/' . $lead->id . '/demo-roadmap')->assertStatus(200);

        $this->assertCount(1, $r->json('condiciones_invalidas'));
        $this->assertSame('9.1', $r->json('condiciones_invalidas.0.id'));
        $this->assertSame('usa_ecommerce || registra_compras', $r->json('condiciones_invalidas.0.condicion'));
    }

    /**
     * Las respuestas que viajan son las CONGELADAS dentro del plan, no las columnas actuales del
     * lead: si reenvió el formulario pueden diferir, y lo que explica el recorrido que se está
     * mostrando es con lo que se resolvió, no lo último que contestó.
     */
    public function test_las_respuestas_son_las_congeladas_y_no_las_columnas_actuales(): void
    {
        $this->autenticar();
        $lead = $this->crear_lead(true);

        // El lead cambia de opinión después de congelado.
        $lead->usa_ecommerce = false;
        $lead->save();

        $r = $this->getJson('/api/admin/lead/' . $lead->id . '/demo-roadmap')->assertStatus(200);

        $this->assertTrue($r->json('respuestas.usa_ecommerce'));
    }
}

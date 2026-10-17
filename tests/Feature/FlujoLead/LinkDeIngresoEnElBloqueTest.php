<?php

namespace Tests\Feature\FlujoLead;

use App\Models\AdminSetting;
use App\Models\Demo;
use App\Models\Lead;
use App\Services\LeadAiService;
use App\Services\LeadDemoSettings;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El bloque DEMO DIRECTA después de la misión flujo-lead-landing-closer (17/10/2026): la línea de
 * calificación, el link de ingreso directo con token (solo con demo asignada, setup exitoso y token
 * vigente; si no, el motivo), y las reglas nuevas (oferta completa, "ahora o cuándo", agendar_demo con
 * día y hora, el estado de cada paso).
 */
class LinkDeIngresoEnElBloqueTest extends TestCase
{
    use DatabaseTransactions;

    const TZ = 'America/Argentina/Buenos_Aires';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-13 10:30:00', self::TZ));
        Http::fake();
        config(['services.admin_spa.url' => 'https://admin.test']);

        AdminSetting::set(LeadDemoSettings::KEY_DURACION_MINUTOS, '60');
        AdminSetting::set(LeadDemoSettings::KEY_GRACIA_MINUTOS_POST, '10');
        AdminSetting::set(LeadDemoSettings::KEY_SETUP_MINUTOS_ANTES, '15');
        AdminSetting::set(LeadDemoSettings::KEY_VENTANA_EXTENDIDA_MAX_HORAS, '2');
        AdminSetting::set(LeadDemoSettings::KEY_BLOQUEO_REAL_MINUTOS, '120');
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
     * (1) Sin demo asignada: el link no está y el motivo lo dice. La calificación sin datos también.
     *
     * @return void
     */
    public function test_sin_demo_asignada_el_link_no_esta_y_la_calificacion_dice_sin_dato(): void
    {
        $this->crear_demo();
        $lead = $this->crear_lead('calificado');

        $bloque = $this->contexto($lead);

        $this->assertStringContainsString('Link de ingreso directo a la demo (SOLO si el lead dice que no puede entrar desde su pagina; copiar textual): (todavia no disponible: no hay demo asignada)', $bloque);
        $this->assertStringContainsString('Calificacion: rubro=(sin dato); empleados=(sin dato); sistema actual=(sin dato)', $bloque);
    }

    /**
     * (2) Con demo asignada pero el setup todavía corriendo: "la demo se esta preparando".
     *
     * @return void
     */
    public function test_con_setup_pendiente_dice_que_se_esta_preparando(): void
    {
        $demo = $this->crear_demo();
        $lead = $this->crear_lead_con_demo($demo, 'pendiente', 'token-de-prueba-vigente', now()->addHours(3), null);

        $this->assertStringContainsString('(todavia no disponible: la demo se esta preparando)', $this->contexto($lead));
    }

    /**
     * (3) Setup exitoso pero token revocado, vencido o inexistente: "el token vencio".
     *
     * @return void
     */
    public function test_sin_token_vigente_dice_que_vencio(): void
    {
        $demo = $this->crear_demo();

        $revocado = $this->crear_lead_con_demo($demo, 'exitoso', 'token-revocado', now()->addHours(3), now()->subMinute(), '5493519990011');
        $this->assertStringContainsString('(todavia no disponible: el token vencio)', $this->contexto($revocado));

        $vencido = $this->crear_lead_con_demo($demo, 'exitoso', 'token-vencido', now()->subMinute(), null, '5493519990012');
        $this->assertStringContainsString('(todavia no disponible: el token vencio)', $this->contexto($vencido));

        $sin_token = $this->crear_lead_con_demo($demo, 'exitoso', null, null, null, '5493519990013');
        $this->assertStringContainsString('(todavia no disponible: el token vencio)', $this->contexto($sin_token));
    }

    /**
     * (3 bis) El setup `fallido` no es «se esta preparando»: el bloque dice que el armado falló y manda escalar.
     *         Y con token vigente pero la instancia sin `erp_spa_url`, dice que falta el link, no que el token venció.
     *
     * @return void
     */
    public function test_setup_fallido_y_demo_sin_url_tienen_su_propio_motivo(): void
    {
        $demo    = $this->crear_demo();
        $fallido = $this->crear_lead_con_demo($demo, 'fallido', 'token-de-prueba-vigente', now()->addHours(3), null, '5493519990014');
        $bloque  = $this->contexto($fallido);
        $this->assertStringContainsString('(todavia no disponible: el armado de la demo fallo; no prometas el link', $bloque);
        $this->assertStringContainsString('requiere_intervencion_humana: true con el motivo)', $bloque);
        $this->assertStringNotContainsString('(todavia no disponible: la demo se esta preparando)', $bloque);

        $sin_url                    = new Demo();
        $sin_url->uuid              = (string) Str::uuid();
        $sin_url->erp_spa_url       = '';
        $sin_url->erp_api_url       = 'https://demo-2-api.test';
        $sin_url->ecommerce_spa_url = 'https://tienda-2.test';
        $sin_url->ecommerce_api_url = 'https://tienda-2-api.test';
        $sin_url->save();
        $lead_sin_url = $this->crear_lead_con_demo($sin_url, 'exitoso', 'token-de-prueba-vigente', now()->addHours(3), null, '5493519990015');
        $bloque       = $this->contexto($lead_sin_url);
        $this->assertStringContainsString('(todavia no disponible: la instancia no tiene link de ingreso cargado', $bloque);
        $this->assertStringNotContainsString('el token vencio', $bloque);
    }

    /**
     * (4) 🔴 Con demo asignada, setup exitoso y token vigente: el link completo, copiable.
     *
     * @return void
     */
    public function test_con_todo_en_orden_el_link_con_token_esta_en_el_bloque(): void
    {
        $demo = $this->crear_demo();
        $lead = $this->crear_lead_con_demo($demo, 'exitoso', 'token-de-prueba-vigente', now()->addHours(3), null);
        $lead->business_type      = 'ferretería';
        $lead->cantidad_empleados = 3;
        $lead->sistema_actual     = 'Excel';
        $lead->save();

        $bloque = $this->contexto($lead);

        $this->assertStringContainsString('Link de ingreso directo a la demo (SOLO si el lead dice que no puede entrar desde su pagina; copiar textual): https://demo-1.test/demo/ingreso?t=token-de-prueba-vigente', $bloque);
        $this->assertStringContainsString('Calificacion: rubro=ferretería; empleados=3; sistema actual=Excel', $bloque);
        $this->assertStringContainsString('YA ASIGNADA (instancia ' . $demo->id . ', arranca a las 10:10 y queda abierta hasta las 12:10 de hoy)', $bloque);
    }

    /**
     * (5) Las reglas nuevas del turno están, y las viejas que las contradecían no.
     *
     * @return void
     */
    public function test_las_reglas_nuevas_estan_y_las_viejas_no(): void
    {
        $this->crear_demo();
        $lead = $this->crear_lead('calificado');

        $bloque = $this->contexto($lead);

        $this->assertStringContainsString('Calificacion minima: antes de ofrecer la demo', $bloque);
        $this->assertStringContainsString('guardar_calificacion {"rubro": "...", "empleados": N, "sistema_actual": "..."}', $bloque);
        $this->assertStringContainsString('La oferta, completa y una sola vez', $bloque);
        $this->assertStringContainsString("¿la hace\n    ahora o cuando le queda comodo?", $bloque);
        $this->assertStringContainsString('Si dice AHORA: pedi el mail (si no lo tenemos) y con el mail devolve guardar_email + agendar_demo {"ahora": true}', $bloque);
        $this->assertStringContainsString('agendar_demo {"dia": "hoy|manana|pasado_manana|lunes...domingo|<dia>_proximo|+N", "hora": "HH:MM"}', $bloque);
        $this->assertStringContainsString('Minimo quince minutos desde ahora', $bloque);
        $this->assertStringContainsString('estado_sugerido "solicita_disponibilidad" cuando dijo que si y todavia no agendamos', $bloque);
        $this->assertStringContainsString("no devuelvas confirmar_ingreso como si lo\n    hubieras verificado", $bloque);
        $this->assertStringContainsString('NUNCA solicita_disponibilidad: true, NUNCA dia_solicitado', $bloque);

        $this->assertStringNotContainsString('la demo se hace AHORA, sin horarios', $bloque);
        $this->assertStringNotContainsString('Para ofrecerla: "si queres, te la puedo tener lista en diez minutos"', $bloque);
        $this->assertStringNotContainsString('No propongas horarios ni pidas disponibilidad', $bloque);
    }

    /**
     * @param Lead $lead
     *
     * @return string
     */
    private function contexto(Lead $lead): string
    {
        $metodo = new \ReflectionMethod(LeadAiService::class, 'build_demo_directa_context');
        $metodo->setAccessible(true);

        return (string) $metodo->invoke(new LeadAiService(), $lead);
    }

    /**
     * @return Demo
     */
    private function crear_demo(): Demo
    {
        $demo                    = new Demo();
        $demo->uuid              = (string) Str::uuid();
        $demo->erp_spa_url       = 'https://demo-1.test';
        $demo->erp_api_url       = 'https://demo-1-api.test';
        $demo->ecommerce_spa_url = 'https://tienda-1.test';
        $demo->ecommerce_api_url = 'https://tienda-1-api.test';
        $demo->save();

        return $demo;
    }

    /**
     * @param string $status
     * @param string $telefono
     *
     * @return Lead
     */
    private function crear_lead(string $status, string $telefono = '5493519990010'): Lead
    {
        $lead               = new Lead();
        $lead->uuid         = (string) Str::uuid();
        $lead->contact_name = 'Guillermo González';
        $lead->phone        = $telefono;
        $lead->status       = $status;
        $lead->save();

        return $lead->refresh();
    }

    /**
     * Lead en demo_agendada con la demo de hoy 10:10-12:10 (vigente a las 10:30) y el estado del setup
     * y del token que pida el caso.
     *
     * @param Demo        $demo
     * @param string      $setup
     * @param string|null $token
     * @param Carbon|null $expira
     * @param Carbon|null $revocado
     * @param string      $telefono
     *
     * @return Lead
     */
    private function crear_lead_con_demo(Demo $demo, string $setup, ?string $token, ?Carbon $expira, ?Carbon $revocado, string $telefono = '5493519990010'): Lead
    {
        $lead                                 = $this->crear_lead('demo_agendada', $telefono);
        $lead->demo_id                        = $demo->id;
        $lead->demo_date                      = '2026-10-13';
        $lead->demo_start_time                = '10:10';
        $lead->demo_end_time                  = '12:10';
        $lead->demo_flexible                  = true;
        $lead->demo_setup_status              = $setup;
        $lead->demo_ingreso_token             = $token;
        $lead->demo_ingreso_token_expira_at   = $expira;
        $lead->demo_ingreso_token_revocado_at = $revocado;
        $lead->save();

        return $lead->refresh();
    }
}

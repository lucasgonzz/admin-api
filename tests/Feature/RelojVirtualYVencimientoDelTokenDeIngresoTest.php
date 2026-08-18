<?php

namespace Tests\Feature;

use App\Helpers\AppTime;
use App\Models\AdminSetting;
use App\Models\Demo;
use App\Models\Lead;
use App\Services\RunDemoSetupService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Bug reportado por Lucas el 18/8/2026, probando el flujo de demo con el reloj virtual de debug
 * (`AdminSetting::debug_virtual_time`, leído por `AppTime::now()` solo con `app.env === 'local'`):
 * atrasar el reloj virtual hasta la hora del turno reabre el botón "Entrar a la demo" —
 * `build_turno()` usa `AppTime::now()` y respeta el reloj virtual—, pero el POST de canje seguía
 * devolviendo 409 `token_invalido`, porque ese chequeo comparaba con `isPast()` (Carbon::now()
 * real, ajeno al reloj virtual). El turno se veía abierto y el token se marcaba inválido igual.
 *
 * El arreglo reusa `modo_prueba()` (el mismo gate que ya exime el video de intro en local, línea
 * de `evaluar_ingreso()`): en local, vencimiento y revocación del token dejan de bloquear —mismo
 * criterio que `empresa-api` ya adoptó para este problema en la vecindad
 * (informe `20260817-demo-vigencia-turno.md`, hallazgo 2: "cero fricción mientras prueba").
 */
class RelojVirtualYVencimientoDelTokenDeIngresoTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Ningún test de este archivo sale a la red (avisar_instancia, etc. no corren en este flujo,
        // pero Http::fake() es la convención ya usada en los tests hermanos de este endpoint).
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
     * @return Demo
     */
    private function crear_demo(): Demo
    {
        $demo = new Demo();
        $demo->uuid              = (string) Str::uuid();
        $demo->erp_spa_url       = 'https://demo-erp.comerciocity.com';
        $demo->erp_api_url       = 'https://demo-erp-api.comerciocity.com';
        $demo->ecommerce_spa_url = 'https://demo-tienda.comerciocity.com';
        $demo->ecommerce_api_url = 'https://demo-tienda-api.comerciocity.com';
        $demo->save();

        return $demo;
    }

    /**
     * Lead con turno 2026-08-20 10:00–11:00 (TZ Buenos Aires) y el token de ingreso ya emitido,
     * con expiración/revocación parametrizables para reproducir el estado exacto del bug.
     *
     * @param Carbon      $expira_at Vencimiento a persistir en `demo_ingreso_token_expira_at`.
     * @param Carbon|null $revocado_at Si no es null, se persiste en `demo_ingreso_token_revocado_at`.
     *
     * @return Lead
     */
    private function crear_lead_con_token(Carbon $expira_at, ?Carbon $revocado_at = null): Lead
    {
        $demo = $this->crear_demo();

        $lead = new Lead();
        $lead->uuid                        = (string) Str::uuid();
        $lead->contact_name                = 'Lead de prueba';
        $lead->company_name                = 'Empresa de prueba';
        $lead->status                      = 'demo_agendada';
        $lead->demo_id                     = $demo->id;
        $lead->demo_date                   = '2026-08-20';
        $lead->demo_start_time             = '10:00';
        $lead->demo_end_time               = '11:00';
        $lead->demo_setup_status           = 'exitoso';
        $lead->demo_ingreso_token          = 'tok-' . Str::random(20);
        $lead->demo_ingreso_token_expira_at   = $expira_at;
        $lead->demo_ingreso_token_revocado_at = $revocado_at;

        // Gate del intro satisfecho explícitamente: sin esto, un video de introducción sembrado en
        // el futuro (tabla `demo_media`) haría caer estos tests por `intro_pendiente`, un motivo
        // ajeno a lo que se está probando acá.
        $lead->intro_visto_pct = 100;

        $lead->save();

        return $lead->refresh();
    }

    /**
     * El caso reportado: reloj virtual dentro del turno (así que `build_turno()` lo ve `activo`),
     * pero el token vence en tiempo real —el reloj que `isPast()` usa— antes de la hora real actual.
     * Sin el fix, esto devolvía 409 `token_invalido` con el botón ya habilitado.
     *
     * @return void
     */
    public function test_en_local_el_reloj_virtual_reabre_el_turno_y_el_token_vencido_en_tiempo_real_ya_no_bloquea(): void
    {
        // Reloj real: bien avanzado, después de que el token venció de verdad.
        Carbon::setTestNow(Carbon::parse('2026-08-21 09:00:00', RunDemoSetupService::TZ));

        // Reloj virtual: parado adentro del turno (10:00–11:00), como hace Lucas para no repetir el
        // agendamiento.
        AdminSetting::set(AppTime::SETTING_KEY, '2026-08-20 10:30:00');
        config(['app.env' => 'local']);

        $lead = $this->crear_lead_con_token(
            Carbon::parse('2026-08-20 11:10:00', RunDemoSetupService::TZ) // fin (11:00) + gracia (10 min)
        );

        $respuesta = $this->postJson('/api/demo-experiencia/' . $lead->uuid . '/ingresar');

        $respuesta->assertStatus(200);
        $this->assertNotEmpty($respuesta->json('url'));
    }

    /**
     * Fuera de `local` (el criterio real de producción y de cualquier instancia de cliente), un
     * token vencido en tiempo real sigue bloqueando aunque el turno siga activo — el bypass no se
     * escapa fuera del entorno de prueba.
     *
     * @return void
     */
    public function test_fuera_de_local_un_token_vencido_en_tiempo_real_sigue_bloqueando_aunque_el_turno_este_activo(): void
    {
        // "Ahora" real cae adentro del turno (10:00–11:10 con gracia): build_turno() lo ve activo.
        Carbon::setTestNow(Carbon::parse('2026-08-20 10:45:00', RunDemoSetupService::TZ));

        $lead = $this->crear_lead_con_token(
            // El token venció 5 minutos antes de "ahora" — independiente del turno, sigue siendo
            // vencido y `app.env` no es `local`.
            Carbon::parse('2026-08-20 10:40:00', RunDemoSetupService::TZ)
        );

        $respuesta = $this->postJson('/api/demo-experiencia/' . $lead->uuid . '/ingresar');

        $respuesta->assertStatus(409);
        $respuesta->assertJson(['motivo' => 'token_invalido']);
    }

    /**
     * En local, un token revocado a mano tampoco bloquea — mismo criterio de "cero fricción" que el
     * vencimiento, y misma decisión ya tomada para `empresa-api` en el hallazgo 2 del informe del
     * 17/8/2026.
     *
     * @return void
     */
    public function test_en_local_un_token_revocado_tampoco_bloquea_el_ingreso(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20 10:30:00', RunDemoSetupService::TZ));
        config(['app.env' => 'local']);

        $lead = $this->crear_lead_con_token(
            Carbon::parse('2026-08-20 11:10:00', RunDemoSetupService::TZ),
            Carbon::parse('2026-08-20 10:31:00', RunDemoSetupService::TZ) // revocado un minuto atrás
        );

        $respuesta = $this->postJson('/api/demo-experiencia/' . $lead->uuid . '/ingresar');

        $respuesta->assertStatus(200);
        $this->assertNotEmpty($respuesta->json('url'));
    }

    /**
     * Fuera de local, un token revocado sigue bloqueando — es el control de seguridad real del
     * link, que viaja por WhatsApp y es compartible (ver docblock de
     * `DemoIngresoTokenService::calcular_expiracion()`).
     *
     * @return void
     */
    public function test_fuera_de_local_un_token_revocado_sigue_bloqueando(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20 10:30:00', RunDemoSetupService::TZ));

        $lead = $this->crear_lead_con_token(
            Carbon::parse('2026-08-20 11:10:00', RunDemoSetupService::TZ),
            Carbon::parse('2026-08-20 10:31:00', RunDemoSetupService::TZ)
        );

        $respuesta = $this->postJson('/api/demo-experiencia/' . $lead->uuid . '/ingresar');

        $respuesta->assertStatus(409);
        $respuesta->assertJson(['motivo' => 'token_invalido']);
    }
}

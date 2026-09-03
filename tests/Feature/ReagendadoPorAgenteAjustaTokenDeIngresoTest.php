<?php

namespace Tests\Feature;

use App\Helpers\AppTime;
use App\Models\AdminSetting;
use App\Models\Demo;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\LeadAiService;
use App\Services\LeadDemoSettings;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El reagendado automático del agente de WhatsApp (`LeadAiService`, bloque `agendar_demo`) ahora
 * ajusta el token de ingreso a la demo igual que ya hacía `LeadController::update_demo_end_time_json()`
 * (la edición manual desde el panel, tarea 62).
 *
 * POR QUÉ EXISTE ESTE ARCHIVO
 * ---------------------------
 * Medido en producción el 3/9/2026 (lead 594, Patricio Ridella, demo1): el lead reagendó por
 * WhatsApp de 18:00 a 19:00-20:00. El token de ingreso, calculado cuando se agendó a las 18:00,
 * quedó con el vencimiento viejo (fin original + 10 min de gracia) y nunca se resincronizó. El
 * gate público (`DemoExperienciaController::evaluar_ingreso()`) lo dejaba pasar porque lee la hora
 * en vivo, pero el login real contra la instancia (`DemoIngresoController::store()`) rechazaba el
 * token vencido — el lead nunca entró, sin ningún error visible.
 *
 * La asimetría: `update_demo_end_time_json()` sí llama a `DemoIngresoTokenService` al cambiar
 * `demo_end_time`; el bloque de reagendado de `LeadAiService` (el camino más común: un lead que
 * pide mover el horario por el chat) no lo hacía. Este archivo prueba el mismo contrato que ya
 * cubre `FinDeDemoEditableTest`, pero ejercitando el camino del agente.
 */
class ReagendadoPorAgenteAjustaTokenDeIngresoTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Grilla y duración previsibles, mismo criterio que FinDeDemoEditableTest.
        AdminSetting::set(LeadDemoSettings::KEY_DEMO_HORARIO_LUNES_VIERNES, '00:00-23:59');
        AdminSetting::set(LeadDemoSettings::KEY_DEMO_HORARIO_SABADO, '00:00-23:59');
        AdminSetting::set(LeadDemoSettings::KEY_DEMO_HORARIO_DOMINGO, '00:00-23:59');
        AdminSetting::set(LeadDemoSettings::KEY_FRECUENCIA_SLOTS_MINUTOS, '30');
        AdminSetting::set(LeadDemoSettings::KEY_DURACION_MINUTOS, '60');
        AdminSetting::set(LeadDemoSettings::KEY_GRACIA_MINUTOS_POST, '10');
        AdminSetting::set(LeadDemoSettings::KEY_SETUP_MINUTOS_ANTES, '15');
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
     * Momento base: un jueves a las 08:00, con todos los horarios de los tests por delante.
     *
     * @return Carbon
     */
    private function momento_base(): Carbon
    {
        return Carbon::parse('2026-08-20 08:00:00', 'America/Argentina/Buenos_Aires');
    }

    /**
     * Todo lo que salga a la red responde 200. Se llama EXPLÍCITAMENTE en cada test que la
     * necesita, no en `setUp()`: `Http::fake()` acumula stubs entre llamadas y resuelve por el
     * PRIMERO que matchea, así que un fake genérico puesto en `setUp()` le ganaría siempre a
     * cualquier fake más específico que un test intente agregar después (ver
     * `test_si_el_aviso_a_la_instancia_falla_el_reagendado_no_se_revierte()`, que necesita que su
     * propio patrón para `/admin-sync/demo-token` sea el único registrado).
     *
     * @return void
     */
    private function fakear_red_exitosa(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true], 200),
        ]);
    }

    /**
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
     * Lead de la dinámica nueva con una demo agendada 10:00–11:00 y token de ingreso emitido —
     * el estado real de un lead al que ya se le mandó el link por WhatsApp.
     *
     * @param Demo        $demo
     * @param string|null $revocado_at Si se pasa, el token queda revocado a esa fecha/hora.
     *
     * @return Lead
     */
    private function crear_lead_agendado(Demo $demo, ?string $revocado_at = null): Lead
    {
        $lead = new Lead();
        $lead->uuid         = (string) Str::uuid();
        $lead->contact_name = 'Lead de prueba';
        $lead->company_name = 'Empresa de prueba';
        $lead->status       = 'demo_agendada';
        $lead->save();

        $lead->demo_experiencia             = Lead::EXPERIENCIA_NUEVA;
        $lead->demo_id                      = $demo->id;
        $lead->demo_date                    = '2026-08-20';
        $lead->demo_start_time              = '10:00';
        $lead->demo_end_time                = '11:00';
        $lead->demo_ingreso_token           = Str::random(64);
        $lead->demo_ingreso_token_expira_at = Carbon::parse('2026-08-20 11:10:00', 'America/Argentina/Buenos_Aires');
        if ($revocado_at !== null) {
            $lead->demo_ingreso_token_revocado_at = Carbon::parse($revocado_at, 'America/Argentina/Buenos_Aires');
        }
        $lead->save();

        return $lead->refresh();
    }

    /**
     * Lead de la dinámica nueva sin demo, para el caso de primer agendamiento.
     *
     * @return Lead
     */
    private function crear_lead_sin_demo(): Lead
    {
        $lead = new Lead();
        $lead->uuid         = (string) Str::uuid();
        $lead->contact_name = 'Lead de prueba';
        $lead->company_name = 'Empresa de prueba';
        $lead->status       = 'calificado';
        $lead->save();

        $lead->demo_experiencia = Lead::EXPERIENCIA_NUEVA;
        $lead->save();

        return $lead->refresh();
    }

    /**
     * Instancia del service con el método protegido que estos tests necesitan expuesto — mismo
     * recurso que ya usa FinDeDemoEditableTest.
     *
     * @return LeadAiService
     */
    private function service(): LeadAiService
    {
        return new class extends LeadAiService {
            /**
             * @param Lead                 $lead
             * @param array<string, mixed> $parsed
             *
             * @return LeadMessage
             */
            public function aplicar(Lead $lead, array $parsed): LeadMessage
            {
                return $this->apply_parsed_response($lead, $parsed, false);
            }
        };
    }

    /**
     * Paquete mínimo de (re)agendamiento del agente.
     *
     * @param Demo   $demo
     * @param string $fecha
     * @param string $hora
     *
     * @return array<string, mixed>
     */
    private function paquete(Demo $demo, string $fecha, string $hora): array
    {
        return [
            'mensaje_sugerido' => 'Te dejo la demo lista.',
            'estado_sugerido'  => 'demo_agendada',
            'agendar_demo'     => [
                'demo_id'         => $demo->id,
                'demo_date'       => $fecha,
                'demo_start_time' => $hora,
            ],
        ];
    }

    /**
     * 1. El caso real de producción: demo de 10 a 11 con token vigente hasta las 11:10, el agente
     *    la reagenda a las 15:00 (mismo día, duración 60 → fin 16:00). El token corre su
     *    vencimiento a 16:10 SIN cambiar de valor (el link que el lead ya tiene por WhatsApp sigue
     *    sirviendo), y a la instancia se le avisa con el token viejo y el vencimiento nuevo.
     *
     * @return void
     */
    public function test_el_reagendado_del_agente_extiende_el_token_a_un_horario_mas_tarde(): void
    {
        Carbon::setTestNow($this->momento_base());
        $this->fakear_red_exitosa();

        $demo        = $this->crear_demo();
        $lead        = $this->crear_lead_agendado($demo);
        $token_antes = $lead->demo_ingreso_token;

        $this->service()->aplicar($lead, $this->paquete($demo, '2026-08-20', '15:00'));

        $lead->refresh();
        $this->assertSame('15:00', $lead->demo_start_time);
        $this->assertSame('16:00', $lead->demo_end_time);
        $this->assertSame($token_antes, $lead->demo_ingreso_token, 'El token cambió de valor: el link que ya tiene el lead quedaría inválido.');
        $this->assertSame(
            '2026-08-20 16:10:00',
            $lead->demo_ingreso_token_expira_at->format('Y-m-d H:i:s'),
            'El vencimiento del token no acompañó al reagendado (fin nuevo + gracia).'
        );

        Http::assertSent(function ($request) use ($token_antes) {
            if (strpos($request->url(), '/api/admin-sync/demo-token') === false) {
                return false;
            }
            $data = $request->data();

            return isset($data['token']) && $data['token'] === $token_antes
                && isset($data['expira_at']) && $data['expira_at'] === '2026-08-20 16:10:00';
        });
    }

    /**
     * 2. El reagendado a un horario MÁS TEMPRANO el mismo día: el token acorta su vencimiento en
     *    sincronía, con el mismo valor — el mismo criterio que `FinDeDemoEditableTest` ya prueba
     *    para el panel, acá para el camino del agente.
     *
     * @return void
     */
    public function test_el_reagendado_del_agente_acorta_el_token_a_un_horario_mas_temprano(): void
    {
        Carbon::setTestNow($this->momento_base());
        $this->fakear_red_exitosa();

        $demo                                = $this->crear_demo();
        $lead                                = $this->crear_lead_agendado($demo);
        $lead->demo_start_time               = '15:00';
        $lead->demo_end_time                 = '16:00';
        $lead->demo_ingreso_token_expira_at  = Carbon::parse('2026-08-20 16:10:00', 'America/Argentina/Buenos_Aires');
        $lead->save();
        $token_antes = $lead->demo_ingreso_token;

        $this->service()->aplicar($lead, $this->paquete($demo, '2026-08-20', '09:00'));

        $lead->refresh();
        $this->assertSame('09:00', $lead->demo_start_time);
        $this->assertSame('10:00', $lead->demo_end_time);
        $this->assertSame($token_antes, $lead->demo_ingreso_token);
        $this->assertSame('2026-08-20 10:10:00', $lead->demo_ingreso_token_expira_at->format('Y-m-d H:i:s'));
    }

    /**
     * 3. Un token REVOCADO no se toca: se revocó a propósito (panel), extenderlo/acortarlo acá lo
     *    reviviría en silencio.
     *
     * @return void
     */
    public function test_un_token_revocado_no_se_ajusta_al_reagendar(): void
    {
        Carbon::setTestNow($this->momento_base());
        $this->fakear_red_exitosa();

        $demo             = $this->crear_demo();
        $lead             = $this->crear_lead_agendado($demo, '2026-08-20 09:00:00');
        $expira_antes     = $lead->demo_ingreso_token_expira_at->format('Y-m-d H:i:s');

        $this->service()->aplicar($lead, $this->paquete($demo, '2026-08-20', '15:00'));

        $lead->refresh();
        $this->assertSame('15:00', $lead->demo_start_time, 'El reagendado en sí tiene que seguir funcionando.');
        $this->assertSame(
            $expira_antes,
            $lead->demo_ingreso_token_expira_at->format('Y-m-d H:i:s'),
            'Un token revocado no se ajusta: se revocó a propósito.'
        );
    }

    /**
     * 4. Un lead que agenda por PRIMERA VEZ (sin token todavía) no dispara ningún aviso a la
     *    instancia por este camino: `calcular_expiracion()` ya lo resuelve cuando el token se
     *    emita de verdad (RunDemoSetupService).
     *
     * @return void
     */
    public function test_agendar_por_primera_vez_no_avisa_a_la_instancia_por_el_token(): void
    {
        Carbon::setTestNow($this->momento_base());
        $this->fakear_red_exitosa();

        $demo = $this->crear_demo();
        $lead = $this->crear_lead_sin_demo();

        $this->service()->aplicar($lead, $this->paquete($demo, '2026-08-20', '12:00'));

        $lead->refresh();
        $this->assertSame('12:00', $lead->demo_start_time);
        $this->assertNull($lead->demo_ingreso_token_expira_at, 'Sin token emitido no hay nada que calcular todavía.');

        Http::assertNotSent(function ($request) {
            return strpos($request->url(), '/api/admin-sync/demo-token') !== false;
        });
    }

    /**
     * 5. Si el aviso a la instancia falla, el reagendado NO se revierte — a propósito, distinto del
     *    panel: acá el agente ya le confirmó (o está por confirmarle) al lead el horario nuevo por
     *    WhatsApp, y revertir dejaría al lead creyendo que tiene un turno que el sistema deshizo.
     *
     * @return void
     */
    public function test_si_el_aviso_a_la_instancia_falla_el_reagendado_no_se_revierte(): void
    {
        Carbon::setTestNow($this->momento_base());

        Http::fake([
            '*/admin-sync/demo-token' => Http::response(['error' => 'instancia caida'], 500),
            '*'                       => Http::response(['ok' => true], 200),
        ]);

        $demo        = $this->crear_demo();
        $lead        = $this->crear_lead_agendado($demo);
        $expira_antes = $lead->demo_ingreso_token_expira_at->format('Y-m-d H:i:s');

        $this->service()->aplicar($lead, $this->paquete($demo, '2026-08-20', '15:00'));

        $lead->refresh();
        $this->assertSame('15:00', $lead->demo_start_time, 'El reagendado no puede revertirse por una falla en el ajuste del token.');
        $this->assertSame('16:00', $lead->demo_end_time);
        $this->assertSame(
            $expira_antes,
            $lead->demo_ingreso_token_expira_at->format('Y-m-d H:i:s'),
            'El vencimiento del token queda como estaba: el servicio ya revierte SU parte al fallar el aviso.'
        );
    }
}

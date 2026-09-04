<?php

namespace Tests\Feature;

use App\Helpers\AppTime;
use App\Models\AdminSetting;
use App\Models\Demo;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\DemoIngresoTokenService;
use App\Services\LeadAiService;
use App\Services\LeadDemoSettings;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * La ventana extendida: el lead que no puede comprometerse a un horario puntual (misión 47).
 *
 * Lo que estos tests fijan, y que no es obvio leyendo el código: la hora de fin la calcula SIEMPRE
 * el servidor. El modelo pide la modalidad con un booleano y nada más — porque ya inventó horarios
 * frente a un lead real (lead #232, 2/7/2026), y darle una hora de fin para escribir reabre esa
 * puerta.
 */
class DemoExtendidaHastaElFinDelDiaTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Ningún test de este archivo sale a la red: el aviso a la instancia se sustituye entero.
        Http::fake([
            '*' => Http::response(['ok' => true], 200),
        ]);

        // Franja de demo de día completo y una grilla previsible, para que los horarios de los
        // tests existan de verdad en la disponibilidad. Son las settings que ya usa el cálculo.
        AdminSetting::set(LeadDemoSettings::KEY_DEMO_HORARIO_LUNES_VIERNES, '00:00-23:59');
        AdminSetting::set(LeadDemoSettings::KEY_DEMO_HORARIO_SABADO, '00:00-23:59');
        AdminSetting::set(LeadDemoSettings::KEY_DEMO_HORARIO_DOMINGO, '00:00-23:59');
        AdminSetting::set(LeadDemoSettings::KEY_FRECUENCIA_SLOTS_MINUTOS, '30');
        AdminSetting::set(LeadDemoSettings::KEY_DURACION_MINUTOS, '60');
        AdminSetting::set(LeadDemoSettings::KEY_GRACIA_MINUTOS_POST, '10');
        AdminSetting::set(LeadDemoSettings::KEY_SETUP_MINUTOS_ANTES, '15');
        AdminSetting::set(LeadDemoSettings::KEY_VENTANA_EXTENDIDA_MAX_HORAS, '6');
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
     * Momento base: un jueves a las 08:00, para que todos los horarios de los tests (10:00, 20:00,
     * 23:30) caigan más adelante en el mismo día.
     *
     * @return Carbon
     */
    private function momento_base(): Carbon
    {
        return Carbon::parse('2026-08-20 08:00:00', 'America/Argentina/Buenos_Aires');
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
     * Lead de la dinámica nueva, sin turno.
     *
     * @param string $status
     *
     * @return Lead
     */
    private function crear_lead(string $status = 'calificado'): Lead
    {
        $lead = new Lead();
        $lead->uuid         = (string) Str::uuid();
        $lead->contact_name = 'Lead de prueba';
        $lead->company_name = 'Empresa de prueba';
        $lead->status       = $status;
        $lead->save();

        // Después del save: el hook `creating` estampa la dinámica por defecto.
        $lead->demo_experiencia = Lead::EXPERIENCIA_NUEVA;
        $lead->save();

        return $lead->refresh();
    }

    /**
     * Instancia del service con `apply_parsed_response()` expuesto: es `protected` y es justo el
     * método donde vive la persistencia que hay que probar. La subclase no cambia ninguna lógica.
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
     * El paquete mínimo que `apply_parsed_response()` acepta para agendar.
     *
     * @param Demo        $demo
     * @param string      $fecha
     * @param string      $hora
     * @param bool        $ventana_extendida
     * @param string|null $demo_end_time_del_modelo Para el test 3: lo que el modelo NO debería mandar.
     *
     * @return array<string, mixed>
     */
    private function paquete(Demo $demo, string $fecha, string $hora, bool $ventana_extendida, ?string $demo_end_time_del_modelo = null): array
    {
        $agendar = [
            'demo_id'         => $demo->id,
            'demo_date'       => $fecha,
            'demo_start_time' => $hora,
        ];

        if ($ventana_extendida) {
            $agendar['ventana_extendida'] = true;
        }

        if ($demo_end_time_del_modelo !== null) {
            $agendar['demo_end_time'] = $demo_end_time_del_modelo;
        }

        return [
            'mensaje_sugerido' => 'Te dejo la demo lista.',
            'estado_sugerido'  => 'demo_agendada',
            'agendar_demo'     => $agendar,
        ];
    }

    /**
     * 1. Ventana extendida con inicio 20:00 → el fin es 23:59 (el corte del día, no inicio + 6h) y
     *    `demo_flexible` queda prendida.
     *
     * @return void
     */
    public function test_la_ventana_extendida_corta_al_fin_del_dia(): void
    {
        Carbon::setTestNow($this->momento_base());

        $demo = $this->crear_demo();
        $lead = $this->crear_lead();

        $this->service()->aplicar($lead, $this->paquete($demo, '2026-08-20', '20:00', true));

        $lead->refresh();
        $this->assertSame('20:00', $lead->demo_start_time);
        $this->assertSame('23:59', $lead->demo_end_time);
        $this->assertTrue((bool) $lead->demo_flexible);
    }

    /**
     * 2. La misma ventana con inicio 10:00 → 16:00, no 23:59. Es la guarda de las 6 horas: sin
     *    ella, un lead que dice "a partir de las 10" bloquea la instancia el día entero.
     *
     * @return void
     */
    public function test_el_tope_de_seis_horas_manda_cuando_el_dia_da_para_mas(): void
    {
        Carbon::setTestNow($this->momento_base());

        $demo = $this->crear_demo();
        $lead = $this->crear_lead();

        $this->service()->aplicar($lead, $this->paquete($demo, '2026-08-20', '10:00', true));

        $lead->refresh();
        $this->assertSame('16:00', $lead->demo_end_time);
        $this->assertTrue((bool) $lead->demo_flexible);
    }

    /**
     * 3. 🔴 El `demo_end_time` que invente el modelo se descarta: el guardado es el que calculó el
     *    servidor. Es la regla que existe porque el agente ya inventó horarios en producción.
     *
     * @return void
     */
    public function test_el_demo_end_time_del_modelo_se_descarta(): void
    {
        Carbon::setTestNow($this->momento_base());

        $demo = $this->crear_demo();
        $lead = $this->crear_lead();

        $this->service()->aplicar($lead, $this->paquete($demo, '2026-08-20', '20:00', true, '03:00'));

        $lead->refresh();
        $this->assertSame('23:59', $lead->demo_end_time);
        $this->assertNotSame('03:00', $lead->demo_end_time);
    }

    /**
     * 4. Con la ventana del test 1 vigente, otro lead pidiendo las 23:00 en la MISMA instancia no
     *    encuentra ese horario disponible. Es el requisito principal de la misión: que nadie le
     *    pise la instancia al lead que tiene la ventana reservada.
     *
     * @return void
     */
    public function test_la_ventana_reservada_le_saca_el_horario_a_otro_lead(): void
    {
        Carbon::setTestNow($this->momento_base());

        $demo = $this->crear_demo();
        $lead = $this->crear_lead();

        $this->service()->aplicar($lead, $this->paquete($demo, '2026-08-20', '20:00', true));

        // Otro lead consultando disponibilidad de la misma instancia, ese mismo día.
        $otro       = $this->crear_lead();
        $snapshot   = null;
        $config     = null;
        $ventanas   = null;
        $datos      = $this->service()->build_availability_json(
            LeadAiService::DIAS_DISPONIBILIDAD,
            $snapshot,
            '2026-08-20',
            $otro->id,
            true,
            null,
            $config,
            $ventanas
        );

        $slots = $this->slots_de($datos, $demo->id, '2026-08-20');

        $this->assertNotContains('23:00', $slots);
        // Y el control: un horario de la mañana, fuera de la ventana, sigue disponible.
        $this->assertContains('09:00', $slots);
    }

    /**
     * 5. Si entre la oferta y la confirmación otro lead ocupó un horario de adentro de la ventana,
     *    no se agenda: cae por el camino de slot inválido y `demo_end_time` no queda escrito a
     *    medias.
     *
     * @return void
     */
    public function test_si_la_ventana_ya_no_entra_no_se_agenda(): void
    {
        Carbon::setTestNow($this->momento_base());

        $demo = $this->crear_demo();

        // El que se metió en el medio: turno normal a las 22:00, adentro de la ventana 20:00-23:59.
        $intruso = $this->crear_lead('demo_agendada');
        $intruso->demo_id         = $demo->id;
        $intruso->demo_date       = '2026-08-20';
        $intruso->demo_start_time = '22:00';
        $intruso->demo_end_time   = '23:00';
        $intruso->save();

        $lead = $this->crear_lead();
        $this->service()->aplicar($lead, $this->paquete($demo, '2026-08-20', '20:00', true));

        $lead->refresh();
        $this->assertNotSame('23:59', (string) $lead->demo_end_time);
        $this->assertFalse((bool) $lead->demo_flexible);
        $this->assertNotSame('demo_agendada', $lead->status);
    }

    /**
     * 5bis. 🔴 EL CAMINO REAL, y es el que estaba roto: todo `agendar_demo` pasa por el panel de
     *       verificación antes de enviarse (el auto-send lo corta explícitamente cuando el paquete
     *       trae un agendamiento). El panel reconstruye `agendar_demo` clave por clave, así que una
     *       clave que no viaje se pierde — y el backend pisaba el objeto entero con lo que llegaba.
     *       Resultado: el lead recibía el mensaje prometiéndole la ventana y la base le guardaba una
     *       demo normal de una hora.
     *
     *       Este test simula exactamente eso: un panel de una versión del SPA que NO manda la clave.
     *       La modalidad tiene que sobrevivir igual.
     *
     * @return void
     */
    public function test_la_ventana_sobrevive_al_panel_de_verificacion(): void
    {
        Carbon::setTestNow($this->momento_base());

        $demo = $this->crear_demo();
        $lead = $this->crear_lead();

        $paquete = $this->paquete($demo, '2026-08-20', '20:00', true);

        // El mensaje pendiente, como lo deja el flujo antes de la aprobación humana.
        $mensaje = LeadMessage::create([
            'lead_id'         => $lead->id,
            'sender'          => 'agente',
            'content'         => 'Te reservo la instancia hasta las 23:59, entrá cuando puedas.',
            'status'          => 'sugerido',
            'is_followup'     => false,
            'pending_actions' => $paquete,
        ]);

        /* Lo que manda el panel: las tres claves de siempre, SIN ventana_extendida. */
        $final_actions = [
            'estado_sugerido' => 'demo_agendada',
            'agendar_demo'    => [
                'demo_id'         => $demo->id,
                'demo_date'       => '2026-08-20',
                'demo_start_time' => '20:00',
            ],
            'forzar_slot' => false,
        ];

        $this->service()->apply_pending_actions($mensaje, $final_actions);

        $lead->refresh();
        $this->assertSame('23:59', $lead->demo_end_time, 'La ventana se perdió al pasar por el panel: el lead quedó con una demo normal.');
        $this->assertTrue((bool) $lead->demo_flexible);
    }

    /**
     * 6. El lead flexible que entra 23:50 no se queda sin sesión a los 19 minutos: al ingresar, el
     *    vencimiento se corre al menos una demo completa, con el MISMO valor de token.
     *
     * @return void
     */
    public function test_al_ingresar_el_token_se_extiende_sin_cambiar_de_valor(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20 23:50:00', 'America/Argentina/Buenos_Aires'));

        $demo = $this->crear_demo();
        $lead = $this->crear_lead('demo_agendada');
        $lead->demo_id                      = $demo->id;
        $lead->demo_date                    = '2026-08-20';
        $lead->demo_start_time              = '20:00';
        $lead->demo_end_time                = '23:59';
        $lead->demo_flexible                = true;
        $lead->demo_setup_status            = 'exitoso';
        $lead->demo_ingreso_token           = Str::random(64);
        $lead->demo_ingreso_token_expira_at = Carbon::parse('2026-08-21 00:09:00', 'America/Argentina/Buenos_Aires');
        $lead->save();

        $token_antes = $lead->demo_ingreso_token;

        $respuesta = $this->postJson('/api/demo-experiencia/' . $lead->uuid . '/ingresar');
        $respuesta->assertStatus(200);

        $lead->refresh();
        $this->assertSame($token_antes, $lead->demo_ingreso_token, 'El token cambió de valor: la URL que el lead está abriendo quedaría inválida.');
        $this->assertTrue(
            $lead->demo_ingreso_token_expira_at->gte(AppTime::now()->copy()->addMinutes(70)),
            'El vencimiento no cubre una demo completa desde el ingreso.'
        );

        // Y a la instancia se le avisó con ese mismo token, no con uno nuevo.
        Http::assertSent(function ($request) use ($token_antes) {
            if (strpos($request->url(), '/api/admin-sync/demo-token') === false) {
                return false;
            }
            $data = $request->data();

            return isset($data['token']) && $data['token'] === $token_antes;
        });
    }

    /**
     * 7/8/8bis. 🔴 Los tres tests que vivían acá (el flexible fuera del check de ingreso, el no
     * flexible que lo sigue tomando, y el flag manual de un lead actual que no lo saca del ciclo)
     * probaban `leads:check-demo-ingress` -- el comando que enviaba el mensaje de WhatsApp
     * "¿pudiste entrar?" y transicionaba a `ingresando_demo`. Ese comando se borró entero en la
     * misión demo-v2-estados-automaticos (4/9/2026): el ingreso real ahora se detecta solo, sin
     * mandarle ningún mensaje al lead (ver DemoEventosController::avanzar_pipeline_por_ingreso_real).
     * No es un caso de "ajustar una aserción para que pase": es un comportamiento que dejó de
     * existir. La preocupación de fondo de esos tres tests -- que la ventana extendida (flexible +
     * dinámica nueva) quede afuera de la automatización de ingreso, y que un flexible manual de la
     * dinámica actual SÍ siga adentro -- se re-cubre sobre el mecanismo nuevo en
     * tests/Feature/CheckDemoIngresoTimeoutTest.php.
     */

    /**
     * 8ter. Y por el mismo motivo, agendar por WhatsApp no le puede APAGAR ese checkbox a un lead de
     *       la dinámica actual: si se lo apagara, volvería a reservar ventana de closer automática
     *       — el "bloqueo fantasma" que el fix del 2/7/2026 eliminó.
     *
     * @return void
     */
    public function test_agendar_no_pisa_el_flag_manual_de_un_lead_actual(): void
    {
        Carbon::setTestNow($this->momento_base());

        $demo = $this->crear_demo();
        $lead = $this->crear_lead();
        $lead->demo_experiencia = Lead::EXPERIENCIA_ACTUAL;
        $lead->demo_flexible    = true;
        $lead->save();

        $this->service()->aplicar($lead, $this->paquete($demo, '2026-08-20', '10:00', false));

        $this->assertTrue(
            (bool) $lead->refresh()->demo_flexible,
            'El agendamiento apagó el flag manual de un lead de la dinámica actual.'
        );
    }

    /**
     * 9. Un inicio desde el cual no entra una demo completa antes de las 23:59 no admite ventana
     *    extendida, así que no figura en el mapa que se le ofrece al agente.
     *
     * @return void
     */
    public function test_un_inicio_muy_tarde_no_admite_ventana_extendida(): void
    {
        Carbon::setTestNow($this->momento_base());

        $demo = $this->crear_demo();
        $lead = $this->crear_lead();

        $snapshot = null;
        $config   = null;
        $ventanas = null;
        $this->service()->build_availability_json(
            LeadAiService::DIAS_DISPONIBILIDAD,
            $snapshot,
            '2026-08-20',
            $lead->id,
            true,
            null,
            $config,
            $ventanas
        );

        $por_slot = $this->ventanas_de($ventanas, $demo->id, '2026-08-20');

        // 23:30 no entra: quedan 29 minutos hasta las 23:59 y una demo son 60 + 10 de gracia.
        $this->assertArrayNotHasKey('23:30', $por_slot);
        // 20:00 sí, y hasta el fin del día.
        $this->assertArrayHasKey('20:00', $por_slot);
        $this->assertSame('23:59', $por_slot['20:00']);
    }

    /**
     * Slots de una demo en una fecha, buscando la clave que termina en ese Y-m-d.
     *
     * @param array<string, mixed> $datos
     * @param int                  $demo_id
     * @param string               $fecha
     *
     * @return array<int, string>
     */
    private function slots_de(array $datos, int $demo_id, string $fecha): array
    {
        $por_fecha = isset($datos['demos'][$demo_id]) ? $datos['demos'][$demo_id] : [];
        foreach ($por_fecha as $label => $slots) {
            if (substr((string) $label, -strlen($fecha)) === $fecha) {
                return array_map('strval', $slots);
            }
        }

        return [];
    }

    /**
     * Ventanas extendidas de una demo en una fecha.
     *
     * @param mixed  $ventanas
     * @param int    $demo_id
     * @param string $fecha
     *
     * @return array<string, string>
     */
    private function ventanas_de($ventanas, int $demo_id, string $fecha): array
    {
        $por_fecha = (is_array($ventanas) && isset($ventanas[$demo_id])) ? $ventanas[$demo_id] : [];
        foreach ($por_fecha as $label => $por_slot) {
            if (substr((string) $label, -strlen($fecha)) === $fecha) {
                return $por_slot;
            }
        }

        return [];
    }
}

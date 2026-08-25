<?php

namespace Tests\Feature;

use App\Helpers\AppTime;
use App\Models\Admin;
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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * El fin de demo editable (tarea 62): la palanca HUMANA sobre `demo_end_time`, continuación de la
 * misión 47 (que le prohibió al modelo escribir ese campo — restricción que acá NO se afloja).
 *
 * Dos frentes:
 * - El endpoint del panel (POST lead/{id}/demo-end-time): valida server-side, corre el vencimiento
 *   del token con el MISMO valor y reprograma el check de fin con el mecanismo del grupo 307.
 * - El agente negocia la franja ("de 12 a 18") con `ventana_hasta`, un PEDIDO que el servidor
 *   valida contra el tope de la 47 y escribe él — nunca el modelo directo.
 */
class FinDeDemoEditableTest extends TestCase
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

        // Las mismas settings previsibles que la suite de la misión 47: franja de día completo,
        // slots cada 30, demo de 60 con 10 de gracia y 15 de setup, ventana de hasta 6 horas.
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
     * Momento base: un jueves a las 08:00, con todos los horarios de los tests por delante.
     *
     * @return Carbon
     */
    private function momento_base(): Carbon
    {
        return Carbon::parse('2026-08-20 08:00:00', 'America/Argentina/Buenos_Aires');
    }

    /**
     * Admin autenticado por Sanctum, como exige el grupo de rutas del panel.
     *
     * @return Admin
     */
    private function autenticar_admin(): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Admin de prueba';
        $admin->email    = 'admin+' . Str::random(6) . '@test.local';
        $admin->password = bcrypt('secret');
        $admin->save();

        Sanctum::actingAs($admin);

        return $admin;
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
     * Lead de la dinámica nueva con una demo agendada 10:00–11:00 y token de ingreso emitido.
     *
     * @param string $status
     *
     * @return Lead
     */
    private function crear_lead_agendado(string $status = 'demo_agendada'): Lead
    {
        $demo = $this->crear_demo();

        $lead = new Lead();
        $lead->uuid         = (string) Str::uuid();
        $lead->contact_name = 'Lead de prueba';
        $lead->company_name = 'Empresa de prueba';
        $lead->status       = $status;
        $lead->save();

        $lead->demo_experiencia             = Lead::EXPERIENCIA_NUEVA;
        $lead->demo_id                      = $demo->id;
        $lead->demo_date                    = '2026-08-20';
        $lead->demo_start_time              = '10:00';
        $lead->demo_end_time                = '11:00';
        $lead->demo_ingreso_token           = Str::random(64);
        $lead->demo_ingreso_token_expira_at = Carbon::parse('2026-08-20 11:10:00', 'America/Argentina/Buenos_Aires');
        $lead->save();

        return $lead->refresh();
    }

    /**
     * Lead de la dinámica nueva sin demo, para los caminos del agente.
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
     * Instancia del service con los dos métodos protegidos que estos tests necesitan expuestos.
     * La subclase no cambia ninguna lógica (mismo recurso que la suite de la 47).
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
     * Paquete mínimo de agendamiento del agente, con la franja opcional de la tarea 62.
     *
     * @param Demo        $demo
     * @param string      $fecha
     * @param string      $hora
     * @param bool        $ventana_extendida
     * @param string|null $ventana_hasta            La franja negociada (tarea 62), o null.
     * @param string|null $demo_end_time_del_modelo Lo que el modelo NO debería mandar.
     *
     * @return array<string, mixed>
     */
    private function paquete(Demo $demo, string $fecha, string $hora, bool $ventana_extendida, ?string $ventana_hasta = null, ?string $demo_end_time_del_modelo = null): array
    {
        $agendar = [
            'demo_id'         => $demo->id,
            'demo_date'       => $fecha,
            'demo_start_time' => $hora,
        ];

        if ($ventana_extendida) {
            $agendar['ventana_extendida'] = true;
        }
        if ($ventana_hasta !== null) {
            $agendar['ventana_hasta'] = $ventana_hasta;
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
     * 1. (a)+(c) El caso del pedido de Lucas: demo de 10 a 11, el fin se mueve a 15:00 desde el
     *    panel. El campo queda escrito, el token corre su vencimiento a 15:10 SIN cambiar de
     *    valor (la URL que ya viajó por WhatsApp sigue sirviendo, y a las 14:50 el lead entra), y
     *    el check de fin queda reprogramado a las 15:00 con el mecanismo del grupo 307.
     *
     * @return void
     */
    public function test_extender_el_fin_corre_el_token_y_reprograma_el_check(): void
    {
        Carbon::setTestNow($this->momento_base());
        $this->autenticar_admin();

        $lead        = $this->crear_lead_agendado();
        $token_antes = $lead->demo_ingreso_token;

        $respuesta = $this->postJson('/api/admin/lead/' . $lead->id . '/demo-end-time', ['demo_end_time' => '15:00']);
        $respuesta->assertStatus(200);

        $lead->refresh();
        $this->assertSame('15:00', $lead->demo_end_time);
        $this->assertSame($token_antes, $lead->demo_ingreso_token, 'El token cambió de valor: el link que ya tiene el lead quedaría inválido.');
        $this->assertSame(
            '2026-08-20 15:10:00',
            $lead->demo_ingreso_token_expira_at->format('Y-m-d H:i:s'),
            'El vencimiento del token no acompañó al fin editado (fin + gracia).'
        );
        $this->assertNotNull($lead->demo_fin_check_reprogramado_para, 'El check de fin no se reprogramó: preguntaría "¿terminaste?" a las 11:00.');
        $this->assertSame('2026-08-20 15:00:00', $lead->demo_fin_check_reprogramado_para->format('Y-m-d H:i:s'));

        // A la instancia se le avisó con el MISMO token, no con uno nuevo.
        Http::assertSent(function ($request) use ($token_antes) {
            if (strpos($request->url(), '/api/admin-sync/demo-token') === false) {
                return false;
            }
            $data = $request->data();

            return isset($data['token']) && $data['token'] === $token_antes
                && isset($data['expira_at']) && $data['expira_at'] === '2026-08-20 15:10:00';
        });
    }

    /**
     * 2. El fin que se ACORTA (15:00 → 12:00 antes de que empiece la demo): el token acorta su
     *    vencimiento en sincronía, con el mismo valor. La decisión documentada de la tarea: un fin
     *    que se adelanta con un token que vale hasta la hora vieja dejaría el link entrando a una
     *    demo que ya no existe.
     *
     * @return void
     */
    public function test_acortar_el_fin_acorta_el_token(): void
    {
        Carbon::setTestNow($this->momento_base());
        $this->autenticar_admin();

        $lead                               = $this->crear_lead_agendado();
        $lead->demo_end_time                = '15:00';
        $lead->demo_ingreso_token_expira_at = Carbon::parse('2026-08-20 15:10:00', 'America/Argentina/Buenos_Aires');
        $lead->save();
        $token_antes = $lead->demo_ingreso_token;

        $respuesta = $this->postJson('/api/admin/lead/' . $lead->id . '/demo-end-time', ['demo_end_time' => '12:00']);
        $respuesta->assertStatus(200);

        $lead->refresh();
        $this->assertSame('12:00', $lead->demo_end_time);
        $this->assertSame($token_antes, $lead->demo_ingreso_token);
        $this->assertSame('2026-08-20 12:10:00', $lead->demo_ingreso_token_expira_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-20 12:00:00', $lead->demo_fin_check_reprogramado_para->format('Y-m-d H:i:s'));
    }

    /**
     * 3. El acorte NUNCA deja el vencimiento en el pasado (piso now + gracia): con el lead adentro
     *    de la demo, un vencimiento vencido le corta la sesión en su próximo click (la instancia
     *    valida vigencia en cada request — middleware DemoSessionVigente de empresa-api). Con el
     *    piso, nadie más entra pero el que está adentro tiene la gracia para cerrar. Y el check de
     *    fin apunta a `now`, no al pasado: apuntarlo al pasado lo dejaría trabado para siempre
     *    (la ventana del comando es de ±2 minutos).
     *
     * @return void
     */
    public function test_acortar_a_una_hora_pasada_no_deja_el_vencimiento_en_el_pasado(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20 14:00:00', 'America/Argentina/Buenos_Aires'));
        $this->autenticar_admin();

        $lead                               = $this->crear_lead_agendado('demo_en_curso');
        $lead->demo_end_time                = '15:00';
        $lead->demo_ingreso_token_expira_at = Carbon::parse('2026-08-20 15:10:00', 'America/Argentina/Buenos_Aires');
        $lead->save();

        $respuesta = $this->postJson('/api/admin/lead/' . $lead->id . '/demo-end-time', ['demo_end_time' => '12:00']);
        $respuesta->assertStatus(200);

        $lead->refresh();
        $this->assertSame('12:00', $lead->demo_end_time);
        // Piso: now (14:00) + gracia (10) = 14:10 — nunca las 12:10, que ya pasaron.
        $this->assertSame('2026-08-20 14:10:00', $lead->demo_ingreso_token_expira_at->format('Y-m-d H:i:s'));
        // Y el check de fin dispara en la próxima corrida en vez de quedar trabado.
        $this->assertSame('2026-08-20 14:00:00', $lead->demo_fin_check_reprogramado_para->format('Y-m-d H:i:s'));
    }

    /**
     * 4. Un fin de otro día, ilegible o que no sea posterior al inicio es 422 y no toca nada: la
     *    demo nunca cruza de día (mismo criterio y techo 23:59 de la misión 47).
     *
     * @return void
     */
    public function test_el_fin_de_otro_dia_o_ilegible_es_422(): void
    {
        Carbon::setTestNow($this->momento_base());
        $this->autenticar_admin();

        $lead = $this->crear_lead_agendado();

        // Con fecha: no es una hora del día.
        $this->postJson('/api/admin/lead/' . $lead->id . '/demo-end-time', ['demo_end_time' => '2026-08-21 15:00'])
            ->assertStatus(422);
        // Fuera de rango.
        $this->postJson('/api/admin/lead/' . $lead->id . '/demo-end-time', ['demo_end_time' => '25:00'])
            ->assertStatus(422);
        // Anterior al inicio (sería cruzar de día).
        $this->postJson('/api/admin/lead/' . $lead->id . '/demo-end-time', ['demo_end_time' => '09:00'])
            ->assertStatus(422);

        $this->assertSame('11:00', $lead->refresh()->demo_end_time, 'Un fin inválido no puede haber tocado el campo.');
    }

    /**
     * 5. Lead sin demo vigente: 422. No hay fin que editar.
     *
     * @return void
     */
    public function test_lead_sin_demo_es_422(): void
    {
        Carbon::setTestNow($this->momento_base());
        $this->autenticar_admin();

        $lead = $this->crear_lead_sin_demo();

        $this->postJson('/api/admin/lead/' . $lead->id . '/demo-end-time', ['demo_end_time' => '15:00'])
            ->assertStatus(422);
    }

    /**
     * 6. (b) La grilla del agente respeta el fin editado: con la demo de 10 a 11 extendida a las
     *    15:00 desde el panel, otro lead NO puede agendar las 13:00 en la misma instancia — y las
     *    16:00, ya fuera del rango ocupado (fin + gracia + setup del siguiente), siguen libres.
     *    El bloqueo sale de `demo_end_time` real (LeadAiService::load_blocked_ranges_by_demo), no
     *    de inicio + duración fija.
     *
     * @return void
     */
    public function test_el_fin_editado_bloquea_la_grilla_hasta_esa_hora(): void
    {
        Carbon::setTestNow($this->momento_base());
        $this->autenticar_admin();

        $lead = $this->crear_lead_agendado();
        $this->postJson('/api/admin/lead/' . $lead->id . '/demo-end-time', ['demo_end_time' => '15:00'])
            ->assertStatus(200);

        $otro     = $this->crear_lead_sin_demo();
        $snapshot = null;
        $config   = null;
        $ventanas = null;
        $datos    = $this->service()->build_availability_json(
            LeadAiService::DIAS_DISPONIBILIDAD,
            $snapshot,
            '2026-08-20',
            $otro->id,
            true,
            null,
            $config,
            $ventanas
        );

        $slots = $this->slots_de($datos, (int) $lead->demo_id, '2026-08-20');

        $this->assertNotContains('13:00', $slots, 'Las 13:00 tendrían que estar ocupadas hasta el fin editado (15:00).');
        $this->assertContains('16:00', $slots, 'Las 16:00 quedan fuera del rango ocupado y tienen que seguir libres.');
    }

    /**
     * 7. Reenviar el mismo fin (doble click) no escribe, no avisa a la instancia y no ensucia el
     *    hilo: mismo patrón anti-eventos-vacíos que demo-experiencia.
     *
     * @return void
     */
    public function test_reenviar_el_mismo_fin_no_avisa_a_la_instancia(): void
    {
        Carbon::setTestNow($this->momento_base());
        $this->autenticar_admin();

        $lead = $this->crear_lead_agendado();

        $this->postJson('/api/admin/lead/' . $lead->id . '/demo-end-time', ['demo_end_time' => '11:00'])
            ->assertStatus(200);

        Http::assertNothingSent();
        $this->assertSame(0, LeadMessage::where('lead_id', $lead->id)->count(), 'Un no-cambio no deja eventos en el hilo.');
    }

    /**
     * 8. (e) El agente agenda la franja exacta que negoció: "de 12 a 18" viaja como inicio 12:00 +
     *    ventana_extendida + ventana_hasta 18:00, y el SERVIDOR valida contra su tope (12 + 6h =
     *    18:00) y escribe ese fin. El modelo sigue sin escribir demo_end_time.
     *
     * @return void
     */
    public function test_el_agente_agenda_la_franja_pedida(): void
    {
        Carbon::setTestNow($this->momento_base());

        $demo = $this->crear_demo();
        $lead = $this->crear_lead_sin_demo();

        $this->service()->aplicar($lead, $this->paquete($demo, '2026-08-20', '12:00', true, '18:00'));

        $lead->refresh();
        $this->assertSame('12:00', $lead->demo_start_time);
        $this->assertSame('18:00', $lead->demo_end_time);
        $this->assertTrue((bool) $lead->demo_flexible);
        $this->assertSame('demo_agendada', $lead->status);
    }

    /**
     * 8bis. Una franja más corta que el tope también vale tal cual: "hasta las 15" con tope 18:00
     *       reserva hasta las 15:00, no hasta el tope.
     *
     * @return void
     */
    public function test_una_franja_mas_corta_que_el_tope_se_respeta(): void
    {
        Carbon::setTestNow($this->momento_base());

        $demo = $this->crear_demo();
        $lead = $this->crear_lead_sin_demo();

        $this->service()->aplicar($lead, $this->paquete($demo, '2026-08-20', '12:00', true, '15:00'));

        $lead->refresh();
        $this->assertSame('15:00', $lead->demo_end_time);
        $this->assertTrue((bool) $lead->demo_flexible);
    }

    /**
     * 9. (e) Un ventana_hasta pasado el tope (inicio 10:00 → tope 16:00; pide 17:00) cae por el
     *    camino de slot inválido que dispara la reoferta: nada queda agendado y el lead vuelve a
     *    solicita_disponibilidad. Prohibido recortarlo en silencio: el mensaje ya prometió la
     *    franja.
     *
     * @return void
     */
    public function test_ventana_hasta_pasado_el_tope_se_descarta_por_slot_invalido(): void
    {
        Carbon::setTestNow($this->momento_base());

        $demo = $this->crear_demo();
        $lead = $this->crear_lead_sin_demo();

        $this->service()->aplicar($lead, $this->paquete($demo, '2026-08-20', '10:00', true, '17:00'));

        $lead->refresh();
        $this->assertNull($lead->demo_date, 'No tendría que haber quedado nada agendado.');
        $this->assertNull($lead->demo_end_time);
        $this->assertFalse((bool) $lead->demo_flexible);
        $this->assertNotSame('demo_agendada', $lead->status);
    }

    /**
     * 10. (e) Un ventana_hasta "de otro día" (cruza la medianoche: inicio 20:00, hasta 02:00) es el
     *     mismo error: la demo nunca cruza de día. Camino de slot inválido, reoferta.
     *
     * @return void
     */
    public function test_ventana_hasta_que_cruza_la_medianoche_se_descarta(): void
    {
        Carbon::setTestNow($this->momento_base());

        $demo = $this->crear_demo();
        $lead = $this->crear_lead_sin_demo();

        $this->service()->aplicar($lead, $this->paquete($demo, '2026-08-20', '20:00', true, '02:00'));

        $lead->refresh();
        $this->assertNull($lead->demo_date);
        $this->assertNotSame('demo_agendada', $lead->status);
    }

    /**
     * 11. Compatibilidad hacia atrás: ventana extendida SIN ventana_hasta sigue exactamente como la
     *     dejó la misión 47 — el fin es el tope automático del servidor (10:00 + 6h = 16:00).
     *
     * @return void
     */
    public function test_ventana_extendida_sin_ventana_hasta_sigue_como_la_47(): void
    {
        Carbon::setTestNow($this->momento_base());

        $demo = $this->crear_demo();
        $lead = $this->crear_lead_sin_demo();

        $this->service()->aplicar($lead, $this->paquete($demo, '2026-08-20', '10:00', true));

        $lead->refresh();
        $this->assertSame('16:00', $lead->demo_end_time);
        $this->assertTrue((bool) $lead->demo_flexible);
    }

    /**
     * 12. La franja negociada sobrevive al panel de verificación (misma lección que el bloqueante 1
     *     de la 47): un SPA que no conozca la clave no la manda, y el backend la conserva del
     *     paquete original en vez de degradar al tope automático en silencio.
     *
     * @return void
     */
    public function test_la_franja_negociada_sobrevive_al_panel_de_verificacion(): void
    {
        Carbon::setTestNow($this->momento_base());

        $demo = $this->crear_demo();
        $lead = $this->crear_lead_sin_demo();

        $paquete = $this->paquete($demo, '2026-08-20', '12:00', true, '15:00');

        $mensaje = LeadMessage::create([
            'lead_id'         => $lead->id,
            'sender'          => 'agente',
            'content'         => 'Te reservo la instancia hasta las 15, entrá cuando puedas.',
            'status'          => 'sugerido',
            'is_followup'     => false,
            'pending_actions' => $paquete,
        ]);

        /* Lo que manda un panel viejo: sin ventana_extendida NI ventana_hasta. */
        $final_actions = [
            'estado_sugerido' => 'demo_agendada',
            'agendar_demo'    => [
                'demo_id'         => $demo->id,
                'demo_date'       => '2026-08-20',
                'demo_start_time' => '12:00',
            ],
            'forzar_slot' => false,
        ];

        $this->service()->apply_pending_actions($mensaje, $final_actions);

        $lead->refresh();
        $this->assertSame('15:00', $lead->demo_end_time, 'La franja negociada se perdió al pasar por el panel.');
        $this->assertTrue((bool) $lead->demo_flexible);
    }

    /**
     * 13. (d) El descarte-y-log del `demo_end_time` crudo del modelo sigue EXACTO con la franja en
     *     juego: si el modelo manda demo_end_time además de ventana_hasta, el que se escribe es el
     *     ventana_hasta validado por el servidor — jamás el campo crudo.
     *
     * @return void
     */
    public function test_el_demo_end_time_crudo_del_modelo_se_sigue_descartando(): void
    {
        Carbon::setTestNow($this->momento_base());

        $demo = $this->crear_demo();
        $lead = $this->crear_lead_sin_demo();

        $this->service()->aplicar($lead, $this->paquete($demo, '2026-08-20', '12:00', true, '18:00', '03:00'));

        $lead->refresh();
        $this->assertSame('18:00', $lead->demo_end_time, 'El fin tiene que ser el ventana_hasta validado, no el demo_end_time crudo.');
        $this->assertNotSame('03:00', $lead->demo_end_time);
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
}

<?php

namespace Tests\Feature\FlujoLead;

use App\Exceptions\SinDemoLibreException;
use App\Helpers\AppTime;
use App\Mail\LeadDemoAccesoMail;
use App\Models\AdminSetting;
use App\Models\AiSystemPrompt;
use App\Models\Demo;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\SyncedGithubFile;
use App\Services\DemoDirectaService;
use App\Services\LeadAiService;
use App\Services\LeadDemoSettings;
use App\Services\LeadWhatsappOnboardingSettings;
use App\Services\WhatsappProtocolService;
use Carbon\Carbon;
use Database\Seeders\LeadPipelineStatusGroupOrderSeeder;
use Database\Seeders\LeadPipelineStatusSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `agendar_demo: {dia, hora}` (misión flujo-lead-landing-closer, 17/10/2026): la demo directa para el
 * momento concreto que nombró el lead.
 *
 *  - Generación: el paquete se normaliza con el instante resuelto por el servidor y la previsión de
 *    instancia para ESA ventana; el texto tiene que nombrar la hora, si no se retiene; un día u hora
 *    ilegibles sacan la acción y retienen.
 *  - Aplicación: se asigna la instancia libre para la ventana que arranca ahí, con el turno escrito y
 *    los dos checks prendidos (D9); mínimo quince minutos desde ahora; sin instancia, texto fijo y
 *    `solicita_disponibilidad`.
 */
class AgendarDemoConDiaYHoraTest extends TestCase
{
    use DatabaseTransactions;

    /** El "hoy": martes 8/9/2026 a las 10:00. */
    const AHORA = '2026-09-08 10:00:00';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::AHORA, DemoDirectaService::TZ));
        Queue::fake();
        Mail::fake();

        /* El catálogo de estados con su orden real (ver GateDeAprobacionTest::setUp). */
        $this->seed(LeadPipelineStatusSeeder::class);
        $this->seed(LeadPipelineStatusGroupOrderSeeder::class);

        AdminSetting::set(LeadDemoSettings::KEY_DURACION_MINUTOS, '60');
        AdminSetting::set(LeadDemoSettings::KEY_GRACIA_MINUTOS_POST, '10');
        AdminSetting::set(LeadDemoSettings::KEY_SETUP_MINUTOS_ANTES, '15');
        AdminSetting::set(LeadDemoSettings::KEY_VENTANA_EXTENDIDA_MAX_HORAS, '2');
        AdminSetting::set(LeadDemoSettings::KEY_BLOQUEO_REAL_MINUTOS, '120');
        AdminSetting::set(LeadDemoSettings::KEY_DEMO_HORARIO_LUNES_VIERNES, '00:00-23:59');
        AdminSetting::set(LeadDemoSettings::KEY_DEMO_HORARIO_SABADO, '00:00-23:59');
        AdminSetting::set(LeadDemoSettings::KEY_DEMO_HORARIO_DOMINGO, '00:00-23:59');
        AdminSetting::set(LeadWhatsappOnboardingSettings::KEY_AUTO_ACTIVAR_VERIFICACION_AL_SOLICITAR_DISPONIBILIDAD, '1');
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /* La ventana                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * (1) ventana_para(): arranca donde se le pide, dos horas, cortada a las 23:59, y `cabe` dice si
     *     entra una demo entera.
     *
     * @return void
     */
    public function test_ventana_para_arranca_en_el_inicio_pedido(): void
    {
        $servicio = new DemoDirectaService();

        $ventana = $servicio->ventana_para(Carbon::parse('2026-09-09 10:00', DemoDirectaService::TZ));
        $this->assertSame('2026-09-09 10:00', $ventana['inicio']->format('Y-m-d H:i'));
        $this->assertSame('2026-09-09 12:00', $ventana['fin']->format('Y-m-d H:i'));
        $this->assertTrue($ventana['cabe']);

        $tarde = $servicio->ventana_para(Carbon::parse('2026-09-09 23:30', DemoDirectaService::TZ));
        $this->assertSame('2026-09-09 23:59', $tarde['fin']->format('Y-m-d H:i'));
        $this->assertFalse($tarde['cabe']);

        /* ventana_desde() sigue siendo "ahora + 10", sobre la misma aritmética. */
        $ahora = $servicio->ventana_desde();
        $this->assertSame('2026-09-08 10:10', $ahora['inicio']->format('Y-m-d H:i'));
        $this->assertSame('2026-09-08 12:10', $ahora['fin']->format('Y-m-d H:i'));
    }

    /* ------------------------------------------------------------------ */
    /* Generación                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * (2) El modelo devuelve {dia: manana, hora: 10:00} y el texto nombra las 10:00: el paquete queda
     *     retenido (todo agendar_demo espera el ok) con el instante resuelto y la instancia prevista
     *     para esa ventana, sin ninguna nota de alerta.
     *
     * @return void
     */
    public function test_con_dia_y_hora_el_paquete_se_normaliza_con_el_instante_resuelto(): void
    {
        $demo = $this->crear_demo(1);
        $lead = $this->crear_lead_calificado_con_mail();
        $this->sembrar_entorno_del_agente($this->respuesta_del_modelo($lead, $demo, 'manana', '10:00', 'Listo, mañana a las 10:00 la tenés lista.'));

        $msg = (new LeadAiService())->generate_suggestion($lead, false);

        $this->assertTrue((bool) $msg->requiere_verificacion);
        $agendar = $msg->pending_actions['agendar_demo'];
        $this->assertSame('manana', $agendar['dia']);
        $this->assertSame('10:00', $agendar['hora']);
        $this->assertSame('2026-09-09 10:00', $agendar['inicio_resuelto']);
        $this->assertSame($demo->id, (int) $agendar['demo_id_previsto']);
        $this->assertSame('https://tienda-1.test', $agendar['url_tienda_prevista']);
        $this->assertArrayNotHasKey('ahora', $agendar);
        $this->assertStringNotContainsString('⚠️', (string) $msg->ai_reasoning);
    }

    /**
     * (3) 🔴 Si el texto no nombra la hora pedida, se retiene con la nota (la guarda de la hora, al revés
     *     que en el camino "ahora").
     *
     * @return void
     */
    public function test_sin_la_hora_en_el_texto_se_retiene_con_la_nota(): void
    {
        $demo = $this->crear_demo(1);
        $lead = $this->crear_lead_calificado_con_mail();
        $this->sembrar_entorno_del_agente($this->respuesta_del_modelo($lead, $demo, 'manana', '10:00', 'Listo, mañana la tenés lista.'));

        $msg = (new LeadAiService())->generate_suggestion($lead, false);

        $this->assertTrue((bool) $msg->requiere_verificacion);
        $this->assertStringContainsString('no nombra esa hora', (string) $msg->ai_reasoning);
        $this->assertSame('10:00', $msg->pending_actions['agendar_demo']['hora'], 'La acción se conserva: la revisa una persona.');
    }

    /**
     * (4) "a la tarde" no es una hora: la acción se saca, el estado no sube a demo_agendada y se retiene.
     *
     * @return void
     */
    public function test_con_una_hora_ilegible_se_saca_la_accion_y_se_retiene(): void
    {
        $demo = $this->crear_demo(1);
        $lead = $this->crear_lead_calificado_con_mail();
        $this->sembrar_entorno_del_agente($this->respuesta_del_modelo($lead, $demo, 'manana', 'a la tarde', 'Dale, mañana a la tarde.'));

        $msg = (new LeadAiService())->generate_suggestion($lead, false);

        $this->assertTrue((bool) $msg->requiere_verificacion);
        $this->assertStringContainsString('no pudo leer', (string) $msg->ai_reasoning);
        $this->assertArrayNotHasKey('agendar_demo', array_filter($msg->pending_actions, function ($valor) {
            return $valor !== null;
        }));
        $this->assertNull($msg->suggested_lead_status, 'demo_agendada sin agendar_demo no mueve el estado.');
    }

    /**
     * (5) Lo que ya hacía el camino "ahora" sigue igual: un texto con hora se retiene con su nota.
     *
     * @return void
     */
    public function test_el_camino_ahora_sigue_reteniendo_un_texto_con_hora(): void
    {
        $demo = $this->crear_demo(1);
        $lead = $this->crear_lead_calificado_con_mail();
        $this->sembrar_entorno_del_agente([
            'mensaje_sugerido' => 'Dale, a las 15:00 la tenés lista. ' . $lead->demo_experiencia_url . ' y ' . DemoDirectaService::url_tienda($demo),
            'estado_sugerido'  => 'demo_agendada',
            'razonamiento'     => '',
            'agendar_demo'     => ['ahora' => true],
        ]);

        $msg = (new LeadAiService())->generate_suggestion($lead, false);

        $this->assertTrue($msg->pending_actions['agendar_demo']['ahora']);
        $this->assertStringContainsString('se asigna para ahora', (string) $msg->ai_reasoning);
    }

    /* ------------------------------------------------------------------ */
    /* Aplicación                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * (6) 🔴 Al aprobar, se asigna la instancia para mañana a las 10:00: turno 10:00-12:00 del 9/9,
     *     demo_agendada, setup pendiente, los dos checks prendidos, y la carta de acceso sale (hay mail).
     *     El panel de hoy no conoce `dia`/`hora`: manda un agendar_demo sin ellas y el servidor las
     *     preserva del paquete original.
     *
     * @return void
     */
    public function test_al_aprobar_asigna_el_turno_pedido_y_prende_los_checks(): void
    {
        $demo    = $this->crear_demo(1);
        $lead    = $this->crear_lead_calificado_con_mail();
        $mensaje = $this->crear_mensaje_pendiente_con_turno($lead, $demo, 'manana', '10:00', '2026-09-09 10:00');

        $resultado = (new LeadAiService())->apply_pending_actions($mensaje, $this->final_actions_del_panel_sin_dia_ni_hora());

        $lead->refresh();
        $this->assertSame('demo_agendada', (string) $lead->status);
        $this->assertSame($demo->id, (int) $lead->demo_id);
        $this->assertSame('2026-09-09', $lead->demo_date->format('Y-m-d'));
        $this->assertSame('10:00', $lead->demo_start_time);
        $this->assertSame('12:00', $lead->demo_end_time);
        $this->assertTrue((bool) $lead->demo_flexible);
        $this->assertSame('pendiente', (string) $lead->demo_setup_status);
        $this->assertTrue((bool) $lead->auto_check_ingreso_demo, 'D9: el check de ingreso se prende al agendar.');
        $this->assertTrue((bool) $lead->auto_check_fin_demo, 'D9: el check de fin se prende al agendar.');
        $this->assertSame('demo_agendada', $resultado->suggested_lead_status);

        Mail::assertSent(LeadDemoAccesoMail::class);
    }

    /**
     * (7) El camino "ahora" también prende los dos checks (D9 vale para las dos formas).
     *
     * @return void
     */
    public function test_el_camino_ahora_tambien_prende_los_checks(): void
    {
        $demo    = $this->crear_demo(1);
        $lead    = $this->crear_lead_calificado_con_mail();
        $mensaje = $this->crear_mensaje_pendiente_ahora($lead, $demo);

        (new LeadAiService())->apply_pending_actions($mensaje, array_merge($this->final_actions_del_panel_sin_dia_ni_hora(), ['agendar_demo' => ['ahora' => true]]));

        $lead->refresh();
        $this->assertSame('demo_agendada', (string) $lead->status);
        $this->assertSame('10:10', $lead->demo_start_time);
        $this->assertTrue((bool) $lead->auto_check_ingreso_demo);
        $this->assertTrue((bool) $lead->auto_check_fin_demo);
    }

    /**
     * (8) 🔴 Mínimo quince minutos: "hoy a las 10:10" dicho a las 10:00 no se asigna. En aprobación es
     *     una excepción reintentable que no escribe nada.
     *
     * @return void
     */
    public function test_con_menos_de_quince_minutos_no_se_asigna(): void
    {
        $demo    = $this->crear_demo(1);
        $lead    = $this->crear_lead_calificado_con_mail();
        $mensaje = $this->crear_mensaje_pendiente_con_turno($lead, $demo, 'hoy', '10:10', '2026-09-08 10:10');

        try {
            (new LeadAiService())->apply_pending_actions($mensaje, $this->final_actions_del_panel_sin_dia_ni_hora());
            $this->fail('Con menos de quince minutos tiene que tirar SinDemoLibreException.');
        } catch (SinDemoLibreException $e) {
            $this->assertStringContainsString('menos de 15 minutos', $e->getMessage());
        }

        $lead->refresh();
        $this->assertNull($lead->demo_id);
        $this->assertSame('calificado', (string) $lead->status);
    }

    /**
     * (9) 🔴 Sin instancia libre a esa hora, en el camino automático (interruptor apagado): no agenda,
     *     el texto es el fijo con la hora desde la que sí, y el estado queda en solicita_disponibilidad.
     *
     * @return void
     */
    public function test_sin_instancia_a_esa_hora_texto_fijo_y_solicita_disponibilidad(): void
    {
        AdminSetting::set(LeadWhatsappOnboardingSettings::KEY_AUTO_ACTIVAR_VERIFICACION_AL_SOLICITAR_DISPONIBILIDAD, '0');

        $demo = $this->crear_demo(1);
        /* Otro lead ocupa la única instancia de 09:00 a 11:00 de mañana: fin real 11:10 (fin + gracia). */
        $this->ocupar_instancia($demo, '2026-09-09', '09:00', '11:00');
        $lead = $this->crear_lead_calificado_con_mail();
        $this->sembrar_entorno_del_agente($this->respuesta_del_modelo($lead, $demo, 'manana', '10:00', 'Listo, mañana a las 10:00 la tenés lista.'));

        $msg = (new LeadAiService())->generate_suggestion($lead, false);

        $this->assertFalse((bool) $msg->requiere_verificacion, 'Interruptor apagado y sin notas: se aplica en el acto.');
        $this->assertStringStartsWith('A esa hora no tengo lugar; te la puedo tener lista a partir de las 11:10', (string) $msg->content);
        $this->assertSame('solicita_disponibilidad', $msg->suggested_lead_status);

        $lead->refresh();
        $this->assertNull($lead->demo_id);
        $this->assertNull($lead->demo_date);
    }

    /**
     * (10) resolve_dia_solicitado(): 'hoy' solo cuando el llamador lo habilita; el resto como siempre.
     *
     * @return void
     */
    public function test_resolver_hoy_solo_con_permiso(): void
    {
        $service = new class extends LeadAiService {
            public function dia(?string $dia, bool $hoy): ?string
            {
                return $this->resolve_dia_solicitado($dia, $hoy);
            }

            public function inicio(string $dia, string $hora): ?Carbon
            {
                return $this->resolver_inicio_de_demo_pedida($dia, $hora);
            }
        };

        $this->assertNull($service->dia('hoy', false), 'La grilla sigue sin aceptar hoy.');
        $this->assertSame('2026-09-08', $service->dia('hoy', true));
        $this->assertSame('2026-09-09', $service->dia('manana', true));
        $this->assertSame('2026-09-14', $service->dia('lunes', true));

        $this->assertSame('2026-09-08 18:00', $service->inicio('hoy', '18')->format('Y-m-d H:i'));
        $this->assertSame('2026-09-08 18:30', $service->inicio('hoy', '18:30 hs')->format('Y-m-d H:i'));
        $this->assertSame('2026-09-09 09:05', $service->inicio('manana', '9:05')->format('Y-m-d H:i'));
        $this->assertNull($service->inicio('manana', 'a la tarde'));
        $this->assertNull($service->inicio('manana', '25:00'));
        $this->assertNull($service->inicio('2026-09-09', '10:00'), 'Una fecha Y-m-d sigue prohibida: las fechas las calcula el sistema.');
    }

    /* ------------------------------------------------------------------ */
    /* Ayudas                                                                */
    /* ------------------------------------------------------------------ */

    /**
     * @param Lead   $lead
     * @param Demo   $demo
     * @param string $dia
     * @param string $hora
     * @param string $texto
     *
     * @return array<string, mixed>
     */
    private function respuesta_del_modelo(Lead $lead, Demo $demo, string $dia, string $hora, string $texto): array
    {
        return [
            'mensaje_sugerido' => $texto . ' Entrá desde acá: ' . $lead->demo_experiencia_url
                . ' Y esta es la tienda conectada a tu demo: ' . DemoDirectaService::url_tienda($demo) . ' Te mandé todo al mail.',
            'estado_sugerido'  => 'demo_agendada',
            'razonamiento'     => '',
            'agendar_demo'     => ['dia' => $dia, 'hora' => $hora],
        ];
    }

    /**
     * Fake de la API + prompt base + system base + recurso demo_agenda v2 con el marcador.
     *
     * @param array<string, mixed> $respuesta_del_modelo
     *
     * @return void
     */
    private function sembrar_entorno_del_agente(array $respuesta_del_modelo): void
    {
        config(['services.anthropic.api_key' => 'clave-de-prueba']);
        config(['services.admin_spa.url' => 'https://admin.test']);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'stop_reason' => 'end_turn',
                'content'     => [[
                    'type' => 'text',
                    'text' => json_encode($respuesta_del_modelo, JSON_UNESCAPED_UNICODE),
                ]],
            ], 200),
            '*' => Http::response(['ok' => true], 200),
        ]);

        AiSystemPrompt::create([
            'contenido'   => 'System prompt de prueba.',
            'descripcion' => 'Fila mínima para que build_system_prompt() no tire.',
            'activa'      => true,
        ]);
        SyncedGithubFile::create([
            'key'       => WhatsappProtocolService::SYSTEM_BASE_KEY,
            'repo_path' => 'agentes/lead/recursos/README.md',
            'content'   => 'System base de prueba.',
            'synced_at' => AppTime::now(),
        ]);
        SyncedGithubFile::create([
            'key'       => WhatsappProtocolService::RECURSO_KEY_PREFIX_V2 . 'demo_agenda',
            'repo_path' => 'agentes/lead/recursos/v2/demo_agenda.md',
            'content'   => "# Agenda de la demo\n\nMarcador: " . LeadAiService::MARCADOR_DEMO_DIRECTA . "\n",
            'synced_at' => AppTime::now(),
        ]);
    }

    /**
     * Lead calificado, de la dinámica nueva, con correo (para que el sí sea agendar).
     *
     * @return Lead
     */
    private function crear_lead_calificado_con_mail(): Lead
    {
        config(['services.admin_spa.url' => 'https://admin.test']);

        $lead               = new Lead();
        $lead->uuid         = (string) Str::uuid();
        $lead->contact_name = 'Guillermo González';
        $lead->company_name = 'Ferretería de prueba';
        $lead->phone        = '5493511234567';
        $lead->email        = 'guillermo@ferreteria.test';
        $lead->status       = 'calificado';
        $lead->save();

        $lead->demo_experiencia = Lead::EXPERIENCIA_NUEVA;
        $lead->save();

        $inbound              = new LeadMessage();
        $inbound->lead_id     = $lead->id;
        $inbound->sender      = 'lead';
        $inbound->status      = 'enviado';
        $inbound->is_followup = false;
        $inbound->content     = 'Mañana a las 10 me queda bien.';
        $inbound->save();

        return $lead->refresh();
    }

    /**
     * @param int $n
     *
     * @return Demo
     */
    private function crear_demo(int $n): Demo
    {
        $demo                    = new Demo();
        $demo->uuid              = (string) Str::uuid();
        $demo->erp_spa_url       = 'https://demo-' . $n . '.test';
        $demo->erp_api_url       = 'https://demo-' . $n . '-api.test';
        $demo->ecommerce_spa_url = 'https://tienda-' . $n . '.test';
        $demo->ecommerce_api_url = 'https://tienda-' . $n . '-api.test';
        $demo->save();

        return $demo;
    }

    /**
     * @param Demo   $demo
     * @param string $fecha
     * @param string $inicio
     * @param string $fin
     *
     * @return Lead
     */
    private function ocupar_instancia(Demo $demo, string $fecha, string $inicio, string $fin): Lead
    {
        $otro                  = new Lead();
        $otro->uuid            = (string) Str::uuid();
        $otro->contact_name    = 'Otro lead';
        $otro->phone           = '5493519999999';
        $otro->status          = 'demo_agendada';
        $otro->demo_id         = $demo->id;
        $otro->demo_date       = $fecha;
        $otro->demo_start_time = $inicio;
        $otro->demo_end_time   = $fin;
        $otro->save();

        return $otro;
    }

    /**
     * Mensaje `sugerido` con el paquete {dia, hora} tal cual lo deja generate_suggestion().
     *
     * @param Lead   $lead
     * @param Demo   $prevista
     * @param string $dia
     * @param string $hora
     * @param string $inicio_resuelto
     *
     * @return LeadMessage
     */
    private function crear_mensaje_pendiente_con_turno(Lead $lead, Demo $prevista, string $dia, string $hora, string $inicio_resuelto): LeadMessage
    {
        $mensaje                        = new LeadMessage();
        $mensaje->lead_id               = $lead->id;
        $mensaje->sender                = 'sistema';
        $mensaje->status                = 'sugerido';
        $mensaje->is_followup           = false;
        $mensaje->requiere_verificacion = true;
        $mensaje->content               = 'Listo, a las ' . $hora . ' la tenés lista. Acá tenés tu página.';
        $mensaje->pending_actions       = [
            'mensaje_sugerido' => $mensaje->content,
            'estado_sugerido'  => 'demo_agendada',
            'razonamiento'     => '',
            'agendar_demo'     => [
                'dia'                 => $dia,
                'hora'                => $hora,
                'inicio_resuelto'     => $inicio_resuelto,
                'demo_id_previsto'    => $prevista->id,
                'url_tienda_prevista' => DemoDirectaService::url_tienda($prevista),
            ],
        ];
        $mensaje->save();

        return $mensaje;
    }

    /**
     * @param Lead $lead
     * @param Demo $prevista
     *
     * @return LeadMessage
     */
    private function crear_mensaje_pendiente_ahora(Lead $lead, Demo $prevista): LeadMessage
    {
        $mensaje                        = new LeadMessage();
        $mensaje->lead_id               = $lead->id;
        $mensaje->sender                = 'sistema';
        $mensaje->status                = 'sugerido';
        $mensaje->is_followup           = false;
        $mensaje->requiere_verificacion = true;
        $mensaje->content               = 'Dale, ya la estoy preparando. Acá tenés tu página.';
        $mensaje->pending_actions       = [
            'mensaje_sugerido' => $mensaje->content,
            'estado_sugerido'  => 'demo_agendada',
            'razonamiento'     => '',
            'agendar_demo'     => [
                'ahora'               => true,
                'demo_id_previsto'    => $prevista->id,
                'url_tienda_prevista' => DemoDirectaService::url_tienda($prevista),
            ],
        ];
        $mensaje->save();

        return $mensaje;
    }

    /**
     * `final_actions` como los manda el panel de verificación de HOY, que reconstruye agendar_demo
     * clave por clave y no conoce `dia`/`hora`.
     *
     * @return array<string, mixed>
     */
    private function final_actions_del_panel_sin_dia_ni_hora(): array
    {
        return [
            'estado_sugerido'              => 'demo_agendada',
            'agendar_demo'                 => ['demo_id' => null, 'demo_date' => null, 'demo_start_time' => null],
            'forzar_slot'                  => false,
            'enviar_mail_demo'             => true,
            'reenviar_mail_demo'           => false,
            'guardar_nombre'               => null,
            'guardar_email'                => null,
            'cancelar_demo'                => false,
            'requiere_intervencion_humana' => false,
            'motivo_intervencion'          => null,
        ];
    }
}

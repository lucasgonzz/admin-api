<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminSetting;
use App\Models\AiSystemPrompt;
use App\Models\FollowupRule;
use App\Models\FollowupTemplate;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\SyncedGithubFile;
use App\Services\LeadAiService;
use App\Services\LeadFollowupService;
use App\Services\LeadWhatsappOnboardingSettings;
use App\Services\WhatsappProtocolService;
use App\Services\WhatsappSendService;
use App\Helpers\AppTime;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Interruptor global de Cuenta (decisión de Lucas, 1/9/2026): con el toggle prendido (default,
 * comportamiento histórico), un lead que entra al tramo solicita_disponibilidad → closer_activo
 * se auto-marca para verificación de mensajes (Lead::booted, prompt 406). Con el toggle apagado,
 * ningún lead se marca solo por el tramo. En ningún caso se toca el toggle manual por-lead: el
 * botón del escudo en la conversación sigue funcionando siempre, apagado o prendido el global.
 *
 * Reversible las veces que haga falta (no es una migración de una sola vía): ver caso (a).
 */
class InterruptorGlobalDeVerificacionDeMensajesTest extends TestCase
{
    use DatabaseTransactions;

    private const ENDPOINT_SETTINGS = '/api/admin/settings/lead-whatsapp-onboarding';

    /**
     * Con QUEUE_CONNECTION=sync (testing), un job con delay() se ejecuta IGUAL en el acto en vez
     * de esperar — así que crear un LeadMessage 'sugerido' dispara el auto-envío de respaldo antes
     * de que el test llegue a la aserción. Los casos que miden el estado recién creado del mensaje
     * (no el auto-envío en sí, que es otra funcionalidad) fakean la cola para medir en el momento
     * correcto.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    /**
     * Crea un admin para autenticar contra los endpoints.
     *
     * @param string $email
     *
     * @return Admin
     */
    private function crear_admin(string $email): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Admin de prueba';
        $admin->email    = $email;
        $admin->password = bcrypt('secret');
        $admin->save();

        return $admin;
    }

    /**
     * Crea un lead mínimo en el estado dado.
     *
     * @param string $status
     *
     * @return Lead
     */
    private function crear_lead(string $status): Lead
    {
        $lead               = new Lead();
        $lead->uuid         = (string) Str::uuid();
        $lead->contact_name = 'Juana Pérez';
        $lead->status       = $status;
        $lead->save();

        return $lead;
    }

    /**
     * Payload completo de los campos existentes del endpoint, con el placeholder {nombre}
     * requerido en las dos variantes "con nombre" (si no, el controller devuelve 422).
     *
     * @param bool $auto_activar
     *
     * @return array<string, mixed>
     */
    private function payload_completo(bool $auto_activar): array
    {
        return [
            'auto_message_with_name'       => 'Hola {nombre}!',
            'auto_message_without_name'    => 'Hola!',
            'welcome_message_with_name'    => 'Bienvenido {nombre}!',
            'welcome_message_without_name' => 'Bienvenido!',
            'welcome_delay_seconds'        => 60,
            'ai_suggestion_delay_seconds'  => 60,
            'verificacion_agendamiento_auto_send_delay_minutes' => 30,
            'auto_activar_verificacion_al_solicitar_disponibilidad' => $auto_activar,
        ];
    }

    /**
     * (a) GET sin fila previa devuelve el default true (prueba el seed). PUT persiste el valor en
     * ambos sentidos, confirmando que el interruptor es reversible las veces que haga falta.
     */
    public function test_get_y_put_incluyen_y_persisten_el_interruptor_global(): void
    {
        $admin = $this->crear_admin('cuenta1@comerciocity.com');

        $this->assertNull(AdminSetting::get(LeadWhatsappOnboardingSettings::KEY_AUTO_ACTIVAR_VERIFICACION_AL_SOLICITAR_DISPONIBILIDAD));

        $get = $this->actingAs($admin, 'sanctum')->getJson(self::ENDPOINT_SETTINGS);
        $get->assertStatus(200);
        $this->assertTrue($get->json('auto_activar_verificacion_al_solicitar_disponibilidad'));
        $this->assertSame('1', AdminSetting::get(LeadWhatsappOnboardingSettings::KEY_AUTO_ACTIVAR_VERIFICACION_AL_SOLICITAR_DISPONIBILIDAD));

        $apagar = $this->actingAs($admin, 'sanctum')->putJson(self::ENDPOINT_SETTINGS, $this->payload_completo(false));
        $apagar->assertStatus(200);
        $this->assertFalse($apagar->json('auto_activar_verificacion_al_solicitar_disponibilidad'));
        $this->assertSame('0', AdminSetting::get(LeadWhatsappOnboardingSettings::KEY_AUTO_ACTIVAR_VERIFICACION_AL_SOLICITAR_DISPONIBILIDAD));

        $prender = $this->actingAs($admin, 'sanctum')->putJson(self::ENDPOINT_SETTINGS, $this->payload_completo(true));
        $prender->assertStatus(200);
        $this->assertTrue($prender->json('auto_activar_verificacion_al_solicitar_disponibilidad'));
        $this->assertSame('1', AdminSetting::get(LeadWhatsappOnboardingSettings::KEY_AUTO_ACTIVAR_VERIFICACION_AL_SOLICITAR_DISPONIBILIDAD));
    }

    /**
     * (b) Global en true (default): el lead que cruza a solicita_disponibilidad queda marcado.
     */
    public function test_global_en_true_el_cruce_a_solicita_disponibilidad_marca_al_lead(): void
    {
        AdminSetting::set(LeadWhatsappOnboardingSettings::KEY_AUTO_ACTIVAR_VERIFICACION_AL_SOLICITAR_DISPONIBILIDAD, '1');

        $lead = $this->crear_lead('contactado');
        $this->assertFalse((bool) $lead->requiere_verificacion_mensajes);

        $lead->status = 'solicita_disponibilidad';
        $lead->save();
        $lead->refresh();

        $this->assertTrue((bool) $lead->requiere_verificacion_mensajes);
    }

    /**
     * (c) Global en false: el mismo cruce NO auto-enciende el flag. Cubre dos estados distintos
     * de la ventana para no depender de uno solo.
     */
    public function test_global_en_false_el_cruce_a_la_ventana_no_marca_al_lead(): void
    {
        AdminSetting::set(LeadWhatsappOnboardingSettings::KEY_AUTO_ACTIVAR_VERIFICACION_AL_SOLICITAR_DISPONIBILIDAD, '0');

        $lead_a = $this->crear_lead('contactado');
        $lead_a->status = 'solicita_disponibilidad';
        $lead_a->save();
        $lead_a->refresh();
        $this->assertFalse((bool) $lead_a->requiere_verificacion_mensajes);

        $lead_b = $this->crear_lead('contactado');
        $lead_b->status = 'demo_agendada';
        $lead_b->save();
        $lead_b->refresh();
        $this->assertFalse((bool) $lead_b->requiere_verificacion_mensajes);
    }

    /**
     * (d) Global en false: el toggle manual por-lead sigue funcionando en los dos sentidos,
     * independiente del interruptor.
     */
    public function test_global_en_false_el_toggle_manual_por_lead_sigue_funcionando(): void
    {
        AdminSetting::set(LeadWhatsappOnboardingSettings::KEY_AUTO_ACTIVAR_VERIFICACION_AL_SOLICITAR_DISPONIBILIDAD, '0');
        $admin = $this->crear_admin('cuenta2@comerciocity.com');
        $lead  = $this->crear_lead('solicita_disponibilidad');
        $this->assertFalse((bool) $lead->requiere_verificacion_mensajes);

        $endpoint = '/api/admin/lead/' . $lead->id . '/toggle-requiere-verificacion-mensajes';

        $this->actingAs($admin, 'sanctum')->postJson($endpoint)->assertStatus(200);
        $lead->refresh();
        $this->assertTrue((bool) $lead->requiere_verificacion_mensajes);

        $this->actingAs($admin, 'sanctum')->postJson($endpoint)->assertStatus(200);
        $lead->refresh();
        $this->assertFalse((bool) $lead->requiere_verificacion_mensajes);
    }

    /**
     * (e) Global en false: un lead ya marcado a mano no se apaga solo al cambiar de estado. El
     * latch solo prende, nunca apaga — ni el interruptor global cambia eso.
     */
    public function test_global_en_false_no_apaga_un_lead_ya_marcado_a_mano(): void
    {
        AdminSetting::set(LeadWhatsappOnboardingSettings::KEY_AUTO_ACTIVAR_VERIFICACION_AL_SOLICITAR_DISPONIBILIDAD, '0');

        $lead = $this->crear_lead('contactado');
        $lead->requiere_verificacion_mensajes = true;
        $lead->save();

        $lead->status = 'solicita_disponibilidad';
        $lead->save();
        $lead->refresh();
        $this->assertTrue((bool) $lead->requiere_verificacion_mensajes);

        $lead->status = 'demo_agendada';
        $lead->save();
        $lead->status = 'en_pausa';
        $lead->save();
        $lead->refresh();
        $this->assertTrue((bool) $lead->requiere_verificacion_mensajes);
    }

    /* ------------------------------------------------------------------ */
    /* (f)-(j) Extensión: el gate de agendamiento de LeadAiService          */
    /* ------------------------------------------------------------------ */

    /**
     * Instancia de LeadAiService con requires_agendamiento_verification_gate() y
     * create_message_and_update_lead() expuestos (son protected). La subclase no cambia lógica.
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
             * @return bool
             */
            public function gate(Lead $lead, array $parsed): bool
            {
                return $this->requires_agendamiento_verification_gate($lead, $parsed);
            }

            /**
             * @param Lead                 $lead
             * @param array<string, mixed> $parsed
             *
             * @return \App\Models\LeadMessage
             */
            public function crear(Lead $lead, array $parsed): \App\Models\LeadMessage
            {
                return $this->create_message_and_update_lead($lead, $parsed, false);
            }

            /**
             * @param Lead $lead
             *
             * @return \App\Models\LeadMessage
             */
            public function segunda_llamada(Lead $lead): \App\Models\LeadMessage
            {
                return $this->generate_suggestion_with_availability($lead, false, null, true);
            }
        };
    }

    /**
     * Fila mínima de AiSystemPrompt + SyncedGithubFile para que build_system_prompt() no tire.
     * Solo hace falta para el caso (i), que sale de verdad a (un Http::fake de) la API.
     *
     * @return void
     */
    private function sembrar_prompt_y_protocolo(): void
    {
        AiSystemPrompt::create([
            'contenido'   => 'System prompt de prueba.',
            'descripcion' => 'Fila mínima para que build_system_prompt() no tire.',
            'activa'      => true,
        ]);
        SyncedGithubFile::create([
            'key'       => WhatsappProtocolService::SYSTEM_BASE_KEY,
            'repo_path' => 'comercial/agente_leads/system_base.md',
            'content'   => 'System base de prueba.',
            'synced_at' => AppTime::now(),
        ]);
    }

    /**
     * Las 6 condiciones de requires_agendamiento_verification_gate(), reusadas por (f) y (g).
     *
     * @return array<string, array{0: string, 1: array<string, mixed>}> status del lead => [status, parsed]
     */
    private function escenarios_del_gate(): array
    {
        return [
            'lead ya en el tramo'          => ['solicita_disponibilidad', []],
            'estado_sugerido entra al tramo' => ['calificado', ['estado_sugerido' => 'demo_agendada']],
            'agendar_demo'                  => ['calificado', ['agendar_demo' => true]],
            'cancelar_demo'                 => ['calificado', ['cancelar_demo' => true]],
            // Ambas acciones ahora solo valen desde demo_agendada -- ingresando_demo se sacó del
            // catálogo en la misión demo-v2-estados-automaticos (4/9/2026).
            'confirmar_ingreso'             => ['demo_agendada', ['confirmar_ingreso' => true]],
            'marcar_no_ingreso'             => ['demo_agendada', ['marcar_no_ingreso' => true]],
        ];
    }

    /**
     * (f) Interruptor en true (default): las 6 condiciones del gate siguen reteniendo, exactamente
     * como antes de esta misión.
     */
    public function test_global_en_true_las_seis_condiciones_del_gate_retienen(): void
    {
        AdminSetting::set(LeadWhatsappOnboardingSettings::KEY_AUTO_ACTIVAR_VERIFICACION_AL_SOLICITAR_DISPONIBILIDAD, '1');
        $service = $this->service();

        foreach ($this->escenarios_del_gate() as $nombre => list($status, $parsed)) {
            $lead = $this->crear_lead($status);
            $this->assertTrue($service->gate($lead, $parsed), "Escenario '$nombre': el gate debería retener con el interruptor prendido.");
        }
    }

    /**
     * (g) Interruptor en false: NINGUNA de las 6 condiciones retiene. Es el corazón del pedido de
     * Lucas: con el interruptor apagado, las acciones de agenda (agendar, cancelar, confirmar
     * ingreso, marcar no ingreso) dejan de esperar aprobación.
     */
    public function test_global_en_false_ninguna_de_las_seis_condiciones_retiene(): void
    {
        AdminSetting::set(LeadWhatsappOnboardingSettings::KEY_AUTO_ACTIVAR_VERIFICACION_AL_SOLICITAR_DISPONIBILIDAD, '0');
        $service = $this->service();

        foreach ($this->escenarios_del_gate() as $nombre => list($status, $parsed)) {
            $lead = $this->crear_lead($status);
            $this->assertFalse($service->gate($lead, $parsed), "Escenario '$nombre': el gate no debería retener con el interruptor apagado.");
        }
    }

    /**
     * (h) 🔴 El bypass no puede quedar a medias. Interruptor false, lead en demo_agendada, parsed
     * neutro (sin requiere_verificacion explícito) → el paquete se aplica en el acto: sin
     * pending_actions, sin requiere_verificacion. Control positivo en el mismo test: interruptor
     * true → el paquete queda diferido (pending_actions con el parsed crudo, requiere_verificacion).
     */
    public function test_el_bypass_completo_no_deja_pending_actions_ni_requiere_verificacion(): void
    {
        $service = $this->service();
        $parsed  = [
            'mensaje_sugerido' => 'Dale, nos vemos en la demo.',
            'estado_sugerido'  => 'demo_agendada',
            'razonamiento'     => '',
        ];

        /* El lead se crea con el interruptor APAGADO a propósito: crearlo directo en 'demo_agendada'
         * con el interruptor prendido dispararía también el latch de Lead::booted() (entra a la
         * ventana en el mismo save()), y entonces este control positivo daría verde por el flag
         * por-lead en vez de por el gate/Cambios A-C que este test mide. */
        AdminSetting::set(LeadWhatsappOnboardingSettings::KEY_AUTO_ACTIVAR_VERIFICACION_AL_SOLICITAR_DISPONIBILIDAD, '0');
        $lead_on = $this->crear_lead('demo_agendada');
        $this->assertFalse((bool) $lead_on->requiere_verificacion_mensajes, 'Montaje inválido: el lead nació marcado.');

        AdminSetting::set(LeadWhatsappOnboardingSettings::KEY_AUTO_ACTIVAR_VERIFICACION_AL_SOLICITAR_DISPONIBILIDAD, '1');
        $msg_on = $service->crear($lead_on, $parsed);
        $this->assertNotEmpty($msg_on->pending_actions, 'Con el interruptor prendido, el paquete debería quedar diferido.');
        $this->assertTrue((bool) $msg_on->requiere_verificacion);

        AdminSetting::set(LeadWhatsappOnboardingSettings::KEY_AUTO_ACTIVAR_VERIFICACION_AL_SOLICITAR_DISPONIBILIDAD, '0');
        $lead_off = $this->crear_lead('demo_agendada');
        $msg_off  = $service->crear($lead_off, $parsed);
        $this->assertEmpty($msg_off->pending_actions, 'Con el interruptor apagado, el paquete NO debería quedar diferido (R1/R2 a medias).');
        $this->assertFalse((bool) $msg_off->requiere_verificacion, 'Con el interruptor apagado, el mensaje no debería requerir verificación (R2 sin gatear).');
    }

    /**
     * (i) 🔴 R3: la segunda llamada (la que ofrece horarios) eleva el estado a
     * solicita_disponibilidad SIEMPRE (no se gatea, ver Cambio B), pero la RETENCIÓN de esa
     * elevación sí depende del interruptor. Sin este caso, el mensaje más típico del tramo de
     * agenda seguiría retenido aunque el interruptor esté apagado.
     */
    public function test_segunda_llamada_eleva_el_estado_siempre_pero_solo_retiene_si_el_interruptor_esta_prendido(): void
    {
        $this->sembrar_prompt_y_protocolo();
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'stop_reason' => 'end_turn',
                'content'     => [[
                    'type' => 'text',
                    'text' => json_encode([
                        'mensaje_sugerido'   => 'Tengo lugar mañana a las 10.',
                        'estado_sugerido'    => 'calificado',
                        'razonamiento'       => '',
                        'horarios_ofrecidos' => ['2026-09-02 10:00'],
                    ], JSON_UNESCAPED_UNICODE),
                ]],
            ], 200),
            '*' => Http::response(['ok' => true], 200),
        ]);

        /*
         * Nota de método: se afirma sobre suggested_lead_status (lo que el mensaje registró que
         * había que aplicar), no sobre $lead->status directamente. $lead->status SÍ se aplica en
         * el acto para el paquete RETENIDO (FIX 6/7/2026, caso especial de
         * create_pending_agendamiento_message) pero para el paquete APLICADO recién se aplica al
         * enviarse de verdad (LeadSuggestionSendService), que acá está deliberadamente
         * deshabilitado por Queue::fake() del setUp() — no es parte de lo que este caso mide.
         * suggested_lead_status es la señal fiel de que la elevación ocurrió en el $parsed usado
         * para construir el mensaje, sin importar el camino de envío.
         */
        AdminSetting::set(LeadWhatsappOnboardingSettings::KEY_AUTO_ACTIVAR_VERIFICACION_AL_SOLICITAR_DISPONIBILIDAD, '1');
        $lead_on = $this->crear_lead('calificado');
        $msg_on  = $this->service()->segunda_llamada($lead_on);
        $this->assertSame('solicita_disponibilidad', $msg_on->suggested_lead_status, 'La elevación de estado tiene que ocurrir con el interruptor prendido.');
        $this->assertTrue((bool) $msg_on->requiere_verificacion, 'Con el interruptor prendido, el mensaje de disponibilidad debe retenerse.');
        $this->assertNotEmpty($msg_on->pending_actions);

        AdminSetting::set(LeadWhatsappOnboardingSettings::KEY_AUTO_ACTIVAR_VERIFICACION_AL_SOLICITAR_DISPONIBILIDAD, '0');
        $lead_off = $this->crear_lead('calificado');
        $msg_off  = $this->service()->segunda_llamada($lead_off);
        $this->assertSame('solicita_disponibilidad', $msg_off->suggested_lead_status, 'La elevación de estado NO se gatea: tiene que ocurrir igual con el interruptor apagado.');
        $this->assertFalse((bool) $msg_off->requiere_verificacion, 'Con el interruptor apagado, el mensaje de disponibilidad NO debería quedar retenido.');
        $this->assertEmpty($msg_off->pending_actions, 'Con el interruptor apagado, el mensaje de disponibilidad debería salir aplicado, no diferido.');
    }

    /**
     * (j) Lo que el interruptor NUNCA apaga: el flag por-lead prendido a mano, y el
     * requiere_verificacion explícito que devuelva Claude en su propia respuesta.
     */
    public function test_global_en_false_no_apaga_el_flag_por_lead_ni_el_requiere_verificacion_explicito(): void
    {
        AdminSetting::set(LeadWhatsappOnboardingSettings::KEY_AUTO_ACTIVAR_VERIFICACION_AL_SOLICITAR_DISPONIBILIDAD, '0');
        $service = $this->service();
        $parsed  = [
            'mensaje_sugerido' => 'Todo bien, seguimos coordinando.',
            'estado_sugerido'  => 'calificado',
            'razonamiento'     => '',
        ];

        $lead_manual = $this->crear_lead('calificado');
        $lead_manual->requiere_verificacion_mensajes = true;
        $lead_manual->save();
        $msg_manual = $service->crear($lead_manual, $parsed);
        $this->assertNotEmpty($msg_manual->pending_actions, 'El flag por-lead prendido a mano tiene que retener, apagado o prendido el interruptor global.');
        $this->assertTrue((bool) $msg_manual->requiere_verificacion);

        $lead_claude = $this->crear_lead('calificado');
        $parsed_claude = $parsed;
        $parsed_claude['requiere_verificacion'] = true;
        $msg_claude = $service->crear($lead_claude, $parsed_claude);
        $this->assertNotEmpty($msg_claude->pending_actions, 'El requiere_verificacion explícito de Claude tiene que retener, apagado o prendido el interruptor global.');
        $this->assertTrue((bool) $msg_claude->requiere_verificacion);
    }

    /* ------------------------------------------------------------------ */
    /* (k) R4: seguimientos por plantilla (LeadFollowupService)            */
    /* ------------------------------------------------------------------ */

    /**
     * Sustituye WhatsappSendService por un espía: mide si se pidió mandar la plantilla, sin salir
     * a Meta. Mismo patrón que SeguimientoConVariableVaciaTest.
     *
     * @return WhatsappSendService
     */
    private function espiar_sender(): WhatsappSendService
    {
        $espia = new class extends WhatsappSendService {
            /** @var array<int, array<string, mixed>> */
            public $envios = [];

            public function send_template(string $to, string $template_name, array $variables = [], string $language_code = 'es_AR', ?string $context = null): ?string
            {
                $this->envios[] = ['to' => $to, 'template_name' => $template_name];

                return 'wamid.ENVIADO' . count($this->envios);
            }
        };

        $this->app->instance(WhatsappSendService::class, $espia);

        return $espia;
    }

    /**
     * FollowupRule + FollowupTemplate para demo_agendada, día 1, sin ingreso confirmado. Se llama
     * una sola vez por test: `estado` es único en followup_rules.
     *
     * @return void
     */
    private function sembrar_regla_y_plantilla_de_seguimiento(): void
    {
        $rule = new FollowupRule();
        $rule->estado        = 'demo_agendada';
        $rule->horas_espera  = 0;
        $rule->max_followups = 5;
        $rule->activa        = true;
        $rule->save();

        $template = new FollowupTemplate();
        $template->estado                     = 'demo_agendada';
        $template->dia_numero                 = 1;
        $template->template_name              = 'cc_seg_demo_agendada_d1';
        $template->language_code              = 'es_AR';
        $template->body_template              = 'Hola, ¿segura la demo?';
        $template->solo_si_ingreso_confirmado = false;
        $template->activa                     = true;
        $template->save();
    }

    /**
     * Lead nuevo en demo_agendada, listo para force_followup_now() contra la regla/plantilla ya
     * sembradas.
     *
     * 🔴 Se crea SIEMPRE con el interruptor global apagado, sin importar qué escenario lo llame
     * después: crear un lead directo en 'demo_agendada' con el interruptor prendido dispara TAMBIÉN
     * el latch de Lead::booted() (entra a la ventana en el mismo save()), que prendería
     * requiere_verificacion_mensajes por su cuenta — y entonces el control positivo de (k) daría
     * verde por el flag por-lead, no por el operando de LeadFollowupService que este test mide.
     * Se restaura el valor del interruptor pedido recién después, ya con el lead creado.
     *
     * @param string $interruptor_deseado '1' o '0': el valor que el interruptor debe tener para el
     *                                    llamado real a force_followup_now(), ya con el lead a salvo del latch.
     *
     * @return Lead
     */
    private function crear_lead_con_seguimiento_pendiente(string $interruptor_deseado): Lead
    {
        AdminSetting::set(LeadWhatsappOnboardingSettings::KEY_AUTO_ACTIVAR_VERIFICACION_AL_SOLICITAR_DISPONIBILIDAD, '0');

        $lead                = new Lead();
        $lead->uuid          = (string) Str::uuid();
        $lead->contact_name  = 'Juana Pérez';
        $lead->phone         = '+5493417778899';
        $lead->status        = 'demo_agendada';
        $lead->save();

        $this->assertFalse(
            (bool) $lead->requiere_verificacion_mensajes,
            'Montaje inválido: el lead nació con el flag prendido, contamina el escenario que este test mide.'
        );

        AdminSetting::set(LeadWhatsappOnboardingSettings::KEY_AUTO_ACTIVAR_VERIFICACION_AL_SOLICITAR_DISPONIBILIDAD, $interruptor_deseado);

        return $lead;
    }

    /**
     * (k) R4: interruptor true (default) retiene el seguimiento; interruptor false lo envía
     * directo; interruptor false + escudo por-lead prendido a mano vuelve a retenerlo (el
     * operando agregado en LeadFollowupService que cierra el hueco, ver sección 6.4 del plan).
     */
    public function test_seguimientos_por_plantilla_respetan_el_interruptor_y_el_escudo_por_lead(): void
    {
        $espia   = $this->espiar_sender();
        $service = app(LeadFollowupService::class);
        $this->sembrar_regla_y_plantilla_de_seguimiento();

        $lead_on      = $this->crear_lead_con_seguimiento_pendiente('1');
        $resultado_on = $service->force_followup_now($lead_on);
        $this->assertSame('verificacion', $resultado_on['via'], 'Interruptor prendido: el seguimiento del tramo debería quedar retenido.');
        $msg_on = LeadMessage::query()->where('lead_id', $lead_on->id)->where('is_followup', true)->first();
        $this->assertNotNull($msg_on);
        $this->assertTrue((bool) $msg_on->requiere_verificacion);
        $this->assertNull($msg_on->whatsapp_message_id, 'No debería haber salido nada.');

        $lead_off      = $this->crear_lead_con_seguimiento_pendiente('0');
        $resultado_off = $service->force_followup_now($lead_off);
        $this->assertSame('template', $resultado_off['via'], 'Interruptor apagado: el seguimiento debería salir directo por plantilla.');
        $this->assertNotEmpty($espia->envios, 'Debería haberse pedido enviar la plantilla.');

        $lead_manual = $this->crear_lead_con_seguimiento_pendiente('0');
        $lead_manual->requiere_verificacion_mensajes = true;
        $lead_manual->save();
        $resultado_manual = $service->force_followup_now($lead_manual);
        $this->assertSame('verificacion', $resultado_manual['via'], 'Con el interruptor apagado pero el escudo por-lead prendido, el seguimiento tiene que seguir retenido.');
    }
}

<?php

namespace Tests\Feature;

use App\Jobs\AutoSendLeadAiSuggestionJob;
use App\Models\Admin;
use App\Models\AdminSetting;
use App\Models\AiSystemPrompt;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\SyncedGithubFile;
use App\Services\LeadAiService;
use App\Services\LeadAiSuggestionAutoSendScheduler;
use App\Services\LeadConversationAiState;
use App\Services\LeadWhatsappOnboardingSettings;
use App\Services\WhatsappProtocolService;
use App\Helpers\AppTime;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * La verificación de mensajes de un lead la maneja SOLO su escudo (decisión de Lucas, 22/10/2026,
 * lead Christian).
 *
 * El caso de origen: un lead en `contactado` con el escudo de la conversación apagado igual recibía
 * «Requiere verificación humana → Lucas» cuando el agente generaba la respuesta, porque la IA
 * devolvía `requiere_verificacion: true` por su cuenta y eso alcanzaba para retener el mensaje.
 * Además, un lead parado en un estado del tramo de agenda (solicita_disponibilidad en adelante)
 * seguía retenido aunque Lucas le apagara el escudo, porque el solo estado retenía.
 *
 * Reglas que cubre este archivo:
 *   1. El único momento en que la verificación se prende SOLA es cuando el lead pasa de fuera a
 *      dentro del tramo (latch de Lead::booted()). Si Lucas la apaga estando adentro, nada la
 *      vuelve a prender mientras el lead siga en el tramo.
 *   2. Con el escudo apagado, todo lo que sugiere la IA sale solo: no retiene el
 *      `requiere_verificacion` que pide Claude, ni el estado del tramo, ni el respaldo del
 *      scheduler.
 *   3. Con el escudo prendido, retiene como siempre.
 *   4. Las guardas de INTEGRIDAD del sistema (paquete defectuoso) siguen reteniendo.
 */
class VerificacionSoloPorEscudoDelLeadTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Con QUEUE_CONNECTION=sync un job con delay() correría en el acto: se fakea la cola para medir
     * el estado recién creado del mensaje y qué se encoló, no el envío en sí.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    /**
     * Fija el interruptor global de Cuenta.
     *
     * @param bool $prendido
     *
     * @return void
     */
    private function fijar_interruptor_global(bool $prendido): void
    {
        AdminSetting::set(
            LeadWhatsappOnboardingSettings::KEY_AUTO_ACTIVAR_VERIFICACION_AL_SOLICITAR_DISPONIBILIDAD,
            $prendido ? '1' : '0'
        );
    }

    /**
     * Crea un lead en el estado dado con el escudo APAGADO, sin importar el interruptor global (se
     * crea con el interruptor apagado para que el latch no lo prenda al nacer) y deja el interruptor
     * en el valor pedido. Es el lead al que Lucas le apagó el escudo, o que nunca lo tuvo.
     *
     * @param string $status
     * @param bool   $interruptor_global Valor con el que queda el interruptor al terminar.
     *
     * @return Lead
     */
    private function crear_lead_con_escudo_apagado(string $status, bool $interruptor_global = true): Lead
    {
        $this->fijar_interruptor_global(false);

        $lead               = new Lead();
        $lead->uuid         = (string) Str::uuid();
        $lead->contact_name = 'Christian';
        $lead->phone        = '+5493415550101';
        $lead->status       = $status;
        $lead->save();

        $this->assertFalse((bool) $lead->requiere_verificacion_mensajes, 'Montaje inválido: el lead nació con el escudo prendido.');

        $this->fijar_interruptor_global($interruptor_global);

        return $lead;
    }

    /**
     * Instancia de LeadAiService con los métodos protegidos que el test necesita expuestos. La
     * subclase no cambia lógica.
     *
     * @return LeadAiService
     */
    private function service(): LeadAiService
    {
        return new class extends LeadAiService {
            /**
             * @param string $raw
             *
             * @return array<string, mixed>
             */
            public function parsear(string $raw): array
            {
                return $this->parse_json_response($raw);
            }

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
             * @return LeadMessage
             */
            public function crear(Lead $lead, array $parsed): LeadMessage
            {
                return $this->create_message_and_update_lead($lead, $parsed, false);
            }

            /**
             * @param Lead $lead
             *
             * @return LeadMessage
             */
            public function segunda_llamada(Lead $lead): LeadMessage
            {
                return $this->generate_suggestion_with_availability($lead, false, null, true);
            }
        };
    }

    /**
     * Respuesta cruda de Claude con `requiere_verificacion: true`, como la devolvería el modelo.
     *
     * @param string $estado
     *
     * @return string
     */
    private function respuesta_de_claude_que_pide_verificacion(string $estado = 'contactado'): string
    {
        return json_encode([
            'mensaje_sugerido'      => 'Sí, las dos cosas las tenés: lees la factura con una foto y las listas se actualizan solas.',
            'estado_sugerido'       => $estado,
            'razonamiento'          => 'El lead pregunta por la carga de facturas.',
            'requiere_verificacion' => true,
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Fila mínima de AiSystemPrompt + SyncedGithubFile para que build_system_prompt() no tire. Solo
     * hace falta para el caso que sale de verdad a (un Http::fake de) la API.
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

    /* ------------------------------------------------------------------ */
    /* 1. Cuándo se prende solo, y que apagado a mano se queda apagado     */
    /* ------------------------------------------------------------------ */

    /**
     * El único momento en que el escudo se prende SOLO es el pase de fuera a dentro del tramo; si
     * después Lucas lo apaga estando adentro, ningún movimiento dentro del tramo ni ningún guardado
     * lo vuelve a prender.
     */
    public function test_se_prende_solo_al_entrar_al_tramo_y_apagado_a_mano_no_se_vuelve_a_prender(): void
    {
        $this->fijar_interruptor_global(true);
        $admin = new Admin();
        $admin->name     = 'Lucas';
        $admin->email    = 'verificacion-escudo@comerciocity.com';
        $admin->password = bcrypt('secret');
        $admin->save();

        $lead               = new Lead();
        $lead->uuid         = (string) Str::uuid();
        $lead->contact_name = 'Christian';
        $lead->status       = 'contactado';
        $lead->save();
        $this->assertFalse((bool) $lead->fresh()->requiere_verificacion_mensajes);

        /* El pase a solicita_disponibilidad lo prende solo. */
        $lead->status = 'solicita_disponibilidad';
        $lead->save();
        $this->assertTrue((bool) $lead->fresh()->requiere_verificacion_mensajes, 'Al entrar al tramo el escudo tiene que prenderse solo.');

        /* Lucas lo apaga desde el escudo de la conversación. */
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/lead/' . $lead->id . '/toggle-requiere-verificacion-mensajes')
            ->assertStatus(200);
        $this->assertFalse((bool) $lead->fresh()->requiere_verificacion_mensajes, 'El toggle manual tiene que apagarlo.');

        /* Ni los movimientos dentro del tramo ni los guardados comunes lo vuelven a prender. */
        $lead = $lead->fresh();
        foreach (['demo_agendada', 'demo_en_curso', 'demo_pendiente_de_terminar', 'demo_realizada', 'closer_activo', 'demo_agendada'] as $estado) {
            $lead->status = $estado;
            $lead->save();
            $this->assertFalse(
                (bool) $lead->fresh()->requiere_verificacion_mensajes,
                "Apagado a mano, el escudo no puede volver a prenderse solo al pasar a '$estado'."
            );
        }

        $lead->contact_name = 'Christian R.';
        $lead->save();
        $this->assertFalse((bool) $lead->fresh()->requiere_verificacion_mensajes, 'Un guardado común no puede prender el escudo.');
    }

    /* ------------------------------------------------------------------ */
    /* 2. Con el escudo apagado todo sale solo                              */
    /* ------------------------------------------------------------------ */

    /**
     * 🔴 EL CASO DE CHRISTIAN. Lead en `contactado` (fuera del tramo), escudo apagado, el interruptor
     * global prendido (default) y la IA devuelve `requiere_verificacion: true` por su cuenta: el
     * mensaje sale solo, sin diferir y sin marcar «requiere verificación».
     */
    public function test_la_ia_pide_verificacion_pero_con_el_escudo_apagado_el_mensaje_sale_solo(): void
    {
        $service = $this->service();
        $lead    = $this->crear_lead_con_escudo_apagado('contactado');

        $parsed = $service->parsear($this->respuesta_de_claude_que_pide_verificacion('contactado'));
        $msg    = $service->crear($lead, $parsed);

        $this->assertFalse((bool) $msg->requiere_verificacion, 'Con el escudo apagado el mensaje no debe quedar «requiere verificación».');
        $this->assertEmpty($msg->pending_actions, 'Con el escudo apagado el paquete no debe quedar diferido.');
        $this->assertEmpty($msg->admin_notifications, 'No se le debe avisar a ningún admin «Requiere verificación humana».');
        Queue::assertPushed(AutoSendLeadAiSuggestionJob::class);
    }

    /**
     * Lo mismo con el lead YA dentro del tramo y el escudo apagado a mano, con el interruptor global
     * prendido: estar adentro no retiene.
     */
    public function test_lead_en_el_tramo_con_el_escudo_apagado_y_la_ia_pidiendo_verificacion_sale_solo(): void
    {
        $service = $this->service();

        foreach (['solicita_disponibilidad', 'demo_agendada'] as $estado) {
            $lead = $this->crear_lead_con_escudo_apagado($estado);

            $parsed = $service->parsear($this->respuesta_de_claude_que_pide_verificacion($estado));
            $msg    = $service->crear($lead, $parsed);

            $this->assertFalse((bool) $msg->requiere_verificacion, "Lead en '$estado' con el escudo apagado: no debe requerir verificación.");
            $this->assertEmpty($msg->pending_actions, "Lead en '$estado' con el escudo apagado: el paquete no debe quedar diferido.");
        }
    }

    /**
     * Control positivo: el mismo paquete con el escudo PRENDIDO queda retenido.
     */
    public function test_con_el_escudo_prendido_el_mismo_paquete_queda_retenido(): void
    {
        $service = $this->service();
        $lead    = $this->crear_lead_con_escudo_apagado('contactado');
        $lead->requiere_verificacion_mensajes = true;
        $lead->save();

        $parsed = $service->parsear($this->respuesta_de_claude_que_pide_verificacion('contactado'));
        $msg    = $service->crear($lead, $parsed);

        $this->assertTrue((bool) $msg->requiere_verificacion);
        $this->assertNotEmpty($msg->pending_actions, 'Con el escudo prendido el paquete tiene que quedar diferido.');
    }

    /**
     * El `requiere_verificacion` que pide la IA se separa al leer su respuesta: queda a la vista
     * en otra clave y la clave `requiere_verificacion` queda libre para las guardas del sistema.
     */
    public function test_el_parseo_separa_lo_que_pide_la_ia_de_lo_que_ponen_las_guardas(): void
    {
        $parsed = $this->service()->parsear($this->respuesta_de_claude_que_pide_verificacion());

        $this->assertArrayNotHasKey('requiere_verificacion', $parsed);
        $this->assertTrue($parsed['requiere_verificacion_pedida_por_claude']);

        $sin_pedido = $this->service()->parsear(json_encode(['mensaje_sugerido' => 'Hola', 'estado_sugerido' => 'contactado']));
        $this->assertArrayNotHasKey('requiere_verificacion', $sin_pedido);
        $this->assertArrayNotHasKey('requiere_verificacion_pedida_por_claude', $sin_pedido);
    }

    /**
     * Las guardas de integridad del sistema (un paquete defectuoso: correo dudoso, respaldo
     * documental, cancelación sin acción, oferta mal formada…) dejan `requiere_verificacion` en el
     * paquete y SIGUEN reteniendo con el escudo apagado: no son la verificación que Lucas controla.
     */
    public function test_una_guarda_de_integridad_sigue_reteniendo_con_el_escudo_apagado(): void
    {
        $service = $this->service();
        $lead    = $this->crear_lead_con_escudo_apagado('contactado');

        $msg = $service->crear($lead, [
            'mensaje_sugerido'      => 'Te mando el mail ahora.',
            'estado_sugerido'       => 'contactado',
            'razonamiento'          => '⚠️ La respuesta promete un correo sin una casilla guardada.',
            'requiere_verificacion' => true,
        ]);

        $this->assertTrue((bool) $msg->requiere_verificacion);
        $this->assertNotEmpty($msg->pending_actions);
    }

    /**
     * 🔴 La IA deriva el caso a una persona (`requiere_intervencion_humana`, junto con el
     * `requiere_verificacion` que el prompt le pide en ese caso): el borrador es para que «el setter
     * decida si lo usa», así que NO sale solo aunque el escudo esté apagado. Es una derivación, no
     * una inseguridad suelta.
     */
    public function test_una_derivacion_a_humano_de_la_ia_queda_retenida_con_el_escudo_apagado(): void
    {
        $service = $this->service();
        $lead    = $this->crear_lead_con_escudo_apagado('contactado');

        $parsed = $service->parsear(json_encode([
            'mensaje_sugerido'             => 'Entiendo tu enojo, lo vemos ahora mismo.',
            'estado_sugerido'              => 'contactado',
            'razonamiento'                 => 'El lead se quejó.',
            'requiere_intervencion_humana' => true,
            'requiere_verificacion'        => true,
        ], JSON_UNESCAPED_UNICODE));
        $msg = $service->crear($lead, $parsed);

        $this->assertTrue((bool) $msg->requiere_verificacion);
        $this->assertNotEmpty($msg->pending_actions, 'La derivación a humano tiene que quedar retenida.');
    }

    /**
     * El gate de agendamiento ya no retiene por estar el lead adentro del tramo; solo retiene las
     * acciones que lo meten desde afuera (el momento en que el latch prende el escudo).
     */
    public function test_el_gate_no_retiene_al_lead_adentro_y_si_a_la_accion_que_lo_mete(): void
    {
        $service = $this->service();

        $adentro = $this->crear_lead_con_escudo_apagado('solicita_disponibilidad');
        $this->assertFalse($service->gate($adentro, []));
        $this->assertFalse($service->gate($adentro, ['agendar_demo' => true]));
        $this->assertFalse($service->gate($adentro, ['cancelar_demo' => true]));

        $afuera = $this->crear_lead_con_escudo_apagado('calificado');
        $this->assertTrue($service->gate($afuera, ['agendar_demo' => true]));
        $this->assertFalse($service->gate($afuera, []));
        $this->assertFalse($service->gate($afuera, ['cancelar_demo' => true]), 'cancelar_demo desde afuera no mete al lead en el tramo: no se gatea.');
    }

    /**
     * Un lead que TODAVÍA está fuera del tramo, con el escudo apagado, y cuyo agendar_demo lo va a
     * meter adentro: el mensaje queda retenido Y el escudo se prende en ese mismo momento (es el pase
     * al tramo). Antes quedaba retenido con el escudo mostrándose apagado.
     */
    public function test_el_agendar_demo_que_mete_al_lead_en_el_tramo_prende_el_escudo(): void
    {
        $service = $this->service();
        $lead    = $this->crear_lead_con_escudo_apagado('calificado');

        $msg = $service->crear($lead, [
            'mensaje_sugerido' => 'Listo, te agendo para mañana a las 10.',
            'estado_sugerido'  => 'demo_agendada',
            'razonamiento'     => '',
            'agendar_demo'     => ['demo_date' => '2026-10-23', 'demo_start_time' => '10:00'],
        ]);

        $this->assertTrue((bool) $msg->requiere_verificacion);
        $this->assertNotEmpty($msg->pending_actions);
        $this->assertTrue((bool) $lead->fresh()->requiere_verificacion_mensajes, 'Al meter al lead en el tramo el escudo tiene que quedar prendido.');
    }

    /**
     * Un cancelar_demo de un lead que está fuera del tramo y con el escudo apagado sale solo: no
     * puede quedar retenido para siempre sin escudo que lo explique.
     */
    public function test_el_cancelar_demo_de_un_lead_fuera_del_tramo_con_el_escudo_apagado_sale_solo(): void
    {
        $service = $this->service();
        $lead    = $this->crear_lead_con_escudo_apagado('calificado');

        $msg = $service->crear($lead, [
            'mensaje_sugerido' => 'Listo, cancelé la demo.',
            'estado_sugerido'  => 'calificado',
            'razonamiento'     => '',
            'cancelar_demo'    => true,
        ]);

        $this->assertFalse((bool) $msg->requiere_verificacion);
        $this->assertEmpty($msg->pending_actions);
        $this->assertFalse((bool) $lead->fresh()->requiere_verificacion_mensajes);
    }

    /**
     * La segunda llamada de disponibilidad (la que ofrece horarios) con la IA pidiendo verificación:
     *   - lead ya adentro del tramo, escudo apagado: sale solo;
     *   - lead que todavía está fuera: el mensaje que lo mete al tramo queda retenido (es el momento
     *     en que el latch prende el escudo).
     */
    public function test_segunda_llamada_solo_retiene_el_mensaje_que_mete_al_lead_en_el_tramo(): void
    {
        $this->sembrar_prompt_y_protocolo();
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'stop_reason' => 'end_turn',
                'content'     => [[
                    'type' => 'text',
                    'text' => json_encode([
                        'mensaje_sugerido'      => 'Tengo lugar mañana a las 10.',
                        'estado_sugerido'       => 'calificado',
                        'razonamiento'          => '',
                        'horarios_ofrecidos'    => ['2026-09-02 10:00'],
                        'requiere_verificacion' => true,
                    ], JSON_UNESCAPED_UNICODE),
                ]],
            ], 200),
            '*' => Http::response(['ok' => true], 200),
        ]);

        $adentro  = $this->crear_lead_con_escudo_apagado('solicita_disponibilidad');
        $msg_dentro = $this->service()->segunda_llamada($adentro);
        $this->assertFalse((bool) $msg_dentro->requiere_verificacion, 'Lead ya adentro del tramo con el escudo apagado: la oferta de horarios sale sola.');
        $this->assertEmpty($msg_dentro->pending_actions);

        $afuera     = $this->crear_lead_con_escudo_apagado('calificado');
        $msg_fuera  = $this->service()->segunda_llamada($afuera);
        $this->assertSame('solicita_disponibilidad', $msg_fuera->suggested_lead_status);
        $this->assertTrue((bool) $msg_fuera->requiere_verificacion, 'El mensaje que mete al lead en el tramo queda retenido.');
        $this->assertNotEmpty($msg_fuera->pending_actions);
    }

    /**
     * La aprobación humana que consultan el scheduler, el job de auto-envío y el envío manual
     * depende solo del escudo: el estado del tramo no cuenta.
     */
    public function test_la_aprobacion_humana_depende_solo_del_escudo(): void
    {
        $lead = $this->crear_lead_con_escudo_apagado('demo_agendada');
        $this->assertFalse(LeadConversationAiState::requiere_aprobacion_humana($lead));

        $lead->requiere_verificacion_mensajes = true;
        $lead->save();
        $this->assertTrue(LeadConversationAiState::requiere_aprobacion_humana($lead));
    }

    /**
     * El scheduler del auto-envío: un mensaje sugerido sin «requiere verificación» de un lead en el
     * tramo con el escudo apagado se encola para salir en el acto; con el escudo prendido se retiene
     * para aprobación y no se encola nada.
     */
    public function test_el_scheduler_manda_el_mensaje_con_el_escudo_apagado_y_lo_retiene_con_el_prendido(): void
    {
        $scheduler = new LeadAiSuggestionAutoSendScheduler();

        $lead_apagado = $this->crear_lead_con_escudo_apagado('demo_agendada');
        $mensaje_apagado = LeadMessage::create([
            'lead_id'               => $lead_apagado->id,
            'sender'                => 'sistema',
            'content'               => 'Dale, nos vemos en la demo.',
            'status'                => 'sugerido',
            'requiere_verificacion' => false,
        ]);
        $scheduler->schedule_for_suggested_message($mensaje_apagado);
        Queue::assertPushed(AutoSendLeadAiSuggestionJob::class, 1);

        $lead_prendido = $this->crear_lead_con_escudo_apagado('demo_agendada');
        $lead_prendido->requiere_verificacion_mensajes = true;
        $lead_prendido->save();
        $mensaje_prendido = LeadMessage::create([
            'lead_id'               => $lead_prendido->id,
            'sender'                => 'sistema',
            'content'               => 'Dale, nos vemos en la demo.',
            'status'                => 'sugerido',
            'requiere_verificacion' => false,
        ]);
        $scheduler->schedule_for_suggested_message($mensaje_prendido);

        Queue::assertPushed(AutoSendLeadAiSuggestionJob::class, 1);
        $this->assertTrue((bool) $mensaje_prendido->fresh()->requiere_verificacion, 'Con el escudo prendido el mensaje queda retenido para aprobación.');
    }
}

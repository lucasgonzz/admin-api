<?php

namespace Tests\Feature;

use App\Events\SupportAiSuggestionGenerating;
use App\Events\SupportAiSuggestionPending;
use App\Events\SupportAiSuggestionScheduled;
use App\Events\SupportMessageReceived;
use App\Events\SupportMessageRemoved;
use App\Jobs\AutoSendPendingSupportSuggestion;
use App\Jobs\SendSupportAiSuggestion;
use App\Models\Admin;
use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\ClientEmployee;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\RecursosCatalogoService;
use App\Services\SupportAiSettings;
use App\Services\SupportAiSuggestionDeliveryService;
use App\Services\SupportAiSuggestionScheduler;
use App\Services\SupportAiSuggestionService;
use App\Services\SupportCodexSettings;
use App\Services\SupportTourService;
use App\Services\SupportWhatsappOpenerService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class ToursDeSoporteTest extends TestCase
{
    use DatabaseTransactions;

    private const CLAUDE = '/api/claude/support-codex/tickets/';
    private const PUENTE = 'https://empresa-tours.test/api/admin-sync/support/tours';
    private const INBOUND = '/api/inbound/support/messages';
    private const GUION = ['tema' => 'crear_ticket', 'titulo' => 'Crear un ticket'];
    private const GUION_DINAMICO = [
        'tema' => 'guia_dinamica', 'titulo' => 'Aplicar un recargo en Vender',
        'pasos' => [
            ['clave' => 'vender.recargos', 'texto' => 'Activá el recargo que corresponde a esta venta.'],
            ['clave' => 'vender.total', 'texto' => 'Revisá el total antes de guardar.'],
        ],
    ];
    private const CATALOGO_DINAMICO = [
        'available' => true, 'pasos' => [
            ['clave' => 'vender.recargos', 'descripcion' => 'Interruptores de recargos de venta'],
            ['clave' => 'vender.total', 'descripcion' => 'Total de la venta'],
            ['clave' => 'indicacion', 'descripcion' => 'Indicación textual en pantalla'],
        ],
    ];
    private const GUION_IMPORTACION = [
        'tema' => 'revertir_importacion_articulos',
        'titulo' => 'Revertir la importación del proveedor',
        'import_history_id' => 735,
        'textos' => ['Abrí Crear en Listado.', 'Tocá Historial.', 'Revisá el botón de esta importación.'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-08 11:00:00'));
        config(['services.claude_task_ingest.key' => 'clave-monitor-tour', 'services.admin_inbound_integration.require_api_key' => false]);
        AdminSetting::set(SupportCodexSettings::KEY_ENABLED, '1');
        AdminSetting::set(SupportCodexSettings::KEY_ACTIVATED_AT, '2026-11-08 10:00:00');
        AdminSetting::set(SupportCodexSettings::KEY_ACTIVATED_AFTER_ID, '0');
        AdminSetting::set(SupportAiSettings::KEY_SUGGESTIONS_ENABLED, '1');
        AdminSetting::set(SupportAiSettings::KEY_AUTO_SEND_DELAY_SECONDS, '0');
        Event::fake([
            SupportMessageReceived::class, SupportMessageRemoved::class, SupportAiSuggestionGenerating::class,
            SupportAiSuggestionPending::class, SupportAiSuggestionScheduled::class,
        ]);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_whatsapp_reintenta_con_el_uuid_del_entrante_y_no_envia_un_destinatario_del_prompt(): void
    {
        [$ticket, $entrante] = $this->ticket_whatsapp($this->cliente(['asistente_phone' => '+5493519843001']));
        $headers = $this->reserva($ticket, $entrante);
        Http::fake([self::PUENTE => Http::response(['ok' => true, 'uuid' => $entrante->uuid])]);
        $body = self::GUION + ['expected_message_id' => $entrante->id, 'user_id' => 412];

        for ($intento = 0; $intento < 2; $intento++) {
            $this->postJson(self::CLAUDE . $ticket->id . '/tour', $body, $headers)
                ->assertOk()->assertExactJson(['ok' => true, 'uuid' => $entrante->uuid]);
        }
        Http::assertSentCount(2);
        Http::assertSent(function ($request) use ($ticket, $entrante) {
            return $request->hasHeader('X-Admin-Api-Key', 'clave-empresa') && $request->data() === self::GUION + [
                'uuid' => $entrante->uuid, 'ticket_uuid' => $ticket->uuid, 'source' => 'whatsapp',
            ];
        });
    }

    public function test_martin_invita_con_el_objetivo_verificado_y_solo_envia_el_mismo_tour_aceptado(): void
    {
        $cliente = $this->cliente(['user_id' => 17]);
        [$ticket, $consulta] = $this->ticket_whatsapp($cliente, $cliente->phone, null, false);
        $headers = $this->reserva($ticket, $consulta);
        $body = [
            'lease_token' => $headers['X-Support-Lease-Token'], 'operation_id' => 'invitar-tour-' . $consulta->id,
            'expected_message_id' => $consulta->id, 'action' => 'responder',
            'body' => "En Listado abrí el historial.\nhttps://recursos.comerciocity.com/?v=T0.1\n\n" . SupportTourService::INVITACION,
            'tour_invitation' => self::GUION_IMPORTACION,
        ];
        Http::fakeSequence(self::PUENTE . '/importaciones/candidatas*')->push([], 404)->push(['model' => [
            ['id' => 735, 'created_at' => '2026-11-08 10:37:12', 'provider_name' => 'Proveedor ejemplo'],
        ]]);
        $this->mock(SupportWhatsappOpenerService::class)->shouldReceive('deliver_follow_up')->once()
            ->andReturnUsing(function ($ticket, $message) {
                $message->update(['whatsapp_message_id' => 'wamid.plan-personalizado']);
                return ['delivery' => 'sent'];
            });
        $url = self::CLAUDE . $ticket->id;
        $this->postJson($url . '/reply', $body, $headers)->assertStatus(422)
            ->assertJsonPath('message', 'tour_target_unavailable: El sistema no confirmó que esa persona pueda encontrar la importación en el historial.');
        $this->assertSame(0, $ticket->messages()->where('sender_type', 'admin')->count());

        $this->postJson($url . '/reply', $body, $headers)->assertOk()->assertJsonPath('operation.status', 'sent');
        $invitacion = $ticket->messages()->where('sender_type', 'admin')->firstOrFail();
        $this->assertEquals(self::GUION_IMPORTACION, $invitacion->erp_auto_reply['tour_invitation']);
        Http::assertSent(function ($request) {
            return $request->method() === 'GET' && $request->hasHeader('X-Admin-Api-Key', 'clave-empresa')
                && strpos($request->url(), '/importaciones/candidatas?user_id=17&import_history_id=735') !== false;
        });

        $this->entrante_whatsapp($ticket, 'Sí');
        $aceptacion = $this->entrante_whatsapp($ticket, 'Por favor.');
        $headers = $this->reserva($ticket, $aceptacion);
        $this->getJson($url, $headers)->assertOk()
            ->assertJsonPath('ticket.capabilities.support_tour_consent', true)
            ->assertJsonPath('ticket.accepted_tour', self::GUION_IMPORTACION);
        Http::fake([self::PUENTE => Http::response(['ok' => true, 'uuid' => $aceptacion->uuid])]);
        $payload = self::GUION_IMPORTACION + ['expected_message_id' => $aceptacion->id];
        $this->postJson($url . '/tour', array_replace($payload, ['import_history_id' => 736]), $headers)
            ->assertStatus(409)->assertJsonPath('code', 'tour_invitation_mismatch');
        $this->postJson($url . '/tour', $payload, $headers)->assertOk()->assertJsonPath('uuid', $aceptacion->uuid);

        $otro = $this->entrante_whatsapp($ticket, 'Sí, quiero el tour', ['reply_to_message_id' => $invitacion->id]);
        $this->getJson($url, $headers)->assertOk()->assertJsonPath('ticket.expected_message_id', $otro->id)
            ->assertJsonPath('ticket.accepted_tour', null);
        Http::assertSentCount(1);
    }

    public function test_martin_invita_sin_guion_y_solo_llama_deepseek_una_vez_despues_del_si(): void
    {
        config(['services.deepseek.api_key' => 'deepseek-prueba']);
        [$ticket, $consulta] = $this->ticket_whatsapp($this->cliente(), '+5493419843001', null, false);
        $consulta->update(['body' => '¿Cómo aplico un recargo que ya existe?']);
        $headers = $this->reserva($ticket, $consulta);
        $url = self::CLAUDE . $ticket->id;
        $this->mock(SupportWhatsappOpenerService::class)->shouldReceive('deliver_follow_up')->once()
            ->andReturnUsing(function ($ticket, $message) {
                $message->update(['whatsapp_message_id' => 'wamid.invitacion-dinamica']);
                return ['delivery' => 'sent'];
            });
        Http::fake([
            self::PUENTE . '/catalogo' => Http::response(self::CATALOGO_DINAMICO),
            'api.deepseek.com/*' => Http::response($this->respuesta_deepseek(self::GUION_DINAMICO)),
            self::PUENTE => function ($request) {
                return Http::response(['ok' => true, 'uuid' => $request['uuid']]);
            },
        ]);
        $this->postJson($url . '/tour/prepare', ['expected_message_id' => $consulta->id], $headers)
            ->assertStatus(409)->assertJsonPath('code', 'tour_consent_required');
        $this->postJson($url . '/reply', [
            'lease_token' => $headers['X-Support-Lease-Token'], 'operation_id' => 'invitar-dinamico-' . $consulta->id,
            'expected_message_id' => $consulta->id, 'action' => 'responder', 'tour_requested' => true,
            'body' => "Elegí el recargo en Vender.\nhttps://recursos.comerciocity.com/?v=T11.34\n\n" . SupportTourService::INVITACION,
        ], $headers)->assertOk()->assertJsonPath('operation.status', 'sent');
        Http::assertNothingSent();

        $aceptacion = $this->entrante_whatsapp($ticket, 'Sí, quiero hacer el tour.');
        $headers = $this->reserva($ticket, $aceptacion);
        $this->getJson($url, $headers)->assertOk()->assertJsonPath('ticket.capabilities.support_dynamic_tour', true)
            ->assertJsonPath('ticket.accepted_tour_request', true)->assertJsonPath('ticket.accepted_tour', null);
        for ($intento = 0; $intento < 2; $intento++) {
            $this->postJson($url . '/tour/prepare', ['expected_message_id' => $aceptacion->id], $headers)
                ->assertOk()->assertExactJson(['ok' => true, 'uuid' => $aceptacion->uuid]);
        }
        Http::assertSentCount(3);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.deepseek.com/anthropic/v1/messages'
                && $request->hasHeader('x-api-key', 'deepseek-prueba')
                && strpos($request['system'], 'vender.recargos señala el grupo de interruptores reales') !== false
                && strpos($request['messages'][0]['content'], 'Cómo aplico un recargo') !== false;
        });
        Http::assertSent(function ($request) use ($aceptacion) {
            return $request->method() === 'POST' && $request->url() === self::PUENTE
                && $request->hasHeader('X-Admin-Api-Key', 'clave-empresa')
                && $request['uuid'] === $aceptacion->uuid && $request['pasos'] === self::GUION_DINAMICO['pasos'];
        });
    }

    public function test_la_planificacion_no_entrega_un_tour_si_llega_otro_mensaje_mientras_deepseek_trabaja(): void
    {
        config(['services.deepseek.api_key' => 'deepseek-prueba']);
        [$ticket, $consulta] = $this->ticket_whatsapp($this->cliente(), '+5493419843001', null, false);
        $this->invitacion($ticket, self::GUION, ['erp_auto_reply' => ['tour_requested' => true]]);
        $aceptacion = $this->entrante_whatsapp($ticket, 'Sí.');
        $headers = $this->reserva($ticket, $aceptacion);
        Http::fake([
            self::PUENTE . '/catalogo' => Http::response(self::CATALOGO_DINAMICO),
            'api.deepseek.com/*' => function () use ($ticket) {
                $this->entrante_whatsapp($ticket, 'Ya pude, gracias.');
                return Http::response($this->respuesta_deepseek(self::GUION_DINAMICO));
            },
        ]);
        $this->postJson(self::CLAUDE . $ticket->id . '/tour/prepare', ['expected_message_id' => $aceptacion->id], $headers)
            ->assertStatus(409);
        Http::assertSentCount(2);
    }

    /** @dataProvider preguntas_durante_recorrido */
    public function test_robotito_recalcula_solo_por_una_pregunta_acreditada_y_con_un_control_real(array $marcas, int $estado): void
    {
        config(['services.deepseek.api_key' => 'deepseek-prueba']);
        $body = $this->mensaje_erp($this->cliente());
        SupportCodexSettings::set_enabled(true);
        $firma = ['X-Admin-Api-Key' => 'clave-inbound'];
        $consulta = SupportMessage::findOrFail($this->postJson(self::INBOUND, $body, $firma)->assertCreated()->json('model.id'));
        $ticket = $consulta->ticket;
        $this->invitacion($ticket, self::GUION_DINAMICO, ['erp_auto_reply' => ['tour_requested' => true]]);
        $pregunta = '¿Cómo uso dos medios de pago en una venta?';
        $body = array_replace($body, [
            'body' => 'Pregunta para Robotito sobre «Revertir una importación» en el paso 2: ' . $pregunta,
            'message_uuid' => (string) Str::uuid(),
        ], $marcas);
        $entrante = SupportMessage::findOrFail($this->postJson(self::INBOUND, $body, $firma)->assertCreated()->json('model.id'));

        // Un reenvío del ERP no puede convertir el mismo mensaje en una pregunta firmada del formulario.
        $this->postJson(self::INBOUND, array_replace($body, ['robotito_followup' => true, 'robotito_question' => $pregunta]), $firma)->assertOk();

        $url = self::CLAUDE . $ticket->id;
        $headers = $this->reserva($ticket, $entrante);
        $this->getJson($url, $headers)->assertOk()->assertJsonPath('ticket.accepted_tour_request', $estado !== 409)
            ->assertJsonPath('ticket.accepted_tour', null);

        $paso = ['clave' => $estado === 422 ? 'indicacion' : 'vender.metodos_pago', 'texto' => 'Elegí los dos medios.'];
        $guion = ['tema' => 'guia_dinamica', 'titulo' => 'Combinar medios de pago', 'pasos' => [$paso]];
        $catalogo = self::CATALOGO_DINAMICO;
        $catalogo['pasos'][] = ['clave' => 'vender.metodos_pago', 'descripcion' => 'Distribuir el cobro entre distintos medios'];
        Http::fake([
            self::PUENTE . '/catalogo' => Http::response($catalogo),
            'api.deepseek.com/*' => function ($request) use ($guion, $pregunta) {
                $datos = json_decode($request['messages'][0]['content'], true);
                $this->assertSame($pregunta, $datos['pregunta_durante_recorrido']);
                $this->assertStringContainsString('«Revertir una importación» en el paso 2', $datos['contexto_del_recorrido']);
                $this->assertNull($datos['respuesta_que_invito']);
                return Http::response($this->respuesta_deepseek($guion));
            },
            self::PUENTE => function ($request) use ($entrante, $ticket, $guion) {
                $this->assertSame($entrante->uuid, $request['uuid']);
                $this->assertSame($ticket->uuid, $request['ticket_uuid']);
                $this->assertSame(17, $request['user_id']);
                $this->assertSame($guion['pasos'], $request['pasos']);
                return Http::response(['ok' => true, 'uuid' => $request['uuid']]);
            },
        ]);

        for ($intento = 0; $intento < 2; $intento++) {
            $respuesta = $this->postJson($url . '/tour/prepare', ['expected_message_id' => $entrante->id], $headers)->assertStatus($estado);
            if ($estado === 200) {
                $respuesta->assertExactJson(['ok' => true, 'uuid' => $entrante->uuid]);
            } else {
                $respuesta->assertJsonPath('code', $estado === 422 ? 'tour_plan_unavailable' : 'tour_consent_required');
            }
        }
        Http::assertSentCount([200 => 3, 422 => 2, 409 => 0][$estado]);
    }

    public function preguntas_durante_recorrido(): array
    {
        $formulario = ['robotito_followup' => true, 'robotito_question' => '¿Cómo uso dos medios de pago en una venta?'];
        return [
            'pregunta de la guía' => [$formulario, 200],
            'plan sin un control' => [$formulario, 422],
            'mismo texto escrito en el chat' => [[], 409],
            'marca sin sesión apta para Robotito' => [['robotito_support_version' => null] + $formulario, 409],
        ];
    }

    public function test_martin_conserva_atencion_escrita_en_erp_firmado_sin_robotito(): void
    {
        $body = $this->mensaje_erp($this->cliente());
        SupportCodexSettings::set_enabled(true);
        $body['robotito_support_version'] = null;
        $firma = ['X-Admin-Api-Key' => 'clave-inbound'];
        $consulta = SupportMessage::findOrFail($this->postJson(self::INBOUND, $body, $firma)->assertCreated()->json('model.id'));
        $this->assertTrue($consulta->erp_auto_reply[SupportMessage::MARCA_ERP_VERIFICADO_PARA_CODEX]);
        $this->assertArrayNotHasKey('inbound_version', $consulta->erp_auto_reply);
        $ticket = $consulta->ticket;
        $url = self::CLAUDE . $ticket->id;
        $headers = $this->reserva($ticket, $consulta);
        $this->getJson($url, $headers)->assertOk()->assertJsonPath('ticket.robotito_available', false);
        $texto = "Abrí Listado y buscá Exportación.\nhttps://recursos.comerciocity.com/?v=T0.1";
        $respuesta = [
            'lease_token' => $headers['X-Support-Lease-Token'], 'operation_id' => 'sin-robotito-' . $consulta->id,
            'expected_message_id' => $consulta->id, 'action' => 'responder',
        ];
        Http::fake(['empresa-tours.test/api/admin-sync/support/messages' => Http::response(['ok' => true])]);
        $this->postJson($url . '/reply', $respuesta + [
            'body' => $texto . "\n\n" . SupportTourService::INVITACION, 'tour_requested' => true,
        ], $headers)->assertStatus(422)
            ->assertJsonPath('message', 'tour_target_unavailable: Este mensaje llegó desde una sesión del sistema sin Robotito disponible.');
        Http::assertNothingSent();
        $this->postJson($url . '/reply', $respuesta + ['body' => $texto], $headers)
            ->assertOk()->assertJsonPath('operation.status', 'sent');
        $this->assertSame($texto, $this->respuesta_erp($body)->body);
        Http::assertSentCount(1);
    }

    public function test_no_planifica_el_si_de_una_sesion_sin_robotito_aunque_la_invitacion_ya_salio(): void
    {
        $body = $this->mensaje_erp($this->cliente());
        SupportCodexSettings::set_enabled(true);
        $firma = ['X-Admin-Api-Key' => 'clave-inbound'];
        $consulta = SupportMessage::findOrFail($this->postJson(self::INBOUND, $body, $firma)->assertCreated()->json('model.id'));
        $ticket = $consulta->ticket;
        $this->invitacion($ticket, self::GUION, ['erp_auto_reply' => ['tour_requested' => true]]);
        $body = array_replace($body, ['body' => 'Sí, quiero hacer el tour.', 'message_uuid' => (string) Str::uuid(), 'robotito_support_version' => null]);
        $aceptacion = SupportMessage::findOrFail($this->postJson(self::INBOUND, $body, $firma)->assertCreated()->json('model.id'));
        $url = self::CLAUDE . $ticket->id;
        $headers = $this->reserva($ticket, $aceptacion);
        $this->getJson($url, $headers)->assertOk()->assertJsonPath('ticket.robotito_available', false)
            ->assertJsonPath('ticket.accepted_tour_request', false)->assertJsonPath('ticket.accepted_tour', null);
        Http::fake();
        $this->postJson($url . '/tour/prepare', ['expected_message_id' => $aceptacion->id], $headers)
            ->assertStatus(409)->assertJsonPath('code', 'tour_consent_required');
        Http::assertNothingSent();
    }

    public function test_robotito_no_acredita_una_version_enviada_con_una_llave_compartida(): void
    {
        $body = $this->mensaje_erp($this->cliente()) + ['robotito_followup' => true];
        $this->cliente(['phone' => '+5493419843009']);
        $id = $this->postJson(self::INBOUND, $body, ['X-Admin-Api-Key' => 'clave-inbound'])->assertCreated()->json('model.id');
        $mensaje = SupportMessage::findOrFail($id);
        $this->assertArrayNotHasKey('inbound_version', $mensaje->erp_auto_reply ?? []);
        $this->assertArrayNotHasKey(SupportMessage::MARCA_PREGUNTA_ROBOTITO, $mensaje->erp_auto_reply ?? []);
        Queue::assertNotPushed(SendSupportAiSuggestion::class);
    }

    public function test_el_agente_documental_descarta_la_invitacion_si_el_entrante_web_no_admite_robotito(): void
    {
        $body = $this->mensaje_erp($this->cliente());
        unset($body['robotito_support_version']);
        $firma = ['X-Admin-Api-Key' => 'clave-inbound'];
        $entrante = SupportMessage::findOrFail($this->postJson(self::INBOUND, $body, $firma)->assertCreated()->json('model.id'));
        $texto = "Abrí Listado y buscá Exportación.\nhttps://recursos.comerciocity.com/?v=T0.1";
        Http::fake(['empresa-tours.test/api/admin-sync/support/messages' => Http::response(['ok' => true])]);
        $respuesta = app(SupportAiSuggestionDeliveryService::class)->deliver_erp_auto_reply(
            $entrante->ticket, $texto . "\n\n" . SupportTourService::INVITACION, $entrante->id, null, true
        );
        $this->assertSame($texto, $respuesta->body);
        $this->assertNull($respuesta->erp_auto_reply);
        Http::assertSentCount(1);
    }

    /** @dataProvider controles_exportacion */
    public function test_robotito_distingue_el_historial_de_exportaciones_del_de_importaciones(string $clave, bool $valido): void
    {
        config(['services.deepseek.api_key' => 'deepseek-prueba']);
        [$ticket, $consulta] = $this->ticket_whatsapp($this->cliente(), '+5493419843001', null, false);
        $consulta->update(['body' => '¿Dónde veo el historial de exportaciones del Listado?']);
        $this->invitacion($ticket, self::GUION, ['erp_auto_reply' => ['tour_requested' => true]]);
        $aceptacion = $this->entrante_whatsapp($ticket, 'Sí, quiero hacer el tour.');
        $headers = $this->reserva($ticket, $aceptacion);
        $catalogo = ['available' => true, 'pasos' => [
            ['clave' => 'listado.opcion_historial_exportaciones', 'descripcion' => 'Historial de exportaciones'],
            ['clave' => 'listado.historial_importaciones', 'descripcion' => 'Historial de importaciones'],
        ]];
        $guion = ['tema' => 'guia_dinamica', 'titulo' => 'Historial de exportaciones', 'pasos' => [
            ['clave' => $clave, 'texto' => 'Abrí Historial de exportaciones.'],
        ]];
        Http::fake([
            self::PUENTE . '/catalogo' => Http::response($catalogo),
            'api.deepseek.com/*' => Http::response($this->respuesta_deepseek($guion)),
            self::PUENTE => Http::response(['ok' => true, 'uuid' => $aceptacion->uuid]),
        ]);
        $respuesta = $this->postJson(self::CLAUDE . $ticket->id . '/tour/prepare', ['expected_message_id' => $aceptacion->id], $headers);
        if ($valido) {
            $respuesta->assertOk()->assertJsonPath('uuid', $aceptacion->uuid);
        } else {
            $respuesta->assertStatus(422)->assertJsonPath('code', 'tour_plan_unavailable');
        }
        Http::assertSentCount($valido ? 3 : 2);
    }

    public function controles_exportacion(): array
    {
        return [
            'exportaciones' => ['listado.opcion_historial_exportaciones', true],
            'importaciones no es exportaciones' => ['listado.historial_importaciones', false],
        ];
    }

    /** @dataProvider disponibilidad_catalogo_dinamico */
    public function test_el_agente_web_persiste_la_invitacion_sin_planear_y_actua_tras_el_si(bool $disponible): void
    {
        config(['services.deepseek.api_key' => 'deepseek-prueba']);
        $body = $this->mensaje_erp($this->cliente());
        $body['body'] = '¿Cómo aplico un recargo en Vender?';
        $firma = ['X-Admin-Api-Key' => 'clave-inbound'];
        $this->postJson(self::INBOUND, $body, $firma)->assertCreated();
        $texto = "Elegí el recargo en Vender.\nhttps://recursos.comerciocity.com/?v=T11.34\n\n" . SupportTourService::INVITACION;
        $this->mock(SupportAiSuggestionService::class)->shouldReceive('generate')->once()->andReturn([
            'suggested_message' => $texto, 'tour_requested' => true,
        ]);
        Http::fake(['empresa-tours.test/api/admin-sync/support/messages' => Http::response(['ok' => true])]);
        app()->call([Queue::pushed(SendSupportAiSuggestion::class)->first(), 'handle']);
        Http::assertSentCount(1);
        $invitacion = $this->respuesta_erp($body);
        $this->assertSame($texto, $invitacion->body);
        $this->assertSame(['tour_requested' => true], $invitacion->erp_auto_reply);

        $aceptacion = array_replace($body, ['body' => 'Sí, por favor.', 'message_uuid' => (string) Str::uuid()]);
        $this->postJson(self::INBOUND, $aceptacion, $firma)->assertCreated();
        $this->mock(SupportAiSuggestionService::class)->shouldNotReceive('generate');
        Http::fake([
            self::PUENTE . '/catalogo*' => $disponible ? Http::response(self::CATALOGO_DINAMICO) : Http::response([], 404),
            'api.deepseek.com/*' => Http::response($this->respuesta_deepseek(self::GUION_DINAMICO)),
            self::PUENTE => Http::response(['ok' => true, 'uuid' => $aceptacion['message_uuid']]),
            'empresa-tours.test/api/admin-sync/support/messages' => Http::response(['ok' => true]),
        ]);
        app()->call([Queue::pushed(SendSupportAiSuggestion::class)->last(), 'handle']);
        Http::assertSentCount($disponible ? 4 : 2);
        Http::assertSent(function ($request) {
            return $request->method() === 'GET' && $request->url() === self::PUENTE . '/catalogo?user_id=17';
        });
        $respuesta = $this->respuesta_erp($body);
        if ($disponible) {
            $this->assertSame(SupportTourService::AVISO, $respuesta->body);
        } else {
            $this->assertStringContainsString('No pude confirmar que Robotito', $respuesta->body);
        }
    }

    public function disponibilidad_catalogo_dinamico(): array
    {
        return ['actualizado' => [true], 'anterior' => [false]];
    }

    public function test_el_agente_web_responde_por_escrito_la_pregunta_del_tour_si_no_hay_plan(): void
    {
        config(['services.deepseek.api_key' => 'deepseek-prueba']);
        $body = $this->mensaje_erp($this->cliente());
        $body['body'] = '¿Dónde veo las compras de un proveedor?';
        $body['robotito_followup'] = true;
        $body['robotito_question'] = $body['body'];
        $this->postJson(self::INBOUND, $body, ['X-Admin-Api-Key' => 'clave-inbound'])->assertCreated();

        $texto = 'Entrá a Compras y filtrá por el proveedor.';
        $this->mock(SupportAiSuggestionService::class)->shouldReceive('generate')->once()->andReturn([
            'suggested_message' => $texto . "\n\n" . SupportTourService::INVITACION,
            'tour_requested' => true,
        ]);
        Http::fake([
            self::PUENTE . '/catalogo*' => Http::response([], 404),
            'empresa-tours.test/api/admin-sync/support/messages' => Http::response(['ok' => true]),
        ]);
        app()->call([Queue::pushed(SendSupportAiSuggestion::class)->last(), 'handle']);

        Http::assertSentCount(2);
        $respuesta = $this->respuesta_erp($body);
        $this->assertSame($texto, $respuesta->body);
        $this->assertNull($respuesta->erp_auto_reply);
    }

    private function respuesta_deepseek(array $guion): array
    {
        return ['content' => [['type' => 'text', 'text' => json_encode($guion)]]];
    }

    /** @dataProvider respuestas_sin_consentimiento */
    public function test_no_programa_robotito_sin_una_invitacion_entregada_y_un_si_inequivoco(string $respuesta, array $cambios): void
    {
        [$ticket, $consulta] = $this->ticket_whatsapp($this->cliente(), '+5493419843001', null, false);
        $this->invitacion($ticket, self::GUION, $cambios);
        $entrante = $this->entrante_whatsapp($ticket, $respuesta);
        $headers = $this->reserva($ticket, $entrante);
        Http::fake();
        $this->postJson(self::CLAUDE . $ticket->id . '/tour', self::GUION + ['expected_message_id' => $entrante->id], $headers)
            ->assertStatus(409)->assertJsonPath('code', 'tour_consent_required');
        Http::assertNothingSent();
    }

    public function test_la_aceptacion_que_cita_la_invitacion_no_se_pierde_con_un_entrante_intermedio(): void
    {
        [$ticket, $consulta] = $this->ticket_whatsapp($this->cliente(), '+5493419843001', null, false);
        $invitacion = $this->invitacion($ticket, self::GUION);
        $this->entrante_whatsapp($ticket, 'Vi el video.');
        $otra_cita = $this->entrante_whatsapp($ticket, 'Sí', ['reply_to_message_id' => $consulta->id]);
        $aceptacion = $this->entrante_whatsapp($ticket, 'Sí, quiero que me lo prepares', ['reply_to_message_id' => $invitacion->id]);
        $tours = app(SupportTourService::class);
        $this->assertNull($tours->guion_aceptado($ticket, $otra_cita));
        $this->assertSame(self::GUION, $tours->guion_aceptado($ticket, $aceptacion));
    }

    public function respuestas_sin_consentimiento(): array
    {
        return [
            'agradecimiento' => ['Gracias!', []],
            'acuse ambiguo' => ['Ok', []],
            'negativa' => ['No, gracias.', []],
            'otra consulta' => ['Sí, pero ya lo resolví.', []],
            'borrador' => ['Sí, por favor.', ['is_ai_suggestion_draft' => true]],
            'no confirmada' => ['Sí, por favor.', ['whatsapp_message_id' => null]],
            'falló en Meta' => ['Sí, por favor.', ['whatsapp_delivery_status' => 'fallido']],
            'pregunta editada' => ['Sí, por favor.', ['body' => 'Querés revisar el historial?']],
            'dos preguntas' => ['Sí, por favor.', ['body' => "Lo pudiste abrir?\n\n" . SupportTourService::INVITACION]],
            'invitación anterior sin guion guardado' => ['Sí, por favor.', ['erp_auto_reply' => null]],
        ];
    }

    public function test_whatsapp_envia_empleado_sincronizado_con_su_numero_actual_y_rechaza_coincidencias_ambiguas(): void
    {
        $cliente = $this->cliente();
        $empleado = ClientEmployee::create(['client_id' => $cliente->id, 'name' => 'Empleado', 'phone' => '3519843002', 'empresa_employee_id' => 33]);
        [$ticket, $entrante] = $this->ticket_whatsapp($cliente, '+5493519843002', $empleado);
        $headers = $this->reserva($ticket, $entrante);
        Http::fake([self::PUENTE => Http::response(['ok' => true, 'uuid' => $entrante->uuid])]);
        $body = self::GUION + ['expected_message_id' => $entrante->id];

        $this->postJson(self::CLAUDE . $ticket->id . '/tour', $body, $headers)->assertOk();
        Http::assertSent(function ($request) {
            return $request['user_id'] === 33 && $request['recipient_phone'] === '5493519843002';
        });
        $this->cliente(['phone' => '+5493519843002']);
        $this->postJson(self::CLAUDE . $ticket->id . '/tour', $body, $headers)->assertStatus(409)->assertJsonPath('code', 'tour_recipient_unresolved');
        Http::assertSentCount(1);
    }

    public function test_lease_revision_y_frente_anterior_no_confirman_un_tour(): void
    {
        [$ticket, $entrante] = $this->ticket_whatsapp($this->cliente());
        $headers = $this->reserva($ticket, $entrante);
        $body = self::GUION + ['expected_message_id' => $entrante->id];
        Http::fake([self::PUENTE => Http::response([], 404)]);

        $this->postJson(self::CLAUDE . $ticket->id . '/tour', $body, ['X-Claude-Task-Key' => 'clave-monitor-tour'])->assertStatus(423);
        $ticket->update(['requiere_verificacion_mensajes' => true]);
        $this->postJson(self::CLAUDE . $ticket->id . '/tour', $body, $headers)->assertStatus(409)->assertJsonPath('code', 'verification_required');
        Http::assertNothingSent();

        $ticket->update(['requiere_verificacion_mensajes' => false]);
        $this->postJson(self::CLAUDE . $ticket->id . '/tour', $body, $headers)->assertStatus(409)->assertJsonPath('code', 'tour_unsupported');
        SupportMessage::create(['support_ticket_id' => $ticket->id, 'sender_type' => 'user', 'body' => 'Otra consulta.', 'kind' => 'text']);
        $this->postJson(self::CLAUDE . $ticket->id . '/tour', $body, $headers)->assertStatus(409);
        Http::assertSentCount(1);
    }

    public function test_erp_anterior_o_sin_firma_no_encola_y_un_entrante_firmado_no_se_procesa_dos_veces(): void
    {
        $cliente = $this->cliente();
        $body = $this->mensaje_erp($cliente);
        $this->postJson(self::INBOUND, array_diff_key($body, ['robotito_support_version' => true]))->assertCreated();
        $body['message_uuid'] = (string) Str::uuid();
        $this->postJson(self::INBOUND, $body)->assertCreated();
        Queue::assertNotPushed(SendSupportAiSuggestion::class);

        $body['message_uuid'] = (string) Str::uuid();
        $this->postJson(self::INBOUND, $body, ['X-Admin-Api-Key' => 'clave-inbound'])->assertCreated();
        $this->postJson(self::INBOUND, $body, ['X-Admin-Api-Key' => 'clave-inbound'])->assertOk();
        Queue::assertPushed(SendSupportAiSuggestion::class, 1);
    }

    public function test_reintenta_la_programacion_de_una_consulta_firmada_guardada_sin_duplicar_el_mensaje(): void
    {
        $body = $this->mensaje_erp($this->cliente());
        $headers = ['X-Admin-Api-Key' => 'clave-inbound'];
        $this->mock(SupportAiSuggestionScheduler::class)->shouldReceive('schedule_after_client_inbound')->once()
            ->andThrow(new \RuntimeException('La cola está caída.'));
        $this->postJson(self::INBOUND, $body, $headers)->assertStatus(503);
        $entrante = SupportMessage::where('uuid', $body['message_uuid'])->firstOrFail();
        $this->assertSame([
            'inbound_version' => 1, SupportMessage::MARCA_ERP_VERIFICADO_PARA_CODEX => true,
        ], $entrante->erp_auto_reply);
        Queue::assertNotPushed(SendSupportAiSuggestion::class);

        $this->app->forgetInstance(SupportAiSuggestionScheduler::class);
        $this->postJson(self::INBOUND, $body, $headers)->assertOk()->assertJsonPath('model.id', $entrante->id);
        $this->postJson(self::INBOUND, $body, $headers)->assertOk()->assertJsonPath('model.id', $entrante->id);
        Queue::assertPushed(SendSupportAiSuggestion::class, 1);
        $this->assertNotEmpty($entrante->fresh()->erp_auto_reply['scheduled_at']);
    }

    public function test_un_reintento_firmado_no_agenda_un_entrante_superado_ni_convierte_un_mensaje_sin_firma(): void
    {
        $body = $this->mensaje_erp($this->cliente());
        $headers = ['X-Admin-Api-Key' => 'clave-inbound'];
        $this->mock(SupportAiSuggestionScheduler::class)->shouldReceive('schedule_after_client_inbound')->once()
            ->andThrow(new \RuntimeException('La cola está caída.'));
        $this->postJson(self::INBOUND, $body, $headers)->assertStatus(503);
        $posterior = array_replace($body, ['message_uuid' => (string) Str::uuid()]);
        $this->postJson(self::INBOUND, $posterior)->assertCreated();

        $this->app->forgetInstance(SupportAiSuggestionScheduler::class);
        $this->postJson(self::INBOUND, $body, $headers)->assertOk();
        $this->postJson(self::INBOUND, $posterior, $headers)->assertOk();
        Queue::assertNotPushed(SendSupportAiSuggestion::class);
    }

    public function test_erp_ofrece_robotito_y_agrega_el_aviso_solo_con_confirmacion(): void
    {
        $cliente = $this->cliente();
        $body = $this->mensaje_erp($cliente);
        $this->postJson(self::INBOUND, $body, ['X-Admin-Api-Key' => 'clave-inbound'])->assertCreated();
        Http::fake(['empresa-tours.test/api/admin-sync/support/messages' => Http::response(['ok' => true])]);
        $this->simular_agente(SupportTourService::INVITACION, self::GUION_IMPORTACION);
        app()->call([Queue::pushed(SendSupportAiSuggestion::class)->first(), 'handle']);
        Http::assertSentCount(1);
        $invitacion = $this->respuesta_erp($body);
        $this->assertEquals(self::GUION_IMPORTACION, $invitacion->erp_auto_reply['tour_invitation']);
        $this->assertStringContainsString('https://recursos.comerciocity.com/?v=T0.1', $invitacion->body);
        $this->assertStringContainsString(SupportTourService::INVITACION, $invitacion->body);
        $this->assertStringNotContainsString(SupportTourService::AVISO, $invitacion->body);
        $this->assertArrayNotHasKey('erp_auto_reply', $invitacion->toArray());

        $body = array_replace($body, ['message_uuid' => (string) Str::uuid(), 'body' => 'Sí, me vendría bien']);
        $this->postJson(self::INBOUND, $body, ['X-Admin-Api-Key' => 'clave-inbound'])->assertCreated();
        Http::fake([
            self::PUENTE => Http::response(['ok' => true, 'uuid' => $body['message_uuid']]),
            'empresa-tours.test/api/admin-sync/support/messages' => Http::response(['ok' => true]),
        ]);
        $this->mock(SupportAiSuggestionService::class)->shouldNotReceive('generate');
        app()->call([Queue::pushed(SendSupportAiSuggestion::class)->last(), 'handle']);

        Http::assertSentCount(2);
        Http::assertSent(function ($request) use ($body) {
            return $request->url() === self::PUENTE && $request['source'] === 'sistema' && $request['user_id'] === 17
                && $request['uuid'] === $body['message_uuid'] && $request['import_history_id'] === 735
                && $request['textos'] === self::GUION_IMPORTACION['textos'];
        });
        $respuesta = $this->respuesta_erp($body);
        $this->assertNotNull($respuesta->synced_to_client_at);
        $this->assertStringContainsString(SupportTourService::AVISO, $respuesta->body);
        Http::assertSent(function ($request) use ($respuesta) {
            return $request->url() === 'https://empresa-tours.test/api/admin-sync/support/messages'
                && $request['body'] === $respuesta->body && $request['message_uuid'] === $respuesta->uuid;
        });
    }

    public function test_erp_demorado_guarda_el_plan_y_mantiene_la_respuesta_si_el_frente_es_anterior(): void
    {
        AdminSetting::set(SupportAiSettings::KEY_AUTO_SEND_DELAY_SECONDS, '30');
        $cliente = $this->cliente();
        $body = $this->mensaje_erp($cliente);
        $ticket = SupportTicket::create(['uuid' => $body['ticket_uuid'], 'client_id' => $cliente->id, 'client_user_id' => 17, 'source' => 'erp', 'status' => 'open']);
        $this->invitacion($ticket, self::GUION);
        $body['body'] = 'Sí, por favor.';
        $this->postJson(self::INBOUND, $body, ['X-Admin-Api-Key' => 'clave-inbound'])->assertCreated();
        Http::fake([self::PUENTE => Http::response([], 404), 'empresa-tours.test/api/admin-sync/support/messages' => Http::response(['ok' => true])]);
        $this->mock(SupportAiSuggestionService::class)->shouldNotReceive('generate');
        app()->call([Queue::pushed(SendSupportAiSuggestion::class)->first(), 'handle']);
        $borrador = $this->respuesta_erp($body);
        $this->assertTrue($borrador->is_ai_suggestion_draft);
        $this->assertSame(self::GUION, $borrador->erp_auto_reply['tour']);
        Http::assertNothingSent();

        Carbon::setTestNow(now()->addSeconds(31));
        app()->call([Queue::pushed(AutoSendPendingSupportSuggestion::class)->first(), 'handle']);
        $respuesta = $borrador->fresh();
        $this->assertFalse($respuesta->is_ai_suggestion_draft);
        $this->assertNull($respuesta->erp_auto_reply);
        $this->assertStringNotContainsString('Te mandé a Robotito', $respuesta->body);
        $this->assertStringContainsString('No pude confirmar que Robotito quedara disponible', $respuesta->body);
    }

    public function test_erp_con_revision_no_ofrece_tour_ni_envia_respuesta_automatica(): void
    {
        $body = $this->mensaje_erp($this->cliente());
        $this->postJson(self::INBOUND, $body, ['X-Admin-Api-Key' => 'clave-inbound'])->assertCreated();
        SupportTicket::where('uuid', $body['ticket_uuid'])->update(['requiere_verificacion_mensajes' => true]);
        Http::fake();
        $this->simular_agente();
        app()->call([Queue::pushed(SendSupportAiSuggestion::class)->first(), 'handle']);
        $borrador = $this->respuesta_erp($body);
        $this->assertTrue($borrador->is_ai_suggestion_draft);
        $this->assertNull($borrador->erp_auto_reply['tour']);
        $this->assertStringNotContainsString('Te mandé a Robotito', $borrador->body);
        Http::assertNothingSent();
    }

    public function test_erp_aprueba_y_sincroniza_el_texto_editado_sin_ofrecer_tour(): void
    {
        $body = $this->mensaje_erp($this->cliente());
        $this->postJson(self::INBOUND, $body, ['X-Admin-Api-Key' => 'clave-inbound'])->assertCreated();
        SupportTicket::where('uuid', $body['ticket_uuid'])->update(['requiere_verificacion_mensajes' => true]);
        Http::fake(['empresa-tours.test/api/admin-sync/support/messages' => Http::response(['ok' => true])]);
        $this->simular_agente();
        app()->call([Queue::pushed(SendSupportAiSuggestion::class)->first(), 'handle']);
        $borrador = $this->respuesta_erp($body);
        $admin = Admin::create(['name' => 'Operador', 'email' => 'operador-aprueba-tour@test.local', 'password' => bcrypt('test')]);
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/support-message/' . $borrador->id . '/approve-ai-draft', ['body' => 'Texto revisado.'])
            ->assertOk()->assertJsonPath('delivered', true)->assertJsonPath('model.body', 'Texto revisado.');
        Http::assertSentCount(1);
        Http::assertSent(function ($request) use ($borrador) {
            $actual = $borrador->fresh();
            return $request['body'] === 'Texto revisado.' && $request['message_uuid'] === $actual->uuid
                && $actual->body === 'Texto revisado.' && ! $actual->is_ai_suggestion_draft && $actual->erp_auto_reply === null;
        });
    }

    public function test_el_agente_web_agrega_solo_el_video_verificado_por_la_herramienta(): void
    {
        config(['services.anthropic.api_key' => 'clave-falsa']);
        SupportCodexSettings::set_enabled(false);
        $ticket = SupportTicket::create(['client_id' => $this->cliente()->id, 'client_user_id' => 17, 'source' => 'erp', 'status' => 'open']);
        $ticket->messages()->create([
            'sender_type' => 'user', 'kind' => 'text', 'body' => '¿Cómo creo un ticket?',
            'erp_auto_reply' => ['inbound_version' => SupportMessage::VERSION_ROBOTITO_ERP],
        ]);
        $this->mock(RecursosCatalogoService::class)->shouldReceive('catalogo')->once()->andReturn(['videos' => [[
            'codigo' => 'T0.4', 'titulo' => 'Crear un ticket de soporte', 'para_que' => 'Pedir ayuda desde el sistema', 'url' => 'https://cdn.test/video.mp4',
        ]]]);
        $fin = [
            'suggested_message' => 'Abrí Soporte y tocá Nuevo ticket.', 'tipo_respuesta' => 'afirmacion_del_sistema',
            'fuentes_kb' => ['manual_sistema/general/soporte-tecnico.md'], 'tour' => self::GUION, 'invitar_tour' => true,
        ];
        Http::fakeSequence('api.anthropic.com/*')->push([
            'stop_reason' => 'tool_use', 'content' => [
                ['type' => 'tool_use', 'id' => 'manual', 'name' => 'get_manual_file', 'input' => ['path' => 'manual_sistema/general/soporte-tecnico.md']],
                ['type' => 'tool_use', 'id' => 'video', 'name' => 'buscar_video_de_ayuda', 'input' => ['consulta' => 'crear ticket de soporte']],
            ],
        ])->push([
            'stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => json_encode($fin + ['video_codigo' => 'T0.4'])]],
        ])->push([
            'stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => json_encode($fin + ['video_codigo' => 'T0.4'])]],
        ]);
        $agente = $this->agente_documental();
        $respuesta = $agente->generate($ticket);
        $this->assertTrue($respuesta['tour_requested']);
        $this->assertArrayNotHasKey('tour', $respuesta);
        $this->assertStringContainsString('https://recursos.comerciocity.com/?v=T0.4', $respuesta['suggested_message']);
        $this->assertStringContainsString(SupportTourService::INVITACION, $respuesta['suggested_message']);
        $this->assertStringNotContainsString('https://recursos.comerciocity.com/', $agente->generate($ticket)['suggested_message']);
        Http::assertSent(function ($request) {
            return strpos($request['system'][0]['text'], 'buscar_video_de_ayuda') !== false
                && in_array('buscar_video_de_ayuda', array_column($request['tools'], 'name'), true);
        });
    }

    /** @dataProvider verificacion_destino_dinamico */
    public function test_robotito_solo_señala_la_importacion_que_empresa_confirma(string $consulta_texto, ?int $referencia, ?int $id_pedido, bool $confirmada): void
    {
        config(['services.deepseek.api_key' => 'deepseek-prueba']);
        [$ticket, $consulta] = $this->ticket_whatsapp($this->cliente(), '+5493419843001', null, false);
        $consulta->update(['body' => $consulta_texto]);
        $this->invitacion($ticket, self::GUION, ['erp_auto_reply' => array_filter(['tour_requested' => true, 'tour_import_history_id' => $referencia])]);
        $aceptacion = $this->entrante_whatsapp($ticket, 'Sí, por favor.');
        $headers = $this->reserva($ticket, $aceptacion);
        $catalogo = [
            'available' => true, 'pasos' => [
                ['clave' => 'indicacion', 'descripcion' => 'Indicación sin ancla'],
                ['clave' => 'listado.revertir_importacion', 'descripcion' => 'Botón Revertir de la fila', 'requiere_destino' => true],
            ],
            'destinos' => ['listado.revertir_importacion' => $confirmada ? [['id' => 735, 'created_at' => '2026-11-08 10:37:12']] : []],
        ];
        $guion = [
            'tema' => 'guia_dinamica', 'titulo' => 'Revertir importación',
            'pasos' => [['clave' => 'listado.revertir_importacion', 'texto' => 'Revisá el botón Revertir de la fila.', 'destino' => 735]],
        ];
        Http::fake([
            self::PUENTE . '/catalogo*' => Http::response($catalogo),
            'api.deepseek.com/*' => Http::response($this->respuesta_deepseek($guion)),
            self::PUENTE => Http::response(['ok' => true, 'uuid' => $aceptacion->uuid]),
        ]);
        $respuesta = $this->postJson(self::CLAUDE . $ticket->id . '/tour/prepare', ['expected_message_id' => $aceptacion->id], $headers);
        if ($confirmada) {
            $respuesta->assertOk()->assertJsonPath('uuid', $aceptacion->uuid);
        } else {
            $respuesta->assertStatus(422)->assertJsonPath('code', 'tour_plan_unavailable');
        }
        Http::assertSentCount($confirmada ? 3 : 2);
        Http::assertSent(function ($request) use ($id_pedido) {
            return $request->url() === self::PUENTE . '/catalogo' . ($id_pedido === null ? '' : '?import_history_id=' . $id_pedido);
        });
    }

    public function verificacion_destino_dinamico(): array
    {
        return [
            'referencia de Martín habilitada' => ['Quiero revertir toda la importación del proveedor de esta mañana.', 735, 735, true],
            'referencia de Martín rechazada' => ['Quiero revertir toda la importación del proveedor de esta mañana.', 735, 735, false],
            'ID mencionado por la persona' => ['Quiero revertir toda la importación #735.', null, 735, true],
            'fecha ambigua' => ['Quiero revertir toda la importación 9 de noviembre.', null, null, false],
        ];
    }

    private function agente_documental(): SupportAiSuggestionService
    {
        return new class extends SupportAiSuggestionService {
            protected function fetch_manual_file_list(): string
            {
                return '- manual_sistema/general/soporte-tecnico.md';
            }

            protected function fetch_escalation_rules(): string
            {
                return 'Protocolo de prueba.';
            }

            protected function github_get_file(string $path): string
            {
                return 'Abrí Soporte y tocá Nuevo ticket.';
            }
        };
    }

    public function test_un_envio_erp_automatico_fallido_se_reintenta_desde_la_bandeja_con_el_mismo_uuid(): void
    {
        config(['services.client_api.retries' => 1]);
        $body = $this->mensaje_erp($this->cliente());
        $this->postJson(self::INBOUND, $body, ['X-Admin-Api-Key' => 'clave-inbound'])->assertCreated();
        Http::fake([self::PUENTE => Http::response([], 404)]);
        Http::fakeSequence('empresa-tours.test/api/admin-sync/support/messages')->push([], 500)->push(['ok' => true]);
        $this->simular_agente();
        app()->call([Queue::pushed(SendSupportAiSuggestion::class)->first(), 'handle']);
        $respuesta = $this->respuesta_erp($body);
        $this->assertSame('not_received', $respuesta->remote_delivery_status);

        $admin = Admin::create(['name' => 'Operador', 'email' => 'operador-reintento-tours@test.local', 'password' => bcrypt('test')]);
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/support-message/' . $respuesta->id . '/retry-remote-sync')
            ->assertOk()->assertJsonPath('model.uuid', $respuesta->uuid)->assertJsonPath('model.remote_delivery_status', null);
        $this->assertNotNull($respuesta->fresh()->synced_to_client_at);
        $this->assertCount(2, Http::recorded(function ($request) use ($respuesta) {
            return $request->url() === 'https://empresa-tours.test/api/admin-sync/support/messages' && $request['message_uuid'] === $respuesta->uuid;
        }));
    }

    public function test_seguimiento_usa_el_ticket_del_operador_y_marca_frente_anterior(): void
    {
        [$ticket] = $this->ticket_whatsapp($this->cliente());
        $url = '/api/admin/support-ticket/' . $ticket->id . '/tours';
        $this->getJson($url)->assertUnauthorized();
        $admin = Admin::create(['name' => 'Operador', 'email' => 'operador-tours@test.local', 'password' => bcrypt('test')]);
        $this->actingAs($admin, 'sanctum');
        Http::fakeSequence(self::PUENTE . '/' . $ticket->uuid)->push(['model' => [['uuid' => 'tour', 'state' => 'completed']]])->push([], 404);
        $this->getJson($url)->assertOk()->assertJsonPath('available', true)->assertJsonPath('model.0.state', 'completed');
        $this->getJson($url)->assertOk()->assertExactJson(['available' => false, 'model' => []]);
    }

    private function cliente(array $extra = []): Client
    {
        return Client::create($extra + [
            'name' => 'Comercio tours', 'phone' => '+5493419843001', 'is_active' => true, 'api_url' => 'https://empresa-tours.test',
            'api_key' => 'clave-empresa', 'inbound_api_key' => 'clave-inbound',
        ]);
    }

    private function ticket_whatsapp(Client $cliente, string $telefono = '+5493419843001', ?ClientEmployee $empleado = null, bool $aceptado = true): array
    {
        $ticket = SupportTicket::create([
            'client_id' => $cliente->id, 'client_employee_id' => optional($empleado)->id, 'client_user_id' => 0,
            'status' => 'open', 'source' => 'whatsapp', 'whatsapp_phone' => $telefono, 'opened_at' => now(), 'last_client_message_at' => now(),
        ]);
        if ($aceptado) {
            $this->invitacion($ticket, self::GUION);
        }
        $entrante = $this->entrante_whatsapp($ticket, $aceptado ? 'Sí, por favor.' : '¿Cómo hago un ticket?');
        return [$ticket, $entrante];
    }

    private function entrante_whatsapp(SupportTicket $ticket, string $body, array $extra = []): SupportMessage
    {
        return SupportMessage::create($extra + [
            'support_ticket_id' => $ticket->id, 'sender_type' => 'user', 'kind' => 'text', 'body' => $body,
            'delivered_at' => now(), 'whatsapp_remitente_crudo' => ltrim($ticket->whatsapp_phone, '+'),
        ]);
    }

    private function invitacion(SupportTicket $ticket, array $guion, array $extra = []): SupportMessage
    {
        return SupportMessage::create($extra + [
            'support_ticket_id' => $ticket->id, 'sender_type' => 'admin', 'kind' => 'text',
            'body' => "Abrí Soporte.\nhttps://recursos.comerciocity.com/?v=T0.1\n\n" . SupportTourService::INVITACION,
            'ai_generated_at' => now(), 'delivered_at' => now(), 'erp_auto_reply' => ['tour_invitation' => $guion],
            'whatsapp_message_id' => $ticket->source === 'whatsapp' ? 'wamid.invitacion-' . $ticket->id : null,
            'synced_to_client_at' => $ticket->source === 'erp' ? now() : null,
        ]);
    }

    private function reserva(SupportTicket $ticket, SupportMessage $entrante): array
    {
        $headers = ['X-Claude-Task-Key' => 'clave-monitor-tour'];
        $respuesta = $this->postJson(self::CLAUDE . $ticket->id . '/claim', ['worker_id' => 'tour-tests', 'expected_message_id' => $entrante->id], $headers)->assertOk();
        return $headers + ['X-Support-Lease-Token' => $respuesta->json('lease_token')];
    }

    private function mensaje_erp(Client $cliente): array
    {
        // Estos casos prueban Robotito en instalaciones anteriores cuando Martín está pausado.
        SupportCodexSettings::set_enabled(false);

        return [
            'client_uuid' => $cliente->uuid, 'ticket_uuid' => (string) Str::uuid(), 'message_uuid' => (string) Str::uuid(),
            'client_user_id' => 17, 'sender_user_id' => 17, 'sender_type' => 'user', 'kind' => 'text',
            'body' => '¿Cómo creo un ticket?', 'robotito_support_version' => 1,
        ];
    }

    private function simular_agente(string $invitacion = '', array $tour = self::GUION): void
    {
        $this->mock(SupportAiSuggestionService::class)->shouldReceive('generate')->once()->andReturn([
            'suggested_message' => trim("Abrí Soporte y tocá Nuevo ticket.\nhttps://recursos.comerciocity.com/?v=T0.1\n\n" . $invitacion),
            'tour' => $tour,
        ]);
    }

    private function respuesta_erp(array $body): SupportMessage
    {
        return SupportMessage::whereHas('ticket', function ($ticket) use ($body) {
            $ticket->where('uuid', $body['ticket_uuid']);
        })->where('sender_type', 'admin')->latest('id')->firstOrFail();
    }
}

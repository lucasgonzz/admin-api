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
use App\Models\SupportCodexOperation;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\TrialInstance;
use App\Models\Version;
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

    public function test_el_planificador_sabe_llegar_a_una_solapa_del_abm_y_el_guion_acepta_sus_claves(): void
    {
        // El defecto del ticket 104: cinco indicaciones de texto que pedían «abrí Precios» sobre otra pantalla.
        $instrucciones = new \ReflectionMethod(\App\Services\SupportTourPlannerService::class, 'instrucciones');
        $instrucciones->setAccessible(true);
        $prompt = $instrucciones->invoke(new \App\Services\SupportTourPlannerService());
        $this->assertStringContainsString('Una indicacion es solo texto: no señala nada y no lleva a la persona a ninguna pantalla ni solapa.', $prompt);
        $this->assertStringContainsString('abm.grupo.<grupo>', $prompt);
        $this->assertStringContainsString('abm.solapa.precios.impuestos_sobre_ventas señala Impuestos sobre ventas', $prompt);
        $this->assertStringContainsString('Usá esas claves solo si están en pasos_disponibles', $prompt);

        $pasos = [
            ['clave' => 'abm.grupo.precios', 'texto' => 'Dentro de ABM, tocá Precios.'],
            ['clave' => 'abm.solapa.precios.impuestos_sobre_ventas', 'texto' => 'Ahora tocá Impuestos sobre ventas.'],
            ['clave' => 'indicacion', 'texto' => 'Abrí Ingresos Brutos y destildá Activo.'],
            // La clave más larga que publica Empresa (67 caracteres).
            ['clave' => 'abm.solapa.facturacion.metodo_de_pago_seleccionado_para_facturacion', 'texto' => 'Revisá esta solapa.'],
        ];
        $guion = SupportTourService::guion(['tema' => 'guia_dinamica', 'titulo' => 'Desactivar Ingresos Brutos', 'pasos' => $pasos]);
        $this->assertSame($pasos, $guion['pasos']);
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
    public function test_robotito_recalcula_solo_por_una_pregunta_acreditada_con_pasos_del_catalogo(array $marcas, int $estado, string $clave): void
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

        $paso = ['clave' => $clave, 'texto' => 'En Vender, abrí el reparto de pago y cargá un importe para cada medio.'];
        $guion = ['tema' => 'guia_dinamica', 'titulo' => 'Combinar medios de pago', 'pasos' => [$paso]];
        $esperado = $clave === 'aclaracion' ? [[
            'clave' => 'indicacion',
            'texto' => 'Contame qué querés hacer y en qué pantalla. Podés agregar ese detalle en «Preguntale a Robotito».',
        ]] : $guion['pasos'];
        $catalogo = self::CATALOGO_DINAMICO;
        $catalogo['pasos'][] = ['clave' => 'vender.metodos_pago', 'descripcion' => 'Distribuir el cobro entre distintos medios'];
        Http::fake([
            self::PUENTE . '/catalogo?user_id=17' => Http::response($catalogo),
            'api.deepseek.com/*' => function ($request) use ($guion, $pregunta, $clave) {
                $datos = json_decode($request['messages'][0]['content'], true);
                $this->assertSame($pregunta, $datos['pregunta_durante_recorrido']);
                $this->assertStringContainsString('«Revertir una importación» en el paso 2', $datos['contexto_del_recorrido']);
                $this->assertNull($datos['respuesta_que_invito']);
                return Http::response($clave === 'aclaracion'
                    ? ['content' => [['type' => 'text', 'text' => '{}']]]
                    : $this->respuesta_deepseek($guion));
            },
            self::PUENTE => function ($request) use ($entrante, $ticket, $esperado) {
                $this->assertSame($entrante->uuid, $request['uuid']);
                $this->assertSame($ticket->uuid, $request['ticket_uuid']);
                $this->assertSame(17, $request['user_id']);
                $this->assertSame($esperado, $request['pasos']);
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
            'pregunta de la guía' => [$formulario, 200, 'vender.metodos_pago'],
            'indicación sin ancla' => [$formulario, 200, 'indicacion'],
            'aclaración dentro de la guía' => [$formulario, 200, 'aclaracion'],
            'ancla fuera del catálogo' => [$formulario, 422, 'vender.pago_inexistente'],
            'mismo texto escrito en el chat' => [[], 409, 'vender.metodos_pago'],
            'marca sin sesión apta para Robotito' => [['robotito_support_version' => null] + $formulario, 409, 'vender.metodos_pago'],
        ];
    }

    /** @dataProvider catalogos_para_editar_porcentaje */
    public function test_robotito_recalcula_de_aplicar_un_recargo_a_editar_su_porcentaje(string $ajuste, bool $controles): void
    {
        config(['services.deepseek.api_key' => '']);
        $body = $this->mensaje_erp($this->cliente());
        SupportCodexSettings::set_enabled(true);
        $firma = ['X-Admin-Api-Key' => 'clave-inbound'];
        $body['body'] = '¿Cómo aplico un recargo en Vender?';
        $consulta = SupportMessage::findOrFail($this->postJson(self::INBOUND, $body, $firma)->assertCreated()->json('model.id'));
        $ticket = $consulta->ticket;
        $this->invitacion($ticket, self::GUION_DINAMICO, ['erp_auto_reply' => ['tour_requested' => true]]);
        $pregunta = "Como cambio el porcentaje de un {$ajuste}?";
        $body = array_replace($body, [
            'body' => 'Pregunta para Robotito sobre «Elegir un recargo existente en una venta sin guardar» en el paso 3: ' . $pregunta,
            'message_uuid' => (string) Str::uuid(),
            'robotito_followup' => true,
            'robotito_question' => $pregunta,
        ]);
        $entrante = SupportMessage::findOrFail($this->postJson(self::INBOUND, $body, $firma)->assertCreated()->json('model.id'));
        $headers = $this->reserva($ticket, $entrante);

        $catalogo = self::CATALOGO_DINAMICO;
        $catalogo['pasos'][] = ['clave' => 'vista.abm', 'descripcion' => 'Abrir el ABM.'];
        $claves = ['abm.editar_' . $ajuste, 'abm.porcentaje_' . $ajuste];
        if ($controles) {
            $catalogo['pasos'][] = ['clave' => $claves[0], 'descripcion' => 'Señalar la edición en ABM.'];
            $catalogo['pasos'][] = ['clave' => $claves[1], 'descripcion' => 'Señalar el campo Porcentaje.'];
        }
        Http::fake([
            self::PUENTE . '/catalogo?user_id=17' => Http::response($catalogo),
            self::PUENTE => function ($request) use ($entrante, $ajuste, $claves, $controles) {
                $this->assertSame($entrante->uuid, $request['uuid']);
                $this->assertSame($controles ? $claves : ['vista.abm'], array_column($request['pasos'], 'clave'));
                $ultimo_paso = collect($request['pasos'])->last();
                $this->assertStringContainsString('Porcentaje', $ultimo_paso['texto']);
                if (! $controles) {
                    $this->assertStringContainsString('Precios → ' . ucfirst($ajuste) . 's', $ultimo_paso['texto']);
                }
                return Http::response(['ok' => true, 'uuid' => $request['uuid']]);
            },
        ]);

        $this->postJson(self::CLAUDE . $ticket->id . '/tour/prepare', ['expected_message_id' => $entrante->id], $headers)
            ->assertOk()->assertExactJson(['ok' => true, 'uuid' => $entrante->uuid]);
        Http::assertSentCount(2);
    }

    public function catalogos_para_editar_porcentaje(): array
    {
        return [
            'recargo con controles de ABM' => ['recargo', true],
            'recargo con catálogo anterior' => ['recargo', false],
            'descuento con controles de ABM' => ['descuento', true],
        ];
    }

    /** @dataProvider fallas_al_recalcular */
    public function test_robotito_ofrece_reintentar_en_una_tarjeta_si_falla_el_planificador(string $falla, bool $indicacion): void
    {
        config(['services.deepseek.api_key' => $falla === 'sin_clave' ? '' : 'deepseek-prueba']);
        $pregunta = '¿Cómo reparto el cobro entre dos medios?';
        $body = array_replace($this->mensaje_erp($this->cliente()), [
            'body' => 'Pregunta para Robotito en el paso 2: ' . $pregunta,
            'robotito_followup' => true,
            'robotito_question' => $pregunta,
        ]);
        SupportCodexSettings::set_enabled(true);
        $entrante = SupportMessage::findOrFail($this->postJson(self::INBOUND, $body, ['X-Admin-Api-Key' => 'clave-inbound'])
            ->assertCreated()->json('model.id'));
        $ticket = $entrante->ticket;
        $headers = $this->reserva($ticket, $entrante);
        $catalogo = self::CATALOGO_DINAMICO;
        if (! $indicacion) {
            array_pop($catalogo['pasos']);
        }
        Http::fake([
            self::PUENTE . '/catalogo?user_id=17' => Http::response($catalogo),
            'api.deepseek.com/*' => function () use ($falla) {
                if ($falla === 'timeout') {
                    throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: Operation timed out');
                }
                return $falla === 'respuesta_invalida' ? Http::response($this->respuesta_deepseek([])) : Http::response([], 503);
            },
            self::PUENTE => function ($request) {
                $this->assertSame('Reintentar el recorrido', $request['titulo']);
                $this->assertSame([[
                    'clave' => 'indicacion',
                    'texto' => 'No pude preparar la guía en este momento. Volvé a escribir tu pregunta en «Preguntale a Robotito» para reintentar.',
                ]], $request['pasos']);
                return Http::response(['ok' => true, 'uuid' => $request['uuid']]);
            },
        ]);

        $respuesta = $this->postJson(self::CLAUDE . $ticket->id . '/tour/prepare', ['expected_message_id' => $entrante->id], $headers);
        if ($indicacion) {
            $respuesta->assertOk()->assertExactJson(['ok' => true, 'uuid' => $entrante->uuid]);
        } else {
            $respuesta->assertStatus(422)->assertJsonPath('code', 'tour_plan_unavailable');
            Http::assertNotSent(function ($request) { return $request->url() === self::PUENTE; });
        }
        if ($falla === 'sin_clave') {
            Http::assertNotSent(function ($request) { return strpos($request->url(), 'https://api.deepseek.com/') === 0; });
        }
    }

    public function fallas_al_recalcular(): array
    {
        return [
            'sin clave del proveedor' => ['sin_clave', true],
            'proveedor indisponible' => ['error_http', true],
            'timeout de proveedor' => ['timeout', true],
            'respuesta inválida distinta de objeto vacío' => ['respuesta_invalida', true],
            'catálogo sin indicación' => ['sin_clave', false],
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
        $this->getJson($url, $headers)->assertOk()->assertJsonPath('ticket.robotito_available', false)
            ->assertJsonPath('ticket.capabilities.support_dynamic_tour', false);
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
        $url = self::CLAUDE . $ticket->id;
        $this->getJson($url, ['X-Claude-Task-Key' => 'clave-monitor-tour'])->assertOk()
            ->assertJsonPath('ticket.capabilities.support_dynamic_tour', true);
        $this->invitacion($ticket, self::GUION, ['erp_auto_reply' => ['tour_requested' => true]]);
        $body = array_replace($body, ['body' => 'Sí, quiero hacer el tour.', 'message_uuid' => (string) Str::uuid(), 'robotito_support_version' => null]);
        $aceptacion = SupportMessage::findOrFail($this->postJson(self::INBOUND, $body, $firma)->assertCreated()->json('model.id'));
        $headers = $this->reserva($ticket, $aceptacion);
        $this->getJson($url, $headers)->assertOk()->assertJsonPath('ticket.robotito_available', false)
            ->assertJsonPath('ticket.capabilities.support_dynamic_tour', false)
            ->assertJsonPath('ticket.accepted_tour_request', false)->assertJsonPath('ticket.accepted_tour', null);
        Http::fake();
        $this->postJson($url . '/tour/prepare', ['expected_message_id' => $aceptacion->id], $headers)
            ->assertStatus(409)->assertJsonPath('code', 'tour_consent_required');
        Http::assertNothingSent();
    }

    public function test_martin_conserva_la_oferta_al_recibir_un_si_desde_una_sesion_no_apta(): void
    {
        config(['services.deepseek.api_key' => 'deepseek-prueba']);
        $body = $this->mensaje_erp($this->cliente_con_version('4.3.47'));
        SupportCodexSettings::set_enabled(true);
        $firma = ['X-Admin-Api-Key' => 'clave-inbound'];
        $consulta = SupportMessage::findOrFail($this->postJson(self::INBOUND, $body, $firma)->assertCreated()->json('model.id'));
        $ticket = $consulta->ticket;
        $oferta = $this->invitacion($ticket, self::GUION, [
            'erp_auto_reply' => ['tour_requested' => true, 'tour_import_history_id' => 1059],
        ]);
        $intento = SupportMessage::findOrFail($this->postJson(self::INBOUND, array_replace($body, [
            'body' => 'Dale', 'message_uuid' => (string) Str::uuid(), 'robotito_support_version' => null,
        ]), $firma)->assertCreated()->json('model.id'));
        $url = self::CLAUDE . $ticket->id;
        $headers = $this->reserva($ticket, $intento);
        $this->getJson($url, $headers)->assertOk()->assertJsonPath('ticket.accepted_tour_request', false);
        Http::fake(['empresa-tours.test/api/admin-sync/support/messages' => Http::response(['ok' => true])]);
        $this->postJson($url . '/tour/prepare', ['expected_message_id' => $intento->id], $headers)
            ->assertStatus(409)->assertJsonPath('code', 'tour_consent_required');
        Http::assertNothingSent();
        $respuesta = [
            'lease_token' => $headers['X-Support-Lease-Token'], 'operation_id' => 'recordar-robotito-' . $intento->id,
            'expected_message_id' => $intento->id, 'action' => 'responder', 'body' => 'Explicación del agente sin la invitación.',
        ];
        $operacion = $this->postJson($url . '/reply', $respuesta, $headers)->assertOk()->assertJsonPath('operation.status', 'sent');
        $recordatorio = SupportMessage::findOrFail($operacion->json('operation.message_id'));
        $this->assertStringContainsString(SupportTourService::RETOMAR_DESDE_CUENTA_PROPIA, $recordatorio->body);
        $this->assertTrue(SupportTourService::tiene_invitacion($recordatorio->body));
        $this->assertSame([
            'tour_requested' => true, 'tour_import_history_id' => 1059, 'tour_original_offer_id' => $oferta->id,
        ], $recordatorio->erp_auto_reply);
        $this->postJson($url . '/reply', $respuesta, $headers)->assertOk()->assertJsonPath('operation.message_id', $recordatorio->id);
        Http::assertSentCount(1);

        $aceptacion = SupportMessage::findOrFail($this->postJson(self::INBOUND, array_replace($body, [
            'body' => 'Sí, quiero el tour', 'message_uuid' => (string) Str::uuid(),
        ]), $firma)->assertCreated()->json('model.id'));
        $headers = $this->reserva($ticket, $aceptacion);
        $this->getJson($url, $headers)->assertOk()->assertJsonPath('ticket.accepted_tour_request', true);
        Http::fake([
            self::PUENTE . '/catalogo*' => Http::response(self::CATALOGO_DINAMICO),
            'api.deepseek.com/*' => Http::response($this->respuesta_deepseek(self::GUION_DINAMICO)),
            self::PUENTE => Http::response(['ok' => true, 'uuid' => $aceptacion->uuid]),
        ]);
        $preparar = ['expected_message_id' => $aceptacion->id];
        $this->postJson($url . '/tour/prepare', $preparar, $headers)->assertOk()->assertJsonPath('uuid', $aceptacion->uuid);
        $this->postJson($url . '/tour/prepare', $preparar, $headers)->assertOk()->assertJsonPath('uuid', $aceptacion->uuid);
        $this->assertSame($aceptacion->uuid, $oferta->fresh()->erp_auto_reply['tour_acceptance_uuid']);
        $this->assertArrayNotHasKey('tour_acceptance_uuid', $recordatorio->fresh()->erp_auto_reply);
        Http::assertSent(function ($request) {
            return strpos($request->url(), self::PUENTE . '/catalogo') === 0
                && strpos($request->url(), 'import_history_id=1059') !== false;
        });
        Http::assertSentCount(3);
    }

    /** @dataProvider reanudaciones_tras_aviso_anterior */
    public function test_un_aviso_anterior_solo_recupera_la_oferta_con_consentimiento_explicito_y_evidencia_contigua(
        array $cambios,
        bool $recupera
    ): void {
        $body = $this->mensaje_erp($this->cliente_con_version('4.3.47'));
        $firma = ['X-Admin-Api-Key' => 'clave-inbound'];
        $consulta = SupportMessage::findOrFail($this->postJson(self::INBOUND, $body, $firma)->assertCreated()->json('model.id'));
        $ticket = $consulta->ticket;
        $oferta = $this->invitacion($ticket, self::GUION, [
            'erp_auto_reply' => ['tour_requested' => true], 'synced_to_client_at' => ($cambios['entregada'] ?? true) ? now() : null,
        ]);
        $intento = SupportMessage::findOrFail($this->postJson(self::INBOUND, array_replace($body, [
            'body' => $cambios['intento'] ?? 'Dale', 'message_uuid' => (string) Str::uuid(), 'robotito_support_version' => null,
        ]), $firma)->assertCreated()->json('model.id'));
        $aviso = SupportMessage::create([
            'support_ticket_id' => $ticket->id, 'sender_type' => 'admin', 'kind' => 'text',
            'body' => $cambios['aviso'] ?? 'Para preparar el tour, entrá con tu cuenta propia y volvé al mismo chat.',
            'ai_generated_at' => now(), 'synced_to_client_at' => now(),
        ]);
        SupportCodexOperation::create([
            'support_ticket_id' => $ticket->id, 'message_id' => $aviso->id,
            'expected_message_id' => ! empty($cambios['otra_operacion']) ? $consulta->id : $intento->id,
            'operation_id' => (string) Str::uuid(), 'action' => 'responder', 'status' => $cambios['estado'] ?? 'sent',
            'request_hash' => str_repeat('a', 64),
        ]);
        if (! empty($cambios['intermedio'])) {
            $this->postJson(self::INBOUND, array_replace($body, [
                'body' => '¿Cómo cargo un artículo?', 'message_uuid' => (string) Str::uuid(),
            ]), $firma)->assertCreated();
        }
        $aceptacion = SupportMessage::findOrFail($this->postJson(self::INBOUND, array_replace($body, [
            'body' => $cambios['aceptacion'] ?? 'Sí, quiero el tour', 'message_uuid' => (string) Str::uuid(),
            'robotito_support_version' => ! empty($cambios['sin_marca']) ? null : 1,
        ]), ! empty($cambios['sin_firma']) ? [] : $firma)->assertCreated()->json('model.id'));

        $tours = app(SupportTourService::class);
        $this->assertSame($recupera ? $oferta->id : null, optional($tours->solicitud_aceptada($ticket, $aceptacion))->id);
        if ($recupera) {
            $oferta->update(['erp_auto_reply' => $oferta->erp_auto_reply + ['tour_acceptance_uuid' => $aceptacion->uuid]]);
            $this->assertSame($oferta->id, optional($tours->solicitud_aceptada($ticket, $aceptacion))->id);
            $aceptacion->uuid = (string) Str::uuid();
            $this->assertNull($tours->solicitud_aceptada($ticket, $aceptacion));
        }
    }

    public function reanudaciones_tras_aviso_anterior(): array
    {
        return [
            'aceptación explícita' => [[], true],
            'dale al aviso' => [['aceptacion' => 'Dale'], false],
            'rechazo' => [['aceptacion' => 'No quiero el tour'], false],
            'otra consulta' => [['intermedio' => true], false],
            'respuesta anterior de otro tema' => [['aviso' => 'Una explicación de Martín sobre recargos.'], false],
            'intento previo ambiguo' => [['intento' => '¿Qué es Robotito?'], false],
            'último entrante sin sesión apta' => [['sin_marca' => true], false],
            'último entrante sin firma' => [['sin_firma' => true], false],
            'operación de otro mensaje' => [['otra_operacion' => true], false],
            'operación sin entrega confirmada' => [['estado' => 'unknown'], false],
            'oferta original sin entrega' => [['entregada' => false], false],
        ];
    }

    /** @dataProvider umbrales_de_version */
    public function test_martin_ofrece_por_version_sin_marca_de_sesion(
        ?string $version,
        bool $ofrece,
        ?string $leyenda
    ): void {
        $body = array_replace($this->mensaje_erp($this->cliente_con_version($version)), [
            'body' => '¿Me explicás cómo indico recargos en una venta?', 'robotito_support_version' => null,
        ]);
        SupportCodexSettings::set_enabled(true);
        $consulta = SupportMessage::findOrFail($this->postJson(self::INBOUND, $body, ['X-Admin-Api-Key' => 'clave-inbound'])
            ->assertCreated()->json('model.id'));
        $this->assertArrayNotHasKey('inbound_version', $consulta->erp_auto_reply);
        $ticket = $consulta->ticket;
        $url = self::CLAUDE . $ticket->id;
        $headers = $this->reserva($ticket, $consulta);
        $this->getJson($url, $headers)->assertOk()
            ->assertJsonPath('ticket.capabilities.support_tour_offer', $ofrece)
            ->assertJsonPath('ticket.capabilities.support_dynamic_tour', false);
        Http::fake(['empresa-tours.test/api/admin-sync/support/messages' => Http::response(['ok' => true])]);
        $texto = "En Vender, abrí Cierre y opciones y tildá el recargo.\nhttps://recursos.comerciocity.com/?v=T11.34";
        $this->postJson($url . '/reply', [
            'lease_token' => $headers['X-Support-Lease-Token'], 'operation_id' => 'version-robotito-' . $consulta->id,
            'expected_message_id' => $consulta->id, 'action' => 'responder', 'body' => $texto,
        ], $headers)->assertOk()->assertJsonPath('operation.status', 'sent');
        $respuesta = $this->respuesta_erp($body);
        $this->assertSame($ofrece ? $texto . "\n\n" . $leyenda . "\n\n" . SupportTourService::INVITACION : $texto, $respuesta->body);
        $this->assertSame($ofrece ? ['tour_requested' => true] : null, $respuesta->erp_auto_reply);
        Http::assertSentCount(1);
    }

    public function umbrales_de_version(): array
    {
        return [
            'sin registro conserva el origen como criterio' => [null, true, SupportTourService::ACEPTAR_DESDE_CUENTA_PROPIA],
            'antes del umbral' => ['3.2.46', false, null],
            'umbral exacto' => ['3.2.47', false, null],
            'cuarto componente cero equivale al umbral' => ['3.2.47.0', false, null],
            'por encima y sin recorrido personalizado' => ['3.2.48', true, SupportTourService::ACTUALIZAR_PARA_ABRIR],
            'con recorrido personalizado' => ['4.3.44+build.7', true, SupportTourService::ACEPTAR_DESDE_CUENTA_PROPIA],
        ];
    }

    public function test_la_version_habilita_oferta_escrita_pero_no_reserva_automatica_sin_firma_erp(): void
    {
        $body = array_replace($this->mensaje_erp($this->cliente_con_version('4.3.47')), ['robotito_support_version' => null]);
        SupportCodexSettings::set_enabled(true);
        $consulta = SupportMessage::findOrFail($this->postJson(self::INBOUND, $body)->assertCreated()->json('model.id'));
        $this->assertTrue(SupportTourService::entrante_admite_oferta($consulta->ticket, $consulta));
        $this->assertFalse(SupportTourService::entrante_admite_robotito($consulta->ticket, $consulta));
        $this->postJson(self::CLAUDE . $consulta->support_ticket_id . '/claim', [
            'worker_id' => 'tour-tests', 'expected_message_id' => $consulta->id,
        ], ['X-Claude-Task-Key' => 'clave-monitor-tour'])->assertStatus(409);
    }

    public function test_oferta_usa_version_de_la_instancia_de_prueba_ocupada_y_conserva_el_registro_del_cliente_como_respaldo(): void
    {
        $cliente = $this->cliente_con_version('4.3.47');
        $body = $this->mensaje_erp($cliente);
        $consulta = SupportMessage::findOrFail($this->postJson(self::INBOUND, $body, ['X-Admin-Api-Key' => 'clave-inbound'])
            ->assertCreated()->json('model.id'));
        $instancia = TrialInstance::create([
            'nombre' => 'prueba-version-tour', 'estado' => TrialInstance::ESTADO_ASIGNADA,
            'client_id' => $cliente->id, 'user_id' => 17, 'erp_vps_path' => 'prueba-version-tour',
            'ecommerce_vps_path' => 'tienda-prueba-version-tour', 'version' => '3.2.47',
        ]);
        $this->assertFalse(SupportTourService::entrante_admite_oferta($consulta->ticket, $consulta));
        $instancia->update(['version' => null]);
        $this->assertTrue(SupportTourService::entrante_admite_oferta($consulta->ticket, $consulta));
    }

    public function test_nativa_erp_persiste_la_oferta_por_version_en_un_entrante_sin_marca(): void
    {
        $body = array_replace($this->mensaje_erp($this->cliente_con_version('4.3.27')), ['robotito_support_version' => null]);
        $consulta = SupportMessage::findOrFail($this->postJson(self::INBOUND, $body, ['X-Admin-Api-Key' => 'clave-inbound'])
            ->assertCreated()->json('model.id'));
        Http::fake(['empresa-tours.test/api/admin-sync/support/messages' => Http::response(['ok' => true])]);
        $respuesta = app(SupportAiSuggestionDeliveryService::class)->deliver_erp_auto_reply(
            $consulta->ticket, "Abrí Soporte y tocá Nuevo ticket.\n\n" . SupportTourService::INVITACION, $consulta->id, null, true
        );
        $this->assertStringContainsString(SupportTourService::ACTUALIZAR_PARA_ABRIR, $respuesta->body);
        $this->assertSame(['tour_requested' => true], $respuesta->erp_auto_reply);
        Http::assertSentCount(1);
    }

    public function test_nativa_erp_muestra_la_oferta_antes_de_aprobar_y_envia_solo_al_confirmar(): void
    {
        $body = $this->mensaje_erp($this->cliente_con_version('4.3.47'));
        $this->postJson(self::INBOUND, $body, ['X-Admin-Api-Key' => 'clave-inbound'])->assertCreated();
        SupportTicket::where('uuid', $body['ticket_uuid'])->update(['requiere_verificacion_mensajes' => true]);
        $this->mock(SupportAiSuggestionService::class)->shouldReceive('generate')->once()->andReturn([
            'suggested_message' => "Abrí Soporte y tocá Nuevo ticket.\n\n" . SupportTourService::INVITACION,
            'tour_requested' => true,
        ]);
        Http::fake(['empresa-tours.test/api/admin-sync/support/messages' => Http::response(['ok' => true])]);
        app()->call([Queue::pushed(SendSupportAiSuggestion::class)->first(), 'handle']);
        $borrador = $this->respuesta_erp($body);
        $this->assertTrue($borrador->is_ai_suggestion_draft);
        $this->assertStringContainsString(SupportTourService::REVISION_ANTES_DE_PREPARAR, $borrador->body);
        Http::assertNothingSent();
        $admin = Admin::create(['name' => 'Operador', 'email' => 'operador-version-tour@test.local', 'password' => bcrypt('test')]);
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/support-message/' . $borrador->id . '/approve-ai-draft')
            ->assertOk()->assertJsonPath('delivered', true);
        $enviado = $borrador->fresh();
        $this->assertFalse($enviado->is_ai_suggestion_draft);
        $this->assertSame(['tour_requested' => true], $enviado->erp_auto_reply);
        $this->assertSame($borrador->body, $enviado->body);
        Http::assertSentCount(1);
    }

    public function test_nativa_whatsapp_vincula_la_oferta_al_turno_y_prepara_al_aceptar(): void
    {
        SupportCodexSettings::set_enabled(false);
        config(['services.deepseek.api_key' => 'deepseek-prueba']);
        [$ticket, $consulta] = $this->ticket_whatsapp($this->cliente_con_version('4.3.44'), '+5493419843001', null, false);
        $consulta->update(['body' => '¿Cómo aplico un recargo en Vender?']);
        $this->mock(SupportAiSuggestionService::class)->shouldReceive('generate')->once()->andReturn([
            'suggested_message' => "En Vender, elegí el recargo.\n\n" . SupportTourService::INVITACION,
            'tour_requested' => true,
        ]);
        $this->mock(SupportWhatsappOpenerService::class)->shouldReceive('deliver_follow_up')->twice()
            ->andReturnUsing(function (SupportTicket $ticket, SupportMessage $mensaje) {
                $mensaje->update(['whatsapp_message_id' => 'wamid.version-tour-' . $mensaje->id, 'remote_delivery_status' => null]);
                return ['delivery' => 'sent', 'message_id' => $mensaje->whatsapp_message_id, 'error' => null];
            });
        $scheduler = app(SupportAiSuggestionScheduler::class);
        $scheduler->schedule_after_client_inbound($ticket->id);
        app()->call([Queue::pushed(SendSupportAiSuggestion::class)->last(), 'handle']);
        $invitacion = SupportMessage::where('support_ticket_id', $ticket->id)->where('sender_type', 'admin')->orderByDesc('id')->firstOrFail();
        $this->assertSame(['tour_requested' => true], $invitacion->erp_auto_reply);
        $this->assertTrue(SupportTourService::tiene_invitacion($invitacion->body));

        $aceptacion = $this->entrante_whatsapp($ticket, 'Sí, quiero hacer el tour.');
        $this->assertSame($invitacion->id, optional(app(SupportTourService::class)->solicitud_aceptada($ticket, $aceptacion))->id);
        Http::fake([
            self::PUENTE . '/catalogo*' => Http::response(self::CATALOGO_DINAMICO),
            'api.deepseek.com/*' => Http::response($this->respuesta_deepseek(self::GUION_DINAMICO)),
            self::PUENTE => Http::response(['ok' => true, 'uuid' => $aceptacion->uuid]),
        ]);
        $scheduler->schedule_after_client_inbound($ticket->id);
        app()->call([Queue::pushed(SendSupportAiSuggestion::class)->last(), 'handle']);
        Http::assertSentCount(3);
        $this->assertSame(SupportTourService::AVISO, $ticket->messages()->where('sender_type', 'admin')->reorder('id', 'desc')->value('body'));
        Http::assertSent(function ($request) use ($aceptacion) {
            return $request->url() === self::PUENTE && $request['uuid'] === $aceptacion->uuid;
        });
    }

    private function cliente_con_version(?string $version): Client
    {
        $registro = $version === null ? null : Version::firstOrCreate(['version' => $version],
            ['title' => 'Versión del negocio', 'status' => 'published', 'published_at' => now()]);
        return $this->cliente(['current_version_id' => optional($registro)->id]);
    }

    /** @dataProvider sesiones_de_consulta_y_aceptacion */
    public function test_martin_ofrece_tour_en_la_consulta_de_recargos_aun_sin_campo_del_monitor(
        ?int $marca_consulta,
        ?int $marca_aceptacion
    ): void {
        config(['services.deepseek.api_key' => 'deepseek-prueba']);
        $body = array_replace($this->mensaje_erp($this->cliente()), [
            'body' => 'Como indico recargos en una venta?', 'robotito_support_version' => $marca_consulta,
        ]);
        SupportCodexSettings::set_enabled(true);
        $firma = ['X-Admin-Api-Key' => 'clave-inbound'];
        $consulta = SupportMessage::findOrFail($this->postJson(self::INBOUND, $body, $firma)->assertCreated()->json('model.id'));
        $ticket = $consulta->ticket;
        $url = self::CLAUDE . $ticket->id;
        $headers = $this->reserva($ticket, $consulta);
        $this->getJson($url, $headers)->assertOk()
            ->assertJsonPath('ticket.capabilities.support_tour_offer', true)
            ->assertJsonPath('ticket.capabilities.support_dynamic_tour', $marca_consulta !== null);

        Http::fake(['empresa-tours.test/api/admin-sync/support/messages' => Http::response(['ok' => true])]);
        $texto = "Buenas tardes! En Vender, abrí Cierre y opciones. En Recargos, tildá el que querés aplicar."
            . "\n\nAcá tenés el video: https://recursos.comerciocity.com/?v=T11.34";
        $respuesta = [
            'lease_token' => $headers['X-Support-Lease-Token'], 'operation_id' => 'ticket-92-' . $consulta->id,
            'expected_message_id' => $consulta->id, 'action' => 'responder', 'body' => $texto,
        ];
        $this->postJson($url . '/reply', $respuesta, $headers)->assertOk()->assertJsonPath('operation.status', 'sent');
        $this->postJson($url . '/reply', $respuesta, $headers)->assertOk()->assertJsonPath('operation.status', 'sent');
        $invitacion = $this->respuesta_erp($body);
        $esperado = $texto . ($marca_consulta === null ? "\n\n" . SupportTourService::ACEPTAR_DESDE_CUENTA_PROPIA : '')
            . "\n\n" . SupportTourService::INVITACION;
        $this->assertSame($esperado, $invitacion->body);
        $this->assertSame($texto, SupportTourService::quitar_invitacion($invitacion->body));
        $this->assertSame(['tour_requested' => true], $invitacion->erp_auto_reply);
        Http::assertSentCount(1);

        $body = array_replace($body, [
            'body' => 'Sí, quiero hacer el tour.', 'message_uuid' => (string) Str::uuid(),
            'robotito_support_version' => $marca_aceptacion,
        ]);
        $aceptacion = SupportMessage::findOrFail($this->postJson(self::INBOUND, $body, $firma)->assertCreated()->json('model.id'));
        $headers = $this->reserva($ticket, $aceptacion);
        $this->getJson($url, $headers)->assertOk()->assertJsonPath('ticket.accepted_tour_request', $marca_aceptacion !== null);
        Http::fake([
            self::PUENTE . '/catalogo*' => Http::response(self::CATALOGO_DINAMICO),
            'api.deepseek.com/*' => Http::response($this->respuesta_deepseek(self::GUION_DINAMICO)),
            self::PUENTE => Http::response(['ok' => true, 'uuid' => $aceptacion->uuid]),
        ]);
        $preparacion = $this->postJson($url . '/tour/prepare', ['expected_message_id' => $aceptacion->id], $headers);
        if ($marca_aceptacion === null) {
            $preparacion->assertStatus(409)->assertJsonPath('code', 'tour_consent_required');
        } else {
            $preparacion->assertOk()->assertJsonPath('uuid', $aceptacion->uuid);
        }
        Http::assertSentCount($marca_aceptacion === null ? 0 : 3);
    }

    public function sesiones_de_consulta_y_aceptacion(): array
    {
        return [
            'consulta y aceptación desde cuenta propia' => [1, 1],
            'consulta delegada y aceptación desde cuenta propia' => [null, 1],
            'consulta y aceptación delegadas' => [null, null],
        ];
    }

    /** @dataProvider respuestas_clasificadas_de_martin */
    public function test_martin_ofrece_tour_por_tipo_de_respuesta_sin_confundir_aclaraciones_o_rechazos(
        string $consulta_texto,
        string $texto,
        array $clasificacion,
        bool $ofrece
    ): void {
        $body = array_replace($this->mensaje_erp($this->cliente()), [
            'body' => $consulta_texto, 'robotito_support_version' => null,
        ]);
        SupportCodexSettings::set_enabled(true);
        $firma = ['X-Admin-Api-Key' => 'clave-inbound'];
        $consulta = SupportMessage::findOrFail($this->postJson(self::INBOUND, $body, $firma)->assertCreated()->json('model.id'));
        $headers = $this->reserva($consulta->ticket, $consulta);
        Http::fake(['empresa-tours.test/api/admin-sync/support/messages' => Http::response(['ok' => true])]);
        $this->postJson(self::CLAUDE . $consulta->support_ticket_id . '/reply', $clasificacion + [
            'lease_token' => $headers['X-Support-Lease-Token'], 'operation_id' => 'uso-' . $consulta->id,
            'expected_message_id' => $consulta->id, 'action' => 'responder', 'body' => $texto,
        ], $headers)->assertOk()->assertJsonPath('operation.status', 'sent');
        $respuesta = $this->respuesta_erp($body);
        $this->assertSame($ofrece, SupportTourService::tiene_invitacion($respuesta->body));
        $this->assertSame($ofrece ? ['tour_requested' => true] : null, $respuesta->erp_auto_reply);
        Http::assertSentCount(1);
    }

    public function respuestas_clasificadas_de_martin(): array
    {
        return [
            'consulta indirecta clasificada por Martín' => [
                'Necesito sumar un porcentaje a una venta.', 'Abrí Vender y elegí un recargo.', ['usage_answer' => true], true,
            ],
            'pregunta para aclarar' => [
                '¿Cómo aplico recargos?', '¿Lo querés aplicar solo a una venta o a todas?', ['usage_answer' => true], false,
            ],
            'pedido de dato sin signos' => [
                '¿Cómo aplico recargos?', 'Escribí si el recargo es de tarjeta o de toda la venta.', [], false,
            ],
            'rechazo' => [
                'No quiero el tour, gracias.', 'Podés seguir los pasos en Vender.', ['usage_answer' => true], false,
            ],
            'saludo' => ['¿Cómo estás?', 'Todo bien, gracias.', [], false],
        ];
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

    /** @dataProvider clasificaciones_del_agente_documental */
    public function test_el_agente_web_agrega_solo_el_video_verificado_por_la_herramienta(array $clasificacion): void
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
        $fin = $clasificacion + [
            'suggested_message' => 'Abrí Soporte y tocá Nuevo ticket.', 'tipo_respuesta' => 'afirmacion_del_sistema',
            'fuentes_kb' => ['manual_sistema/general/soporte-tecnico.md'], 'tour' => self::GUION,
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

    public function clasificaciones_del_agente_documental(): array
    {
        return [
            'invita explícitamente' => [['invitar_tour' => true]],
            'omite el campo en una consulta de uso' => [[]],
        ];
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

    /*
    |------------------------------------------------------------------------------------------------
    | Ticket 107 (12/11/2026): el dueño pidió el recorrido, aceptó la invitación y el admin contestó
    | tour_consent_required las dos veces. Canal sistema, empresa 4.3.53 con su catálogo real.
    |------------------------------------------------------------------------------------------------
    */

    private const CAMINO_107 = 'Para verlo, entrá a ABM → Precios → Impuestos sobre ventas → Ingresos Brutos → casilla "Activo".';
    private const PEDIDO_107 = 'mostrámelo con el robotito ese. quiero ver dónde se apaga lo de ingresos brutos';
    private const GUION_107 = [
        'tema' => 'guia_dinamica', 'titulo' => 'Dónde se apaga Ingresos Brutos',
        'pasos' => [
            ['clave' => 'abm.grupo.precios', 'texto' => 'Dentro de ABM, tocá Precios.'],
            ['clave' => 'abm.solapa.precios.impuestos_sobre_ventas', 'texto' => 'Ahora tocá Impuestos sobre ventas.'],
            ['clave' => 'indicacion', 'texto' => 'Abrí "Ingresos Brutos" tocando su fila.'],
            ['clave' => 'indicacion', 'texto' => 'La casilla "Activo" lo prende o lo apaga. Destildala y guardá.'],
        ],
    ];

    private function catalogo_4_3_53(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/catalogo_tours_empresa_4_3_53.json')), true);
    }

    /** Arma el ticket del sistema con un primer mensaje del dueño y devuelve [ticket, mensaje, cuerpo, firma]. */
    private function ticket_erp_107(string $primer_mensaje): array
    {
        $body = $this->mensaje_erp($this->cliente());
        SupportCodexSettings::set_enabled(true);
        $firma = ['X-Admin-Api-Key' => 'clave-inbound'];
        $body['body'] = $primer_mensaje;
        $mensaje = SupportMessage::findOrFail($this->postJson(self::INBOUND, $body, $firma)->assertCreated()->json('model.id'));

        return [$mensaje->ticket, $mensaje, $body, $firma];
    }

    private function invitacion_de_martin_107(SupportTicket $ticket): SupportMessage
    {
        return $this->invitacion($ticket, self::GUION_DINAMICO, [
            'body' => "Raúl, lo revisé y \"Ingresos Brutos\" ya está desactivado en tu sistema.\n\n" . self::CAMINO_107
                . " Esa casilla tiene que quedar destildada.\n\nEste video muestra esa configuración: https://recursos.comerciocity.com/?v=T11.31\n\n"
                . "Todavía no pude activar Robotito con tu pedido. Te dejo la invitación para que te señale esa casilla.\n\n"
                . SupportTourService::INVITACION,
            'erp_auto_reply' => ['tour_requested' => true],
        ]);
    }

    private function puente_107(array $catalogo, $modelo, ?array &$recibido): void
    {
        Http::fake([
            self::PUENTE . '/catalogo?user_id=17' => Http::response($catalogo),
            'api.deepseek.com/*' => $modelo,
            self::PUENTE => function ($request) use (&$recibido) {
                $recibido = $request->data();
                return Http::response(['ok' => true, 'uuid' => $request['uuid']]);
            },
        ]);
    }

    public function test_107_el_pedido_del_recorrido_con_palabras_propias_es_el_consentimiento_y_el_catalogo_de_4_3_53_pasa(): void
    {
        config(['services.deepseek.api_key' => 'deepseek-prueba']);
        [$ticket, $pedido] = $this->ticket_erp_107(self::PEDIDO_107);
        $headers = $this->reserva($ticket, $pedido);
        $recibido = null;
        $this->puente_107($this->catalogo_4_3_53(), Http::response($this->respuesta_deepseek(self::GUION_107)), $recibido);

        $this->getJson(self::CLAUDE . $ticket->id, $headers)->assertOk()
            ->assertJsonPath('ticket.accepted_tour_request', true)->assertJsonPath('ticket.last_tour_failure', null);
        $this->postJson(self::CLAUDE . $ticket->id . '/tour/prepare', ['expected_message_id' => $pedido->id], $headers)
            ->assertOk()->assertExactJson(['ok' => true, 'uuid' => $pedido->uuid]);

        $this->assertSame(self::GUION_107['pasos'], $recibido['pasos']);
        $this->assertSame($pedido->uuid, $recibido['uuid']);
        Http::assertSent(function ($request) {
            if (strpos($request->url(), 'https://api.deepseek.com/') !== 0) {
                return false;
            }
            $contenido = json_decode($request['messages'][0]['content'], true);
            // El modelo recibe el pedido del dueño como la tarea y las claves de una 4.3.53: las 112 del catálogo
            // menos revertir una importación, que solo viaja con un destino verificado.
            return $contenido['pregunta_durante_recorrido'] === self::PEDIDO_107
                && count($contenido['pasos_disponibles']) === 111
                && in_array('abm.solapa.precios.impuestos_sobre_ventas', array_column($contenido['pasos_disponibles'], 'clave'), true);
        });
    }

    public function test_107_si_ya_te_dije_que_si_acepta_la_invitacion_que_dejo_martin(): void
    {
        config(['services.deepseek.api_key' => 'deepseek-prueba']);
        [$ticket, , $body, $firma] = $this->ticket_erp_107('¿Dónde apago ingresos brutos?');
        $this->invitacion_de_martin_107($ticket);
        $si = SupportMessage::findOrFail($this->postJson(self::INBOUND, array_replace($body, [
            'body' => 'sí, ya te dije que sí', 'message_uuid' => (string) Str::uuid(),
        ]), $firma)->assertCreated()->json('model.id'));
        $headers = $this->reserva($ticket, $si);
        $recibido = null;
        $this->puente_107($this->catalogo_4_3_53(), Http::response($this->respuesta_deepseek(self::GUION_107)), $recibido);

        $this->getJson(self::CLAUDE . $ticket->id, $headers)->assertOk()->assertJsonPath('ticket.accepted_tour_request', true);
        $this->postJson(self::CLAUDE . $ticket->id . '/tour/prepare', ['expected_message_id' => $si->id], $headers)
            ->assertOk()->assertExactJson(['ok' => true, 'uuid' => $si->uuid]);
        $this->assertSame(self::GUION_107['pasos'], $recibido['pasos']);
    }

    /** @dataProvider respuestas_a_la_invitacion_107 */
    public function test_107_que_respuestas_valen_como_si_a_la_invitacion(string $respuesta, bool $acepta): void
    {
        [$ticket, , $body, $firma] = $this->ticket_erp_107('¿Dónde apago ingresos brutos?');
        $this->invitacion_de_martin_107($ticket);
        $entrante = SupportMessage::findOrFail($this->postJson(self::INBOUND, array_replace($body, [
            'body' => $respuesta, 'message_uuid' => (string) Str::uuid(),
        ]), $firma)->assertCreated()->json('model.id'));

        $this->assertSame($acepta, app(SupportTourService::class)->solicitud_aceptada($ticket->fresh(), $entrante) !== null);
    }

    public function respuestas_a_la_invitacion_107(): array
    {
        $casos = [];
        // Aceptan: un sí hecho solo de rellenos, o el pedido de verlo con Robotito.
        foreach (['sí', 'sí dale', 'si, gracias', 'sí, ya te dije que sí', 'Sí sí, dale nomás', 'dale, hacelo por favor',
            'mostrámelo con el robotito ese', self::PEDIDO_107] as $respuesta) {
            $casos['acepta: ' . $respuesta] = [$respuesta, true];
        }
        // No aceptan (revisión del 12/11/2026): empiezan con un sí pero aplazan, contestan otra cosa o ponen una condición.
        foreach (['sí pero después', 'sí, pero después', 'dale, pero primero cargame la lista', 'no, gracias', 'no, no quiero el tour',
            'sí, mañana', 'sí, mañana a la tarde', 'sí, ahora estoy ocupado', 'sí, el lunes', 'sí, cuando puedas', 'sí, ya lo hice',
            'sí, ya lo encontré, gracias', 'sí, ya está', 'sí, es mi costo', 'sí, ese es el precio', 'dale, hacé el tour mañana',
            'dale lo vemos', 'si me decís cuánto sale', 'gracias, ya lo encontré', 'sí, ¿y cómo cargo un artículo?', 'ok',
            'no quiero el robotito', 'el robotito no anda', '¿qué es el robotito?', 'el tour de bienvenida no lo vi',
            'mandame el robotito mañana', 'quisiera cancelar el tour'] as $respuesta) {
            $casos['no acepta: ' . $respuesta] = [$respuesta, false];
        }

        return $casos;
    }

    public function test_107_una_invitacion_de_mas_de_un_dia_ya_no_se_acepta_con_un_si(): void
    {
        [$ticket, , $body, $firma] = $this->ticket_erp_107('¿Dónde apago ingresos brutos?');
        $invitacion = $this->invitacion_de_martin_107($ticket);
        $si = SupportMessage::findOrFail($this->postJson(self::INBOUND, array_replace($body, [
            'body' => 'Sí', 'message_uuid' => (string) Str::uuid(),
        ]), $firma)->assertCreated()->json('model.id'));
        $tours = app(SupportTourService::class);

        $invitacion->forceFill(['created_at' => now()->subHours(23)])->save();
        $this->assertNotNull($tours->solicitud_aceptada($ticket->fresh(), $si));
        $invitacion->forceFill(['created_at' => now()->subHours(25)])->save();
        $this->assertNull($tours->solicitud_aceptada($ticket->fresh(), $si));
    }

    /** @dataProvider mensajes_que_piden_o_no_el_recorrido */
    public function test_107_que_mensajes_son_un_pedido_del_recorrido(string $mensaje, bool $pide): void
    {
        $this->assertSame($pide, SupportTourService::pide_recorrido(Str::ascii(mb_strtolower($mensaje))));
    }

    public function mensajes_que_piden_o_no_el_recorrido(): array
    {
        $casos = [];
        foreach ([self::PEDIDO_107, 'mostrámelo con el robotito', 'preparame un recorrido guiado para cargar precios',
            'quiero que robotito me muestre dónde se cargan los precios'] as $mensaje) {
            $casos['pide: ' . $mensaje] = [$mensaje, true];
        }
        // Revisión del 12/11/2026: un verbo de pedido y la palabra «tour» no alcanzan. Ante la duda, no.
        foreach (['quisiera cancelar el tour', 'quiero un tour por el local', 'quiero que el tour me lo hagas otro día',
            'mandame el robotito mañana', 'quiero saber si el tour sirve', 'necesito un tour?',
            'necesito que me muestres el tour de ventas que no anda', 'quiero ver el tour',
            'Quiero el tour para ver cómo se cargan los precios', 'me lo mostrás con Robotito?',
            'no quiero el robotito', 'el robotito no anda', '¿qué es el robotito?', 'el tour de bienvenida no lo vi',
            'ya no necesito el robotito', 'quiero hablar con una persona, no con el robotito ni el tour',
            'mostrame cómo hago una venta, sin el tour', 'no quiero el tour, explicame por acá',
            'quiero saber para qué sirve el tour', 'mostrame el robotito cuando puedas', 'quiero el robotito el lunes',
            '¿Cómo apago ingresos brutos?', 'El recorrido del repartidor quedó mal cargado',
            // Empiezan con un sí: contestan una invitación y lo resuelven las reglas de la invitación.
            'dale, preparame un recorrido guiado', 'sí, mostrame con el robotito'] as $mensaje) {
            $casos['no pide: ' . $mensaje] = [$mensaje, false];
        }

        return $casos;
    }

    /** @dataProvider caminos_para_el_recorrido_minimo */
    public function test_107_el_recorrido_minimo_sigue_el_camino_y_no_lo_primero_que_encuentra(array $textos, array $claves): void
    {
        $pasos = [];
        foreach ($this->catalogo_4_3_53()['pasos'] as $paso) {
            $pasos[$paso['clave']] = $paso + ['requiere_destino' => false];
        }
        $minimo = new \ReflectionMethod(\App\Services\SupportTourPlannerService::class, 'recorrido_minimo');
        $minimo->setAccessible(true);
        $guion = $minimo->invoke(new \App\Services\SupportTourPlannerService(), $pasos, $textos, '');

        $this->assertSame($claves, array_column($guion['pasos'], 'clave'));
        $this->assertNotNull(SupportTourService::guion($guion), 'El recorrido mínimo pasa la validación del guion.');
    }

    public function caminos_para_el_recorrido_minimo(): array
    {
        return [
            'la solapa del camino le gana a otra de nombre más largo nombrada después' => [
                ["Entrá a ABM → Articulos → Marcas.\nNo es en Tipos de Propiedad de Articulo, eso es otra cosa."],
                ['abm.grupo.articulos', 'abm.solapa.articulos.marcas', 'indicacion'],
            ],
            'la solapa del camino le gana a la de un mensaje anterior' => [
                ['Andá a ABM → Precios → Recargos.', 'Antes te hablé de ABM → Precios → Impuestos sobre ventas.'],
                ['abm.grupo.precios', 'abm.solapa.precios.recargos', 'indicacion'],
            ],
            'una palabra que contiene el nombre de una solapa no es esa solapa' => [
                ['En ABM → Ventas vas a ver la lista. Si desmarcas la casilla no se guarda.'],
                ['vista.abm'],
            ],
            'la pantalla es la que está justo antes de la flecha, no una negada antes' => [
                ['En Reportes no está; andá a Ventas → Todas → botón de filtro.'],
                ['vista.ventas'],
            ],
            'sin pantalla reconocible queda la tarjeta con el camino' => [
                ['Tocá tu nombre → Configuración → General.'],
                ['indicacion'],
            ],
        ];
    }

    /** @dataProvider fallas_del_modelo_107 */
    public function test_107_si_el_modelo_no_arma_el_recorrido_se_entrega_el_minimo_con_la_solapa_y_el_camino($modelo): void
    {
        config(['services.deepseek.api_key' => 'deepseek-prueba']);
        [$ticket, , $body, $firma] = $this->ticket_erp_107('¿Dónde apago ingresos brutos?');
        $this->invitacion_de_martin_107($ticket);
        $si = SupportMessage::findOrFail($this->postJson(self::INBOUND, array_replace($body, [
            'body' => 'Sí', 'message_uuid' => (string) Str::uuid(),
        ]), $firma)->assertCreated()->json('model.id'));
        $headers = $this->reserva($ticket, $si);
        $recibido = null;
        $this->puente_107($this->catalogo_4_3_53(), $modelo($this), $recibido);

        $this->postJson(self::CLAUDE . $ticket->id . '/tour/prepare', ['expected_message_id' => $si->id], $headers)
            ->assertOk()->assertExactJson(['ok' => true, 'uuid' => $si->uuid]);
        $this->assertSame('Ir a Impuestos sobre ventas', $recibido['titulo']);
        $this->assertSame([
            ['clave' => 'abm.grupo.precios', 'texto' => 'En el ABM, tocá «Precios».'],
            ['clave' => 'abm.solapa.precios.impuestos_sobre_ventas', 'texto' => 'Ahora tocá «Impuestos sobre ventas».'],
            // Desde el ticket 110 el camino manda: la tarjeta final trae solo lo que el catálogo no sabe señalar.
            ['clave' => 'indicacion', 'texto' => 'En esta solapa seguí: Ingresos Brutos → casilla "Activo". Esa casilla tiene que quedar destildada.'],
        ], $recibido['pasos']);
    }

    public function fallas_del_modelo_107(): array
    {
        return [
            'el modelo no responde' => [function () { return Http::response([], 503); }],
            'devuelve algo que no es un recorrido' => [function (self $test) { return Http::response($test->respuesta_deepseek(['hola' => 'mundo'])); }],
            'elige una clave que no existe' => [function (self $test) {
                return Http::response($test->respuesta_deepseek(['tema' => 'guia_dinamica', 'titulo' => 'x', 'pasos' => [
                    ['clave' => 'abm.solapa.precios.ingresos_brutos', 'texto' => 'Tocá Ingresos Brutos.'],
                ]]));
            }],
            'no entiende la tarea' => [function (self $test) { return Http::response($test->respuesta_deepseek([])); }],
        ];
    }

    public function test_107_en_un_sistema_4_3_52_el_minimo_abre_el_abm_con_el_camino(): void
    {
        config(['services.deepseek.api_key' => '']);
        [$ticket, , $body, $firma] = $this->ticket_erp_107('¿Dónde apago ingresos brutos?');
        $this->invitacion_de_martin_107($ticket);
        $si = SupportMessage::findOrFail($this->postJson(self::INBOUND, array_replace($body, [
            'body' => 'Sí', 'message_uuid' => (string) Str::uuid(),
        ]), $firma)->assertCreated()->json('model.id'));
        $headers = $this->reserva($ticket, $si);
        $catalogo = self::CATALOGO_DINAMICO;
        $catalogo['pasos'][] = ['clave' => 'vista.abm', 'descripcion' => 'Abrir el ABM y mostrar una indicación textual.'];
        $recibido = null;
        $this->puente_107($catalogo, Http::response([], 500), $recibido);

        $this->postJson(self::CLAUDE . $ticket->id . '/tour/prepare', ['expected_message_id' => $si->id], $headers)->assertOk();
        $this->assertSame([['clave' => 'vista.abm', 'texto' => 'Seguí este camino: ' . self::CAMINO_107 . ' Esa casilla tiene que quedar destildada.']], $recibido['pasos']);
        Http::assertNotSent(function ($request) { return strpos($request->url(), 'https://api.deepseek.com/') === 0; });
    }

    public function test_107_un_tour_que_no_sale_deja_el_motivo_en_la_respuesta_en_el_log_y_en_el_contexto(): void
    {
        config(['services.deepseek.api_key' => 'deepseek-prueba']);
        \Illuminate\Support\Facades\Log::spy();

        // Un mensaje que no acepta ni pide el recorrido.
        [$ticket, , $body, $firma] = $this->ticket_erp_107('¿Dónde apago ingresos brutos?');
        $this->invitacion_de_martin_107($ticket);
        $gracias = SupportMessage::findOrFail($this->postJson(self::INBOUND, array_replace($body, [
            'body' => 'gracias, después lo miro', 'message_uuid' => (string) Str::uuid(),
        ]), $firma)->assertCreated()->json('model.id'));
        $headers = $this->reserva($ticket, $gracias);
        $this->postJson(self::CLAUDE . $ticket->id . '/tour/prepare', ['expected_message_id' => $gracias->id], $headers)
            ->assertStatus(409)->assertJsonPath('code', 'tour_consent_required')
            ->assertJsonPath('reason', 'El último mensaje de la persona no se reconoció como un sí a la invitación ni como un pedido del recorrido.');
        $this->getJson(self::CLAUDE . $ticket->id, $headers)->assertOk()
            ->assertJsonPath('ticket.last_tour_failure.code', 'tour_consent_required')
            ->assertJsonPath('ticket.last_tour_failure.expected_message_id', $gracias->id);
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->withArgs(function ($mensaje, $contexto) use ($ticket) {
            return $mensaje === 'Robotito: tour/prepare no entregó el recorrido.'
                && $contexto['ticket_id'] === (int) $ticket->id && $contexto['code'] === 'tour_consent_required';
        })->once();

        // Con un mensaje nuevo de la persona, el fallo del anterior ya no describe el turno.
        $otro = SupportMessage::findOrFail($this->postJson(self::INBOUND, array_replace($body, [
            'body' => '¿Y cómo cargo un artículo?', 'message_uuid' => (string) Str::uuid(),
        ]), $firma)->assertCreated()->json('model.id'));
        $this->assertNotNull(\App\Services\SupportDynamicTourService::ultimo_fallo((int) $ticket->id, (int) $gracias->id));
        $this->assertNull(\App\Services\SupportDynamicTourService::ultimo_fallo((int) $ticket->id, (int) $otro->id));

    }

    public function test_107_sin_catalogo_del_comercio_el_motivo_lo_dice(): void
    {
        config(['services.deepseek.api_key' => 'deepseek-prueba']);
        [$ticket, $pedido] = $this->ticket_erp_107(self::PEDIDO_107);
        $headers = $this->reserva($ticket, $pedido);
        Http::fake([self::PUENTE . '/catalogo?user_id=17' => Http::response(['message' => 'Server Error'], 500)]);
        $this->postJson(self::CLAUDE . $ticket->id . '/tour/prepare', ['expected_message_id' => $pedido->id], $headers)
            ->assertStatus(422)->assertJsonPath('code', 'tour_plan_unavailable')
            ->assertJsonPath('reason', 'El sistema del comercio no entregó su catálogo de pasos (sin conexión, versión anterior o persona sin identificar).');
        $this->getJson(self::CLAUDE . $ticket->id, $headers)->assertOk()
            ->assertJsonPath('ticket.last_tour_failure.code', 'tour_plan_unavailable');
    }

    /*
    |------------------------------------------------------------------------------------------------
    | Ticket 110 (12/11/2026): el consentimiento anduvo, pero el modelo devolvió cuatro tarjetas de texto
    | («abrí el ABM y buscá el grupo donde…») teniendo las claves del grupo y la solapa. El camino manda.
    |------------------------------------------------------------------------------------------------
    */

    private const PEDIDO_110 = 'mostrame con el robotito donde se apaga lo de ingresos brutos';
    private const PATH_110 = 'ABM → Precios → Impuestos sobre ventas → Ingresos Brutos → casilla "Activo"';
    private const PLAN_DE_TEXTO_110 = [
        'tema' => 'guia_dinamica', 'titulo' => 'Apagar Ingresos Brutos',
        'pasos' => [
            ['clave' => 'indicacion', 'texto' => 'Ingresos Brutos se configura en el ABM. Abrí el ABM y buscá el grupo donde están los impuestos sobre ventas.'],
            ['clave' => 'indicacion', 'texto' => 'Entrá al grupo de precios y abrí la solapa de impuestos sobre ventas.'],
            ['clave' => 'indicacion', 'texto' => 'Buscá en la lista el impuesto de Ingresos Brutos y abrí su edición. Si no lo encontrás en esta solapa, revisá el grupo Facturacion.'],
            ['clave' => 'indicacion', 'texto' => 'Destildá la casilla Activo y guardá los cambios.'],
        ],
    ];

    /** Prepara el recorrido del pedido del ticket 110 y devuelve [respuesta, lo que recibió Empresa]. */
    private function preparar_110($modelo, ?string $path, ?array $catalogo = null): array
    {
        config(['services.deepseek.api_key' => 'deepseek-prueba']);
        [$ticket, $pedido] = $this->ticket_erp_107(self::PEDIDO_110);
        $headers = $this->reserva($ticket, $pedido);
        $recibido = null;
        $this->puente_107($catalogo ?? $this->catalogo_4_3_53(), $modelo, $recibido);
        $respuesta = $this->postJson(self::CLAUDE . $ticket->id . '/tour/prepare',
            ['expected_message_id' => $pedido->id] + ($path === null ? [] : ['path' => $path]), $headers);

        return [$respuesta, $recibido];
    }

    public function test_110_con_camino_los_pasos_con_ancla_se_arman_sin_modelo(): void
    {
        \Illuminate\Support\Facades\Log::spy();
        [$respuesta, $recibido] = $this->preparar_110(Http::response([], 503), self::PATH_110);

        $respuesta->assertOk();
        $this->assertSame([
            ['clave' => 'abm.grupo.precios', 'texto' => 'En el ABM, tocá «Precios».'],
            ['clave' => 'abm.solapa.precios.impuestos_sobre_ventas', 'texto' => 'Ahora tocá «Impuestos sobre ventas».'],
            ['clave' => 'indicacion', 'texto' => 'En esta solapa seguí: Ingresos Brutos → casilla "Activo"'],
        ], $recibido['pasos']);
        \Illuminate\Support\Facades\Log::shouldHaveReceived('info')->withArgs(function ($mensaje, $contexto) {
            return $mensaje === 'Robotito: recorrido planificado.' && $contexto['origen'] === 'determinista'
                && $contexto['pasos'] === 3 && $contexto['con_ancla'] === 2;
        })->once();
    }

    public function test_110_el_plan_de_puro_texto_del_modelo_no_le_saca_las_anclas_al_camino(): void
    {
        [$respuesta, $recibido] = $this->preparar_110(Http::response($this->respuesta_deepseek(self::PLAN_DE_TEXTO_110)), self::PATH_110);

        $respuesta->assertOk();
        $this->assertSame(['abm.grupo.precios', 'abm.solapa.precios.impuestos_sobre_ventas', 'indicacion', 'indicacion'], array_column($recibido['pasos'], 'clave'));
        // Lo que el modelo agrega es solo lo que el catálogo no sabe señalar, y nada que mande a buscar.
        $this->assertSame('En esta solapa seguí: Ingresos Brutos → casilla "Activo"', $recibido['pasos'][2]['texto']);
        $this->assertSame('Destildá la casilla Activo y guardá los cambios.', $recibido['pasos'][3]['texto']);
        $this->assertSame('Apagar Ingresos Brutos', $recibido['titulo']);
        foreach ($recibido['pasos'] as $paso) {
            $this->assertStringNotContainsString('Si no lo encontrás', $paso['texto']);
            $this->assertStringNotContainsString('buscá el grupo', $paso['texto']);
        }
        Http::assertSent(function ($request) {
            return strpos($request->url(), 'https://api.deepseek.com/') === 0
                && json_decode($request['messages'][0]['content'], true)['pasos_fijos'] === ['abm.grupo.precios', 'abm.solapa.precios.impuestos_sobre_ventas'];
        });
    }

    public function test_110_el_modelo_puede_redactar_los_pasos_fijos_pero_no_reordenarlos_ni_cambiarlos(): void
    {
        $plan = ['tema' => 'guia_dinamica', 'titulo' => 'Apagar Ingresos Brutos', 'pasos' => [
            ['clave' => 'indicacion', 'texto' => 'Primero mirá el menú de la izquierda.'],
            ['clave' => 'abm.solapa.precios.impuestos_sobre_ventas', 'texto' => 'Tocá la solapa Impuestos sobre ventas.'],
            ['clave' => 'abm.grupo.precios', 'texto' => 'Si no lo ves, revisá el grupo Precios.'],
            ['clave' => 'indicacion', 'texto' => 'Abrí "Ingresos Brutos" tocando su fila y destildá "Activo".'],
            ['clave' => 'vista.listado', 'texto' => 'Así quedan los precios en el Listado.'],
        ]];
        [$respuesta, $recibido] = $this->preparar_110(Http::response($this->respuesta_deepseek($plan)), self::PATH_110);

        $respuesta->assertOk();
        $this->assertSame([
            // El orden es el del camino; el texto dudoso del modelo no se usa y el del otro paso sí.
            ['clave' => 'abm.grupo.precios', 'texto' => 'En el ABM, tocá «Precios».'],
            ['clave' => 'abm.solapa.precios.impuestos_sobre_ventas', 'texto' => 'Tocá la solapa Impuestos sobre ventas.'],
            ['clave' => 'indicacion', 'texto' => 'Abrí "Ingresos Brutos" tocando su fila y destildá "Activo".'],
        ], $recibido['pasos'], 'El último paso no lleva al Listado.');
    }

    public function test_110_el_camino_que_escribio_soporte_en_la_invitacion_tambien_manda(): void
    {
        config(['services.deepseek.api_key' => 'deepseek-prueba']);
        [$ticket, , $body, $firma] = $this->ticket_erp_107('¿Dónde apago ingresos brutos?');
        $this->invitacion_de_martin_107($ticket);
        $si = SupportMessage::findOrFail($this->postJson(self::INBOUND, array_replace($body, [
            'body' => 'Sí', 'message_uuid' => (string) Str::uuid(),
        ]), $firma)->assertCreated()->json('model.id'));
        $headers = $this->reserva($ticket, $si);
        $recibido = null;
        $this->puente_107($this->catalogo_4_3_53(), Http::response($this->respuesta_deepseek(self::PLAN_DE_TEXTO_110)), $recibido);

        $this->postJson(self::CLAUDE . $ticket->id . '/tour/prepare', ['expected_message_id' => $si->id], $headers)->assertOk();
        $this->assertSame(['abm.grupo.precios', 'abm.solapa.precios.impuestos_sobre_ventas'], array_slice(array_column($recibido['pasos'], 'clave'), 0, 2));
        $this->assertStringContainsString('Ingresos Brutos → casilla "Activo"', $recibido['pasos'][2]['texto']);
    }

    /*
    |------------------------------------------------------------------------------------------------
    | A2 (12/11/2026): el camino del Listado se ancla con las claves de la ficha del artículo que Empresa
    | publica desde la 4.3.58, solo si están en el catálogo de ESE pedido.
    |------------------------------------------------------------------------------------------------
    */

    private const CAMINO_DEL_PRECIO = 'Listado → ficha del artículo → Precio → Precio final → Setear precio final → Guardar';
    private const CLAVES_DE_LA_FICHA = [
        'listado.buscador' => 'Abrir el Listado y señalar el buscador de artículos.',
        'listado.abrir_ficha' => 'Señalar la tabla del Listado; avanza cuando la persona abre la ficha de un artículo.',
        'listado.pestana_precio' => 'Señalar la pestaña Precio de la ficha del artículo.',
        'listado.setear_precio_final' => 'Señalar el interruptor «Setear precio final» (precio manual) de la ficha.',
        'listado.precio_final' => 'Señalar el campo Precio final de la ficha.',
        'listado.guardar_articulo' => 'Señalar Guardar en la ficha; avanza cuando la ficha se cierra.',
    ];

    /** El catálogo de la 4.3.53 más las claves de la ficha, menos las de `$sin`. */
    private function catalogo_con_ficha(array $sin = []): array
    {
        $catalogo = $this->catalogo_4_3_53();
        foreach (self::CLAVES_DE_LA_FICHA as $clave => $descripcion) {
            if (! in_array($clave, $sin, true)) {
                $catalogo['pasos'][] = ['clave' => $clave, 'descripcion' => $descripcion];
            }
        }

        return $catalogo;
    }

    /** @dataProvider caminos_del_listado */
    public function test_que_tramos_del_camino_del_listado_se_anclan(string $camino, ?array $sin, array $claves, string $resto = ''): void
    {
        $pasos = [];
        foreach (($sin === null ? $this->catalogo_4_3_53() : $this->catalogo_con_ficha($sin))['pasos'] as $paso) {
            $pasos[$paso['clave']] = $paso + ['requiere_destino' => false];
        }
        $planificador = new \App\Services\SupportTourPlannerService();
        $anclar = new \ReflectionMethod($planificador, 'pasos_del_camino');
        $anclar->setAccessible(true);
        $resultado = $anclar->invoke($planificador, $pasos, $camino);

        $this->assertSame($claves, array_column($resultado['anclados'], 'clave'));
        $this->assertSame($resto, $resultado['resto']);
        $this->assertFalse($resultado['ambiguo']);
    }

    public function caminos_del_listado(): array
    {
        $todo = ['vista.listado', 'listado.buscador', 'listado.abrir_ficha', 'listado.pestana_precio', 'listado.setear_precio_final', 'listado.guardar_articulo'];

        return [
            'el del ticket 115, con todas las claves' => [self::CAMINO_DEL_PRECIO, [], $todo],
            'Precio final sin Setear ancla el campo' => ['Listado → ficha del artículo → Precio → Precio final → Guardar', [],
                ['vista.listado', 'listado.buscador', 'listado.abrir_ficha', 'listado.pestana_precio', 'listado.precio_final', 'listado.guardar_articulo']],
            'con otros nombres' => ['Entrá al Listado → el artículo → Precios → Setear precio final → Guardar y cerrar', [], $todo],
            // Tercera revisión (12/11/2026; antes anclaba la ficha): sin llegar a la pestaña Precio no es el recorrido
            // de la ficha, y decide el modelo.
            'solo la ficha' => ['Listado → ficha', [], []],
            'la ficha sin Precio: como antes' => ['Listado → el artículo → Stock → Guardar', [], []],
            // Los artículos en plural son otra operación del Listado: no se anclan como «abrir la ficha».
            'seleccionar los artículos no es abrir la ficha' => ['Listado → seleccioná los artículos → Actualizar precios', [], []],
            'tildar los productos no es abrir la ficha' => ['Listado → tildá los productos → Acciones → Eliminar', [], []],
            'Artículos → Importar excel no es abrir la ficha' => ['Listado → Artículos → Importar excel', [], []],
            'artículos con stock bajo no es abrir la ficha' => ['Listado → artículos con stock bajo', [], []],
            'los artículos y después Precio tampoco' => ['Listado → los artículos → Precio → Guardar', [], []],
            // UN artículo en singular sí es la ficha.
            'abrí el producto' => ['Listado → abrí el producto → Precio → Guardar', [],
                ['vista.listado', 'listado.buscador', 'listado.abrir_ficha', 'listado.pestana_precio', 'listado.guardar_articulo']],
            'tocá un artículo' => ['Listado → tocá un artículo → Precio', [],
                ['vista.listado', 'listado.buscador', 'listado.abrir_ficha', 'listado.pestana_precio']],
            'ficha del producto' => ['Listado → ficha del producto → Precio', [],
                ['vista.listado', 'listado.buscador', 'listado.abrir_ficha', 'listado.pestana_precio']],
            // Sin el interruptor en el catálogo, «Precio final» ancla el campo; «Setear» queda en texto.
            'sin Setear en el catálogo ancla el campo Precio final' => [self::CAMINO_DEL_PRECIO, ['listado.setear_precio_final'],
                ['vista.listado', 'listado.buscador', 'listado.abrir_ficha', 'listado.pestana_precio', 'listado.precio_final'],
                'Setear precio final → Guardar'],
            'sin buscador en el catálogo' => [self::CAMINO_DEL_PRECIO, ['listado.buscador'],
                ['vista.listado', 'listado.abrir_ficha', 'listado.pestana_precio', 'listado.setear_precio_final', 'listado.guardar_articulo']],
            'sin la pestaña Precio: el resto queda en texto' => [self::CAMINO_DEL_PRECIO, ['listado.pestana_precio'],
                ['vista.listado', 'listado.buscador', 'listado.abrir_ficha'], 'Precio → Precio final → Setear precio final → Guardar'],
            'sin Guardar: el último tramo queda en texto' => [self::CAMINO_DEL_PRECIO, ['listado.guardar_articulo'],
                ['vista.listado', 'listado.buscador', 'listado.abrir_ficha', 'listado.pestana_precio', 'listado.setear_precio_final'], 'Guardar'],
            'un tramo desconocido corta el anclaje' => ['Listado → ficha → Precio → Margen de ganancia → Guardar', [],
                ['vista.listado', 'listado.buscador', 'listado.abrir_ficha', 'listado.pestana_precio'], 'Margen de ganancia → Guardar'],
            'fuera de orden no se ancla' => ['Listado → Guardar → ficha del artículo', [], []],
            'crear un artículo no es abrir la ficha de uno' => ['Listado → Nuevo artículo → Precio → Guardar', [], []],
            'un sistema sin las claves de la ficha: como antes' => [self::CAMINO_DEL_PRECIO, null, []],
            'sin abrir la ficha en el catálogo: como antes' => [self::CAMINO_DEL_PRECIO, ['listado.abrir_ficha'], []],
            // 4 de 6 (empleado sin permiso de editar): ancla hasta el campo Precio final; Setear y Guardar quedan en texto.
            'sin Setear ni Guardar en el catálogo' => [self::CAMINO_DEL_PRECIO, ['listado.setear_precio_final', 'listado.guardar_articulo'],
                ['vista.listado', 'listado.buscador', 'listado.abrir_ficha', 'listado.pestana_precio', 'listado.precio_final'],
                'Setear precio final → Guardar'],
            // Otras operaciones del Listado que no son la ficha de un artículo.
            'Importar no es la ficha' => ['Listado → Importar', [], []],
            'Crear → Nuevo artículo no es la ficha' => ['Listado → Crear → Nuevo artículo', [], []],
            'Filtros no es la ficha' => ['Listado → Filtros', [], []],
            'el Listado de artículos con su nombre largo' => ['Listado de artículos → ficha del artículo → Precio', [],
                ['vista.listado', 'listado.buscador', 'listado.abrir_ficha', 'listado.pestana_precio']],
            // El primer tramo tiene que ser el Listado de artículos: el de clientes, proveedores o ventas es otra pantalla.
            'el Listado de clientes no es el de artículos' => ['Listado de clientes → ficha → Precio', [], []],
            'el Listado de proveedores no es el de artículos' => ['Listado de proveedores → ficha → Precio → Guardar', [], []],
            'el Listado de ventas no es el de artículos' => ['Listado de ventas → ficha → Precio', [], []],
            // Cuarta revisión (12/11/2026): solo la ficha del artículo; la ficha de otra entidad no se ancla.
            'ficha del proveedor no es la del artículo' => ['Listado → ficha del proveedor → Precio → Guardar', [], []],
            'ficha del cliente no es la del artículo' => ['Listado → ficha del cliente → Precio → Guardar', [], []],
            'ficha de la categoría no es la del artículo' => ['Listado → ficha de la categoría → Precio → Guardar', [], []],
            'ficha del combo no es la del artículo' => ['Listado → ficha del combo → Precio → Guardar', [], []],
            'ficha técnica no es la del artículo' => ['Listado → ficha técnica → Precio → Guardar', [], []],
            'ficha de importación no es la del artículo' => ['Listado → ficha de importación → Precio → Guardar', [], []],
            // Sin la ficha anclada no es el recorrido de la ficha: el buscador solo no alcanza.
            'buscar el artículo sin abrir la ficha: como antes' => ['Listado → Buscá el artículo → Precio → Precio final → Guardar', [], []],
            'catálogo con buscador sin abrir la ficha: como antes' => ['Listado → Buscá el artículo → ficha → Precio → Guardar',
                ['listado.abrir_ficha', 'listado.pestana_precio', 'listado.precio_final', 'listado.setear_precio_final', 'listado.guardar_articulo'], []],
            'duplicar un artículo no es abrir la ficha' => ['Listado → duplicá el artículo → Precio → Guardar', [], []],
            'lo de la ficha después de Guardar no se ancla ni queda en texto' => ['Listado → ficha del artículo → Precio → Guardar → Precio final', [],
                ['vista.listado', 'listado.buscador', 'listado.abrir_ficha', 'listado.pestana_precio', 'listado.guardar_articulo']],
            // Los caminos reales siguen dando el recorrido completo.
            'abrí el artículo' => ['Listado → abrí el artículo → Precio → Setear precio final → Guardar', [], $todo],
            'ficha sola' => ['Listado → ficha → Precio → Guardar', [],
                ['vista.listado', 'listado.buscador', 'listado.abrir_ficha', 'listado.pestana_precio', 'listado.guardar_articulo']],
            'abrí la ficha del artículo' => ['Listado → abrí la ficha del artículo → Precio', [],
                ['vista.listado', 'listado.buscador', 'listado.abrir_ficha', 'listado.pestana_precio']],
        ];
    }

    /** @dataProvider caminos_del_abm_como_en_master */
    public function test_los_caminos_del_abm_se_anclan_igual_que_antes(string $camino, array $claves, string $resto): void
    {
        // Los caminos del ABM no pasan por el Listado: dan lo mismo que antes de anclar la ficha, aunque el catálogo la traiga.
        $pasos = [];
        foreach ($this->catalogo_con_ficha()['pasos'] as $paso) {
            $pasos[$paso['clave']] = $paso + ['requiere_destino' => false];
        }
        $planificador = new \App\Services\SupportTourPlannerService();
        $anclar = new \ReflectionMethod($planificador, 'pasos_del_camino');
        $anclar->setAccessible(true);
        $resultado = $anclar->invoke($planificador, $pasos, $camino);

        $this->assertSame($claves, array_column($resultado['anclados'], 'clave'));
        $this->assertSame($resto, $resultado['resto']);
        $this->assertArrayNotHasKey('prefijo', $resultado);
    }

    public function caminos_del_abm_como_en_master(): array
    {
        return [
            'Precios → Impuestos sobre ventas' => [self::PATH_110,
                ['abm.grupo.precios', 'abm.solapa.precios.impuestos_sobre_ventas'], 'Ingresos Brutos → casilla "Activo"'],
            'Articulos → Marcas' => ['ABM → Articulos → Marcas', ['abm.grupo.articulos', 'abm.solapa.articulos.marcas'], ''],
            'Precios → Recargos' => ['ABM → Precios → Recargos', ['abm.grupo.precios', 'abm.solapa.precios.recargos'], ''],
        ];
    }

    /** El recorrido del camino del Listado cuando Soporte lo pasa por `path`, con el modelo caído (503). */
    private function preparar_listado(array $catalogo): array
    {
        return $this->preparar_110(Http::response([], 503), self::CAMINO_DEL_PRECIO, $catalogo);
    }

    public function test_115_el_camino_del_listado_se_ancla_sin_modelo_si_el_catalogo_trae_la_ficha(): void
    {
        \Illuminate\Support\Facades\Log::spy();
        [$respuesta, $recibido] = $this->preparar_listado($this->catalogo_con_ficha());

        $respuesta->assertOk();
        $this->assertSame([
            ['clave' => 'vista.listado', 'texto' => 'Abrí el Listado.'],
            ['clave' => 'listado.buscador', 'texto' => 'Buscá el artículo por nombre o código.'],
            ['clave' => 'listado.abrir_ficha', 'texto' => 'Tocá el artículo para abrir su ficha.'],
            ['clave' => 'listado.pestana_precio', 'texto' => 'Tocá la pestaña «Precio».'],
            ['clave' => 'listado.setear_precio_final', 'texto' => 'Activá «Setear precio final» y escribí el precio nuevo.'],
            ['clave' => 'listado.guardar_articulo', 'texto' => 'Tocá «Guardar» para guardar el cambio.'],
        ], $recibido['pasos']);
        $this->assertSame('Cambiar el precio de un artículo', $recibido['titulo']);
        \Illuminate\Support\Facades\Log::shouldHaveReceived('info')->withArgs(function ($mensaje, $contexto) {
            return $mensaje === 'Robotito: recorrido planificado.' && $contexto['origen'] === 'determinista'
                && $contexto['camino'] === 'path' && $contexto['tramos_anclados'] === 6;
        })->once();
    }

    public function test_115_con_4_de_las_6_claves_ancla_hasta_el_precio_final_y_el_resto_queda_en_texto(): void
    {
        // Un empleado sin permiso de editar: el sistema no publica el interruptor ni Guardar.
        \Illuminate\Support\Facades\Log::spy();
        $catalogo = $this->catalogo_con_ficha(['listado.setear_precio_final', 'listado.guardar_articulo']);
        [$respuesta, $recibido] = $this->preparar_listado($catalogo);

        $respuesta->assertOk();
        $this->assertSame([
            ['clave' => 'vista.listado', 'texto' => 'Abrí el Listado.'],
            ['clave' => 'listado.buscador', 'texto' => 'Buscá el artículo por nombre o código.'],
            ['clave' => 'listado.abrir_ficha', 'texto' => 'Tocá el artículo para abrir su ficha.'],
            ['clave' => 'listado.pestana_precio', 'texto' => 'Tocá la pestaña «Precio».'],
            ['clave' => 'listado.precio_final', 'texto' => 'Este es el «Precio final» del artículo.'],
            ['clave' => 'indicacion', 'texto' => 'En la ficha seguí: Setear precio final → Guardar'],
        ], $recibido['pasos']);
        // Nunca se manda una clave que el catálogo de ese pedido no trae.
        $del_catalogo = array_column($catalogo['pasos'], 'clave');
        foreach ($recibido['pasos'] as $paso) {
            $this->assertTrue($paso['clave'] === 'indicacion' || in_array($paso['clave'], $del_catalogo, true), $paso['clave']);
        }
        \Illuminate\Support\Facades\Log::shouldHaveReceived('info')->withArgs(function ($mensaje, $contexto) {
            return $mensaje === 'Robotito: recorrido planificado.' && $contexto['origen'] === 'determinista'
                && $contexto['camino'] === 'path' && $contexto['tramos_anclados'] === 5;
        })->once();
    }

    public function test_115_sin_las_claves_de_la_ficha_el_camino_del_listado_sigue_como_antes(): void
    {
        // Un cliente en una versión anterior: sin anclas de la ficha, el respaldo abre el Listado con el camino en texto.
        \Illuminate\Support\Facades\Log::spy();
        [$respuesta, $recibido] = $this->preparar_listado($this->catalogo_4_3_53());

        $respuesta->assertOk();
        $this->assertSame([['clave' => 'vista.listado', 'texto' => 'Seguí este camino: ' . self::CAMINO_DEL_PRECIO]], $recibido['pasos']);
        \Illuminate\Support\Facades\Log::shouldHaveReceived('info')->withArgs(function ($mensaje, $contexto) {
            return $mensaje === 'Robotito: recorrido planificado.' && $contexto['camino'] === 'path' && $contexto['tramos_anclados'] === 0;
        })->once();
    }

    public function test_el_modelo_redacta_los_pasos_del_listado_pero_no_los_reordena(): void
    {
        $plan = ['tema' => 'guia_dinamica', 'titulo' => 'Cambiar el precio del tornillo', 'pasos' => [
            ['clave' => 'listado.pestana_precio', 'texto' => 'Andá a la pestaña Precio.'],
            ['clave' => 'listado.abrir_ficha', 'texto' => 'Abrí el tornillo autoperforante.'],
            ['clave' => 'listado.guardar_articulo', 'texto' => 'Guardá.'],
            ['clave' => 'indicacion', 'texto' => 'Revisá que el precio nuevo quede en la lista.'],
        ]];
        [$respuesta, $recibido] = $this->preparar_pedido('mostrame con el robotito', $plan, self::CAMINO_DEL_PRECIO, $this->catalogo_con_ficha());

        $respuesta->assertOk();
        $this->assertSame(['vista.listado', 'listado.buscador', 'listado.abrir_ficha', 'listado.pestana_precio',
            'listado.setear_precio_final', 'listado.guardar_articulo', 'indicacion'], array_column($recibido['pasos'], 'clave'));
        $this->assertSame('Abrí el tornillo autoperforante.', $recibido['pasos'][2]['texto']);
        $this->assertSame('Andá a la pestaña Precio.', $recibido['pasos'][3]['texto']);
        $this->assertSame('Cambiar el precio del tornillo', $recibido['titulo']);
        Http::assertSent(function ($request) {
            return strpos($request->url(), 'https://api.deepseek.com/') === 0
                && json_decode($request['messages'][0]['content'], true)['pasos_fijos'] === ['vista.listado', 'listado.buscador',
                    'listado.abrir_ficha', 'listado.pestana_precio', 'listado.setear_precio_final', 'listado.guardar_articulo'];
        });
    }

    public function test_la_cola_del_modelo_no_suma_claves_de_la_ficha_al_camino_del_listado(): void
    {
        // El modelo agrega el campo Precio final después de Guardar y repite la pestaña Precio: no aparecen.
        $plan = ['tema' => 'guia_dinamica', 'titulo' => 'Cambiar el precio del tornillo', 'pasos' => [
            ['clave' => 'listado.abrir_ficha', 'texto' => 'Abrí el tornillo autoperforante.'],
            ['clave' => 'listado.pestana_precio', 'texto' => 'Andá a la pestaña Precio.'],
            ['clave' => 'listado.guardar_articulo', 'texto' => 'Guardá.'],
            ['clave' => 'listado.precio_final', 'texto' => 'Mirá el precio final.'],
            ['clave' => 'listado.pestana_precio', 'texto' => 'Volvé a la pestaña Precio.'],
            ['clave' => 'indicacion', 'texto' => 'Revisá que el precio nuevo quede en la lista.'],
        ]];
        [$respuesta, $recibido] = $this->preparar_pedido('mostrame con el robotito', $plan, self::CAMINO_DEL_PRECIO, $this->catalogo_con_ficha());

        $respuesta->assertOk();
        $this->assertSame(['vista.listado', 'listado.buscador', 'listado.abrir_ficha', 'listado.pestana_precio',
            'listado.setear_precio_final', 'listado.guardar_articulo', 'indicacion'], array_column($recibido['pasos'], 'clave'));
        $this->assertSame('Andá a la pestaña Precio.', $recibido['pasos'][3]['texto']);
        $this->assertSame('Revisá que el precio nuevo quede en la lista.', $recibido['pasos'][6]['texto']);
    }

    public function test_en_un_sistema_sin_la_ficha_el_camino_del_listado_sigue_como_antes_sin_error(): void
    {
        $plan = ['tema' => 'guia_dinamica', 'titulo' => 'Cambiar un precio', 'pasos' => [
            ['clave' => 'vista.listado', 'texto' => 'Abrí el Listado.'],
            ['clave' => 'indicacion', 'texto' => 'Abrí el artículo, andá a Precio y cambiá el precio final.'],
        ]];
        [$respuesta, $recibido] = $this->preparar_pedido('mostrame con el robotito', $plan, self::CAMINO_DEL_PRECIO,
            $this->catalogo_con_ficha(['listado.abrir_ficha', 'listado.pestana_precio', 'listado.setear_precio_final']));

        $respuesta->assertOk();
        $this->assertSame($plan['pasos'], $recibido['pasos']);
    }

    public function test_110_en_un_sistema_4_3_52_el_camino_abre_el_abm_con_la_tarjeta_acoplada(): void
    {
        $catalogo = self::CATALOGO_DINAMICO;
        $catalogo['pasos'][] = ['clave' => 'vista.abm', 'descripcion' => 'Abrir el ABM y mostrar una indicación textual.'];
        [$respuesta, $recibido] = $this->preparar_110(Http::response($this->respuesta_deepseek(self::PLAN_DE_TEXTO_110)), self::PATH_110, $catalogo);

        $respuesta->assertOk();
        $this->assertSame([['clave' => 'vista.abm', 'texto' => 'Seguí este camino: ' . self::PATH_110]], $recibido['pasos']);
    }

    public function test_110_sin_camino_un_plan_de_puro_texto_que_manda_a_buscar_no_se_entrega(): void
    {
        \Illuminate\Support\Facades\Log::spy();
        [$respuesta, $recibido] = $this->preparar_110(Http::response($this->respuesta_deepseek(self::PLAN_DE_TEXTO_110)), null);

        $respuesta->assertStatus(422)->assertJsonPath('code', 'tour_plan_unavailable');
        $this->assertStringContainsString('manda a buscar', $respuesta->json('reason'));
        $this->assertNull($recibido);
    }

    public function test_110_sin_camino_un_plan_sin_anclas_cae_al_respaldo_que_si_las_tiene(): void
    {
        \Illuminate\Support\Facades\Log::spy();
        config(['services.deepseek.api_key' => 'deepseek-prueba']);
        [$ticket, , $body, $firma] = $this->ticket_erp_107('¿Dónde apago ingresos brutos?');
        // Soporte explicó sin flechas: no hay camino, pero nombra la solapa.
        $this->invitacion($ticket, self::GUION_DINAMICO, [
            'body' => "Entrá al ABM y, en Precios, abrí Impuestos sobre ventas. Ahí destildás Activo en Ingresos Brutos.\n\n" . SupportTourService::INVITACION,
            'erp_auto_reply' => ['tour_requested' => true],
        ]);
        $si = SupportMessage::findOrFail($this->postJson(self::INBOUND, array_replace($body, [
            'body' => 'Sí', 'message_uuid' => (string) Str::uuid(),
        ]), $firma)->assertCreated()->json('model.id'));
        $headers = $this->reserva($ticket, $si);
        $recibido = null;
        $plan = ['tema' => 'guia_dinamica', 'titulo' => 'Apagar Ingresos Brutos', 'pasos' => [
            ['clave' => 'indicacion', 'texto' => 'Abrí el ABM y entrá a Precios.'],
            ['clave' => 'indicacion', 'texto' => 'Destildá Activo en Ingresos Brutos.'],
        ]];
        $this->puente_107($this->catalogo_4_3_53(), Http::response($this->respuesta_deepseek($plan)), $recibido);

        $this->postJson(self::CLAUDE . $ticket->id . '/tour/prepare', ['expected_message_id' => $si->id], $headers)->assertOk();
        $this->assertSame(['abm.grupo.precios', 'abm.solapa.precios.impuestos_sobre_ventas', 'indicacion'], array_column($recibido['pasos'], 'clave'));
        \Illuminate\Support\Facades\Log::shouldHaveReceived('info')->withArgs(function ($mensaje, $contexto) {
            return $mensaje === 'Robotito: recorrido planificado.' && $contexto['origen'] === 'respaldo'
                && $contexto['con_ancla'] === 2 && $contexto['motivo'] === 'plan_sin_anclas';
        })->once();
    }

    public function test_110_un_plan_del_modelo_con_anclas_se_entrega_y_queda_registrado_como_modelo(): void
    {
        \Illuminate\Support\Facades\Log::spy();
        $plan = self::GUION_107;
        $plan['pasos'][] = ['clave' => 'vista.listado', 'texto' => 'Así quedan los precios en el Listado.'];
        [$respuesta, $recibido] = $this->preparar_110(Http::response($this->respuesta_deepseek($plan)), null);

        $respuesta->assertOk();
        $this->assertSame(self::GUION_107['pasos'], $recibido['pasos'], 'El paso final que llevaba al Listado no viaja.');
        \Illuminate\Support\Facades\Log::shouldHaveReceived('info')->withArgs(function ($mensaje, $contexto) {
            return $mensaje === 'Robotito: recorrido planificado.' && $contexto['origen'] === 'modelo' && $contexto['con_ancla'] === 2;
        })->once();
    }

    /*
    |------------------------------------------------------------------------------------------------
    | Revisión de la rama 3 (12/11/2026): el camino no puede tirar un plan del modelo que ya señala, ni una
    | frase normal de una instrucción («si no aparece, buscalo por nombre») puede invalidarlo.
    |------------------------------------------------------------------------------------------------
    */

    /** El catálogo de un sistema anterior: `sin` saca las claves que empiezan con esos prefijos. */
    private function catalogo_sin(array $prefijos): array
    {
        $catalogo = $this->catalogo_4_3_53();
        $catalogo['pasos'] = array_values(array_filter($catalogo['pasos'], function (array $paso) use ($prefijos) {
            foreach ($prefijos as $prefijo) {
                if (strpos($paso['clave'], $prefijo) === 0) {
                    return false;
                }
            }
            return true;
        }));

        return $catalogo;
    }

    /** Prepara el recorrido de un pedido hecho con palabras propias y devuelve [respuesta, lo que recibió Empresa]. */
    private function preparar_pedido(string $pedido, array $plan_del_modelo, ?string $path, array $catalogo): array
    {
        config(['services.deepseek.api_key' => 'deepseek-prueba']);
        [$ticket, $mensaje] = $this->ticket_erp_107($pedido);
        $headers = $this->reserva($ticket, $mensaje);
        $recibido = null;
        $this->puente_107($catalogo, Http::response($this->respuesta_deepseek($plan_del_modelo)), $recibido);
        $respuesta = $this->postJson(self::CLAUDE . $ticket->id . '/tour/prepare',
            ['expected_message_id' => $mensaje->id] + ($path === null ? [] : ['path' => $path]), $headers);

        return [$respuesta, $recibido];
    }

    /** @dataProvider planes_anclados_con_frases_normales */
    public function test_un_plan_con_anclas_se_entrega_aunque_tenga_frases_condicionales(string $pedido, array $pasos): void
    {
        // Catálogo de una 4.3.48: sin ninguna clave del ABM.
        [$respuesta, $recibido] = $this->preparar_pedido($pedido, ['tema' => 'guia_dinamica', 'titulo' => 'Recorrido', 'pasos' => $pasos],
            null, $this->catalogo_sin(['abm.']));

        $respuesta->assertOk();
        $this->assertSame($pasos, $recibido['pasos']);
    }

    public function planes_anclados_con_frases_normales(): array
    {
        return [
            'compra' => ['mostrame con el robotito cómo cargo una compra', [
                ['clave' => 'vista.compras', 'texto' => 'Entrá a Compras.'],
                ['clave' => 'compras.nueva', 'texto' => 'Tocá el botón para crear la compra. Si no está cargado el proveedor, crealo primero.'],
            ]],
            'venta' => ['mostrame con el robotito cómo hago una venta', [
                ['clave' => 'vender.buscar_articulo', 'texto' => 'Abrí el buscador de artículos.'],
                ['clave' => 'vender.campo_busqueda', 'texto' => 'Escribí el nombre. Si no aparece, buscalo por nombre más corto.'],
                ['clave' => 'vender.total', 'texto' => 'Revisá el total antes de guardar.'],
            ]],
            'venta a un cliente' => ['mostrame con el robotito cómo le vendo a un cliente', [
                ['clave' => 'vista.vender', 'texto' => 'Abrí Vender.'],
                ['clave' => 'indicacion', 'texto' => 'Elegí el cliente; tal vez tengas que crearlo antes.'],
                ['clave' => 'vender.total', 'texto' => 'Revisá el total antes de guardar.'],
            ]],
            'artículo con un campo que puede estar vacío' => ['mostrame con el robotito cómo cargo un artículo', [
                ['clave' => 'vista.listado', 'texto' => 'Abrí el Listado.'],
                ['clave' => 'listado.nuevo_articulo', 'texto' => 'Tocá Crear. El código de barras puede estar vacío.'],
            ]],
            'artículo simple' => ['mostrame con el robotito dónde creo un artículo', [
                ['clave' => 'listado.nuevo_articulo', 'texto' => 'Tocá Crear para abrir la ficha del artículo.'],
            ]],
        ];
    }

    /** @dataProvider caminos_que_no_son_del_abm_nuevo */
    public function test_un_camino_que_no_ancla_grupo_y_solapa_conserva_el_plan_anclado_del_modelo(string $path, array $sin, array $pasos): void
    {
        [$respuesta, $recibido] = $this->preparar_pedido('mostrame con el robotito cómo se hace', ['tema' => 'guia_dinamica', 'titulo' => 'Recorrido', 'pasos' => $pasos],
            $path, $this->catalogo_sin($sin));

        $respuesta->assertOk();
        $this->assertSame($pasos, $recibido['pasos']);
    }

    public function caminos_que_no_son_del_abm_nuevo(): array
    {
        return [
            'Proveedores → Compras → Crear' => ['Proveedores → Compras → Crear', [], [
                ['clave' => 'vista.compras', 'texto' => 'Entrá a Compras.'],
                ['clave' => 'compras.nueva', 'texto' => 'Tocá el botón para crear la compra.'],
            ]],
            'Vender → buscador → artículo' => ['Vender → buscador → artículo', [], [
                ['clave' => 'vender.buscar_articulo', 'texto' => 'Abrí el buscador de artículos.'],
                ['clave' => 'vender.campo_busqueda', 'texto' => 'Escribí el nombre del artículo.'],
                ['clave' => 'vender.resultados_busqueda', 'texto' => 'Elegí el artículo de la lista.'],
            ]],
            'ABM en un sistema 4.3.52, que ya tenía abm.editar_recargo' => ['ABM → Precios → Recargos → Porcentaje', ['abm.grupo.', 'abm.solapa.'], [
                ['clave' => 'abm.editar_recargo', 'texto' => 'Tocá el recargo que querés editar.'],
                ['clave' => 'abm.porcentaje_recargo', 'texto' => 'Cambiá el porcentaje y guardá.'],
            ]],
        ];
    }

    public function test_el_camino_del_abm_se_fusiona_con_los_controles_que_señala_el_modelo(): void
    {
        $plan = ['tema' => 'guia_dinamica', 'titulo' => 'Cambiar un recargo', 'pasos' => [
            ['clave' => 'vista.abm', 'texto' => 'Abrí el ABM.'],
            ['clave' => 'abm.editar_recargo', 'texto' => 'Tocá el recargo que querés editar.'],
            ['clave' => 'abm.porcentaje_recargo', 'texto' => 'Cambiá el porcentaje y guardá.'],
        ]];
        [$respuesta, $recibido] = $this->preparar_pedido('mostrame con el robotito cómo cambio un recargo', $plan,
            'ABM → Precios → Recargos → el recargo → Porcentaje', $this->catalogo_4_3_53());

        $respuesta->assertOk();
        $this->assertSame([
            ['clave' => 'abm.grupo.precios', 'texto' => 'En el ABM, tocá «Precios».'],
            ['clave' => 'abm.solapa.precios.recargos', 'texto' => 'Ahora tocá «Recargos».'],
            ['clave' => 'abm.editar_recargo', 'texto' => 'Tocá el recargo que querés editar.'],
            ['clave' => 'abm.porcentaje_recargo', 'texto' => 'Cambiá el porcentaje y guardá.'],
        ], $recibido['pasos']);
    }

    /** @dataProvider tramos_del_camino */
    public function test_que_tramos_del_camino_se_anclan(string $camino, array $claves, bool $ambiguo, string $resto = ''): void
    {
        $pasos = [];
        foreach ($this->catalogo_4_3_53()['pasos'] as $paso) {
            $pasos[$paso['clave']] = $paso + ['requiere_destino' => false];
        }
        $planificador = new \App\Services\SupportTourPlannerService();
        $leer = new \ReflectionMethod($planificador, 'camino_de');
        $leer->setAccessible(true);
        $anclar = new \ReflectionMethod($planificador, 'pasos_del_camino');
        $anclar->setAccessible(true);
        $resultado = $anclar->invoke($planificador, $pasos, $leer->invoke($planificador, [$camino]));

        $this->assertSame($claves, array_column($resultado['anclados'], 'clave'));
        $this->assertSame($ambiguo, $resultado['ambiguo']);
        $this->assertSame($resto, $resultado['resto']);
    }

    public function tramos_del_camino(): array
    {
        $precios_recargos = ['abm.grupo.precios', 'abm.solapa.precios.recargos'];

        return [
            'el del ticket 110' => [self::PATH_110, ['abm.grupo.precios', 'abm.solapa.precios.impuestos_sobre_ventas'], false, 'Ingresos Brutos → casilla "Activo"'],
            'sin espacios y con el signo mayor' => ['ABM>Precios>Recargos', $precios_recargos, false],
            'con guion y mayor' => ['Entrá a ABM -> Precios -> Recargos', $precios_recargos, false],
            'un tramo desconocido en el medio se saltea' => ['ABM → Configuración → Precios → Recargos', $precios_recargos, false],
            'la solapa está en un solo grupo y no es el nombrado: manda el suyo' => ['ABM → Ventas → Recargos', $precios_recargos, false],
            'sin grupo, la solapa de un solo grupo trae el suyo' => ['ABM → Recargos → el de tarjeta', $precios_recargos, false, 'el de tarjeta'],
            'la solapa está en varios grupos y el nombrado no la tiene: no se adivina' => ['ABM → Ventas → Métodos de pago', [], true],
            'la solapa está en varios grupos y no se nombró ninguno' => ['ABM → Métodos de pago', [], true],
            'con su grupo no hay ambigüedad' => ['ABM → Cuenta corriente → Métodos de pago', ['abm.grupo.cuenta_corriente', 'abm.solapa.cuenta_corriente.metodos_de_pago'], false],
            'solo el grupo' => ['ABM → Precios → Ingresos Brutos', ['abm.grupo.precios'], false, 'Ingresos Brutos'],
            'otra pantalla: decide el modelo' => ['Proveedores → Compras → Crear', [], false],
            'la pantalla negada antes no cuenta' => ['En el ABM no está; andá a Ventas → Todas', [], false],
        ];
    }

    public function test_un_signo_mayor_suelto_no_es_un_camino(): void
    {
        $planificador = new \App\Services\SupportTourPlannerService();
        $leer = new \ReflectionMethod($planificador, 'camino_de');
        $leer->setAccessible(true);

        $this->assertNull($leer->invoke($planificador, ['Si el costo es >100 se aplica el recargo.', 'Poné <b>negrita</b> > y listo > fin']));
        $this->assertSame('Entrá a ABM → Precios', $leer->invoke($planificador, ['Entrá a ABM > Precios']));
    }

    public function test_un_camino_ambiguo_no_elige_en_silencio_y_queda_en_el_log(): void
    {
        \Illuminate\Support\Facades\Log::spy();
        config(['services.deepseek.api_key' => 'deepseek-prueba']);
        [$ticket, $mensaje] = $this->ticket_erp_107('mostrame con el robotito dónde cargo los métodos de pago');
        $headers = $this->reserva($ticket, $mensaje);
        $recibido = null;
        $this->puente_107($this->catalogo_4_3_53(), Http::response([], 503), $recibido);

        $this->postJson(self::CLAUDE . $ticket->id . '/tour/prepare',
            ['expected_message_id' => $mensaje->id, 'path' => 'ABM → Métodos de pago'], $headers)->assertOk();
        $this->assertSame([['clave' => 'vista.abm', 'texto' => 'Seguí este camino: ABM → Métodos de pago']], $recibido['pasos']);
        \Illuminate\Support\Facades\Log::shouldHaveReceived('info')->withArgs(function ($mensaje, $contexto) {
            return $mensaje === 'Robotito: recorrido planificado.' && $contexto['origen'] === 'respaldo'
                && $contexto['camino'] === 'path' && $contexto['camino_ambiguo'] === true && $contexto['tramos_anclados'] === 0;
        })->once();
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

    // ---------------------------------------------------------------------------------------------------------------
    // Ronda 4 (14/11/2026, ticket 122): A3 el último tramo del camino del Listado, A4 el camino viaja con la invitación.
    // ---------------------------------------------------------------------------------------------------------------

    private const LINEA_122 = 'Para llegar al precio seguí: Listado → ficha del artículo → Precio → Precio final → Setear precio final → Guardar y cerrar. En "Setear precio final" activás el interruptor y escribís el importe.';
    private const CAMINO_122 = 'Listado → ficha del artículo → Precio → Precio final → Setear precio final → Guardar y cerrar';
    private const PROSA_122 = 'Para cambiarle el precio, abrí el Listado, buscá el artículo y tocalo para abrir su ficha. Ahí entrá a la pestaña Precio, activá Setear precio final y escribí el importe.';
    private const PASOS_122 = [
        ['clave' => 'vista.listado', 'texto' => 'Abrí el Listado.'],
        ['clave' => 'listado.buscador', 'texto' => 'Buscá el artículo por nombre o código.'],
        ['clave' => 'listado.abrir_ficha', 'texto' => 'Tocá el artículo para abrir su ficha.'],
        ['clave' => 'listado.pestana_precio', 'texto' => 'Tocá la pestaña «Precio».'],
        ['clave' => 'listado.setear_precio_final', 'texto' => 'Activá «Setear precio final» y escribí el precio nuevo.'],
        ['clave' => 'listado.guardar_articulo', 'texto' => 'Tocá «Guardar y cerrar» para guardar el cambio.'],
    ];

    private function anclar_camino(string $camino): array
    {
        $pasos = [];
        foreach ($this->catalogo_con_ficha()['pasos'] as $paso) {
            $pasos[$paso['clave']] = $paso + ['requiere_destino' => false];
        }
        $planificador = new \App\Services\SupportTourPlannerService();
        $anclar = new \ReflectionMethod($planificador, 'pasos_del_camino');
        $anclar->setAccessible(true);

        return $anclar->invoke($planificador, $pasos, $camino);
    }

    /** @dataProvider ultimos_tramos_del_listado */
    public function test_a3_el_camino_del_listado_termina_en_su_ultimo_tramo(string $camino, array $claves, string $resto): void
    {
        $resultado = $this->anclar_camino($camino);

        $this->assertSame($claves, array_column($resultado['anclados'], 'clave'));
        $this->assertSame($resto, $resultado['resto']);
    }

    public function ultimos_tramos_del_listado(): array
    {
        $todo = ['vista.listado', 'listado.buscador', 'listado.abrir_ficha', 'listado.pestana_precio', 'listado.setear_precio_final', 'listado.guardar_articulo'];
        $hasta_precio = ['vista.listado', 'listado.buscador', 'listado.abrir_ficha', 'listado.pestana_precio'];
        $con_guardar = array_merge($hasta_precio, ['listado.guardar_articulo']);

        return [
            'el renglón del ticket 122' => [self::LINEA_122, $todo, ''],
            'el camino del ticket 122 sin la oración' => [self::CAMINO_122, $todo, ''],
            'Guardar con punto final' => ['Listado → ficha → Precio → Guardar.', $con_guardar, ''],
            'Guardar y otra oración' => ['Listado → ficha → Precio → Guardar. Después revisá el precio en la lista.', $con_guardar, ''],
            'Guardar y la invitación en el mismo renglón' => ['Listado → ficha → Precio → Guardar y cerrar ¿Querés que te prepare un tour con Robotito?', $con_guardar, ''],
            'Guardar y una exclamación' => ['Listado → ficha → Precio → Guardar! Listo.', $con_guardar, ''],
            'Guardá y cerrá' => ['Listado → ficha → Precio → Guardá y cerrá.', $con_guardar, ''],
            'puntos suspensivos' => ['Listado → ficha → Precio → Guardar… y listo', $con_guardar, ''],
            // Un tramo que no se ancla queda en texto, pero sin la oración que le sigue.
            'tramo desconocido con otra oración' => ['Listado → ficha → Precio → Margen de ganancia. Ahí escribís el porcentaje.', $hasta_precio, 'Margen de ganancia'],
            // Los puntos de adentro de un nombre no cortan el camino.
            'abreviatura en el último tramo' => ['Listado → ficha → Precio → Cód. de barras', $hasta_precio, 'Cód. de barras'],
            'dos abreviaturas' => ['Listado → ficha → Precio → Cta. Cte. del cliente', $hasta_precio, 'Cta. Cte. del cliente'],
            'una sigla al final no se toma por fin de oración' => ['Listado → ficha → Precio → IVA. Revisalo', $hasta_precio, 'IVA. Revisalo'],
            'un número con punto' => ['Listado → ficha → Precio → Precio 1.5 veces', $hasta_precio, 'Precio 1.5 veces'],
            'sin nada que cortar queda igual' => [self::CAMINO_DEL_PRECIO, $todo, ''],
            'Guardar a secas conserva su texto' => ['Listado → ficha → Precio → Guardar', $con_guardar, ''],
        ];
    }

    public function test_a3_el_texto_de_guardar_dice_el_boton_que_nombra_el_camino(): void
    {
        $ultimo = function (string $camino): string {
            $anclados = $this->anclar_camino($camino)['anclados'];
            return $anclados[count($anclados) - 1]['texto'];
        };
        $this->assertSame('Tocá «Guardar y cerrar» para guardar el cambio.', $ultimo(self::CAMINO_122));
        $this->assertSame('Tocá «Guardar y cerrar» para guardar el cambio.', $ultimo('Listado → ficha → Precio → Guardá y cerrá'));
        $this->assertSame('Tocá «Guardar» para guardar el cambio.', $ultimo(self::CAMINO_DEL_PRECIO));
    }

    public function test_a3_los_caminos_del_abm_con_otra_oracion_quedan_como_en_master(): void
    {
        // El corte es solo del camino del Listado: el ABM conserva la oración que sigue en la tarjeta (test_107).
        $resultado = $this->anclar_camino(self::PATH_110 . '. Esa casilla tiene que quedar destildada.');

        $this->assertSame(['abm.grupo.precios', 'abm.solapa.precios.impuestos_sobre_ventas'], array_column($resultado['anclados'], 'clave'));
        $this->assertSame('Ingresos Brutos → casilla "Activo". Esa casilla tiene que quedar destildada.', $resultado['resto']);
    }

    public function test_a3_la_invitacion_del_ticket_122_ancla_los_seis_tramos_sin_tarjeta_que_repita(): void
    {
        // Lo medido el 14/11: origen=determinista, pasos=6, con_ancla=5, tramos_anclados=5 y una sexta tarjeta sin ancla.
        \Illuminate\Support\Facades\Log::spy();
        config(['services.deepseek.api_key' => 'deepseek-prueba']);
        [$ticket, , $body, $firma] = $this->ticket_erp_107('y mostrame en la pantalla donde le cambio el precio a un producto');
        $this->invitacion($ticket, self::GUION_DINAMICO, [
            'body' => 'Te pido disculpas. ' . self::LINEA_122 . "\n\n" . SupportTourService::INVITACION,
            'erp_auto_reply' => ['tour_requested' => true],
        ]);
        $si = SupportMessage::findOrFail($this->postJson(self::INBOUND, array_replace($body, [
            'body' => 'si', 'message_uuid' => (string) Str::uuid(),
        ]), $firma)->assertCreated()->json('model.id'));
        $headers = $this->reserva($ticket, $si);
        $recibido = null;
        $this->puente_107($this->catalogo_con_ficha(), Http::response([], 503), $recibido);

        $this->postJson(self::CLAUDE . $ticket->id . '/tour/prepare', ['expected_message_id' => $si->id], $headers)
            ->assertOk()->assertExactJson(['ok' => true, 'uuid' => $si->uuid]);
        $this->assertSame(self::PASOS_122, $recibido['pasos']);
        \Illuminate\Support\Facades\Log::shouldHaveReceived('info')->withArgs(function ($mensaje, $contexto) {
            return $mensaje === 'Robotito: recorrido planificado.' && $contexto['origen'] === 'determinista'
                && $contexto['camino'] === 'explicacion' && $contexto['tramos_anclados'] === 6 && $contexto['con_ancla'] === 6;
        })->once();
    }
}

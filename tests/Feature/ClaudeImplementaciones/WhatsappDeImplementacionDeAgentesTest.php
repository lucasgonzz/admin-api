<?php

namespace Tests\Feature\ClaudeImplementaciones;

use App\Models\Admin;
use App\Models\AdminSetting;
use App\Models\AgentImplementation;
use App\Models\AgentImplementationEvent;
use App\Models\AgentImplementationStage;
use App\Models\Client;
use App\Models\SupportCodexCase;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\SupportCodexSettings;
use App\Services\WhatsappSendService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * `POST claude/agent-implementations/{id}/whatsapp`: el WhatsApp de la implementación de Agentes de IA sale por
 * el ticket de soporte del dueño (misión `implementacion-agentes-ia`, 28/10/2026), con los mismos frenos que el
 * de la implementación del sistema.
 *
 * Se verifica: que salga por el ticket y deje el evento `whatsapp` y la operación en la etapa; que la misma
 * `operation_id` no mande dos veces (y con otro contenido sea 409); los 423 de Martín (atendiendo y con un
 * entrante pendiente) sin mandar nada; el 422 con el circuito de Codex apagado; que un envío fallido no deje
 * evento; y los frenos de la entrada.
 *
 * El envío se sustituye a nivel `WhatsappSendService` (el mismo espía que `WhatsappDeHitoPorSoporteTest`): no
 * se toca Kapso ni Meta, y todo lo demás —ruta, frenos, opener, ticket, registro— es el camino real.
 */
class WhatsappDeImplementacionDeAgentesTest extends BaseDeImplementaciones
{
    /** Ruta del endpoint, con el id a completar. */
    const RUTA = '/api/claude/agent-implementations/%d/whatsapp';

    /**
     * El espía del envío a Kapso/Meta.
     *
     * @var WhatsappSendService
     */
    private $espia;

    /**
     * El admin remitente.
     *
     * @var Admin
     */
    private $admin;

    /**
     * Sin red, el espía del envío, un admin remitente fijado por config y el soporte de Codex PRENDIDO.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();

        AdminSetting::set(SupportCodexSettings::KEY_ENABLED, '1');
        AdminSetting::flush_memo();

        $this->admin = $this->crear_admin();
        config(['services.claude_task_ingest.default_creator_admin_id' => $this->admin->id]);

        $this->espia = $this->espiar_el_envio(true);
    }

    /**
     * Sustituye `WhatsappSendService` por un espía que registra los envíos.
     *
     * @param bool $confirma true: devuelve un `whatsapp_message_id`. false: simula un rechazo de Meta.
     *
     * @return WhatsappSendService
     */
    private function espiar_el_envio(bool $confirma): WhatsappSendService
    {
        $espia = new class extends WhatsappSendService {
            /** @var array<int, array<string, mixed>> Envíos de texto libre. */
            public $textos = [];

            /** @var array<int, array<string, mixed>> Envíos de plantilla. */
            public $plantillas = [];

            /** @var bool Si el envío se confirma. */
            public $confirma = true;

            public function send_text(string $to, string $body, ?string $context = null, bool $skip_failure_notification = false): ?string
            {
                $this->textos[] = ['to' => $to, 'body' => $body];

                if (! $this->confirma) {
                    $this->last_send_error = 'Meta rechazó el envío (simulado en el test).';

                    return null;
                }

                return 'wamid.texto.' . count($this->textos) . '.' . Str::random(6);
            }

            public function send_template(string $to, string $template_name, array $variables = [], string $language_code = 'es_AR', ?string $context = null): ?string
            {
                $this->plantillas[] = ['to' => $to, 'template_name' => $template_name, 'variables' => $variables];

                if (! $this->confirma) {
                    $this->last_send_error = 'La plantilla no salió (simulado en el test).';

                    return null;
                }

                return 'wamid.plantilla.' . count($this->plantillas) . '.' . Str::random(6);
            }
        };

        $espia->confirma = $confirma;
        $this->app->instance(WhatsappSendService::class, $espia);

        return $espia;
    }

    /**
     * Un cliente (sin implementación del sistema) con su implementación de agentes recién dada de alta.
     *
     * @return array{0: Client, 1: AgentImplementation}
     */
    private function escenario(): array
    {
        $client = $this->crear_cliente('Mayorista de Pesca');

        $respuesta = $this->postJson('/api/claude/agent-implementations', ['client_id' => $client->id, 'camino' => 'propio'], $this->headers());
        $respuesta->assertStatus(201);

        return [$client, AgentImplementation::findOrFail((int) $respuesta->json('model.id'))];
    }

    /**
     * Ticket abierto del dueño (el que el opener reusaría).
     *
     * @param Client $client El cliente.
     *
     * @return SupportTicket
     */
    private function ticket_abierto(Client $client): SupportTicket
    {
        return SupportTicket::create([
            'client_id'      => $client->id,
            'client_user_id' => 0,
            'status'         => 'open',
            'source'         => 'whatsapp',
            'whatsapp_phone' => (string) $client->phone,
            'opened_at'      => now(),
        ]);
    }

    /**
     * Los eventos `whatsapp` de la implementación.
     *
     * @param AgentImplementation $implementation La implementación.
     *
     * @return \Illuminate\Support\Collection
     */
    private function eventos_whatsapp(AgentImplementation $implementation)
    {
        return AgentImplementationEvent::where('agent_implementation_id', $implementation->id)->where('kind', 'whatsapp')->get();
    }

    /**
     * Sale por el ticket (ventana cerrada → plantilla), deja la operación en la etapa actual y el evento
     * `whatsapp` con el texto; la misma operation_id devuelve lo guardado sin reenviar, y con otro texto es 409.
     *
     * @return void
     */
    public function test_sale_por_el_ticket_registra_el_evento_y_es_idempotente(): void
    {
        [$client, $implementation] = $this->escenario();
        $texto = 'Hola, te paso la guía para conectar tu número: https://admin.ejemplo.test/guias/agentes-ia-numero-propio.pdf';

        $respuesta = $this->postJson(sprintf(self::RUTA, $implementation->id), ['operation_id' => 'op-agentes-0001', 'texto' => $texto], $this->headers());
        $respuesta->assertStatus(200)
            ->assertJsonPath('dry_run', false)
            ->assertJsonPath('repetida', false)
            ->assertJsonPath('enviado', true)
            ->assertJsonPath('estado', 'sent')
            ->assertJsonPath('uso_plantilla', true)
            ->assertJsonPath('etapa', 1)
            ->assertJsonPath('respuesta_ira_a', 'ticket');

        $this->assertCount(1, $this->espia->plantillas);
        $ticket_id = (int) $respuesta->json('ticket_id');
        $this->assertSame((int) $client->id, (int) SupportTicket::findOrFail($ticket_id)->client_id);

        $eventos = $this->eventos_whatsapp($implementation);
        $this->assertCount(1, $eventos);
        $this->assertSame($texto, $eventos[0]->texto);
        $this->assertSame(1, (int) $eventos[0]->stage_number);
        $this->assertSame('claude', $eventos[0]->source);

        $data = AgentImplementationStage::where('agent_implementation_id', $implementation->id)->where('stage_number', 1)->value('data');
        $data = is_array($data) ? $data : json_decode((string) $data, true);
        $this->assertSame('sent', $data['whatsapp_ops']['op-agentes-0001']['estado']);
        $this->assertSame('propio', $data['camino'], 'La operación se suma a la data de la etapa sin pisarla.');

        /* Mismo pedido: lo guardado, sin reenviar. */
        $this->postJson(sprintf(self::RUTA, $implementation->id), ['operation_id' => 'op-agentes-0001', 'texto' => $texto], $this->headers())
            ->assertStatus(200)
            ->assertJsonPath('repetida', true)
            ->assertJsonPath('ticket_id', $ticket_id);
        $this->assertCount(1, $this->espia->plantillas);
        $this->assertCount(1, $this->eventos_whatsapp($implementation));

        /* Misma clave, otro texto (u otra etapa): 409. */
        $this->postJson(sprintf(self::RUTA, $implementation->id), ['operation_id' => 'op-agentes-0001', 'texto' => 'Otro texto'], $this->headers())
            ->assertStatus(409)
            ->assertJsonPath('motivo', 'operation_id_reusado');
        $this->postJson(sprintf(self::RUTA, $implementation->id), ['operation_id' => 'op-agentes-0001', 'texto' => $texto, 'etapa' => 3], $this->headers())
            ->assertStatus(409)
            ->assertJsonPath('motivo', 'operation_id_reusado');
        $this->assertCount(1, $this->espia->plantillas);

        /* Con etapa explícita queda en esa etapa. */
        $this->postJson(sprintf(self::RUTA, $implementation->id), ['operation_id' => 'op-agentes-0002', 'texto' => 'Segundo mensaje', 'etapa' => 3], $this->headers())
            ->assertStatus(200)
            ->assertJsonPath('etapa', 3);
        $this->assertSame(3, (int) $this->eventos_whatsapp($implementation)->sortByDesc('id')->first()->stage_number);
    }

    /**
     * Martín con la reserva vigente del ticket: 423 `martin_atendiendo`, no se manda nada, no hay evento y el
     * caso no pasa a revisión manual.
     *
     * @return void
     */
    public function test_con_martin_atendiendo_es_423(): void
    {
        [$client, $implementation] = $this->escenario();
        $ticket = $this->ticket_abierto($client);
        $caso   = SupportCodexCase::create([
            'support_ticket_id' => $ticket->id,
            'state'             => 'ready',
            'worker_id'         => 'pc-lucas',
            'lease_hash'        => hash('sha256', 'token'),
            'lease_expires_at'  => now()->addMinutes(10),
        ]);

        $this->postJson(sprintf(self::RUTA, $implementation->id), ['operation_id' => 'op-agentes-0003', 'texto' => 'Hola'], $this->headers())
            ->assertStatus(423)
            ->assertJsonPath('motivo', 'martin_atendiendo')
            ->assertJsonPath('ticket_id', $ticket->id);

        $this->assertCount(0, $this->espia->textos);
        $this->assertCount(0, $this->espia->plantillas);
        $this->assertCount(0, $this->eventos_whatsapp($implementation));
        $this->assertSame('ready', $caso->fresh()->state);
    }

    /**
     * Un entrante del dueño esperando a Martín: 423 `martin_pendiente`, sin mandar nada.
     *
     * @return void
     */
    public function test_con_un_entrante_esperando_a_martin_es_423_martin_pendiente(): void
    {
        [$client, $implementation] = $this->escenario();

        $ticket = SupportTicket::create([
            'client_id'              => $client->id,
            'client_user_id'         => 0,
            'status'                 => 'open',
            'source'                 => 'whatsapp',
            'whatsapp_phone'         => (string) $client->phone,
            'opened_at'              => now(),
            'last_client_message_at' => now(),
            'claude_auto_reply'      => true,
        ]);
        SupportMessage::create([
            'support_ticket_id' => $ticket->id,
            'sender_type'       => 'user',
            'kind'              => 'text',
            'body'              => '¿Ya puedo conectar el número?',
            'delivered_at'      => now(),
        ]);

        $this->postJson(sprintf(self::RUTA, $implementation->id), ['operation_id' => 'op-agentes-0004', 'texto' => 'Hola'], $this->headers())
            ->assertStatus(423)
            ->assertJsonPath('motivo', 'martin_pendiente')
            ->assertJsonPath('reason', 'inbound')
            ->assertJsonPath('ticket_id', $ticket->id);

        $this->assertCount(0, $this->espia->textos);
        $this->assertCount(0, $this->espia->plantillas);
        $this->assertSame(1, SupportMessage::where('support_ticket_id', $ticket->id)->count());
        $this->assertCount(0, $this->eventos_whatsapp($implementation));
    }

    /**
     * Con el circuito de Codex apagado nadie atendería la respuesta: 422 `codex_apagado`, sin mandar nada.
     *
     * @return void
     */
    public function test_con_el_circuito_de_codex_apagado_es_422(): void
    {
        [, $implementation] = $this->escenario();

        AdminSetting::set(SupportCodexSettings::KEY_ENABLED, '0');
        AdminSetting::flush_memo();

        $this->postJson(sprintf(self::RUTA, $implementation->id), ['operation_id' => 'op-agentes-0005', 'texto' => 'Hola'], $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('motivo', 'codex_apagado');

        $this->assertCount(0, $this->espia->textos);
        $this->assertCount(0, $this->espia->plantillas);
    }

    /**
     * Un envío que Meta rechaza vuelve 200 con `enviado: false` y no deja evento (Martín leería algo que el
     * cliente no recibió); la misma clave devuelve el `failed` guardado sin reintentar.
     *
     * @return void
     */
    public function test_un_envio_fallido_no_deja_evento_ni_se_reintenta_solo(): void
    {
        [, $implementation] = $this->escenario();
        $this->espia->confirma = false;

        $this->postJson(sprintf(self::RUTA, $implementation->id), ['operation_id' => 'op-agentes-0006', 'texto' => 'Hola'], $this->headers())
            ->assertStatus(200)
            ->assertJsonPath('enviado', false)
            ->assertJsonPath('estado', 'failed');
        $this->assertCount(0, $this->eventos_whatsapp($implementation));

        $this->postJson(sprintf(self::RUTA, $implementation->id), ['operation_id' => 'op-agentes-0006', 'texto' => 'Hola'], $this->headers())
            ->assertStatus(200)
            ->assertJsonPath('repetida', true)
            ->assertJsonPath('estado', 'failed');
        $this->assertCount(1, $this->espia->plantillas);
    }

    /**
     * Frenos de la entrada: operation_id y texto obligatorios, etapa 1–8, lista blanca, implementación que no
     * existe (404), cliente sin teléfono (422 `sin_contacto`) y cliente dado de baja (422 `cliente_inactivo`).
     *
     * @return void
     */
    public function test_frenos_de_la_entrada(): void
    {
        [$client, $implementation] = $this->escenario();
        $ruta = sprintf(self::RUTA, $implementation->id);

        $this->postJson($ruta, ['texto' => 'Hola'], $this->headers())->assertStatus(422);
        $this->postJson($ruta, ['operation_id' => 'op-agentes-0007'], $this->headers())->assertStatus(422);
        $this->postJson($ruta, ['operation_id' => 'corta', 'texto' => 'Hola'], $this->headers())->assertStatus(422);
        $this->postJson($ruta, ['operation_id' => 'op agentes con espacios', 'texto' => 'Hola'], $this->headers())->assertStatus(422);
        $this->postJson($ruta, ['operation_id' => 'op-agentes-0007', 'texto' => 'Hola', 'etapa' => 9], $this->headers())->assertStatus(422);
        $this->postJson($ruta, ['operation_id' => 'op-agentes-0007', 'texto' => 'Hola', 'dry_run' => false], $this->headers())->assertStatus(422);
        $this->postJson(sprintf(self::RUTA, 999999999), ['operation_id' => 'op-agentes-0007', 'texto' => 'Hola'], $this->headers())->assertStatus(404);

        $client->phone = null;
        $client->save();
        $this->postJson($ruta, ['operation_id' => 'op-agentes-0007', 'texto' => 'Hola'], $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('motivo', 'sin_contacto');

        $client->phone     = '+5493415550199';
        $client->is_active = false;
        $client->save();
        $this->postJson($ruta, ['operation_id' => 'op-agentes-0007', 'texto' => 'Hola'], $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('motivo', 'cliente_inactivo');

        $this->assertCount(0, $this->espia->textos);
        $this->assertCount(0, $this->espia->plantillas);
    }
}

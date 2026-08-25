<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Client;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\AdminSetting;
use App\Services\SupportAiSettings;
use App\Services\SupportAiSuggestionScheduler;
use App\Services\SupportAiSuggestionService;
use App\Services\WhatsappSendService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Los interruptores del agente de soporte y la aprobación humana de sus sugerencias.
 *
 * Lo que más importa verificar acá son las AUSENCIAS: que con la verificación prendida el
 * mensaje NO le llegue solo al cliente, y que con el agente apagado NI SIQUIERA se le pregunte
 * a Claude. Un test de camino feliz no mira ninguna de las dos, y las dos son plata: una manda
 * al cliente algo que nadie leyó, la otra paga consultas a la API para tirar el resultado.
 */
class ControlDelAgenteDeSoporteTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Corta cualquier salida HTTP real.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
    }

    /**
     * Operador de soporte.
     *
     * @param string $email Email único.
     *
     * @return Admin
     */
    private function crear_admin(string $email): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Lucas';
        $admin->email    = $email;
        $admin->password = bcrypt('secret');
        $admin->save();

        return $admin;
    }

    /**
     * Cliente activo con teléfono.
     *
     * @return Client
     */
    private function crear_cliente(): Client
    {
        $client            = new Client();
        $client->name      = 'Distribuidora de prueba';
        $client->phone     = '+5493416660001';
        $client->is_active = true;
        $client->api_url   = 'https://api-cliente-de-prueba.test';
        $client->api_key   = 'clave-de-prueba';
        $client->save();

        return $client;
    }

    /**
     * Ticket de WhatsApp abierto, con un mensaje entrante reciente del cliente.
     *
     * El entrante deja la ventana de 24hs abierta, así el envío sale como texto libre y no
     * como plantilla: acá lo que se mide es el modo del agente, no el canal.
     *
     * @param Client $client Cliente dueño.
     *
     * @return SupportTicket
     */
    private function crear_ticket(Client $client): SupportTicket
    {
        $ticket = SupportTicket::create([
            'client_id'        => $client->id,
            'client_user_id'   => 0,
            'client_user_name' => 'Contacto',
            'status'           => 'open',
            'source'           => 'whatsapp',
            'whatsapp_phone'   => '+5493416660001',
            'opened_at'        => now()->subHours(2),
        ]);

        SupportMessage::create([
            'support_ticket_id' => $ticket->id,
            'sender_type'       => 'user',
            'kind'              => 'text',
            'body'              => '¿Cómo cargo una nota de crédito?',
            'delivered_at'      => now()->subMinutes(5),
        ]);

        return $ticket;
    }

    /**
     * Sustituye WhatsappSendService por un espía.
     *
     * @return WhatsappSendService
     */
    private function espiar_sender(): WhatsappSendService
    {
        $espia = new class extends WhatsappSendService {
            /** @var array<int, array<string, mixed>> Envíos de texto libre. */
            public $textos = [];

            /** @var array<int, array<string, mixed>> Envíos de plantilla. */
            public $plantillas = [];

            public function send_text(string $to, string $body, ?string $context = null, bool $skip_failure_notification = false): ?string
            {
                $this->textos[] = ['to' => $to, 'body' => $body];

                return 'wamid.texto.' . count($this->textos);
            }

            public function send_template(string $to, string $template_name, array $variables = [], string $language_code = 'es_AR', ?string $context = null): ?string
            {
                $this->plantillas[] = [
                    'to'            => $to,
                    'template_name' => $template_name,
                    'variables'     => $variables,
                ];

                return 'wamid.plantilla.' . count($this->plantillas);
            }
        };

        $this->app->instance(WhatsappSendService::class, $espia);

        return $espia;
    }

    /**
     * Sustituye el servicio que llama a Claude por uno que cuenta las llamadas.
     *
     * @param array<string, mixed> $resultado Lo que devuelve generate().
     *
     * @return SupportAiSuggestionService
     */
    private function espiar_claude(array $resultado): SupportAiSuggestionService
    {
        $espia = new class extends SupportAiSuggestionService {
            /** @var int Cantidad de veces que se consultó a Claude. */
            public $llamadas = 0;

            /** @var array<string, mixed> Respuesta simulada. */
            public $resultado = [];

            public function generate(SupportTicket $ticket): array
            {
                $this->llamadas++;

                return $this->resultado;
            }
        };

        $espia->resultado = $resultado;
        $this->app->instance(SupportAiSuggestionService::class, $espia);

        return $espia;
    }

    /**
     * Respuesta típica del agente, sin escalado ni cierre.
     *
     * @param string $mensaje Texto sugerido.
     *
     * @return array<string, mixed>
     */
    private function respuesta_del_agente(string $mensaje): array
    {
        return [
            'suggested_message' => $mensaje,
            'reasoning'         => 'Está en el manual.',
            'should_close'      => false,
            'should_escalate'   => false,
            'escalation_reason' => null,
        ];
    }

    /**
     * Corre el job del agente sobre un ticket.
     *
     * @param SupportTicket $ticket Ticket a procesar.
     *
     * @return void
     */
    private function correr_agente(SupportTicket $ticket): void
    {
        // En los tests la cola es `sync`, así que el propio scheduler ejecuta el job en el
        // acto: despacharlo otra vez a mano lo corría dos veces y duplicaba cada envío.
        app(SupportAiSuggestionScheduler::class)->schedule_after_client_inbound((int) $ticket->id);
    }

    /**
     * Un ticket nuevo nace con verificación humana obligatoria.
     *
     * @return void
     */
    public function test_un_ticket_nuevo_exige_verificacion_por_defecto()
    {
        $client = $this->crear_cliente();
        $ticket = $this->crear_ticket($client);

        $this->assertTrue((bool) $ticket->requiere_verificacion_mensajes, 'La verificación no viene prendida de fábrica.');
        $this->assertTrue((bool) $ticket->claude_auto_reply, 'El agente no viene prendido de fábrica.');
    }

    /**
     * Con verificación prendida, la sugerencia queda esperando y NO se manda sola.
     *
     * @return void
     */
    public function test_con_verificacion_la_sugerencia_no_sale_sola()
    {
        $client = $this->crear_cliente();
        $ticket = $this->crear_ticket($client);
        $espia  = $this->espiar_sender();
        $this->espiar_claude($this->respuesta_del_agente('Se carga desde Ventas, botón Nota de crédito.'));

        // Con auto-envío inmediato configurado: si la verificación no frenara, saldría al toque.
        AdminSetting::set(SupportAiSettings::KEY_AUTO_SEND_DELAY_SECONDS, 0);

        $this->correr_agente($ticket);

        $this->assertCount(0, $espia->textos, 'La sugerencia salió al cliente sin que nadie la aprobara.');
        $this->assertCount(0, $espia->plantillas);

        $borrador = SupportMessage::where('support_ticket_id', $ticket->id)
            ->where('is_ai_suggestion_draft', true)
            ->first();

        $this->assertNotNull($borrador, 'No quedó ningún borrador esperando aprobación.');
        $this->assertNull($borrador->ai_auto_send_at, 'El borrador quedó con fecha de autoenvío: se va a mandar solo.');
    }

    /**
     * Sin verificación y sin demora, la sugerencia sale sola, como antes.
     *
     * @return void
     */
    public function test_sin_verificacion_la_sugerencia_sale_sola()
    {
        $client = $this->crear_cliente();
        $ticket = $this->crear_ticket($client);
        $ticket->requiere_verificacion_mensajes = false;
        $ticket->save();

        $espia = $this->espiar_sender();
        $this->espiar_claude($this->respuesta_del_agente('Se carga desde Ventas.'));
        AdminSetting::set(SupportAiSettings::KEY_AUTO_SEND_DELAY_SECONDS, 0);

        $this->correr_agente($ticket);

        $this->assertCount(1, $espia->textos, 'Con la verificación apagada la sugerencia tendría que haber salido.');
        $this->assertSame('Se carga desde Ventas.', $espia->textos[0]['body']);
    }

    /**
     * Con el agente apagado en el ticket, no se consulta a Claude ni se genera nada.
     *
     * @return void
     */
    public function test_con_el_agente_apagado_no_se_consulta_a_claude()
    {
        $client = $this->crear_cliente();
        $ticket = $this->crear_ticket($client);
        $ticket->claude_auto_reply = false;
        $ticket->save();

        $espia_sender = $this->espiar_sender();
        $espia_claude = $this->espiar_claude($this->respuesta_del_agente('No debería llegar acá.'));

        $this->correr_agente($ticket);

        $this->assertSame(0, $espia_claude->llamadas, 'Se pagó una consulta a Claude con el agente apagado.');
        $this->assertCount(0, $espia_sender->textos);
        $this->assertSame(
            0,
            SupportMessage::where('support_ticket_id', $ticket->id)->where('is_ai_suggestion_draft', true)->count()
        );
    }

    /**
     * Aprobar un borrador sin tocarlo lo manda tal cual y no guarda original.
     *
     * @return void
     */
    public function test_aprobar_sin_editar_manda_el_texto_del_agente()
    {
        $admin  = $this->crear_admin('aprueba-sin-editar@test.local');
        $client = $this->crear_cliente();
        $ticket = $this->crear_ticket($client);
        $espia  = $this->espiar_sender();
        $this->espiar_claude($this->respuesta_del_agente('Se carga desde Ventas.'));

        $this->correr_agente($ticket);

        $borrador = SupportMessage::where('support_ticket_id', $ticket->id)
            ->where('is_ai_suggestion_draft', true)
            ->firstOrFail();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/support-message/' . $borrador->id . '/approve-ai-draft')
            ->assertStatus(200);

        $this->assertCount(1, $espia->textos, 'El borrador aprobado no salió.');
        $this->assertSame('Se carga desde Ventas.', $espia->textos[0]['body']);

        $enviado = SupportMessage::find($borrador->id);
        $this->assertFalse((bool) $enviado->is_ai_suggestion_draft, 'Sigue marcado como borrador después de aprobarlo.');
        $this->assertNull($enviado->ai_original_body, 'Guardó un original sin que nadie editara nada.');
        $this->assertSame((int) $admin->id, (int) $enviado->sender_admin_id, 'El mensaje no quedó a nombre de quien lo aprobó.');
    }

    /**
     * Aprobar con ajustes manda el texto editado y guarda el original del agente.
     *
     * Es lo que pidió Lucas para poder medir después en qué se equivoca el agente.
     *
     * @return void
     */
    public function test_aprobar_con_ajustes_guarda_el_original_del_agente()
    {
        $admin  = $this->crear_admin('aprueba-editando@test.local');
        $client = $this->crear_cliente();
        $ticket = $this->crear_ticket($client);
        $espia  = $this->espiar_sender();
        $this->espiar_claude($this->respuesta_del_agente('Se carga desde Ventas.'));

        $this->correr_agente($ticket);

        $borrador = SupportMessage::where('support_ticket_id', $ticket->id)
            ->where('is_ai_suggestion_draft', true)
            ->firstOrFail();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/support-message/' . $borrador->id . '/approve-ai-draft', [
                'body' => 'Se carga desde Ventas, con el botón Nota de crédito arriba a la derecha.',
            ])
            ->assertStatus(200);

        $this->assertCount(1, $espia->textos);
        $this->assertSame(
            'Se carga desde Ventas, con el botón Nota de crédito arriba a la derecha.',
            $espia->textos[0]['body'],
            'Al cliente le llegó el texto del agente y no el corregido.'
        );

        $enviado = SupportMessage::find($borrador->id);
        $this->assertSame('Se carga desde Ventas.', $enviado->ai_original_body, 'No se guardó lo que había propuesto el agente.');
        $this->assertSame(
            'Se carga desde Ventas, con el botón Nota de crédito arriba a la derecha.',
            $enviado->body,
            'El body no quedó con lo que de verdad se envió.'
        );
    }

    /**
     * Descartar un borrador no manda nada y lo saca del hilo.
     *
     * @return void
     */
    public function test_descartar_un_borrador_no_manda_nada()
    {
        $admin  = $this->crear_admin('descarta@test.local');
        $client = $this->crear_cliente();
        $ticket = $this->crear_ticket($client);
        $espia  = $this->espiar_sender();
        $this->espiar_claude($this->respuesta_del_agente('Una respuesta que no sirve.'));

        $this->correr_agente($ticket);

        $borrador = SupportMessage::where('support_ticket_id', $ticket->id)
            ->where('is_ai_suggestion_draft', true)
            ->firstOrFail();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/support-message/' . $borrador->id . '/discard-ai-draft')
            ->assertStatus(200);

        $this->assertCount(0, $espia->textos, 'Se mandó un borrador descartado.');
        $this->assertNull(SupportMessage::find($borrador->id), 'El borrador descartado sigue en el hilo.');
        $this->assertNull(SupportTicket::find($ticket->id)->ai_pending_suggestion);
    }

    /**
     * Los dos interruptores se prenden y apagan desde la conversación.
     *
     * @return void
     */
    public function test_los_interruptores_se_alternan_desde_el_ticket()
    {
        $admin  = $this->crear_admin('interruptores@test.local');
        $client = $this->crear_cliente();
        $ticket = $this->crear_ticket($client);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/support-ticket/' . $ticket->id . '/toggle-claude-auto-reply')
            ->assertStatus(200);

        $this->assertFalse((bool) SupportTicket::find($ticket->id)->claude_auto_reply);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/support-ticket/' . $ticket->id . '/toggle-requiere-verificacion')
            ->assertStatus(200);

        $this->assertFalse((bool) SupportTicket::find($ticket->id)->requiere_verificacion_mensajes);
    }

    /**
     * Cuando el agente escala, le llega un WhatsApp al operador suscrito.
     *
     * Antes el escalado solo emitía un Pusher: si nadie tenía el admin abierto, el ticket
     * quedaba esperando y no se enteraba ninguna persona.
     *
     * @return void
     */
    public function test_el_escalado_avisa_por_whatsapp_al_operador_suscrito()
    {
        $suscrito                                     = $this->crear_admin('escalado-suscrito@test.local');
        $suscrito->phone_number                       = '+5493410000001';
        $suscrito->notify_support_escalation_whatsapp = true;
        $suscrito->save();

        // Este no está suscrito: no tiene que recibir nada.
        $otro                   = $this->crear_admin('escalado-no-suscrito@test.local');
        $otro->phone_number     = '+5493410000002';
        $otro->save();

        $client = $this->crear_cliente();
        $ticket = $this->crear_ticket($client);
        $espia  = $this->espiar_sender();

        $this->espiar_claude([
            'suggested_message' => 'Dame un momento, por favor.',
            'reasoning'         => 'No está en el manual.',
            'should_close'      => false,
            'should_escalate'   => true,
            'escalation_reason' => 'El cliente reporta pérdida de datos en stock',
        ]);

        $this->correr_agente($ticket);

        $this->assertCount(1, $espia->plantillas, 'El escalado no avisó por WhatsApp, o avisó a quien no correspondía.');

        $aviso = $espia->plantillas[0];
        $this->assertSame('soporte_escalacion_humana', $aviso['template_name']);
        $this->assertSame('+5493410000001', $aviso['to'], 'El aviso salió al teléfono equivocado.');
        $this->assertCount(3, $aviso['variables']);
        $this->assertSame('El cliente reporta pérdida de datos en stock', $aviso['variables'][1]);
        $this->assertStringContainsString('/soporte?ticket_id=' . $ticket->id, $aviso['variables'][2], 'El link no abre el ticket.');

        $escalado = SupportTicket::find($ticket->id);
        $this->assertNotNull($escalado->escalated_at, 'El ticket no quedó marcado como escalado.');
    }

    /**
     * Sin operadores suscritos, el escalado igual queda registrado en el ticket.
     *
     * El aviso es un extra: perder el escalado porque nadie tiene el flag prendido sería peor.
     *
     * @return void
     */
    public function test_el_escalado_queda_registrado_aunque_no_haya_a_quien_avisar()
    {
        $client = $this->crear_cliente();
        $ticket = $this->crear_ticket($client);
        $espia  = $this->espiar_sender();

        $this->espiar_claude([
            'suggested_message' => '',
            'reasoning'         => 'No está en el manual.',
            'should_close'      => false,
            'should_escalate'   => true,
            'escalation_reason' => 'Reclamo de facturación',
        ]);

        $this->correr_agente($ticket);

        $this->assertCount(0, $espia->plantillas);

        $escalado = SupportTicket::find($ticket->id);
        $this->assertNotNull($escalado->escalated_at);
        $this->assertSame('Reclamo de facturación', $escalado->escalation_reason);
    }
}

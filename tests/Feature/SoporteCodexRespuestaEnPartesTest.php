<?php

namespace Tests\Feature;

use App\Events\SupportMessageReceived;
use App\Events\SupportTicketUpdated;
use App\Models\Admin;
use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\SupportCodexCase;
use App\Models\SupportCodexOperation;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\ClientPhoneDirectory;
use App\Services\SupportClientSyncService;
use App\Services\SupportCodexSettings;
use App\Services\SupportTourService;
use App\Services\SupportWhatsappOpenerService;
use App\Services\WhatsappSendService;
use App\Services\SupportCodexService;
use App\Services\WhatsappSessionWindowService;
use Carbon\Carbon;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Misión `tono-humano-martin` (13/11/2026): la respuesta de Martín puede salir partida en hasta cinco
 * mensajes cuando el body trae líneas `---`, igual que el agente de leads.
 *
 * Lo que estos tests cuidan no es solo el partido: es la INVARIANTE de las guardas. Muchas de ellas
 * (`can_close_on_reply`, `confirms_recent_exchange`, `confirms_resolution`, el cierre por misión) comparan
 * el último mensaje de admin del ticket contra `last_reply_id` y buscan la operación por `message_id`. Con
 * la respuesta en partes, las dos referencias tienen que apuntar a la ÚLTIMA parte; si quedaran en la
 * primera, la confirmación del cliente dejaría de encontrar su pregunta y el ticket no cerraría nunca.
 *
 * Se le pega a los endpoints reales con la base del slot. Por WhatsApp se usa el opener de verdad, con el
 * sender sustituido por un espía (no sale nada a Meta) y las pausas de 1200ms anuladas; por el sistema
 * (ERP) se sustituye solo la sincronización hacia el empresa-api del cliente.
 */
class SoporteCodexRespuestaEnPartesTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array<string, string> Encabezados del monitor de Martín. */
    private $headers = ['X-Claude-Task-Key' => 'respuesta-en-partes-test', 'Accept' => 'application/json'];

    /** @var string Prefijo de las rutas de Codex. */
    private $base = '/api/claude/support-codex';

    /** @var WhatsappSendService Espía del envío a Kapso/Meta. */
    private $espia;

    /** @var SupportClientSyncService Espía de la sincronización con el sistema del cliente. */
    private $sync;

    /**
     * Prende el circuito de Codex, corta la red y registra los espías.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.claude_task_ingest.key' => 'respuesta-en-partes-test']);
        Carbon::setTestNow(Carbon::parse('2026-11-13 13:00:00'));
        Http::fake();
        Queue::fake();
        Storage::fake('local');
        Event::fake([SupportMessageReceived::class, SupportTicketUpdated::class]);
        AdminSetting::set(SupportCodexSettings::KEY_ENABLED, '1');
        AdminSetting::set(SupportCodexSettings::KEY_ACTIVATED_AT, '2026-11-13 12:00:00');

        // Espía del sender: cuenta textos y plantillas y puede fallar en el envío número N.
        $this->espia = new class extends WhatsappSendService {
            /** @var array<int, string> Textos enviados, en orden. */
            public $textos = [];

            /** @var array<int, array<string, mixed>> Plantillas enviadas. */
            public $plantillas = [];

            /** @var array<int, string> Mensajes con adjunto. */
            public $adjuntos = [];

            /** @var int|null Número de envío de texto (1..N) que falla, o null si no falla ninguno. */
            public $falla_en = null;

            /** @var int Intentos de envío de texto, incluidos los fallidos. */
            public $intentos = 0;

            public function send_text(string $to, string $body, ?string $context = null, bool $skip_failure_notification = false): ?string
            {
                $this->intentos++;
                if ($this->falla_en !== null && $this->intentos >= $this->falla_en) {
                    // Fallo NO transitorio: el opener no reintenta y corta ahí.
                    $this->last_send_error = 'Fallo simulado en el envío ' . $this->intentos;
                    $this->last_send_status_code = 400;

                    return null;
                }
                $this->textos[] = $body;

                return 'wamid.parte.' . count($this->textos);
            }

            public function send_template(string $to, string $template_name, array $variables = [], string $language_code = 'es_AR', ?string $context = null): ?string
            {
                $this->plantillas[] = ['template_name' => $template_name, 'variables' => $variables];

                return 'wamid.plantilla.' . count($this->plantillas);
            }

            public function send_support_message(string $to, SupportMessage $message): ?string
            {
                $message->loadMissing('attachments');
                if ($message->attachments !== null && count($message->attachments) > 0) {
                    $this->adjuntos[] = (string) ($message->body ?? '');

                    return 'wamid.adjunto.' . count($this->adjuntos);
                }

                return $this->send_text($to, (string) $message->body);
            }
        };
        $this->app->instance(WhatsappSendService::class, $this->espia);

        // El opener de verdad, sin las esperas entre parte y parte.
        $opener = new class(
            app(WhatsappSendService::class),
            app(WhatsappSessionWindowService::class),
            app(ClientPhoneDirectory::class)
        ) extends SupportWhatsappOpenerService {
            protected function pausar(int $microsegundos): void
            {
                // A propósito: en el test no se espera.
            }
        };
        $this->app->instance(SupportWhatsappOpenerService::class, $opener);

        // Espía de la sincronización con el empresa-api: registra el orden y puede fallar en la N.
        $this->sync = new class extends SupportClientSyncService {
            /** @var array<int, string> Bodies sincronizados con éxito, en orden. */
            public $bodies = [];

            /** @var int|null Número de sincronización (1..N) que falla. */
            public $falla_en = null;

            /** @var int Intentos de sincronización. */
            public $intentos = 0;

            public function sync_message_to_client(SupportMessage $message): bool
            {
                $this->intentos++;
                if ($this->falla_en !== null && $this->intentos >= $this->falla_en) {
                    return false;
                }
                $this->bodies[] = (string) $message->body;
                $message->synced_to_client_at = now();
                $message->remote_delivery_status = null;
                $message->save();

                return true;
            }
        };
        $this->app->instance(SupportClientSyncService::class, $this->sync);
    }

    /**
     * Restituye el reloj.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Ticket de WhatsApp con un entrante reciente (ventana de 24 h abierta).
     *
     * @param array<string, mixed> $cliente_extra Atributos extra del cliente (p. ej. es_ensayo).
     *
     * @return SupportTicket
     */
    private function ticket_whatsapp(array $cliente_extra = []): SupportTicket
    {
        $telefono = '+54934177' . random_int(10000, 99999);
        $cliente = Client::create([
            'name' => 'Cliente en partes', 'company_name' => 'Comercio en partes',
            'phone' => $telefono, 'is_active' => true,
        ]);
        // Los atributos extra se asignan a mano: alguno (como es_ensayo) puede no ser asignable en masa.
        foreach ($cliente_extra as $campo => $valor) {
            $cliente->{$campo} = $valor;
        }
        $cliente->save();
        $ticket = SupportTicket::create([
            'client_id' => $cliente->id, 'client_user_id' => 0, 'status' => 'open',
            'source' => 'whatsapp', 'whatsapp_phone' => $telefono,
            'opened_at' => now(), 'last_client_message_at' => now(),
        ]);
        $this->entrante($ticket, 'No me aparece el botón de cobrar.');

        return $ticket;
    }

    /**
     * Ticket del chat de Soporte del sistema (ERP) del modo Agentes, con su entrante.
     *
     * @return SupportTicket
     */
    private function ticket_erp(): SupportTicket
    {
        $cliente = Client::create([
            'name' => 'Cliente ERP en partes', 'phone' => '+5493417700001', 'is_active' => true,
            'api_url' => 'https://empresa-partes.test', 'api_key' => 'clave-que-no-sale',
        ]);
        $ticket = SupportTicket::create([
            'client_id' => $cliente->id, 'client_user_id' => 17, 'status' => 'open', 'source' => 'erp',
            'agentes_erp' => true, 'opened_at' => now(), 'last_client_message_at' => now(),
        ]);
        $this->entrante($ticket, 'No me aparece el botón de cobrar.', [
            'erp_auto_reply' => [SupportMessage::MARCA_ERP_VERIFICADO_PARA_CODEX => true],
        ]);

        return $ticket;
    }

    /**
     * Mensaje entrante del cliente.
     *
     * @param SupportTicket        $ticket Ticket.
     * @param string               $body   Texto del cliente.
     * @param array<string, mixed> $extra  Atributos extra.
     *
     * @return SupportMessage
     */
    private function entrante(SupportTicket $ticket, string $body, array $extra = []): SupportMessage
    {
        $mensaje = SupportMessage::create(array_merge([
            'support_ticket_id' => $ticket->id, 'sender_type' => 'user', 'kind' => 'text',
            'body' => $body, 'delivered_at' => now(),
        ], $extra));
        $ticket->last_client_message_at = now();
        $ticket->save();

        return $mensaje;
    }

    /**
     * Toma la reserva del último entrante.
     *
     * @param SupportTicket $ticket Ticket.
     *
     * @return array<string, mixed>
     */
    private function reservar(SupportTicket $ticket): array
    {
        $ultimo = SupportMessage::where('support_ticket_id', $ticket->id)->where('sender_type', 'user')->orderByDesc('id')->first();

        return $this->postJson($this->base . '/tickets/' . $ticket->id . '/claim', [
            'worker_id' => 'pc-partes', 'expected_message_id' => $ultimo->id,
        ], $this->headers)->assertOk()->json();
    }

    /**
     * Manda la respuesta de Martín por el endpoint real.
     *
     * @param SupportTicket        $ticket  Ticket.
     * @param array<string, mixed> $reserva Resultado del claim.
     * @param array<string, mixed> $extra   Campos que pisan los de por defecto.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function responder(SupportTicket $ticket, array $reserva, array $extra = [])
    {
        return $this->postJson($this->base . '/tickets/' . $ticket->id . '/reply', array_merge([
            'lease_token' => $reserva['lease_token'],
            'expected_message_id' => $reserva['ticket']['expected_message_id'],
            'operation_id' => 'partes-' . $ticket->id . '-' . $reserva['ticket']['expected_message_id'],
            'thread_id' => 'hilo-partes-' . $ticket->id,
            'action' => 'responder', 'body' => 'Probá recargar Ventas.',
        ], $extra), $this->headers);
    }

    /**
     * Mensajes de admin del ticket que ya no son borrador, en orden.
     *
     * @param SupportTicket $ticket Ticket.
     *
     * @return \Illuminate\Support\Collection
     */
    private function mensajes_de_martin(SupportTicket $ticket)
    {
        return SupportMessage::where('support_ticket_id', $ticket->id)->where('sender_type', 'admin')
            ->where(function ($q) {
                $q->whereNull('is_ai_suggestion_draft')->orWhere('is_ai_suggestion_draft', false);
            })->orderBy('id')->get();
    }

    /**
     * Comprueba que se emitió el evento de pantalla de un mensaje.
     *
     * @param int $id Id del mensaje.
     *
     * @return void
     */
    private function assert_evento_de(int $id): void
    {
        Event::assertDispatched(SupportMessageReceived::class, function (SupportMessageReceived $evento) use ($id) {
            return $evento->message !== null && (int) $evento->message->id === $id;
        });
    }

    /** Por WhatsApp, tres partes salen como tres mensajes y las referencias quedan en la última. */
    public function test_por_whatsapp_sale_en_tres_partes_y_las_referencias_quedan_en_la_ultima(): void
    {
        $ticket = $this->ticket_whatsapp();
        $reserva = $this->reservar($ticket);
        $respuesta = $this->responder($ticket, $reserva, [
            'body' => "ok, ya lo vi\n---\nes el permiso de cobrar, lo tenés apagado\n---\nse prende en Configuración",
        ])->assertOk()->assertJsonPath('operation.status', 'sent');

        $this->assertSame(['ok, ya lo vi', 'es el permiso de cobrar, lo tenés apagado', 'se prende en Configuración'], $this->espia->textos);
        $partes = $this->mensajes_de_martin($ticket);
        $this->assertCount(3, $partes);
        foreach ($partes as $indice => $parte) {
            $this->assertSame('wamid.parte.' . ($indice + 1), $parte->whatsapp_message_id);
            $this->assertNull($parte->remote_delivery_status);
            $this->assertNotNull($parte->ai_generated_at);
            $this->assert_evento_de((int) $parte->id);
        }
        $ultima = $partes->last();
        $operacion = SupportCodexOperation::where('operation_id', 'partes-' . $ticket->id . '-' . $reserva['ticket']['expected_message_id'])->firstOrFail();
        $this->assertSame((int) $ultima->id, (int) $operacion->message_id);
        $this->assertSame((int) $ultima->id, (int) $respuesta->json('operation.message_id'));
        $this->assertSame((int) $ultima->id, (int) SupportCodexCase::where('support_ticket_id', $ticket->id)->value('last_reply_id'));
    }

    /** Seis partes se cortan en cinco: lo que sobra se pega a la última. */
    public function test_seis_partes_salen_en_cinco_y_lo_que_sobra_va_a_la_ultima(): void
    {
        $ticket = $this->ticket_whatsapp();
        $this->responder($ticket, $this->reservar($ticket), [
            'body' => "Uno\n---\nDos\n---\nTres\n---\nCuatro\n---\nCinco\n---\nSeis",
        ])->assertOk()->assertJsonPath('operation.status', 'sent');

        $this->assertSame(['Uno', 'Dos', 'Tres', 'Cuatro', "Cinco\n\nSeis"], $this->espia->textos);
        $this->assertCount(5, $this->mensajes_de_martin($ticket));
    }

    /** Por el chat del sistema, las partes se persisten y se sincronizan en orden. */
    public function test_por_el_sistema_las_partes_se_sincronizan_en_orden(): void
    {
        $ticket = $this->ticket_erp();
        $this->responder($ticket, $this->reservar($ticket), [
            'body' => "ok, ya lo vi\n\n---\n\nes el permiso de cobrar\n\n---\n\nse prende en Configuración",
        ])->assertOk()->assertJsonPath('operation.status', 'sent');

        $this->assertSame(['ok, ya lo vi', 'es el permiso de cobrar', 'se prende en Configuración'], $this->sync->bodies);
        $this->assertSame([], $this->espia->textos, 'Un ticket del sistema no sale por WhatsApp.');
        $partes = $this->mensajes_de_martin($ticket);
        $this->assertCount(3, $partes);
        $primera = $partes->first();
        foreach ($partes as $parte) {
            $this->assertNotNull($parte->synced_to_client_at);
            $this->assertNull($parte->remote_delivery_status);
            $this->assertSame('text', $parte->kind);
            $this->assertSame((string) $primera->ai_generated_at, (string) $parte->ai_generated_at);
            $this->assert_evento_de((int) $parte->id);
        }
        $this->assertSame((int) $partes->last()->id, (int) SupportCodexCase::where('support_ticket_id', $ticket->id)->value('last_reply_id'));
        $this->assertTrue(SupportCodexOperation::where('message_id', $partes->last()->id)->where('status', 'sent')->exists());
    }

    /** Si la sincronización de la parte 2 falla, se corta ahí y la operación queda incierta, para revisión. */
    public function test_por_el_sistema_un_fallo_en_la_parte_dos_corta_y_deja_la_operacion_incierta(): void
    {
        $ticket = $this->ticket_erp();
        $this->sync->falla_en = 2;
        $this->responder($ticket, $this->reservar($ticket), [
            'body' => "Uno\n---\nDos\n---\nTres",
        ])->assertOk()->assertJsonPath('operation.status', 'unknown');

        $this->assertSame(['Uno'], $this->sync->bodies);
        $this->assertSame(2, $this->sync->intentos, 'Después del fallo no se intenta la parte 3.');
        $partes = $this->mensajes_de_martin($ticket);
        $this->assertCount(3, $partes);
        $this->assertNull($partes[0]->remote_delivery_status);
        $this->assertSame('not_received', $partes[1]->remote_delivery_status);
        $this->assertSame('not_received', $partes[2]->remote_delivery_status);
        $caso = SupportCodexCase::where('support_ticket_id', $ticket->id)->firstOrFail();
        $this->assertSame('manual_review', $caso->state);
        $this->assertStringContainsString('1 de 3', (string) $caso->review_reason);
        $this->assertTrue((bool) $ticket->fresh()->support_attention_enabled);
    }

    /** En un cliente de ensayo las partes quedan solo en el sistema, sin WhatsApp. */
    public function test_cliente_de_ensayo_recibe_las_partes_solo_en_el_sistema(): void
    {
        $ticket = $this->ticket_whatsapp(['es_ensayo' => true]);
        $this->responder($ticket, $this->reservar($ticket), [
            'body' => "Uno\n---\nDos\n---\nTres",
        ])->assertOk()->assertJsonPath('operation.status', 'sent');

        $this->assertSame([], $this->espia->textos);
        $this->assertSame([], $this->sync->bodies);
        $partes = $this->mensajes_de_martin($ticket);
        $this->assertSame(['Uno', 'Dos', 'Tres'], $partes->pluck('body')->all());
        foreach ($partes as $parte) {
            $this->assertNotNull($parte->synced_to_client_at);
            $this->assertNull($parte->whatsapp_message_id);
            $this->assert_evento_de((int) $parte->id);
        }
        $this->assertSame((int) $partes->last()->id, (int) SupportCodexCase::where('support_ticket_id', $ticket->id)->value('last_reply_id'));
    }

    /** Con la ventana cerrada, una respuesta del agente con separadores sale en UNA plantilla, sin `---`. */
    public function test_ventana_cerrada_sale_una_sola_plantilla_sin_separadores(): void
    {
        $cliente = Client::create(['name' => 'Sin ventana', 'phone' => '+5493417700002', 'is_active' => true]);
        $ticket = SupportTicket::create([
            'client_id' => $cliente->id, 'client_user_id' => 0, 'client_user_name' => 'Pablo', 'status' => 'open',
            'source' => 'whatsapp', 'whatsapp_phone' => '+5493417700002', 'opened_at' => now()->subDays(3),
        ]);
        $mensaje = SupportMessage::create([
            'support_ticket_id' => $ticket->id, 'sender_type' => 'admin', 'kind' => 'text',
            'body' => "ok, ya quedó\n\n---\n\nprobá de nuevo", 'ai_generated_at' => now(), 'delivered_at' => now(),
        ]);

        $resultado = app(SupportWhatsappOpenerService::class)->deliver_follow_up($ticket, $mensaje, 'Martín');

        $this->assertSame('sent', $resultado['delivery']);
        $this->assertSame([], $this->espia->textos);
        $this->assertCount(1, $this->espia->plantillas);
        $this->assertStringNotContainsString('---', $this->espia->plantillas[0]['variables'][2]);
        $this->assertSame('ok, ya quedó probá de nuevo', $this->espia->plantillas[0]['variables'][2]);
        $this->assertSame("ok, ya quedó\n\nprobá de nuevo", $mensaje->fresh()->ai_body_before_template);
        $this->assertStringNotContainsString('---', $mensaje->fresh()->body);
    }

    /** Con adjunto no se parte: una sola pieza y los separadores quedan como renglón en blanco. */
    public function test_con_adjunto_sale_una_sola_pieza(): void
    {
        $ticket = $this->ticket_whatsapp();
        $this->responder($ticket, $this->reservar($ticket), [
            'body' => "Te paso la factura\n---\nfijate el total",
            'attachment' => ['filename' => 'Factura.pdf', 'content_base64' => base64_encode("%PDF-1.4\nDocumento\n%%EOF\n")],
        ])->assertOk()->assertJsonPath('operation.status', 'sent');

        $this->assertSame([], $this->espia->textos);
        $this->assertSame(["Te paso la factura\n\nfijate el total"], $this->espia->adjuntos);
        $this->assertCount(1, $this->mensajes_de_martin($ticket));
    }

    /** Con la invitación a Robotito no se parte: la oferta tiene que quedar en el mensaje que la contiene. */
    public function test_con_invitacion_a_robotito_sale_una_sola_pieza(): void
    {
        $ticket = $this->ticket_whatsapp();
        $this->responder($ticket, $this->reservar($ticket), [
            'body' => "ok, mirá\n---\nse cobra desde Ventas\n\n" . SupportTourService::INVITACION,
            'tour_requested' => true,
        ])->assertOk()->assertJsonPath('operation.status', 'sent');

        $this->assertCount(1, $this->espia->textos);
        $this->assertStringNotContainsString('---', $this->espia->textos[0]);
        $this->assertStringNotContainsString("\n\n\n", $this->espia->textos[0]);
        $mensajes = $this->mensajes_de_martin($ticket);
        $this->assertCount(1, $mensajes);
        $this->assertTrue((bool) ($mensajes[0]->erp_auto_reply['tour_requested'] ?? false));
    }

    /** Un fallo en la parte 2 por WhatsApp deja la operación incierta, el caso en revisión y las partes marcadas. */
    public function test_por_whatsapp_un_fallo_en_la_parte_dos_queda_incierto_y_en_revision(): void
    {
        $ticket = $this->ticket_whatsapp();
        $this->espia->falla_en = 2;
        $reserva = $this->reservar($ticket);
        $this->responder($ticket, $reserva, ['body' => "Uno\n---\nDos\n---\nTres"])
            ->assertOk()->assertJsonPath('operation.status', 'unknown');

        $this->assertSame(['Uno'], $this->espia->textos);
        $partes = $this->mensajes_de_martin($ticket);
        $this->assertCount(3, $partes);
        $this->assertNull($partes[0]->remote_delivery_status);
        $this->assertSame('not_received', $partes[1]->remote_delivery_status);
        $this->assertSame('not_received', $partes[2]->remote_delivery_status);
        $caso = SupportCodexCase::where('support_ticket_id', $ticket->id)->firstOrFail();
        $this->assertSame('manual_review', $caso->state);
        $this->assertStringContainsString('1 de 3', (string) $caso->review_reason);
        $this->assertTrue((bool) $ticket->fresh()->support_attention_enabled);
        // Antes del envío la operación apunta a la primera parte, y ahí se queda si no salió entera.
        $operacion = SupportCodexOperation::where('support_ticket_id', $ticket->id)->firstOrFail();
        $this->assertSame((int) $partes[0]->id, (int) $operacion->message_id);
        // El mismo pedido no se reintenta a ciegas.
        $this->responder($ticket, $reserva, ['body' => "Uno\n---\nDos\n---\nTres"])
            ->assertOk()->assertJsonPath('operation.status', 'unknown');
        $this->assertSame(2, $this->espia->intentos);
    }

    /** Pedir confirmación en partes y que el cliente confirme cierra bien: la pregunta está en la última parte. */
    public function test_solicitar_confirmacion_partido_y_la_confirmacion_del_cliente_cierra(): void
    {
        $ticket = $this->ticket_whatsapp();
        $this->responder($ticket, $this->reservar($ticket), [
            'action' => 'solicitar_confirmacion',
            'body' => "listo, te lo prendí\n---\nya te aparece el botón?",
        ])->assertOk()->assertJsonPath('operation.status', 'sent');
        $this->assertSame('awaiting_confirmation', SupportCodexCase::where('support_ticket_id', $ticket->id)->value('state'));

        $confirmacion = $this->entrante($ticket, 'Sí, gracias');
        $this->responder($ticket, $this->reservar($ticket), [
            'action' => 'cerrar', 'confirmation_message_id' => $confirmacion->id, 'body' => 'cualquier cosa me escribís',
        ])->assertOk()->assertJsonPath('operation.status', 'sent')->assertJsonPath('ticket.status', 'closed');

        $this->assertSame('De nada! cualquier cosa me escribís', end($this->espia->textos));
        $this->assertSame('closed', SupportCodexCase::where('support_ticket_id', $ticket->id)->value('state'));
    }

    /** Tras una respuesta partida, «anduvo perfecto gracias» y la despedida cierran el ticket solos. */
    public function test_cierre_por_agradecimiento_tras_una_respuesta_partida(): void
    {
        $ticket = $this->ticket_whatsapp();
        $this->responder($ticket, $this->reservar($ticket), [
            'body' => "ok\n---\nen Tesorería abrí la caja en dólares\n---\ny después registrá el pago en Planes de pago",
        ])->assertOk()->assertJsonPath('operation.status', 'sent');

        $this->entrante($ticket, 'Anduvo perfecto gracias');
        $this->responder($ticket, $this->reservar($ticket), ['body' => 'De nada!'])
            ->assertOk()->assertJsonPath('operation.action', 'cerrar')
            ->assertJsonPath('operation.status', 'sent')->assertJsonPath('ticket.status', 'closed');

        $this->assertSame('De nada!', end($this->espia->textos));
        $this->assertCount(4, $this->espia->textos);
    }

    /** El cierre con una despedida partida: «De nada! » va a la primera parte. */
    public function test_cerrar_partido_antepone_de_nada_a_la_primera_parte(): void
    {
        $ticket = $this->ticket_whatsapp();
        $this->responder($ticket, $this->reservar($ticket), [
            'action' => 'solicitar_confirmacion', 'body' => '¿Ya te aparece el botón?',
        ])->assertOk();
        $confirmacion = $this->entrante($ticket, 'Sí, gracias');
        $this->responder($ticket, $this->reservar($ticket), [
            'action' => 'cerrar', 'confirmation_message_id' => $confirmacion->id,
            'body' => "genial\n---\ncualquier cosa me escribís",
        ])->assertOk()->assertJsonPath('operation.status', 'sent')->assertJsonPath('ticket.status', 'closed');

        $this->assertSame(['¿Ya te aparece el botón?', 'De nada! genial', 'cualquier cosa me escribís'], $this->espia->textos);
    }

    /** Un body sin `---` se comporta exactamente como antes: una pieza, y la operación en ese mensaje. */
    public function test_un_body_sin_separadores_sale_igual_que_antes(): void
    {
        $ticket = $this->ticket_whatsapp();
        $cuerpo = "Probá recargar Ventas.\n\nSi sigue igual avisame.";
        $this->responder($ticket, $this->reservar($ticket), ['body' => $cuerpo])
            ->assertOk()->assertJsonPath('operation.status', 'sent');

        $this->assertSame([$cuerpo], $this->espia->textos);
        $mensajes = $this->mensajes_de_martin($ticket);
        $this->assertCount(1, $mensajes);
        $this->assertSame($cuerpo, $mensajes[0]->body);
        $this->assertSame((int) $mensajes[0]->id, (int) SupportCodexOperation::where('support_ticket_id', $ticket->id)->value('message_id'));
        $this->assertSame((int) $mensajes[0]->id, (int) SupportCodexCase::where('support_ticket_id', $ticket->id)->value('last_reply_id'));
    }

    /** El envío aprobado por Lucas también sale en partes y deja las referencias en la última. */
    public function test_el_envio_aprobado_sale_en_partes_y_las_referencias_quedan_en_la_ultima(): void
    {
        $ticket = $this->ticket_whatsapp();
        $ticket->update(['requiere_verificacion_mensajes' => true]);
        $borrador = $this->responder($ticket, $this->reservar($ticket), [
            'body' => "ok, ya lo vi\n---\nse prende en Configuración", 'supports_human_approval' => true,
        ])->assertOk()->assertJsonPath('operation.status', 'awaiting_approval')->json();
        $this->assertSame([], $this->espia->textos);
        $id_borrador = (int) $borrador['operation']['message_id'];

        $admin = Admin::create(['name' => 'Lucas', 'email' => uniqid('partes') . '@test.local', 'password' => bcrypt('prueba')]);
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/support-message/' . $id_borrador . '/approve-ai-draft')
            ->assertOk()->assertJsonPath('delivered', true);

        $this->assertSame(['ok, ya lo vi', 'se prende en Configuración'], $this->espia->textos);
        $partes = $this->mensajes_de_martin($ticket);
        $this->assertCount(2, $partes);
        $this->assertSame($id_borrador, (int) $partes[0]->id);
        $operacion = SupportCodexOperation::where('support_ticket_id', $ticket->id)->firstOrFail();
        $this->assertSame('sent', $operacion->status);
        $this->assertSame((int) $partes[1]->id, (int) $operacion->message_id);
        $this->assertSame((int) $partes[1]->id, (int) SupportCodexCase::where('support_ticket_id', $ticket->id)->value('last_reply_id'));
    }

    /**
     * Si el aviso a la pantalla (Pusher) falla al emitir la parte 2, el envío NO se revierte: las tres partes quedan
     * persistidas con su id de Meta y la operación dice lo que de verdad salió.
     *
     * Sin el try/catch de emitir_mensaje(), la excepción deshacía la transacción del opener: se perdían las partes
     * 2..N y la primera volvía al body completo con `---`, aunque al cliente ya le habían llegado mensajes.
     */
    public function test_un_fallo_del_aviso_a_la_pantalla_en_la_parte_dos_no_revierte_el_envio(): void
    {
        $ticket = $this->ticket_whatsapp();
        $reserva = $this->reservar($ticket);

        // Despachador que hace fallar el SEGUNDO SupportMessageReceived, como un Pusher caído a mitad del envío.
        $despachador = new class($this->app) extends Dispatcher {
            /** @var int Avisos de mensaje emitidos. */
            public $avisos = 0;

            public function dispatch($event, $payload = [], $halt = false)
            {
                if ($event instanceof SupportMessageReceived) {
                    $this->avisos++;
                    if ($this->avisos === 2) {
                        throw new BroadcastException('Pusher no respondió (simulado).');
                    }
                }

                // El resto no tiene oyentes en este test: no se transmite nada.
                return $halt ? null : [];
            }
        };
        $this->app->instance('events', $despachador);
        Event::swap($despachador);

        $this->responder($ticket, $reserva, ['body' => "Uno\n---\nDos\n---\nTres"])
            ->assertOk()->assertJsonPath('operation.status', 'sent');

        $this->assertGreaterThanOrEqual(2, $despachador->avisos);
        $this->assertSame(['Uno', 'Dos', 'Tres'], $this->espia->textos);
        $partes = $this->mensajes_de_martin($ticket);
        $this->assertSame(['Uno', 'Dos', 'Tres'], $partes->pluck('body')->all());
        foreach ($partes as $indice => $parte) {
            $this->assertSame('wamid.parte.' . ($indice + 1), $parte->whatsapp_message_id);
            $this->assertNull($parte->remote_delivery_status);
        }
        $this->assertSame((int) $partes->last()->id, (int) SupportCodexCase::where('support_ticket_id', $ticket->id)->value('last_reply_id'));
    }

    /** Una propuesta de actualización con líneas `---` se rechaza antes de registrar nada, con un motivo corregible. */
    public function test_una_propuesta_de_actualizacion_con_separadores_se_rechaza_antes_de_registrar_nada(): void
    {
        $ticket = $this->ticket_whatsapp();
        $reserva = $this->reservar($ticket);
        $mensaje = null;
        try {
            app(SupportCodexService::class)->reply((int) $ticket->id, [
                'lease_token' => $reserva['lease_token'], 'expected_message_id' => $reserva['ticket']['expected_message_id'],
                'operation_id' => 'propuesta-con-partes-' . $ticket->id, 'action' => 'responder',
                'body' => "Ya lo corregimos.\n---\nPodemos actualizar el lunes a las 13:00. Te sirve?",
                'upgrade_proposal' => [
                    'starts_at_utc' => '2026-11-16 16:00:00', 'ends_at_utc' => '2026-11-16 19:00:00',
                    'minimum_minutes' => 180, 'confirmation_phrase' => 'Confirmo la actualización',
                ],
            ]);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
            $mensaje = $exception->getMessage();
        }

        $this->assertNotNull($mensaje, 'La propuesta con separadores tenía que rechazarse.');
        $this->assertStringStartsWith('proposal_with_separators:', $mensaje);
        $this->assertStringContainsString('---', $mensaje);
        $this->assertSame(0, SupportCodexOperation::where('support_ticket_id', $ticket->id)->count());
        $this->assertCount(0, $this->mensajes_de_martin($ticket));
        $this->assertSame([], $this->espia->textos);
    }

    /** Un body con fines de línea de Windows (`\r\n`) se parte igual por WhatsApp. */
    public function test_un_body_con_fines_de_linea_de_windows_se_parte_por_whatsapp(): void
    {
        $ticket = $this->ticket_whatsapp();
        $this->responder($ticket, $this->reservar($ticket), ['body' => "Uno\r\n---\r\nDos\r\n---\r\nTres"])
            ->assertOk()->assertJsonPath('operation.status', 'sent');

        $this->assertSame(['Uno', 'Dos', 'Tres'], $this->espia->textos);
        $this->assertCount(3, $this->mensajes_de_martin($ticket));
    }

    /** Un body con fines de línea de Windows (`\r\n`) se parte igual por el chat del sistema. */
    public function test_un_body_con_fines_de_linea_de_windows_se_parte_por_el_sistema(): void
    {
        $ticket = $this->ticket_erp();
        $this->responder($ticket, $this->reservar($ticket), ['body' => "Uno\r\n---\r\nDos"])
            ->assertOk()->assertJsonPath('operation.status', 'sent');

        $this->assertSame(['Uno', 'Dos'], $this->sync->bodies);
        $this->assertSame(['Uno', 'Dos'], $this->mensajes_de_martin($ticket)->pluck('body')->all());
    }

    /** Lo que no se parte (con adjunto) tampoco deja un `---` con fin de línea de Windows en el texto. */
    public function test_lo_que_no_se_parte_limpia_el_separador_con_fines_de_linea_de_windows(): void
    {
        $ticket = $this->ticket_whatsapp();
        $this->responder($ticket, $this->reservar($ticket), [
            'body' => "Te paso la factura\r\n---\r\nfijate el total",
            'attachment' => ['filename' => 'Factura.pdf', 'content_base64' => base64_encode("%PDF-1.4\nDocumento\n%%EOF\n")],
        ])->assertOk()->assertJsonPath('operation.status', 'sent');

        $this->assertSame(["Te paso la factura\n\nfijate el total"], $this->espia->adjuntos);
    }
}

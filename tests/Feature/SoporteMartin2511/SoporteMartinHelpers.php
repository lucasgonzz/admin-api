<?php

namespace Tests\Feature\SoporteMartin2511;

use App\Events\SupportMessageReceived;
use App\Events\SupportTicketUpdated;
use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\SupportCodexCase;
use App\Models\SupportCodexMission;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\SupportCodexSettings;
use App\Services\SupportWhatsappOpenerService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Event;

/**
 * Utilidades compartidas por los tests de la misión `martin-descripcion-y-actualizaciones` (25/11/2026).
 *
 * Arman el mismo escenario que `SoporteAutomaticoEnCodexTest`: el puente del monitor con su clave, Martín
 * habilitado desde el 24/10/2026 y un transporte de WhatsApp de mentira que registra los textos enviados.
 * Todo lo crea el propio test sobre la base de testing del worktree (con `DatabaseTransactions`); nada sale
 * a internet.
 */
trait SoporteMartinHelpers
{
    /** Headers del monitor local (clave del puente fija para la prueba). */
    protected $headers = ['X-Claude-Task-Key' => 'soporte-martin-2511', 'Accept' => 'application/json'];

    /** Prefijo de las rutas del puente del monitor. */
    protected $base = '/api/claude/support-codex';

    /** Transporte de WhatsApp de mentira: guarda los cuerpos enviados en `$bodies`. */
    protected $deliveries;

    /**
     * Prepara el puente: clave, Martín encendido, eventos de tiempo real falsos y el transporte espía.
     *
     * @return void
     */
    protected function preparar_puente(): void
    {
        config(['services.claude_task_ingest.key' => 'soporte-martin-2511']);
        Carbon::setTestNow(Carbon::parse('2026-11-25 13:00:00'));
        Event::fake([SupportMessageReceived::class, SupportTicketUpdated::class]);
        AdminSetting::set(SupportCodexSettings::KEY_ENABLED, '1');
        AdminSetting::set(SupportCodexSettings::KEY_ACTIVATED_AT, '2026-10-24 12:00:00');
        $this->deliveries = new class extends SupportWhatsappOpenerService {
            /** @var array<int, string> Textos que salieron por el transporte. */
            public $bodies = [];

            public function __construct()
            {
                // El espía no necesita transportes ni resolutores de URL.
            }

            public function deliver_follow_up(SupportTicket $ticket, SupportMessage $message, string $admin_name): array
            {
                $this->bodies[] = $message->body;
                $message->whatsapp_message_id = 'wamid.martin.' . count($this->bodies);
                $message->save();
                return ['delivery' => 'sent', 'message_id' => $message->whatsapp_message_id, 'used_template' => false, 'error' => null];
            }
        };
        $this->app->instance(SupportWhatsappOpenerService::class, $this->deliveries);
    }

    /**
     * Crea un cliente y un ticket de WhatsApp abierto con un primer entrante.
     *
     * @param  array<string, mixed> $cliente Columnas extra del cliente.
     * @return SupportTicket
     */
    protected function ticket(array $cliente = []): SupportTicket
    {
        $client = Client::create($cliente + [
            'name' => 'Cliente Martín', 'company_name' => 'Comercio Martín',
            'phone' => '+5493417771111', 'is_active' => true,
            'api_url' => 'https://api-cliente-martin.test', 'api_key' => 'secreto-que-no-sale',
        ]);
        $ticket = SupportTicket::create([
            'client_id' => $client->id, 'client_user_id' => 0, 'status' => 'open',
            'source' => 'whatsapp', 'whatsapp_phone' => $client->phone,
            'opened_at' => now(), 'last_client_message_at' => now(),
        ]);
        $this->inbound($ticket, 'No puedo facturar.');
        return $ticket;
    }

    /**
     * Registra un mensaje entrante del cliente en el ticket.
     *
     * @param  SupportTicket $ticket
     * @param  string        $body
     * @return SupportMessage
     */
    protected function inbound(SupportTicket $ticket, string $body): SupportMessage
    {
        $message = SupportMessage::create([
            'support_ticket_id' => $ticket->id, 'sender_type' => 'user', 'body' => $body,
            'kind' => 'text', 'delivered_at' => now(),
        ]);
        $ticket->last_client_message_at = now();
        $ticket->save();
        return $message;
    }

    /**
     * Toma el ticket como lo hace el monitor (sobre el último mensaje) y devuelve la respuesta del claim.
     *
     * @param  SupportTicket        $ticket
     * @param  array<string, mixed> $extra Campos extra del claim (p. ej. `supports_delivery_verification`).
     * @return array<string, mixed>
     */
    protected function claim(SupportTicket $ticket, array $extra = []): array
    {
        $last = SupportMessage::where('support_ticket_id', $ticket->id)->orderByDesc('id')->first();
        return $this->postJson($this->base . '/tickets/' . $ticket->id . '/claim', $extra + [
            'worker_id' => 'pc-lucas', 'expected_message_id' => $last->id,
        ], $this->headers)->assertOk()->json();
    }

    /**
     * Envía una respuesta con la reserva del claim. Por defecto, `responder` con un texto corto.
     *
     * @param  SupportTicket        $ticket
     * @param  array<string, mixed> $claim
     * @param  array<string, mixed> $extra
     * @return \Illuminate\Testing\TestResponse
     */
    protected function reply(SupportTicket $ticket, array $claim, array $extra = [])
    {
        return $this->postJson($this->base . '/tickets/' . $ticket->id . '/reply', array_merge([
            'lease_token' => $claim['lease_token'],
            'expected_message_id' => $claim['ticket']['expected_message_id'],
            'operation_id' => 'op-' . $ticket->id . '-' . $claim['ticket']['expected_message_id'] . '-' . count($this->deliveries->bodies),
            'action' => 'responder', 'body' => 'Probá abrir Ventas.',
            'thread_id' => 'thread-ticket-' . $ticket->id,
        ], $extra), $this->headers);
    }

    /**
     * Escala el ticket con un diagnóstico (misión `bug`) y devuelve el caso.
     *
     * @param  SupportTicket $ticket
     * @return SupportCodexCase
     */
    protected function prepare_mission(SupportTicket $ticket): SupportCodexCase
    {
        $claim = $this->claim($ticket);
        $this->reply($ticket, $claim, ['action' => 'escalar', 'mission' => 'Corregir el redondeo de la factura B.', 'mission_type' => 'bug'])->assertOk();
        return SupportCodexCase::where('support_ticket_id', $ticket->id)->firstOrFail();
    }

    /**
     * Registra el inicio de la misión del caso con el hilo `tarea-mision-2511`.
     *
     * @param  SupportTicket    $ticket
     * @param  SupportCodexCase $case
     * @return \Illuminate\Testing\TestResponse
     */
    protected function register_mission(SupportTicket $ticket, SupportCodexCase $case)
    {
        return $this->postJson($this->base . '/tickets/' . $ticket->id . '/mission-started', [
            'mission_thread_id' => 'tarea-mision-2511', 'mission_hash' => hash('sha256', $case->mission),
        ], $this->headers);
    }

    /**
     * Evidencia verificada de la corrección de la misión del caso.
     *
     * @param  SupportCodexCase      $case
     * @param  array<string, string> $commits Commits por repo (default: empresa-api y empresa-spa).
     * @return array<string, mixed>
     */
    protected function mission_evidence(SupportCodexCase $case, array $commits = []): array
    {
        $commits = $commits ?: ['empresa-api' => str_repeat('a', 40), 'empresa-spa' => str_repeat('b', 40)];
        $bases = [];
        foreach ($commits as $repo => $sha) {
            $bases[$repo] = str_repeat('c', 40);
        }
        return [
            'mission_thread_id' => 'tarea-mision-2511',
            'mission_hash' => hash('sha256', $case->mission),
            'informe' => 'informes/20261125-arreglo-verificado.md',
            'pruebas' => ['Suite de regresión verificada'],
            'commits' => $commits,
            'bases_verificadas' => $bases,
            'estado' => 'verificada',
        ];
    }

    /**
     * Arma una misión iniciada con su corrección verificada (escalar → inicio → evidencia).
     *
     * @param  SupportTicket         $ticket
     * @param  array<string, string> $commits
     * @return SupportCodexMission
     */
    protected function mision_con_evidencia(SupportTicket $ticket, array $commits = []): SupportCodexMission
    {
        $case = $this->prepare_mission($ticket);
        $this->register_mission($ticket, $case)->assertOk();
        $this->postJson($this->base . '/tickets/' . $ticket->id . '/mission-fix-evidence', $this->mission_evidence($case, $commits), $this->headers)
            ->assertOk();
        return SupportCodexMission::where('support_ticket_id', $ticket->id)->firstOrFail();
    }
}

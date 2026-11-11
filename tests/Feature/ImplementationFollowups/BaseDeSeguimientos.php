<?php

namespace Tests\Feature\ImplementationFollowups;

use App\Models\Admin;
use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\Implementation;
use App\Models\ImplementationFollowup;
use App\Models\ImplementationFollowupEvent;
use App\Models\SupportCodexCase;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\AdminPushNotificationService;
use App\Services\ImplementationFollowups\ImplementationFollowupService;
use App\Services\SupportCodexSettings;
use App\Services\WhatsappSendService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\ClaudeImplementaciones\BaseDeImplementaciones;

/**
 * Andamiaje de los tests del seguimiento proactivo de las implementaciones (misión cruzada
 * `seguimiento-de-implementaciones`, 11/11/2026).
 *
 * Reloj fijo el MIÉRCOLES 11/11/2026 a las 11:00 de Argentina (en horario, día hábil), el soporte de Codex prendido
 * (es la condición para que la respuesta de un cliente en implementación manual caiga en el ticket), un admin
 * remitente fijado por config, el envío a Kapso/Meta sustituido por un espía a nivel `WhatsappSendService` (el mismo
 * de `WhatsappDeHitoPorSoporteTest`: todo lo demás —ruta, frenos, opener, ticket, registro— es el camino real) y el
 * push al admin sustituido por otro espía (`AdminPushNotificationService`), con un admin dueño del soporte como
 * destinatario.
 */
abstract class BaseDeSeguimientos extends BaseDeImplementaciones
{
    /** Ahora en los tests: miércoles 11/11/2026, 11:00 de Argentina. */
    const AHORA = '2026-11-11 11:00:00';

    /**
     * @var WhatsappSendService
     */
    protected $espia;

    /**
     * El espía del push al admin.
     *
     * @var AdminPushNotificationService
     */
    protected $push;

    /**
     * El admin remitente (`resolver_creador()`) y destinatario de los avisos.
     *
     * @var Admin
     */
    protected $admin;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::AHORA, 'America/Argentina/Buenos_Aires'));
        Http::fake();

        AdminSetting::set(SupportCodexSettings::KEY_ENABLED, '1');
        AdminSetting::set(SupportCodexSettings::KEY_ACTIVATED_AT, now()->subDays(30)->format('Y-m-d H:i:s'));
        AdminSetting::where('key', ImplementationFollowupService::SETTING_ENABLED)->delete();
        AdminSetting::flush_memo();

        /* Los avisos van al dueño del soporte y a los que reciben escalaciones: la base del slot puede traer admins
           con esas marcas, y el test necesita que el único destinatario sea el suyo (se revierte con la transacción). */
        Admin::query()->update(['is_default_support_owner' => false, 'notify_support_escalation_whatsapp' => false]);

        $this->admin = $this->crear_admin();
        $this->admin->is_default_support_owner = true;
        $this->admin->save();
        config(['services.claude_task_ingest.default_creator_admin_id' => $this->admin->id]);

        $this->espia = $this->espiar_el_envio(true);
        $this->push  = $this->espiar_el_push();
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
     * Sustituye `WhatsappSendService` por un espía.
     *
     * @param bool $confirma true: devuelve un `whatsapp_message_id`. false: simula un rechazo de Meta.
     *
     * @return WhatsappSendService
     */
    protected function espiar_el_envio(bool $confirma): WhatsappSendService
    {
        $espia = new class extends WhatsappSendService {
            /** @var array<int, array<string, mixed>> */
            public $textos = [];

            /** @var array<int, array<string, mixed>> */
            public $plantillas = [];

            /** @var bool */
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
     * Sustituye el push al admin por un espía que registra los avisos (estático, como el servicio real).
     *
     * @return AdminPushNotificationService
     */
    protected function espiar_el_push(): AdminPushNotificationService
    {
        $espia = new class extends AdminPushNotificationService {
            /** @var array<int, array<string, mixed>> */
            public static $avisos = [];

            public static function send_to_admin_with_result(int $admin_id, string $title, string $body, array $data = [], array $accepted_hashes = []): array
            {
                static::$avisos[] = ['admin_id' => $admin_id, 'title' => $title, 'body' => $body, 'data' => $data];

                return ['accepted_endpoint_hashes' => ['h' . count(static::$avisos)], 'failed_endpoint_hashes' => [], 'complete' => true];
            }
        };

        $espia::$avisos = [];
        $this->app->instance(AdminPushNotificationService::class, $espia);

        return $espia;
    }

    /**
     * Los avisos a Lucas registrados.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function avisos(): array
    {
        $clase = get_class($this->push);

        return $clase::$avisos;
    }

    /**
     * Un cliente con su implementación de sistema en curso (manual) en la etapa pedida.
     *
     * @param int                  $etapa     La etapa.
     * @param array<string, mixed> $atributos Del cliente.
     *
     * @return array{0: Client, 1: Implementation}
     */
    protected function escenario(int $etapa = 6, array $atributos = []): array
    {
        $client         = $this->crear_cliente('Los Peña ' . Str::random(4), $atributos);
        $implementation = $this->llevar_a_la_etapa($this->crear_implementacion($client), $etapa);

        return [$client, $implementation];
    }

    /**
     * Un seguimiento ya creado, vencido por defecto.
     *
     * @param Client               $client    El cliente.
     * @param Implementation|null  $impl      La implementación.
     * @param array<string, mixed> $atributos Atributos a pisar.
     *
     * @return ImplementationFollowup
     */
    protected function seguimiento(Client $client, ?Implementation $impl, array $atributos = []): ImplementationFollowup
    {
        $followup = new ImplementationFollowup();
        $followup->client_id         = $client->id;
        $followup->implementation_id = $impl === null ? null : $impl->id;
        $followup->status            = ImplementationFollowup::STATUS_ACTIVO;
        $followup->next_due_at       = now()->subHour();

        foreach ($atributos as $campo => $valor) {
            $followup->{$campo} = $valor;
        }

        $followup->save();

        return $followup->refresh();
    }

    /**
     * Un ticket del cliente (por defecto cerrado) con un entrante del cliente en `cuando`, que deja
     * `last_client_message_at` y abre la ventana de 24 h de ese teléfono si es reciente.
     *
     * @param Client $client El cliente.
     * @param Carbon $cuando Cuándo escribió.
     * @param string $status open | closed.
     * @param string $texto  Lo que escribió.
     *
     * @return SupportTicket
     */
    protected function el_cliente_escribe(Client $client, Carbon $cuando, string $status = 'closed', string $texto = 'Hola, ¿cómo seguimos?'): SupportTicket
    {
        $ticket = SupportTicket::create([
            'client_id'              => $client->id,
            'client_user_id'         => 0,
            'status'                 => $status,
            'source'                 => 'whatsapp',
            'whatsapp_phone'         => $client->phone,
            'opened_at'              => $cuando->copy()->subMinute(),
            'closed_at'              => $status === 'closed' ? $cuando->copy()->addMinutes(5) : null,
            'last_client_message_at' => $cuando,
        ]);

        $mensaje = SupportMessage::create([
            'support_ticket_id' => $ticket->id,
            'sender_type'       => 'user',
            'kind'              => 'text',
            'body'              => $texto,
            'delivered_at'      => $cuando,
        ]);
        $mensaje->created_at = $cuando;
        $mensaje->save();

        return $ticket;
    }

    /**
     * Una reserva de Martín sobre un ticket (`support_codex_cases`), con su token en claro.
     *
     * @param SupportTicket $ticket El ticket.
     *
     * @return string El token.
     */
    protected function reservar_el_ticket(SupportTicket $ticket): string
    {
        $token = Str::random(64);

        SupportCodexCase::create([
            'support_ticket_id' => $ticket->id,
            'state'             => 'ready',
            'worker_id'         => 'monitor-test',
            'lease_hash'        => hash('sha256', $token),
            'lease_expires_at'  => now()->addMinutes(10),
        ]);

        return $token;
    }

    /**
     * Reclama un seguimiento por la ruta real y devuelve la respuesta.
     *
     * @param ImplementationFollowup $followup El seguimiento.
     * @param string|null            $token    Para renovar.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    protected function claim(ImplementationFollowup $followup, ?string $token = null)
    {
        return $this->postJson('/api/claude/support-codex/implementation-followups/' . $followup->id . '/claim',
            $token === null ? [] : ['lease_token' => $token], $this->headers());
    }

    /**
     * Los eventos del seguimiento de un tipo.
     *
     * @param ImplementationFollowup $followup El seguimiento.
     * @param string                 $kind     El tipo.
     *
     * @return \Illuminate\Support\Collection
     */
    protected function eventos(ImplementationFollowup $followup, string $kind)
    {
        return ImplementationFollowupEvent::where('implementation_followup_id', $followup->id)->where('kind', $kind)->orderBy('id')->get();
    }
}

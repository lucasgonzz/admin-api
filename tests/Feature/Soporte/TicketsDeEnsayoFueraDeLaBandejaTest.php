<?php

namespace Tests\Feature\Soporte;

use App\Events\SupportTicketAlert;
use App\Events\SupportTicketEscalated;
use App\Events\SupportTicketUpdated;
use App\Models\Admin;
use App\Models\AdminPushSubscription;
use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\SupportAttentionNotification;
use App\Models\SupportCodexCase;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\EscalationPushNotificationService;
use App\Services\Prueba\PruebaCicloService;
use App\Services\SupportAiSuggestionScheduler;
use App\Services\SupportAiSuggestionService;
use App\Services\SupportAttentionNotificationService;
use App\Services\SupportCodexSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Feature\AsistenteWhatsapp\BaseDelCanal;

/**
 * Los tickets de un cliente de ENSAYO (`clients.es_ensayo = 1`) no le llegan al operador humano (misión
 * soporte-ocultar-tickets-ensayo, 16/11/2026).
 *
 * Lo que protege:
 *  - La bandeja de Soporte no los lista y ningún contador los cuenta (no leídos, «Prueba», abiertos, la fila
 *    «Sin asignar», las tarjetas de Inicio), esté el ensayo en estado `prueba` o `activo`.
 *  - No generan avisos a humanos: push de atención, alerta de demora, escalado legacy.
 *  - 🔴 Martín NO cambia: el ticket de ensayo sigue saliendo por `claude/support-codex/*`, y el GET puntual del
 *    admin lo sigue devolviendo (con la marca `es_ensayo`).
 *
 * Cada caso lleva su control positivo con un ticket común: sin él, un filtro que escondiera TODO pasaría igual.
 */
class TicketsDeEnsayoFueraDeLaBandejaTest extends BaseDelCanal
{
    /** Clave de ingesta de las requests `claude/*` de estos tests. */
    const CLAVE_DE_INGESTA = 'clave-de-prueba-tickets-de-ensayo';

    protected function setUp(): void
    {
        parent::setUp();

        /* Ningún operador preexistente de la base del slot recibe avisos: los destinatarios son los del test. */
        Admin::query()->update(['is_default_support_owner' => false, 'notify_support_escalation_whatsapp' => false]);
    }

    /* ==================================================================================
     * Armado
     * ================================================================================== */

    /**
     * Un cliente con el estado y la marca de ensayo dados.
     *
     * @param string $phone
     * @param string $estado    Uno de los `PruebaCicloService::CLIENTE_*`.
     * @param bool   $es_ensayo
     *
     * @return Client
     */
    private function cliente(string $phone, string $estado, bool $es_ensayo): Client
    {
        $client            = $this->crear_cliente($phone, false, 'clave-' . Str::random(6));
        $client->estado    = $estado;
        $client->es_ensayo = $es_ensayo;
        $client->save();

        return $client;
    }

    /**
     * Ticket de WhatsApp abierto y sin operador, con un mensaje del cliente sin leer ni responder de hace dos horas.
     *
     * @param Client $client
     *
     * @return SupportTicket
     */
    private function ticket_sin_responder(Client $client): SupportTicket
    {
        $ticket = SupportTicket::create([
            'client_id'              => $client->id,
            'client_employee_id'     => null,
            'client_user_id'         => 0,
            'client_user_name'       => 'Marcela',
            'assigned_admin_id'      => null,
            'name'                   => null,
            'status'                 => 'open',
            'source'                 => 'whatsapp',
            'whatsapp_phone'         => (string) $client->phone,
            'opened_at'              => now(),
            'last_client_message_at' => now()->subHours(2),
        ]);

        SupportMessage::create([
            'support_ticket_id'   => $ticket->id,
            'sender_type'         => 'user',
            'kind'                => 'text',
            'body'                => 'Hola, necesito ayuda',
            'delivered_at'        => now()->subHours(2),
            'whatsapp_message_id' => 'wamid.entrante.' . Str::random(8),
        ]);

        return $ticket;
    }

    /**
     * Un ticket común, uno de prueba gratuita REAL (lead en prueba, sin marca de ensayo), y dos de ensayo: uno
     * con el cliente en estado `prueba` y otro con el cliente `activo`.
     *
     * @return array{0: SupportTicket, 1: SupportTicket, 2: SupportTicket, 3: SupportTicket}
     */
    private function escenario(): array
    {
        return [
            $this->ticket_sin_responder($this->cliente('+5493417771001', PruebaCicloService::CLIENTE_ACTIVO, false)),
            $this->ticket_sin_responder($this->cliente('+5493417771002', PruebaCicloService::CLIENTE_PRUEBA, false)),
            $this->ticket_sin_responder($this->cliente('+5493417771003', PruebaCicloService::CLIENTE_PRUEBA, true)),
            $this->ticket_sin_responder($this->cliente('+5493417771004', PruebaCicloService::CLIENTE_ACTIVO, true)),
        ];
    }

    /**
     * Operador del admin.
     *
     * @param array<string, mixed> $campos
     *
     * @return Admin
     */
    private function admin(array $campos = []): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Lucas';
        $admin->email    = 'ensayo-' . Str::random(10) . '@test.local';
        $admin->password = bcrypt('secret');
        foreach ($campos as $campo => $valor) {
            $admin->{$campo} = $valor;
        }
        $admin->save();

        return $admin;
    }

    /**
     * Registra un device de Web Push para el operador.
     *
     * @param Admin $admin
     *
     * @return void
     */
    private function device(Admin $admin): void
    {
        $endpoint = 'https://push.example.test/ensayo/' . $admin->id;
        AdminPushSubscription::create([
            'admin_id'      => $admin->id,
            'endpoint'      => $endpoint,
            'endpoint_hash' => AdminPushSubscription::hash_endpoint($endpoint),
            'p256dh'        => 'clave-publica-de-prueba',
            'auth'          => 'clave-auth-de-prueba',
        ]);
    }

    /**
     * La fila «Sin asignar» de `inbox_nav`, o null si no viene.
     *
     * @param array<int, array<string, mixed>> $filas
     *
     * @return array<string, mixed>|null
     */
    private function fila_sin_asignar(array $filas): ?array
    {
        foreach ($filas as $fila) {
            if ($fila['assigned_admin_id'] === null) {
                return $fila;
            }
        }

        return null;
    }

    /**
     * Los ids de los tickets que trae la lista de la bandeja.
     *
     * @param array<int, array<string, mixed>> $modelos
     *
     * @return array<int, int>
     */
    private function ids(array $modelos): array
    {
        return array_values(array_map('intval', array_column($modelos, 'id')));
    }

    /* ==================================================================================
     * La bandeja y sus contadores
     * ================================================================================== */

    /** @test */
    public function la_bandeja_no_lista_los_tickets_de_ensayo_y_ningun_contador_los_cuenta(): void
    {
        [$comun, $prueba, $ensayo_en_prueba, $ensayo_activo] = $this->escenario();
        $admin = $this->admin();

        $respuesta = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/support-ticket')->assertOk();

        $ids = $this->ids($respuesta->json('models'));
        $this->assertContains((int) $comun->id, $ids, 'El ticket común tiene que seguir en la bandeja.');
        $this->assertContains((int) $prueba->id, $ids, 'El ticket de una prueba gratuita real tiene que seguir en la bandeja.');
        $this->assertNotContains((int) $ensayo_en_prueba->id, $ids, 'Un ensayo en estado prueba apareció en la bandeja.');
        $this->assertNotContains((int) $ensayo_activo->id, $ids, 'Un ensayo en estado activo apareció en la bandeja.');

        $filas = [];
        foreach ($respuesta->json('models') as $fila) {
            $filas[(int) $fila['id']] = $fila;
        }
        $this->assertSame(false, $filas[(int) $comun->id]['es_prueba']);
        $this->assertSame(true, $filas[(int) $prueba->id]['es_prueba'], 'La prueba real sigue marcada para el botón «Prueba».');
        $this->assertSame(false, $filas[(int) $comun->id]['es_ensayo']);
        $this->assertSame(false, $filas[(int) $prueba->id]['es_ensayo']);

        $this->assertSame(1, $respuesta->json('unread_total'), 'Solo el mensaje del cliente común.');
        $this->assertSame(1, $respuesta->json('unread_total_prueba'), 'Solo el de la prueba real: el ensayo en prueba no cuenta en «Prueba».');
        $this->assertSame(0, $respuesta->json('unread_totals.mine'));
        $this->assertSame(1, $respuesta->json('unread_totals.others'));
        $this->assertSame(1, $respuesta->json('open_totals.all'));
        $this->assertSame(1, $respuesta->json('open_totals.unassigned'));
        $sin_asignar = $this->fila_sin_asignar($respuesta->json('inbox_nav'));
        $this->assertNotNull($sin_asignar);
        $this->assertSame(1, $sin_asignar['unread_count']);
        $this->assertSame(1, $sin_asignar['open_count']);

        /* Control: es la MARCA lo que lo esconde. Sin ella, el mismo ticket vuelve a la bandeja y a los contadores. */
        Client::whereKey($ensayo_activo->client_id)->update(['es_ensayo' => false]);
        $sin_marca = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/support-ticket')->assertOk();
        $this->assertContains((int) $ensayo_activo->id, $this->ids($sin_marca->json('models')));
        $this->assertSame(2, $sin_marca->json('unread_total'));
        $this->assertSame(2, $sin_marca->json('open_totals.all'));
    }

    /** @test */
    public function los_badges_no_cuentan_los_tickets_de_ensayo(): void
    {
        $this->escenario();
        $admin = $this->admin();

        $insignias = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/support-ticket/unread-badges')->assertOk();

        $this->assertSame(1, $insignias->json('unread_total'));
        $this->assertSame(1, $insignias->json('unread_total_prueba'));
        $this->assertSame(0, $insignias->json('unread_totals.mine'));
        $this->assertSame(1, $insignias->json('unread_totals.others'));
        $this->assertSame(1, $insignias->json('open_totals.all'));
        $this->assertSame(0, $insignias->json('open_totals.mine'));
        $this->assertSame(1, $insignias->json('open_totals.unassigned'));
        $sin_asignar = $this->fila_sin_asignar($insignias->json('inbox_nav'));
        $this->assertNotNull($sin_asignar);
        $this->assertSame(1, $sin_asignar['unread_count']);
        $this->assertSame(1, $sin_asignar['open_count']);
    }

    /** @test */
    public function un_ticket_de_ensayo_asignado_tampoco_cuenta_para_su_operador(): void
    {
        $admin  = $this->admin();
        $ensayo = $this->ticket_sin_responder($this->cliente('+5493417771011', PruebaCicloService::CLIENTE_ACTIVO, true));
        $comun  = $this->ticket_sin_responder($this->cliente('+5493417771012', PruebaCicloService::CLIENTE_ACTIVO, false));
        SupportTicket::whereKey([$ensayo->id, $comun->id])->update(['assigned_admin_id' => $admin->id]);

        $insignias = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/support-ticket/unread-badges')->assertOk();

        $this->assertSame(1, $insignias->json('unread_totals.mine'), 'Solo el ticket común asignado.');
        $this->assertSame(0, $insignias->json('unread_totals.others'));
        $this->assertSame(1, $insignias->json('open_totals.mine'));
        foreach ($insignias->json('inbox_nav') as $fila) {
            if ((int) $fila['assigned_admin_id'] === (int) $admin->id) {
                $this->assertSame(1, $fila['unread_count']);
                $this->assertSame(1, $fila['open_count']);
            }
        }
    }

    /** @test */
    public function la_fila_sin_asignar_no_aparece_si_lo_unico_sin_asignar_es_un_ensayo(): void
    {
        $admin = $this->admin();
        $this->ticket_sin_responder($this->cliente('+5493417771021', PruebaCicloService::CLIENTE_ACTIVO, true));

        $solo_ensayo = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/support-ticket/unread-badges')->assertOk();
        $this->assertNull($this->fila_sin_asignar($solo_ensayo->json('inbox_nav')), 'Un ensayo hizo aparecer la fila «Sin asignar».');
        $this->assertSame(0, $solo_ensayo->json('unread_total'));
        $this->assertSame(0, $solo_ensayo->json('open_totals.all'));

        /* Control: con un ticket común sin asignar, la fila aparece. */
        $this->ticket_sin_responder($this->cliente('+5493417771022', PruebaCicloService::CLIENTE_ACTIVO, false));
        $con_comun = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/support-ticket/unread-badges')->assertOk();
        $this->assertNotNull($this->fila_sin_asignar($con_comun->json('inbox_nav')));
    }

    /** @test */
    public function el_ticket_de_ensayo_se_sigue_pudiendo_abrir_por_id_y_viaja_marcado(): void
    {
        [$comun, , $ensayo_en_prueba, $ensayo_activo] = $this->escenario();
        $admin = $this->admin();

        foreach ([$ensayo_en_prueba, $ensayo_activo] as $ensayo) {
            $this->actingAs($admin, 'sanctum')->getJson('/api/admin/support-ticket/' . $ensayo->id)->assertOk()
                ->assertJsonPath('model.id', (int) $ensayo->id)
                ->assertJsonPath('model.es_ensayo', true);
        }
        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/support-ticket/' . $comun->id)->assertOk()
            ->assertJsonPath('model.es_ensayo', false);

        /* La respuesta de una acción sobre un ticket de ensayo sigue trayendo la fila (y no `model: null`): el
           filtro de la bandeja vive en el listado, no en la consulta que arma la fila. */
        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/admin/support-ticket/' . $ensayo_activo->id . '/attention', ['needs_human' => false])
            ->assertOk()
            ->assertJsonPath('model.id', (int) $ensayo_activo->id)
            ->assertJsonPath('model.es_ensayo', true);
    }

    /** @test */
    public function las_tarjetas_de_inicio_no_cuentan_los_tickets_de_ensayo(): void
    {
        [, , , $ensayo_activo] = $this->escenario();
        $admin = $this->admin();

        $tarjetas = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/inicio/tarjetas')->assertOk();
        $this->assertSame(1, $tarjetas->json('tickets_sin_responder'), 'Solo el ticket común (la prueba real ya iba aparte).');
        $this->assertSame(1, $tarjetas->json('tickets_abiertos_hoy'));

        /* Control: sin la marca, el ensayo en estado activo vuelve a contar en las dos tarjetas. */
        Client::whereKey($ensayo_activo->client_id)->update(['es_ensayo' => false]);
        $sin_marca = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/inicio/tarjetas')->assertOk();
        $this->assertSame(2, $sin_marca->json('tickets_sin_responder'));
        $this->assertSame(2, $sin_marca->json('tickets_abiertos_hoy'));
    }

    /* ==================================================================================
     * Los avisos a humanos
     * ================================================================================== */

    /**
     * El servicio de avisos con el transporte sustituido: registra lo que habría salido por push.
     *
     * @return SupportAttentionNotificationService
     */
    private function espiar_avisos_de_atencion(): SupportAttentionNotificationService
    {
        $espia = new class extends SupportAttentionNotificationService {
            /** @var array<int, array<string, mixed>> Push que habrían salido. */
            public $sent = [];

            protected function send_push(int $admin_id, string $title, string $body, array $data, array $accepted_hashes = []): array
            {
                $this->sent[] = compact('admin_id', 'title', 'body', 'data');

                return ['accepted_endpoint_hashes' => [hash('sha256', 'device-' . $admin_id)], 'failed_endpoint_hashes' => [], 'complete' => true];
            }
        };
        $this->app->instance(SupportAttentionNotificationService::class, $espia);

        return $espia;
    }

    /**
     * El aviso de atención del ticket.
     *
     * @param SupportTicket $ticket
     *
     * @return SupportAttentionNotification
     */
    private function aviso(SupportTicket $ticket): SupportAttentionNotification
    {
        return SupportAttentionNotification::where('support_ticket_id', $ticket->id)->firstOrFail();
    }

    /** @test */
    public function un_caso_de_ensayo_que_pide_revision_o_mision_no_encola_ni_manda_el_push(): void
    {
        $servicio = $this->espiar_avisos_de_atencion();
        $dueno    = $this->admin(['is_default_support_owner' => true]);
        $this->device($dueno);

        $ensayo = $this->ticket_sin_responder($this->cliente('+5493417771031', PruebaCicloService::CLIENTE_ACTIVO, true));
        $comun  = $this->ticket_sin_responder($this->cliente('+5493417771032', PruebaCicloService::CLIENTE_ACTIVO, false));
        $caso_del_ensayo = SupportCodexCase::create(['support_ticket_id' => $ensayo->id, 'state' => 'ready']);
        $caso_del_comun  = SupportCodexCase::create(['support_ticket_id' => $comun->id, 'state' => 'ready']);

        /* Revisión humana. */
        $caso_del_ensayo->update(['state' => 'manual_review', 'review_reason' => 'No puede operar.']);
        $caso_del_comun->update(['state' => 'manual_review', 'review_reason' => 'No puede operar.']);

        $this->assertNull($this->aviso($ensayo)->fingerprint, 'El ensayo dejó un aviso pendiente.');
        $this->assertNull($this->aviso($ensayo)->enqueued_at, 'El ensayo encoló un aviso.');
        $this->assertNotNull($this->aviso($comun)->fingerprint, 'Control: el ticket común tiene que dejar su aviso.');
        $this->assertNotNull($this->aviso($comun)->enqueued_at, 'Control: el ticket común tiene que encolar su aviso.');

        $servicio->enviar((int) $ensayo->id, (int) $this->aviso($ensayo)->generation);
        $this->assertCount(0, $servicio->sent, 'Salió un push por un ticket de ensayo.');
        $servicio->enviar((int) $comun->id, (int) $this->aviso($comun)->generation);
        $this->assertCount(1, $servicio->sent, 'Control: el push del ticket común tiene que salir.');
        $this->assertSame('/soporte/ticket/' . $comun->id, $servicio->sent[0]['data']['url']);

        /* 🔴 Solo se cortó el push: el caso y la atención del ticket de ensayo quedaron como los dejó Martín. */
        $this->assertSame('manual_review', $caso_del_ensayo->fresh()->state);
        $this->assertTrue((bool) $ensayo->fresh()->support_attention_enabled);
        $this->assertTrue($ensayo->fresh()->load('support_codex_case')->support_attention['needs_human']);

        /* Misión pendiente de iniciar: tampoco avisa. */
        $caso_del_ensayo->update(['state' => 'mission_pending', 'review_reason' => null, 'mission' => 'Corregir el listado.']);
        $this->assertNull($this->aviso($ensayo)->fingerprint);
        $this->assertNull($this->aviso($ensayo)->enqueued_at);
        $this->assertSame('pending', $ensayo->fresh()->load('support_codex_case')->support_attention['mission']['status']);

        /* El conciliador tampoco lo levanta, ni duplica el del común. */
        $servicio->conciliar();
        $this->assertNull($this->aviso($ensayo)->fingerprint);
        $servicio->enviar((int) $ensayo->id, (int) $this->aviso($ensayo)->generation);
        $this->assertCount(1, $servicio->sent);
    }

    /** @test */
    public function un_aviso_que_ya_estaba_pendiente_de_un_ensayo_se_da_por_resuelto_sin_mandarse(): void
    {
        $servicio = $this->espiar_avisos_de_atencion();
        $dueno    = $this->admin(['is_default_support_owner' => true]);
        $this->device($dueno);

        /* El aviso nace cuando el cliente todavía no estaba marcado (lo que hay en producción antes del despliegue). */
        $ticket = $this->ticket_sin_responder($this->cliente('+5493417771041', PruebaCicloService::CLIENTE_ACTIVO, false));
        $caso   = SupportCodexCase::create(['support_ticket_id' => $ticket->id, 'state' => 'ready']);
        $caso->update(['state' => 'manual_review', 'review_reason' => 'Revisar.']);
        $generacion = (int) $this->aviso($ticket)->generation;
        $this->assertNotNull($this->aviso($ticket)->fingerprint);

        Client::whereKey($ticket->client_id)->update(['es_ensayo' => true]);

        /* El job que ya estaba en la cola no manda nada… */
        $servicio->enviar((int) $ticket->id, $generacion);
        $this->assertCount(0, $servicio->sent, 'Un job ya encolado mandó el push de un ensayo.');

        /* …y el conciliador deja el aviso resuelto, sin reencolarlo. */
        $servicio->conciliar();
        $this->assertNull($this->aviso($ticket)->fingerprint);
        $this->assertNull($this->aviso($ticket)->enqueued_at);
        $this->assertNull($this->aviso($ticket)->notified_at);
        $servicio->enviar((int) $ticket->id, (int) $this->aviso($ticket)->generation);
        $this->assertCount(0, $servicio->sent);
    }

    /** @test */
    public function la_alerta_de_demora_no_salta_para_los_tickets_de_ensayo(): void
    {
        Event::fake([SupportTicketAlert::class]);
        [$comun, $prueba, $ensayo_en_prueba, $ensayo_activo] = $this->escenario();

        Artisan::call('support:check-response-alerts');

        foreach ([$comun, $prueba] as $ticket) {
            Event::assertDispatched(SupportTicketAlert::class, function ($evento) use ($ticket) {
                return (int) $evento->ticket_id === (int) $ticket->id;
            });
        }
        foreach ([$ensayo_en_prueba, $ensayo_activo] as $ticket) {
            Event::assertNotDispatched(SupportTicketAlert::class, function ($evento) use ($ticket) {
                return (int) $evento->ticket_id === (int) $ticket->id;
            });
            $this->assertNull($ticket->fresh()->alert_sent_at);
        }
    }

    /**
     * Sustituye el servicio que llama a Claude por uno que escala con el motivo dado.
     *
     * @param string $motivo
     *
     * @return void
     */
    private function claude_que_escala(string $motivo): void
    {
        $espia = new class extends SupportAiSuggestionService {
            /** @var array<string, mixed> Respuesta simulada. */
            public $resultado = [];

            public function generate(SupportTicket $ticket): array
            {
                return $this->resultado;
            }
        };
        $espia->resultado = [
            'suggested_message' => 'Dame un momento, por favor.',
            'reasoning'         => '',
            'should_close'      => false,
            'should_escalate'   => true,
            'escalation_reason' => $motivo,
        ];
        $this->app->instance(SupportAiSuggestionService::class, $espia);
    }

    /**
     * Sustituye el envío real de Web Push del escalado legacy.
     *
     * @return EscalationPushNotificationService
     */
    private function espiar_push_del_escalado(): EscalationPushNotificationService
    {
        $espia = new class extends EscalationPushNotificationService {
            /** @var array<int, array<string, mixed>> Push despachados. */
            public $enviados = [];

            protected function send_push(int $admin_id, string $titulo, string $cuerpo, array $data): void
            {
                $this->enviados[] = ['admin_id' => $admin_id, 'titulo' => $titulo, 'cuerpo' => $cuerpo, 'data' => $data];
            }
        };
        $this->app->instance(EscalationPushNotificationService::class, $espia);

        return $espia;
    }

    /**
     * Ticket de WhatsApp con un entrante reciente, para el agente legacy (el job `SendSupportAiSuggestion`).
     *
     * @param Client $client
     *
     * @return SupportTicket
     */
    private function ticket_para_el_agente_legacy(Client $client): SupportTicket
    {
        $ticket = SupportTicket::create([
            'client_id'        => $client->id,
            'client_user_id'   => 0,
            'client_user_name' => 'Contacto',
            'name'             => 'Consulta de prueba',
            'status'           => 'open',
            'source'           => 'whatsapp',
            'whatsapp_phone'   => (string) $client->phone,
            'opened_at'        => now()->subHours(2),
            /* La espera queda en borrador: acá se miden los avisos a humanos, no la entrega al cliente. */
            'requiere_verificacion_mensajes' => true,
        ]);

        SupportMessage::create([
            'support_ticket_id' => $ticket->id,
            'sender_type'       => 'user',
            'kind'              => 'text',
            'body'              => '¿Los presupuestos se pueden hacer en dólares?',
            'delivered_at'      => now()->subMinutes(5),
        ]);

        return $ticket;
    }

    /** @test */
    public function el_escalado_legacy_de_un_ensayo_no_avisa_por_toast_push_ni_whatsapp(): void
    {
        Event::fake([SupportTicketEscalated::class, SupportTicketUpdated::class]);

        /* Un operador con device (recibe el push) y otro sin device (recibe la plantilla de WhatsApp). */
        $con_device = $this->admin(['phone_number' => '+5493410000021', 'notify_support_escalation_whatsapp' => true]);
        $this->device($con_device);
        $this->admin(['phone_number' => '+5493410000022', 'notify_support_escalation_whatsapp' => true]);

        $sender = $this->espiar_sender();
        $push   = $this->espiar_push_del_escalado();
        $this->claude_que_escala('No está cubierto por el manual');

        /* Ensayo: se escala igual, pero no le avisa a nadie. */
        $ensayo = $this->ticket_para_el_agente_legacy($this->cliente('+5493417771051', PruebaCicloService::CLIENTE_ACTIVO, true));
        app(SupportAiSuggestionScheduler::class)->schedule_after_client_inbound((int) $ensayo->id);

        $this->assertNotNull($ensayo->fresh()->escalated_at, 'El ticket de ensayo tiene que quedar escalado igual: el manejo no cambia.');
        $this->assertSame('No está cubierto por el manual', $ensayo->fresh()->escalation_reason);
        Event::assertNotDispatched(SupportTicketEscalated::class);
        Event::assertDispatched(SupportTicketUpdated::class);
        $this->assertCount(0, $push->enviados, 'Salió un push por el escalado de un ensayo.');
        $this->assertCount(0, $sender->plantillas, 'Salió un WhatsApp por el escalado de un ensayo.');

        /* Control: el mismo escalado en un ticket común avisa por los tres caminos. */
        $comun = $this->ticket_para_el_agente_legacy($this->cliente('+5493417771052', PruebaCicloService::CLIENTE_ACTIVO, false));
        app(SupportAiSuggestionScheduler::class)->schedule_after_client_inbound((int) $comun->id);

        $this->assertNotNull($comun->fresh()->escalated_at);
        Event::assertDispatched(SupportTicketEscalated::class, function ($evento) use ($comun) {
            return (int) $evento->ticket_id === (int) $comun->id;
        });
        $this->assertCount(1, $push->enviados);
        $this->assertSame((int) $con_device->id, $push->enviados[0]['admin_id']);
        $this->assertCount(1, $sender->plantillas);
        $this->assertSame('+5493410000022', $sender->plantillas[0]['to']);
    }

    /* ==================================================================================
     * Martín, intacto
     * ================================================================================== */

    /** @test */
    public function martin_sigue_viendo_y_leyendo_el_ticket_de_ensayo(): void
    {
        config(['services.claude_task_ingest.key' => self::CLAVE_DE_INGESTA]);
        $headers = ['X-Claude-Task-Key' => self::CLAVE_DE_INGESTA, 'Accept' => 'application/json'];
        AdminSetting::set(SupportCodexSettings::KEY_ENABLED, '1');
        AdminSetting::set(SupportCodexSettings::KEY_ACTIVATED_AT, now()->subDay()->format('Y-m-d H:i:s'));

        $ensayo = $this->ticket_sin_responder($this->cliente('+5493417771061', PruebaCicloService::CLIENTE_PRUEBA, true));
        $comun  = $this->ticket_sin_responder($this->cliente('+5493417771062', PruebaCicloService::CLIENTE_ACTIVO, false));

        $pendientes = $this->getJson('/api/claude/support-codex/pending', $headers)->assertOk();
        $ids = array_map('intval', array_column($pendientes->json('tickets'), 'id'));
        $this->assertContains((int) $ensayo->id, $ids, 'Martín dejó de ver el ticket de ensayo en su cola.');
        $this->assertContains((int) $comun->id, $ids);

        $this->getJson('/api/claude/support-codex/tickets/' . $ensayo->id, $headers)->assertOk()
            ->assertJsonPath('ticket.id', (int) $ensayo->id)
            ->assertJsonPath('ticket.client.es_ensayo', true);
        $this->getJson('/api/claude/support-codex/tickets/' . $comun->id, $headers)->assertOk()
            ->assertJsonPath('ticket.client.es_ensayo', false);

        /* Y en ese mismo momento el operador no lo tiene en su bandeja. */
        $bandeja = $this->actingAs($this->admin(), 'sanctum')->getJson('/api/admin/support-ticket')->assertOk();
        $this->assertNotContains((int) $ensayo->id, $this->ids($bandeja->json('models')));
        $this->assertContains((int) $comun->id, $this->ids($bandeja->json('models')));
    }
}

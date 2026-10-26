<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientEmployee;
use App\Models\SupportMessage;
use App\Models\SupportMessageAttachment;
use App\Models\SupportTicket;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `GET claude/support-codex/tickets/{id}/history` y el bloque `previous_tickets` del contexto (misión
 * `hitos-de-implementacion-por-soporte`, 26/10/2026): Martín puede leer los tickets ANTERIORES del cliente
 * para saber dónde quedó una conversación ya cerrada.
 *
 * Lo que se verifica: el orden (el más nuevo primero), los topes (5 tickets, 30 mensajes reales por ticket),
 * que el actual no venga, que no se cuelen tickets de otro cliente ni los borradores del agente, que un
 * empleado vea solo los suyos (y el dueño todos), y que el adjunto de un ticket anterior se pueda bajar con su
 * `download_path`.
 */
class HistorialDeTicketsDeSoporteParaCodexTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array<string, string> Headers con la clave de ingesta. */
    private $headers = ['X-Claude-Task-Key' => 'historial-codex-test', 'Accept' => 'application/json'];

    /** @var string Base de las rutas del puente. */
    private $base = '/api/claude/support-codex';

    /**
     * La clave de ingesta del test, sin red hacia los clientes y el reloj fijo.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.claude_task_ingest.key' => 'historial-codex-test']);
        Http::fake();
        Carbon::setTestNow(Carbon::parse('2026-10-26 13:00:00'));
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
     * Un cliente activo.
     *
     * @param string $nombre El nombre.
     *
     * @return Client
     */
    private function cliente(string $nombre = 'Ferretería Historial'): Client
    {
        return Client::create([
            'name' => $nombre, 'company_name' => 'Negocio ' . $nombre,
            'phone' => '+549341' . random_int(1000000, 9999999), 'is_active' => true,
            'api_url' => 'https://api-historial.test', 'api_key' => 'secreto',
        ]);
    }

    /**
     * Un ticket de WhatsApp del cliente, abierto hace `$dias` días.
     *
     * @param Client   $client      El cliente.
     * @param string   $status      open | closed.
     * @param int      $dias        Hace cuántos días se abrió.
     * @param int|null $empleado_id El empleado, si el contacto es un empleado.
     *
     * @return SupportTicket
     */
    private function ticket(Client $client, string $status, int $dias, $empleado_id = null): SupportTicket
    {
        return SupportTicket::create([
            'client_id' => $client->id, 'client_employee_id' => $empleado_id, 'client_user_id' => 0,
            'status' => $status, 'source' => 'whatsapp', 'whatsapp_phone' => $client->phone,
            'opened_at' => now()->subDays($dias), 'closed_at' => $status === 'closed' ? now()->subDays($dias)->addHours(3) : null,
            'last_client_message_at' => now()->subDays($dias),
        ]);
    }

    /**
     * Un mensaje del ticket.
     *
     * @param SupportTicket $ticket   El ticket.
     * @param string        $cuerpo   El texto.
     * @param string        $quien    user | admin.
     * @param bool          $borrador true = borrador del agente (no es un mensaje real).
     *
     * @return SupportMessage
     */
    private function mensaje(SupportTicket $ticket, string $cuerpo, string $quien = 'user', bool $borrador = false): SupportMessage
    {
        return SupportMessage::create([
            'support_ticket_id' => $ticket->id, 'sender_type' => $quien, 'kind' => 'text', 'body' => $cuerpo,
            'delivered_at' => now(), 'is_ai_suggestion_draft' => $borrador,
        ]);
    }

    /**
     * Hasta 5 tickets, el más nuevo primero, sin el actual ni los de otro cliente; cada uno con sus últimos 30
     * mensajes reales en orden cronológico y sin borradores. El contexto trae el mismo resumen.
     *
     * @return void
     */
    public function test_orden_topes_sin_el_actual_y_sin_borradores(): void
    {
        $client = $this->cliente();
        $otro   = $this->cliente('Otro Negocio');
        $this->mensaje($this->ticket($otro, 'closed', 1), 'De otro cliente');

        $anteriores = [];
        for ($dias = 20; $dias >= 14; $dias--) {
            $anterior = $this->ticket($client, 'closed', $dias);
            $this->mensaje($anterior, 'Consulta de hace ' . $dias . ' días');
            $anteriores[] = $anterior;
        }

        /* El más nuevo de los anteriores tiene 35 mensajes reales y un borrador al final. */
        $nuevo = $anteriores[count($anteriores) - 1];
        for ($numero = 1; $numero <= 34; $numero++) {
            $this->mensaje($nuevo, 'Mensaje ' . $numero, $numero % 2 === 0 ? 'admin' : 'user');
        }
        $this->mensaje($nuevo, 'Borrador que no salió', 'admin', true);

        $actual = $this->ticket($client, 'open', 0);
        $this->mensaje($actual, '¿En qué quedó lo del catálogo?');

        $respuesta = $this->getJson($this->base . '/tickets/' . $actual->id . '/history', $this->headers)->assertOk();

        $ids = array_column($respuesta->json('tickets'), 'id');
        $this->assertCount(5, $ids);
        $this->assertSame(
            array_map(function ($t) {
                return (int) $t->id;
            }, array_slice(array_reverse($anteriores), 0, 5)),
            $ids
        );
        $this->assertNotContains((int) $actual->id, $ids);

        $primero = $respuesta->json('tickets.0');
        $this->assertSame('closed', $primero['status']);
        $this->assertNotNull($primero['closed_at']);
        $this->assertSame('owner', $primero['contact_role']);
        $this->assertCount(30, $primero['messages']);
        $this->assertSame('Mensaje 5', $primero['messages'][0]['body']);
        $this->assertSame('Mensaje 34', $primero['messages'][29]['body']);
        $this->assertNotContains('Borrador que no salió', array_column($primero['messages'], 'body'));
        $this->assertSame('owner', $respuesta->json('contact_role'));
        $this->assertSame((int) $client->id, $respuesta->json('client_id'));

        $contexto = $this->getJson($this->base . '/tickets/' . $actual->id, $this->headers)->assertOk();
        $resumen  = $contexto->json('ticket.previous_tickets');
        $this->assertSame($ids, array_column($resumen, 'id'));
        $this->assertSame(35, $resumen[0]['mensajes']);
        $this->assertSame(['id', 'status', 'opened_at', 'closed_at', 'mensajes'], array_keys($resumen[0]));
    }

    /**
     * Un empleado ve solo SUS tickets anteriores; el dueño ve todos, los de sus empleados incluidos.
     *
     * @return void
     */
    public function test_el_empleado_ve_solo_los_suyos_y_el_dueno_todos(): void
    {
        $client   = $this->cliente();
        $empleado = ClientEmployee::create(['client_id' => $client->id, 'name' => 'Brisa', 'phone' => '+5493419998888']);
        $otro     = ClientEmployee::create(['client_id' => $client->id, 'name' => 'Tomás', 'phone' => '+5493417776666']);

        $del_dueno    = $this->ticket($client, 'closed', 9);
        $del_empleado = $this->ticket($client, 'closed', 8, $empleado->id);
        $del_otro     = $this->ticket($client, 'closed', 7, $otro->id);

        $actual_del_empleado = $this->ticket($client, 'open', 0, $empleado->id);
        $historial = $this->getJson($this->base . '/tickets/' . $actual_del_empleado->id . '/history', $this->headers)->assertOk();
        $this->assertSame([(int) $del_empleado->id], array_column($historial->json('tickets'), 'id'));
        $this->assertSame('employee', $historial->json('contact_role'));
        $this->assertSame('Brisa', $historial->json('tickets.0.contact_name'));

        $contexto = $this->getJson($this->base . '/tickets/' . $actual_del_empleado->id, $this->headers)->assertOk();
        $this->assertSame([(int) $del_empleado->id], array_column($contexto->json('ticket.previous_tickets'), 'id'));

        $actual_del_dueno = $this->ticket($client, 'open', 0);
        $todos = $this->getJson($this->base . '/tickets/' . $actual_del_dueno->id . '/history', $this->headers)->assertOk();
        $this->assertSame(
            [(int) $actual_del_empleado->id, (int) $del_otro->id, (int) $del_empleado->id, (int) $del_dueno->id],
            array_column($todos->json('tickets'), 'id')
        );
    }

    /**
     * El adjunto de un ticket anterior trae el `download_path` de SU ticket y se baja con esa ruta; desde la
     * ruta del ticket actual, no (404: el adjunto no es de ese ticket).
     *
     * @return void
     */
    public function test_el_adjunto_de_un_ticket_anterior_se_baja_con_su_path(): void
    {
        Storage::fake('public');
        $client   = $this->cliente();
        $anterior = $this->ticket($client, 'closed', 5);
        $mensaje  = $this->mensaje($anterior, 'Te mando la lista');
        Storage::disk('public')->put('soporte/lista.pdf', 'pdf-de-prueba');
        $adjunto = SupportMessageAttachment::create([
            'support_message_id' => $mensaje->id, 'disk' => 'public',
            'path' => 'soporte/lista.pdf', 'mime' => 'application/pdf', 'size' => 13,
        ]);

        $actual = $this->ticket($client, 'open', 0);
        $respuesta = $this->getJson($this->base . '/tickets/' . $actual->id . '/history', $this->headers)->assertOk();

        $path = $respuesta->json('tickets.0.messages.0.attachments.0.download_path');
        $this->assertSame('/api/claude/support-codex/tickets/' . $anterior->id . '/attachments/' . $adjunto->id, $path);
        $this->assertSame('application/pdf', $respuesta->json('tickets.0.messages.0.attachments.0.mime'));

        $this->get($path, $this->headers)->assertOk();
        $this->get($this->base . '/tickets/' . $actual->id . '/attachments/' . $adjunto->id, $this->headers)->assertStatus(404);
    }

    /**
     * Sin tickets anteriores, listas vacías; un ticket inexistente es 404; sin la clave, 401.
     *
     * @return void
     */
    public function test_sin_anteriores_inexistente_y_sin_clave(): void
    {
        $client = $this->cliente();
        $actual = $this->ticket($client, 'open', 0);

        $this->getJson($this->base . '/tickets/' . $actual->id . '/history', $this->headers)
            ->assertOk()
            ->assertJsonPath('tickets', []);
        $this->getJson($this->base . '/tickets/' . $actual->id, $this->headers)
            ->assertOk()
            ->assertJsonPath('ticket.previous_tickets', []);

        $this->getJson($this->base . '/tickets/99999999/history', $this->headers)->assertStatus(404);
        $this->getJson($this->base . '/tickets/' . $actual->id . '/history', ['Accept' => 'application/json'])->assertStatus(401);
    }
}

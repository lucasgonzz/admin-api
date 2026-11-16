<?php

namespace Tests\Feature;

use App\Events\SupportMessageReceived;
use App\Events\SupportTicketUpdated;
use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\TrialInstance;
use App\Services\ClientAdminSyncPuenteService;
use App\Services\Prueba\PruebaVencimientoService;
use App\Services\SupportCodexSettings;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A6.1 (misión prueba-e2e-campana-imperio, 14/11/2026): las llamadas del admin al empresa-api de un negocio EN PRUEBA
 * (instancia del pool en el VPS) se resuelven a la IP del VPS con `CURLOPT_RESOLVE`, sin depender del DNS recién
 * creado del alias. Para cualquier otro cliente la opción NO viaja.
 *
 * Las opciones de Guzzle se leen del segundo argumento del callback de `Http::fake()` (Laravel 8 se las pasa).
 */
class SoportePruebaResolucionLocalTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array<string, string> */
    private $headers = ['X-Claude-Task-Key' => 'resolucion-local-test', 'Accept' => 'application/json'];

    /** @var array<int, array{url: string, options: array}> Pedidos que salieron, con sus opciones. */
    private $pedidos = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.claude_task_ingest.key' => 'resolucion-local-test']);
        config(['services.hostinger.vps_ip' => '76.13.171.147', 'services.hostinger.domain' => 'comerciocity.com']);
        config(['services.client_api.retries' => 1]);
        Carbon::setTestNow(Carbon::parse('2026-11-14 16:44:00'));
        Queue::fake();
        Event::fake([SupportMessageReceived::class, SupportTicketUpdated::class]);
        AdminSetting::set(SupportCodexSettings::KEY_ENABLED, '1');
        AdminSetting::set(SupportCodexSettings::KEY_ACTIVATED_AT, '2026-11-14 12:00:00');

        $this->pedidos = [];
        Http::fake(function ($request, array $options) {
            $this->pedidos[] = ['url' => (string) $request->url(), 'options' => $options];

            return Http::response(['ok' => true, 'vence_at' => null], 200);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Cliente con la API en `$api_url` y, si se pide, su instancia de prueba con ese alias. */
    private function cliente(string $api_url, ?string $alias = null, string $estado = TrialInstance::ESTADO_ASIGNADA): Client
    {
        $cliente = Client::create([
            'name' => 'Ferretería de prueba', 'phone' => '+5493417711' . random_int(100, 999), 'is_active' => true,
            'api_url' => $api_url, 'api_key' => 'clave-que-no-sale',
        ]);
        if ($alias !== null) {
            TrialInstance::where('nombre', 'prueba-97')->delete();
            TrialInstance::create([
                'nombre' => 'prueba-97', 'estado' => $estado, 'client_id' => $cliente->id, 'alias_slug' => $alias,
                'erp_vps_path' => 'prueba-97', 'ecommerce_vps_path' => 'tienda-prueba-97',
            ]);
        }

        return $cliente;
    }

    /** Ticket del chat del sistema (Agentes) con un entrante verificado, como el ticket 125. */
    private function ticket_erp(Client $cliente): SupportTicket
    {
        $ticket = SupportTicket::create([
            'client_id' => $cliente->id, 'client_user_id' => 17, 'status' => 'open', 'source' => 'erp',
            'agentes_erp' => true, 'opened_at' => now(), 'last_client_message_at' => now(),
        ]);
        SupportMessage::create([
            'support_ticket_id' => $ticket->id, 'sender_type' => 'user', 'kind' => 'text',
            'body' => 'No me aparece el Excel que subí.', 'delivered_at' => now(),
            'erp_auto_reply' => [SupportMessage::MARCA_ERP_VERIFICADO_PARA_CODEX => true],
        ]);

        return $ticket;
    }

    /** Martín contesta por el endpoint real de Codex. */
    private function responder(SupportTicket $ticket)
    {
        $ultimo = SupportMessage::where('support_ticket_id', $ticket->id)->where('sender_type', 'user')->orderByDesc('id')->first();
        $reserva = $this->postJson('/api/claude/support-codex/tickets/' . $ticket->id . '/claim', [
            'worker_id' => 'pc-resolucion', 'expected_message_id' => $ultimo->id,
        ], $this->headers)->assertOk()->json();

        return $this->postJson('/api/claude/support-codex/tickets/' . $ticket->id . '/reply', [
            'lease_token' => $reserva['lease_token'],
            'expected_message_id' => $reserva['ticket']['expected_message_id'],
            'operation_id' => 'resolucion-' . $ticket->id,
            'thread_id' => 'hilo-resolucion-' . $ticket->id,
            'action' => 'responder', 'body' => 'ya lo estoy mirando',
        ], $this->headers);
    }

    /** Los pedidos que fueron a un path. */
    private function pedidos_a(string $path): array
    {
        return array_values(array_filter($this->pedidos, function ($pedido) use ($path) {
            return strpos($pedido['url'], $path) !== false;
        }));
    }

    public function test_la_respuesta_de_martin_a_un_negocio_en_prueba_viaja_con_la_ip_del_vps(): void
    {
        $cliente = $this->cliente('https://api-ferreteria-el-galpon.comerciocity.com', 'ferreteria-el-galpon');
        $this->responder($this->ticket_erp($cliente))->assertOk()->assertJsonPath('operation.status', 'sent');

        $pedidos = $this->pedidos_a('/api/admin-sync/support/messages');
        $this->assertCount(1, $pedidos);
        $this->assertSame('https://api-ferreteria-el-galpon.comerciocity.com/api/admin-sync/support/messages', $pedidos[0]['url'],
            'La URL (y con ella el Host, el SNI y la verificación del certificado) sigue siendo la del nombre.');
        $this->assertSame(['api-ferreteria-el-galpon.comerciocity.com:443:76.13.171.147'], $pedidos[0]['options']['curl'][CURLOPT_RESOLVE]);
        $this->assertTrue($pedidos[0]['options']['verify'] ?? true, 'No se toca la verificación TLS (default de Guzzle: true).');
    }

    public function test_la_respuesta_de_martin_a_un_cliente_comun_no_lleva_la_opcion(): void
    {
        $cliente = $this->cliente('https://api-ferretotal.comerciocity.com');
        $this->responder($this->ticket_erp($cliente))->assertOk()->assertJsonPath('operation.status', 'sent');

        $pedidos = $this->pedidos_a('/api/admin-sync/support/messages');
        $this->assertCount(1, $pedidos);
        $this->assertArrayNotHasKey('curl', $pedidos[0]['options']);
    }

    public function test_el_puente_y_el_estado_de_la_prueba_tambien_la_llevan_por_el_host_del_pool(): void
    {
        $cliente = $this->cliente('https://api-prueba-97.comerciocity.com', 'ferreteria-el-galpon');

        app(ClientAdminSyncPuenteService::class)->reenviar($cliente, 'GET', 'prueba/estado');
        app(PruebaVencimientoService::class)->estado($cliente);

        $this->assertCount(2, $this->pedidos);
        foreach ($this->pedidos as $pedido) {
            $this->assertSame(['api-prueba-97.comerciocity.com:443:76.13.171.147'], $pedido['options']['curl'][CURLOPT_RESOLVE]);
        }
    }

    public function test_un_host_que_no_es_de_la_instancia_no_la_lleva_aunque_el_cliente_este_en_prueba(): void
    {
        // El cliente contrató y su API pasó a un dominio propio: ese host no es de la instancia.
        $cliente = $this->cliente('https://api.ferreteriapropia.com.ar', 'ferreteria-el-galpon', TrialInstance::ESTADO_CLIENTE);

        app(ClientAdminSyncPuenteService::class)->reenviar($cliente, 'GET', 'prueba/estado');

        $this->assertCount(1, $this->pedidos);
        $this->assertArrayNotHasKey('curl', $this->pedidos[0]['options']);
    }

    public function test_una_instancia_borrada_no_la_lleva(): void
    {
        $cliente = $this->cliente('https://api-ferreteria-el-galpon.comerciocity.com', 'ferreteria-el-galpon', TrialInstance::ESTADO_BORRADA);

        app(ClientAdminSyncPuenteService::class)->reenviar($cliente, 'GET', 'prueba/estado');

        $this->assertCount(1, $this->pedidos);
        $this->assertArrayNotHasKey('curl', $this->pedidos[0]['options']);
    }

    public function test_sin_ip_del_vps_configurada_es_el_comportamiento_de_siempre(): void
    {
        config(['services.hostinger.vps_ip' => '']);
        $cliente = $this->cliente('https://api-ferreteria-el-galpon.comerciocity.com', 'ferreteria-el-galpon');

        app(ClientAdminSyncPuenteService::class)->reenviar($cliente, 'GET', 'prueba/estado');

        $this->assertCount(1, $this->pedidos);
        $this->assertArrayNotHasKey('curl', $this->pedidos[0]['options']);
    }
}

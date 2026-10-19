<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Client;
use App\Models\ClientSerperUsage;
use App\Models\SharedProductFact;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\AsistenteWhatsapp\BaseDelCanal;

class FichasCompartidasYConsumoSerperTest extends BaseDelCanal
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertMatchesRegularExpression('/(?:^|_)test(?:ing)?(?:_|$)/', config('database.connections.' . config('database.default') . '.database'));
        // Los totales globales deben ser independientes de fixtures QA u otras corridas. La
        // transacción del test recupera exactamente esos datos al terminar cada método.
        ClientSerperUsage::query()->delete();
        SharedProductFact::query()->delete();
    }

    private function negocio(): Client
    {
        $client = $this->crear_cliente();
        $client->inbound_api_key = Str::random(40);
        $client->save();
        return $client;
    }

    private function headers(Client $client): array
    {
        return ['X-Admin-Api-Key' => $client->inbound_api_key];
    }

    private function ficha(): array
    {
        return ['name' => 'Producto de prueba 500 g', 'description' => 'Presentación verificada.', 'brand' => 'Marca',
            'weight_kg' => null, 'width_cm' => null, 'height_cm' => null, 'length_cm' => null,
            'net_content' => '500 g', 'sources' => [['url' => 'https://fabricante.com/producto', 'title' => 'Fabricante']]];
    }

    private function evento(array $changes = []): array
    {
        return array_merge(['event_uuid' => (string) Str::uuid(), 'occurred_at' => '2026-10-19T22:30:00-03:00',
            'auth_user_id' => 501, 'auth_user_name' => 'Ana', 'process' => 'product_facts', 'endpoint' => 'search',
            'query' => '7791234567898', 'ok' => true, 'http_status' => 200, 'credits' => 1, 'duration_ms' => 250], $changes);
    }

    private function login()
    {
        $admin = Admin::create(['name' => 'Admin Serper', 'email' => Str::random(15) . '@test.local', 'password' => bcrypt(Str::random(32))]);
        Sanctum::actingAs($admin);
    }

    public function test_la_clave_es_obligatoria_aun_con_integracion_legacy_desactivada()
    {
        config(['services.admin_inbound_integration.require_api_key' => false]);
        $client = $this->negocio();
        $this->getJson('/api/inbound/product-facts/7791234567898?client_uuid=' . $client->uuid)->assertUnauthorized();
        $this->postJson('/api/inbound/serper-usage', ['client_uuid' => $client->uuid, 'events' => [$this->evento()]])->assertUnauthorized();
        $this->getJson('/api/inbound/product-facts/7791234567898', ['X-Admin-Api-Key' => 'invalida'])->assertUnauthorized();
        $client->is_active = false;
        $client->save();
        $this->getJson('/api/inbound/product-facts/7791234567898', $this->headers($client))->assertUnauthorized();
    }

    public function test_dos_negocios_comparten_ficha_por_campos_y_el_lease_no_se_filtra()
    {
        $a = $this->negocio(); $b = $this->negocio();
        $this->getJson('/api/inbound/product-facts/036000291452', $this->headers($a))->assertOk()->assertJsonPath('status', 'miss');
        $claim = $this->postJson('/api/inbound/product-facts/036000291452/claim', [], $this->headers($a))->assertOk()->assertJsonPath('status', 'claimed')->json();
        $pending = $this->postJson('/api/inbound/product-facts/0036000291452/claim', [], $this->headers($b))->assertOk()->assertJsonPath('status', 'pending')->json();
        $this->assertArrayNotHasKey('lease_token', $pending);
        $this->assertArrayNotHasKey('client_id', $pending);
        $this->putJson('/api/inbound/product-facts/036000291452', ['lease_token' => $claim['lease_token'], 'status' => 'found', 'product' => $this->ficha()], $this->headers($b))->assertStatus(409);
        $this->putJson('/api/inbound/product-facts/036000291452', ['lease_token' => $claim['lease_token'], 'status' => 'found', 'product' => $this->ficha()], $this->headers($a))->assertOk()->assertJsonPath('status', 'found');
        $this->getJson('/api/inbound/product-facts/00036000291452', $this->headers($b))->assertOk()->assertJsonPath('product', $this->ficha());
        $this->assertSame(1, SharedProductFact::where('gtin', '00036000291452')->count());
        $this->putJson('/api/inbound/product-facts/036000291452', ['lease_token' => $claim['lease_token'], 'status' => 'not_found'], $this->headers($a))->assertStatus(409);
    }

    public function test_lease_vencido_se_reasigna_y_la_respuesta_vieja_no_pisa_la_ficha()
    {
        $a = $this->negocio(); $b = $this->negocio();
        $first = $this->postJson('/api/inbound/product-facts/7791234567898/claim', [], $this->headers($a))->json('lease_token');
        SharedProductFact::where('gtin', '07791234567898')->update(['lease_expires_at' => now()->subSecond()]);
        $next = $this->postJson('/api/inbound/product-facts/7791234567898/claim', [], $this->headers($b))->assertOk()->assertJsonPath('status', 'claimed')->json('lease_token');
        $this->putJson('/api/inbound/product-facts/7791234567898', ['lease_token' => $first, 'status' => 'not_found'], $this->headers($a))->assertStatus(409);
        $this->putJson('/api/inbound/product-facts/7791234567898', ['lease_token' => $next, 'status' => 'not_found'], $this->headers($b))->assertOk()->assertJsonPath('status', 'not_found');
        $this->postJson('/api/inbound/product-facts/7791234567898/claim', [], $this->headers($a))->assertOk()->assertJsonPath('status', 'not_found');
        SharedProductFact::where('gtin', '07791234567898')->update(['expires_at' => now()->subSecond()]);
        $retry = $this->postJson('/api/inbound/product-facts/7791234567898/claim', [], $this->headers($a))->assertJsonPath('status', 'claimed')->json('lease_token');
        $this->putJson('/api/inbound/product-facts/7791234567898', ['lease_token' => $retry, 'status' => 'failed'], $this->headers($a))->assertOk()->assertJsonPath('status', 'miss');
        $this->postJson('/api/inbound/product-facts/7791234567898/claim', [], $this->headers($b))->assertJsonPath('status', 'claimed');
    }

    public function test_rechaza_codigos_internos_checksum_incorrecto_y_campos_privados()
    {
        $client = $this->negocio();
        foreach (['7791234567890', '123', '2000000000008', '00000000'] as $code) {
            $this->postJson('/api/inbound/product-facts/' . $code . '/claim', [], $this->headers($client))->assertStatus(422);
        }
        $lease = $this->postJson('/api/inbound/product-facts/7791234567898/claim', [], $this->headers($client))->json('lease_token');
        foreach ([array_merge($this->ficha(), ['price' => 123]), array_merge($this->ficha(), ['sources' => []]),
            array_merge($this->ficha(), ['sources' => [['url' => 'http://127.0.0.1/secreto']]]),
            array_merge($this->ficha(), ['sources' => [['url' => 'http://servidor.local/secreto']]]),
            array_merge($this->ficha(), ['sources' => [['url' => 'https://user:clave@fabricante.com/producto']]]),
            array_merge($this->ficha(), ['weight_kg' => -1]),
            array_merge($this->ficha(), ['brand' => str_repeat('m', 191)]),
            array_merge($this->ficha(), ['net_content' => str_repeat('c', 191)])] as $bad) {
            $this->putJson('/api/inbound/product-facts/7791234567898', ['lease_token' => $lease, 'status' => 'found', 'product' => $bad], $this->headers($client))->assertStatus(422);
        }
        $this->assertNull(SharedProductFact::where('gtin', '07791234567898')->first()->product);
    }

    public function test_eventos_son_idempotentes_y_la_credencial_define_el_negocio()
    {
        $a = $this->negocio(); $b = $this->negocio(); $event = $this->evento();
        $this->postJson('/api/inbound/serper-usage', ['client_uuid' => $b->uuid, 'events' => [$event]], $this->headers($a))->assertOk()->assertJsonPath('accepted', 1);
        $this->postJson('/api/inbound/serper-usage', ['events' => [array_merge($event, ['credits' => 90])]], $this->headers($a))->assertOk()->assertJsonPath('accepted', 1);
        $this->postJson('/api/inbound/serper-usage', ['events' => [$event]], $this->headers($b))->assertOk();
        $this->assertSame(2, ClientSerperUsage::where('event_uuid', $event['event_uuid'])->count());
        $this->assertEquals(1, ClientSerperUsage::where('client_id', $a->id)->value('credits'));
        $this->postJson('/api/inbound/serper-usage', ['events' => [$this->evento(['secret' => 'no'])]], $this->headers($a))->assertStatus(422);
        $this->postJson('/api/inbound/serper-usage', ['events' => [$this->evento(['auth_user_name' => str_repeat('a', 191)])]], $this->headers($a))->assertStatus(422);
    }

    public function test_resumen_respeta_dia_argentino_usuario_proceso_y_creditos_desconocidos()
    {
        config(['app.timezone' => 'America/Argentina/Buenos_Aires']);
        date_default_timezone_set('America/Argentina/Buenos_Aires');
        $a = $this->negocio(); $b = $this->negocio();
        $this->postJson('/api/inbound/serper-usage', ['events' => [$this->evento(), $this->evento(['ok' => false, 'http_status' => null, 'credits' => null, 'endpoint' => 'images', 'process' => 'article_images', 'auth_user_id' => 502, 'auth_user_name' => 'Luis'])]], $this->headers($a))->assertOk();
        $this->postJson('/api/inbound/serper-usage', ['events' => [$this->evento(['credits' => 2, 'auth_user_id' => 501, 'auth_user_name' => 'Otra Ana'])]], $this->headers($b))->assertOk();
        $this->getJson('/api/admin/serper/resumen')->assertUnauthorized();
        $this->login();
        $path = '/api/admin/serper/resumen?desde=2026-10-19&hasta=2026-10-19';
        $data = $this->getJson($path)->assertOk()->assertJsonPath('totales.attempts', 3)->assertJsonPath('totales.unknown_credit_events', 1)->assertJsonPath('totales.failed', 1)->assertJsonPath('por_dia.0.date', '2026-10-19')->json();
        $this->assertEquals(3, $data['totales']['credits']);
        $this->assertCount(3, $data['por_usuario']);
        $this->assertSame('2026-10-20T01:30:00.000000Z', $data['events'][0]['occurred_at']);
        $this->getJson('/api/admin/client/' . $a->id . '/serper?desde=2026-10-19&hasta=2026-10-19&client_id=' . $b->id)->assertOk()->assertJsonPath('totales.attempts', 2);
        $this->getJson($path . '&client_id=' . $a->id . '&auth_user_id=502&process=article_images&result=unknown_credits')->assertOk()->assertJsonPath('totales.attempts', 1)->assertJsonPath('totales.credits', null);
        $this->getJson('/api/admin/serper/resumen?desde=2026-10-20&hasta=2026-10-20')->assertOk()->assertJsonPath('totales.attempts', 0);
        $this->getJson('/api/admin/serper/resumen?desde=2025-01-01&hasta=2026-10-20')->assertStatus(422);
    }

    public function test_un_negocio_sin_reportes_no_se_presenta_como_consumo_cero()
    {
        $client = $this->negocio(); $this->login();
        $this->getJson('/api/admin/client/' . $client->id . '/serper')->assertOk()->assertJsonPath('has_telemetry', false)->assertJsonPath('totales.credits', null)->assertJsonPath('cost_usd', null);
    }
}

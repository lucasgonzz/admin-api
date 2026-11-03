<?php

namespace Tests\Feature\Local;

use App\Models\Client;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as PedidoHttp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `local:sincronizar-logos` / `LogosService` (misión el-local, 3/11/2026): el logo que se dibuja como cabeza de
 * cada negocio en «El local».
 *
 * Se fija la cadena de fuentes (image_url de empresa → logo_url de la tienda → setup_data.logo_url), que la
 * llamada sea la misma que la del ecommerce (URL de admin-sync/branding y clave en X-Admin-Api-Key), y la regla
 * que más importa: si el sistema del cliente no contesta, el logo que ya estaba NO se borra.
 */
class LogosDelLocalTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Cliente con la API legacy en `api_url` (sin /public: el resolver la trata como VPS).
     *
     * @param array<string, mixed> $extra
     *
     * @return Client
     */
    private function negocio(array $extra = []): Client
    {
        $client            = new Client();
        $client->name      = 'Logos de prueba';
        $client->slug      = 'logos-' . Str::lower(Str::random(8));
        $client->phone     = '+5493417770' . random_int(100, 999);
        $client->is_active = true;
        $client->api_url   = 'https://api-' . $client->slug . '.test';
        $client->api_key   = 'clave-' . $client->slug;
        foreach ($extra as $campo => $valor) {
            $client->{$campo} = $valor;
        }
        $client->save();

        return $client;
    }

    /**
     * Las tres columnas del logo, leídas de la base.
     *
     * @param Client $client
     *
     * @return object
     */
    private function columnas(Client $client)
    {
        return DB::table('clients')->where('id', $client->id)->select('logo_url', 'logo_synced_at', 'logo_sync_status')->first();
    }

    /**
     * Con branding completo gana `image_url`, el pedido es el de admin-sync con la clave del cliente, y queda
     * `ok` con fecha de sincronización.
     *
     * @return void
     */
    public function test_gana_el_logo_del_sistema_y_el_pedido_es_el_de_admin_sync(): void
    {
        $client = $this->negocio();

        Http::fake([
            $client->api_url . '/api/admin-sync/branding' => Http::response(['branding' => [
                'image_url' => 'https://cdn.test/empresa.png',
                'logo_url'  => 'https://cdn.test/tienda.png',
            ]], 200),
        ]);

        $this->artisan('local:sincronizar-logos', ['--client' => (string) $client->id])->assertExitCode(0);

        Http::assertSent(function (PedidoHttp $pedido) use ($client) {
            return $pedido->url() === $client->api_url . '/api/admin-sync/branding'
                && $pedido->hasHeader('X-Admin-Api-Key', $client->api_key);
        });

        $columnas = $this->columnas($client);
        $this->assertSame('https://cdn.test/empresa.png', $columnas->logo_url);
        $this->assertSame('ok', $columnas->logo_sync_status);
        $this->assertNotNull($columnas->logo_synced_at);
    }

    /**
     * Sin `image_url` (o con una que no es URL absoluta) se usa el de la tienda; sin ninguno de los dos, el del
     * formulario de implementación; y sin nada, null con `sin_logo`.
     *
     * @return void
     */
    public function test_la_cadena_de_fuentes(): void
    {
        $de_tienda     = $this->negocio();
        $de_formulario = $this->negocio(['setup_data' => ['logo_url' => 'https://cdn.test/formulario.png']]);
        $sin_nada      = $this->negocio();

        Http::fake([
            $de_tienda->api_url . '/*'     => Http::response(['branding' => ['image_url' => 'logo.png', 'logo_url' => 'https://cdn.test/tienda.png']], 200),
            $de_formulario->api_url . '/*' => Http::response(['branding' => ['image_url' => '', 'logo_url' => null]], 200),
            $sin_nada->api_url . '/*'      => Http::response(['branding' => []], 200),
        ]);

        foreach ([$de_tienda, $de_formulario, $sin_nada] as $client) {
            $this->artisan('local:sincronizar-logos', ['--client' => $client->slug])->assertExitCode(0);
        }

        $this->assertSame('https://cdn.test/tienda.png', $this->columnas($de_tienda)->logo_url);
        $this->assertSame('https://cdn.test/formulario.png', $this->columnas($de_formulario)->logo_url);
        $this->assertNull($this->columnas($sin_nada)->logo_url);
        $this->assertSame('sin_logo', $this->columnas($sin_nada)->logo_sync_status);
    }

    /**
     * 🔴 Si el sistema del cliente falla (500, o sin clave para pedirle), el logo que ya estaba queda, el estado
     * dice qué pasó y la fecha de sincronización no se mueve.
     *
     * @return void
     */
    public function test_si_el_cliente_no_contesta_el_logo_que_estaba_no_se_borra(): void
    {
        $caido     = $this->negocio(['logo_url' => 'https://cdn.test/el-de-ayer.png']);
        $sin_clave = $this->negocio(['logo_url' => 'https://cdn.test/otro-de-ayer.png', 'api_key' => null]);

        Http::fake([
            $caido->api_url . '/*' => Http::response('error', 500),
            '*'                    => Http::response(['branding' => ['image_url' => 'https://cdn.test/no-deberia.png']], 200),
        ]);

        $this->artisan('local:sincronizar-logos', ['--client' => (string) $caido->id])->assertExitCode(0);
        $this->artisan('local:sincronizar-logos', ['--client' => (string) $sin_clave->id])->assertExitCode(0);

        $columnas = $this->columnas($caido);
        $this->assertSame('https://cdn.test/el-de-ayer.png', $columnas->logo_url);
        $this->assertSame('http_500', $columnas->logo_sync_status);
        $this->assertNull($columnas->logo_synced_at);

        $columnas = $this->columnas($sin_clave);
        $this->assertSame('https://cdn.test/otro-de-ayer.png', $columnas->logo_url);
        $this->assertSame('sin_api_key', $columnas->logo_sync_status);

        Http::assertNotSent(function (PedidoHttp $pedido) use ($sin_clave) {
            return strpos($pedido->url(), $sin_clave->api_url) === 0;
        });
    }
}

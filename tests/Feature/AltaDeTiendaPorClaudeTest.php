<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientEcommerce;
use App\Models\ClientEcommerceInstallation;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Los frenos del alta de tienda por Claude (`POST claude/ecommerce/stores`).
 *
 * Este endpoint escribe UNA fila y no toca ningún servidor, así que el riesgo no es el de sus
 * vecinos (que arrancan pipelines SSH). El riesgo acá es más callado y por eso tiene test: una fila
 * mal cargada no falla en el momento —falla después, adentro del pipeline o, peor, con la tienda
 * arriba— y para entonces nadie la relaciona con el alta. Lo que se protege, en orden:
 *
 *  1. 🔴 Que `api_url` nazca como `https://api.{domain}` y no con la convención vieja
 *     `{spa_url}/api` que usa el modal del panel cuando la dejan vacía. Esa diferencia no da ningún
 *     error: da una tienda que carga perfecto y devuelve 404 en todas las llamadas a la API.
 *  2. 🔴 Que el alta NO cree ninguna instalación. Registrar la tienda e instalarla son dos
 *     operaciones distintas y la regla de la clase —ninguna ruta claude/* instala desde cero— tiene
 *     que seguir valiendo después de agregar esta.
 *  3. Que todo freno que rechaza devuelva 422 y no escriba absolutamente nada.
 *  4. Que `dry_run` sea el default y que lo que simula sea exactamente lo que después escribe.
 *  5. Que los dominios de la plataforma no puedan ser el dominio de la tienda de un cliente.
 */
class AltaDeTiendaPorClaudeTest extends TestCase
{
    use DatabaseTransactions;

    /** Clave de ingesta usada en las requests del test. */
    const CLAVE = 'clave-de-prueba-claude-alta-tienda';

    /** @return void */
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.claude_task_ingest.key' => self::CLAVE]);
    }

    /* ==============================================================================================
     | El camino feliz
     |============================================================================================= */

    public function test_dry_run_es_el_default_y_no_escribe_nada(): void
    {
        $cliente = $this->crear_cliente('Doble Test Herrajes');
        $antes   = ClientEcommerce::query()->count();

        $respuesta = $this->postJson('/api/claude/ecommerce/stores', [
            'client_id'           => $cliente->id,
            'confirm_client_name' => 'Doble Test Herrajes',
            'domain'              => 'dobletestherrajes.com.ar',
        ], $this->headers());

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('dry_run', true);
        $respuesta->assertJsonPath('se_crearia.domain', 'dobletestherrajes.com.ar');
        $respuesta->assertJsonPath('se_crearia.spa_url', 'https://dobletestherrajes.com.ar');
        $respuesta->assertJsonPath('se_crearia.api_url', 'https://api.dobletestherrajes.com.ar');

        $this->assertSame(
            $antes,
            ClientEcommerce::query()->count(),
            'El dry_run escribió una tienda.'
        );
    }

    public function test_crea_la_tienda_con_la_api_en_su_propio_subdominio(): void
    {
        $cliente = $this->crear_cliente('Tienda Alta OK');

        $respuesta = $this->postJson('/api/claude/ecommerce/stores', [
            'client_id'           => $cliente->id,
            'confirm_client_name' => 'Tienda Alta OK',
            'domain'              => 'tiendaaltaok.com.ar',
            'dry_run'             => false,
        ], $this->headers());

        $respuesta->assertStatus(201);

        $tienda = ClientEcommerce::query()->where('client_id', $cliente->id)->first();
        $this->assertNotNull($tienda, 'No se creó la tienda.');
        $this->assertSame('tiendaaltaok.com.ar', $tienda->domain);
        $this->assertSame('https://tiendaaltaok.com.ar', $tienda->spa_url);

        /* 🔴 La aserción que justifica este archivo. Con la convención vieja esto valdría
           "https://tiendaaltaok.com.ar/api" y la tienda quedaría pidiéndole a una ruta que no
           existe, sin que nada avise. */
        $this->assertSame(
            'https://api.tiendaaltaok.com.ar',
            $tienda->api_url,
            'La API de una tienda nueva vive en su propio subdominio, no en {spa_url}/api.'
        );

        $this->assertSame('pending', $tienda->status);
        $this->assertNull($tienda->spa_path, 'Los paths se derivan del dominio: no se cargan a mano.');
        $this->assertNull($tienda->api_path, 'Los paths se derivan del dominio: no se cargan a mano.');
    }

    public function test_el_dry_run_dice_exactamente_lo_que_despues_escribe(): void
    {
        $cliente = $this->crear_cliente('Tienda Espejo');

        $cuerpo = [
            'client_id'           => $cliente->id,
            'confirm_client_name' => 'Tienda Espejo',
            'domain'              => 'tiendaespejo.com.ar',
        ];

        $simulacro = $this->postJson('/api/claude/ecommerce/stores', $cuerpo, $this->headers())
            ->assertStatus(200)
            ->json('se_crearia');

        $alta = $this->postJson('/api/claude/ecommerce/stores', $cuerpo + ['dry_run' => false], $this->headers())
            ->assertStatus(201);

        foreach (['domain', 'spa_url', 'api_url', 'status'] as $campo) {
            $this->assertSame(
                $simulacro[$campo],
                $alta->json($campo),
                "El dry_run prometió un {$campo} distinto del que escribió."
            );
        }
    }

    public function test_el_alta_no_crea_ninguna_instalacion(): void
    {
        $cliente = $this->crear_cliente('Tienda Sin Corrida');
        $antes   = ClientEcommerceInstallation::query()->count();

        $this->postJson('/api/claude/ecommerce/stores', [
            'client_id'           => $cliente->id,
            'confirm_client_name' => 'Tienda Sin Corrida',
            'domain'              => 'tiendasincorrida.com.ar',
            'dry_run'             => false,
        ], $this->headers())->assertStatus(201);

        $this->assertSame(
            $antes,
            ClientEcommerceInstallation::query()->count(),
            'Registrar una tienda creó una corrida del pipeline: son dos operaciones distintas.'
        );
    }

    public function test_respeta_las_urls_explicitas(): void
    {
        $cliente = $this->crear_cliente('Tienda Con URLs');

        $this->postJson('/api/claude/ecommerce/stores', [
            'client_id'           => $cliente->id,
            'confirm_client_name' => 'Tienda Con URLs',
            'domain'              => 'tiendaconurls.com.ar',
            'spa_url'             => 'https://tiendaconurls.com.ar/',
            'api_url'             => 'https://tiendaconurls.com.ar/api',
            'dry_run'             => false,
        ], $this->headers())->assertStatus(201);

        $tienda = ClientEcommerce::query()->where('client_id', $cliente->id)->first();
        $this->assertSame('https://tiendaconurls.com.ar', $tienda->spa_url, 'La barra final se normaliza.');
        $this->assertSame('https://tiendaconurls.com.ar/api', $tienda->api_url);
    }

    /* ==============================================================================================
     | Los frenos
     |============================================================================================= */

    public function test_el_nombre_tiene_que_confirmar_y_el_error_no_lo_revela(): void
    {
        $cliente = $this->crear_cliente('Nombre Verdadero');

        $respuesta = $this->postJson('/api/claude/ecommerce/stores', [
            'client_id'           => $cliente->id,
            'confirm_client_name' => 'Nombre Equivocado',
            'domain'              => 'nombreequivocado.com.ar',
            'dry_run'             => false,
        ], $this->headers());

        $respuesta->assertStatus(422);
        $this->assertStringNotContainsString(
            'Nombre Verdadero',
            $respuesta->getContent(),
            'El error del freno revela el nombre correcto: dejaría de ser un freno.'
        );
        $this->assertSame(0, ClientEcommerce::query()->where('client_id', $cliente->id)->count());
    }

    public function test_un_cliente_no_puede_tener_dos_tiendas(): void
    {
        $cliente = $this->crear_cliente('Tienda Repetida');
        $primera = $this->crear_tienda($cliente, 'tiendarepetida.com.ar');

        $respuesta = $this->postJson('/api/claude/ecommerce/stores', [
            'client_id'           => $cliente->id,
            'confirm_client_name' => 'Tienda Repetida',
            'domain'              => 'otrodominio.com.ar',
            'dry_run'             => false,
        ], $this->headers());

        $respuesta->assertStatus(422);
        $respuesta->assertJsonPath('client_ecommerce_id', $primera->id);
        $this->assertSame(1, ClientEcommerce::query()->where('client_id', $cliente->id)->count());
    }

    public function test_un_dominio_ya_cargado_no_se_puede_repetir(): void
    {
        $dueno = $this->crear_cliente('Dueño Del Dominio');
        $this->crear_tienda($dueno, 'dominiotomado.com.ar');

        $otro = $this->crear_cliente('Otro Cliente');

        $respuesta = $this->postJson('/api/claude/ecommerce/stores', [
            'client_id'           => $otro->id,
            'confirm_client_name' => 'Otro Cliente',
            'domain'              => 'dominiotomado.com.ar',
            'dry_run'             => false,
        ], $this->headers());

        $respuesta->assertStatus(422);
        $this->assertSame(0, ClientEcommerce::query()->where('client_id', $otro->id)->count());
    }

    /**
     * @dataProvider dominios_de_la_plataforma
     */
    public function test_los_dominios_de_comerciocity_no_entran(string $dominio): void
    {
        $cliente = $this->crear_cliente('Cliente Plataforma');

        $respuesta = $this->postJson('/api/claude/ecommerce/stores', [
            'client_id'           => $cliente->id,
            'confirm_client_name' => 'Cliente Plataforma',
            'domain'              => $dominio,
            'dry_run'             => false,
        ], $this->headers());

        $respuesta->assertStatus(422);
        $this->assertSame(
            0,
            ClientEcommerce::query()->where('client_id', $cliente->id)->count(),
            'Se cargó una tienda sobre una zona de la plataforma: ahí viven el ERP y las demos.'
        );
    }

    /** @return array<string, array<int, string>> */
    public function dominios_de_la_plataforma(): array
    {
        return [
            'la zona del ERP'        => ['comerciocity.com'],
            'un frente de cliente'   => ['doblep.comerciocity.com'],
            'la zona de las demos'   => ['comerciocity.store'],
            'una demo'               => ['demo3.comerciocity.store'],
        ];
    }

    /**
     * @dataProvider dominios_invalidos
     */
    public function test_el_dominio_va_pelado(string $dominio): void
    {
        $cliente = $this->crear_cliente('Cliente Dominio Raro');

        $this->postJson('/api/claude/ecommerce/stores', [
            'client_id'           => $cliente->id,
            'confirm_client_name' => 'Cliente Dominio Raro',
            'domain'              => $dominio,
            'dry_run'             => false,
        ], $this->headers())->assertStatus(422);

        $this->assertSame(0, ClientEcommerce::query()->where('client_id', $cliente->id)->count());
    }

    /** @return array<string, array<int, string>> */
    public function dominios_invalidos(): array
    {
        return [
            'con esquema'     => ['https://mitienda.com.ar'],
            'con barra final' => ['mitienda.com.ar/'],
            'con path'        => ['mitienda.com.ar/tienda'],
            'sin punto'       => ['mitienda'],
            'con espacio'     => ['mi tienda.com.ar'],
        ];
    }

    public function test_un_parametro_de_mas_frena_el_alta(): void
    {
        $cliente = $this->crear_cliente('Cliente Parametro Extra');

        /* `spa_path` es justamente el que alguien intentaría mandar: existe en la tabla y en el
           modal. Acá no entra, y el 422 lo dice en vez de ignorarlo en silencio. */
        $respuesta = $this->postJson('/api/claude/ecommerce/stores', [
            'client_id'           => $cliente->id,
            'confirm_client_name' => 'Cliente Parametro Extra',
            'domain'              => 'parametroextra.com.ar',
            'spa_path'            => 'parametroextra.com.ar/public_html',
            'dry_run'             => false,
        ], $this->headers());

        $respuesta->assertStatus(422);
        $this->assertSame(0, ClientEcommerce::query()->where('client_id', $cliente->id)->count());
    }

    public function test_un_cliente_que_no_existe_da_404(): void
    {
        $this->postJson('/api/claude/ecommerce/stores', [
            'client_id'           => 999999999,
            'confirm_client_name' => 'Cualquiera',
            'domain'              => 'cualquiera.com.ar',
            'dry_run'             => false,
        ], $this->headers())->assertStatus(404);
    }

    public function test_sin_clave_de_ingesta_no_entra(): void
    {
        $cliente = $this->crear_cliente('Cliente Sin Clave');

        $this->postJson('/api/claude/ecommerce/stores', [
            'client_id'           => $cliente->id,
            'confirm_client_name' => 'Cliente Sin Clave',
            'domain'              => 'sinclave.com.ar',
            'dry_run'             => false,
        ], ['Accept' => 'application/json'])->assertStatus(401);

        $this->assertSame(0, ClientEcommerce::query()->where('client_id', $cliente->id)->count());
    }

    /* ==============================================================================================
     | Helpers
     |============================================================================================= */

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'X-Claude-Task-Key' => self::CLAVE,
            'Accept'            => 'application/json',
        ];
    }

    private function crear_cliente(string $nombre): Client
    {
        $client                  = new Client();
        $client->name            = $nombre;
        $client->company_name    = 'Empresa ' . $nombre;
        $client->slug            = Str::slug($nombre) . '-' . Str::random(8);
        $client->api_url         = 'https://ejemplo.test';
        $client->api_key         = 'clave-api';
        $client->inbound_api_key = 'clave-inbound';
        $client->is_active       = true;
        $client->save();

        return $client;
    }

    private function crear_tienda(Client $client, string $dominio): ClientEcommerce
    {
        $tienda            = new ClientEcommerce();
        $tienda->client_id = $client->id;
        $tienda->domain    = $dominio;
        $tienda->spa_url   = 'https://' . $dominio;
        $tienda->api_url   = 'https://api.' . $dominio;
        $tienda->status    = 'active';
        $tienda->save();

        return $tienda;
    }
}

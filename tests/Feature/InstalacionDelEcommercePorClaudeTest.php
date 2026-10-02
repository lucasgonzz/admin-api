<?php

namespace Tests\Feature;

use App\Jobs\RunEcommerceInstallationJob;
use App\Models\Client;
use App\Models\ClientApi;
use App\Models\ClientEcommerce;
use App\Models\ClientEcommerceInstallation;
use App\Models\ClientSshCredential;
use App\Models\EcommerceVersion;
use App\Models\EnvTemplate;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * La instalación inicial de una tienda por API: `POST claude/ecommerce/installs` (decisión de Lucas,
 * 2/10/2026, misión cruzada `versiones-tienda`).
 *
 * Es la única ruta `claude/*` que crea una corrida `mode = install` (la regla la fija
 * `ActualizacionDelEcommercePorClaudeTest::test_la_unica_instalacion_inicial_por_claude_es_installs_con_sus_frenos`).
 * Acá se prueban sus frenos, uno por uno, y que ninguno escriba nada cuando rechaza:
 *  1. 🔴 `dry_run` por defecto: sin `dry_run=false` no se crea nada.
 *  2. `confirm_client_name` equivocado: 422 sin crear nada y sin revelar el nombre.
 *  3. Tienda `active` (ya instalada) o `installing`: 422.
 *  4. Sin versión publicada: 422 directo, sin crear una corrida que después falle.
 *  5. Las precondiciones del panel (API de empresa de donde sale el .env) y la lista blanca.
 *  6. El caso feliz: UNA corrida `install`, con la versión, encolada en la conexión `database`.
 */
class InstalacionDelEcommercePorClaudeTest extends TestCase
{
    use DatabaseTransactions;

    /** Clave de ingesta de las requests del test. */
    const CLAVE = 'clave-de-prueba-claude-installs';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.claude_task_ingest.key' => self::CLAVE]);

        EcommerceVersion::query()->delete();

        ClientSshCredential::query()->delete();
        foreach (['vps', 'shared_hosting'] as $tipo) {
            $credencial           = new ClientSshCredential();
            $credencial->type     = $tipo;
            $credencial->host     = $tipo . '.ejemplo.test';
            $credencial->port     = 22;
            $credencial->username = 'deploy';
            $credencial->password = 'secreta';
            $credencial->save();
        }

        if (! EnvTemplate::where('scope', 'tienda')->exists()) {
            EnvTemplate::create(['key' => 'APP_ENV', 'value' => 'production', 'scope' => 'tienda']);
        }
    }

    /* ------------------------------------------------------------------------------------------
     | Armado
     |----------------------------------------------------------------------------------------- */

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return ['X-Claude-Task-Key' => self::CLAVE, 'Accept' => 'application/json'];
    }

    /**
     * Cuerpo de la respuesta con los acentos y las barras sin escapar (ver
     * ActualizacionDelEcommercePorClaudeTest): sin JSON_UNESCAPED_SLASHES, "claude/ecommerce/updates"
     * viaja como "claude\/ecommerce\/updates" y la comparación mide el escapado, no el mensaje.
     *
     * @param \Illuminate\Testing\TestResponse $respuesta
     *
     * @return string
     */
    private function cuerpo($respuesta): string
    {
        return (string) json_encode($respuesta->json(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Cliente con su API de empresa activa y su tienda registrada (por defecto, sin instalar).
     *
     * @param string $nombre
     * @param string $status_tienda pending | active | installing.
     * @param bool   $con_api       False deja al cliente sin API de empresa activa.
     *
     * @return array{cliente: Client, tienda: ClientEcommerce}
     */
    private function escenario(string $nombre, string $status_tienda = 'pending', bool $con_api = true): array
    {
        $client                  = new Client();
        $client->name            = $nombre;
        $client->company_name    = 'Empresa ' . $nombre;
        $client->slug            = Str::slug($nombre) . '-' . Str::random(8);
        $client->api_url         = 'https://ejemplo.test';
        $client->api_key         = 'clave-api';
        $client->inbound_api_key = 'clave-inbound';
        $client->is_active       = true;
        $client->user_id         = 4200;
        $client->save();

        if ($con_api) {
            $api               = new ClientApi();
            $api->client_id    = $client->id;
            $api->url          = 'https://api-' . Str::slug($nombre) . '.test';
            $api->path         = 'instalar/' . Str::random(6);
            $api->hosting_type = 'shared_hosting';
            $api->save();

            $client->active_client_api_id = $api->id;
            $client->save();
        }

        $dominio           = Str::slug($nombre) . '-' . Str::random(6) . '.com.ar';
        $tienda            = new ClientEcommerce();
        $tienda->client_id = $client->id;
        $tienda->domain    = $dominio;
        $tienda->spa_url   = 'https://' . $dominio;
        $tienda->api_url   = 'https://api.' . $dominio;
        $tienda->status    = $status_tienda;
        $tienda->save();

        return ['cliente' => $client->fresh(), 'tienda' => $tienda];
    }

    /**
     * Versión de ecommerce.
     *
     * @param string $version
     * @param string $status
     *
     * @return EcommerceVersion
     */
    private function version(string $version, string $status = 'published'): EcommerceVersion
    {
        return EcommerceVersion::create([
            'version'      => $version,
            'status'       => $status,
            'published_at' => $status === 'published' ? now() : null,
        ]);
    }

    /**
     * Corridas de una tienda.
     *
     * @param ClientEcommerce $tienda
     *
     * @return int
     */
    private function corridas_de(ClientEcommerce $tienda): int
    {
        return ClientEcommerceInstallation::where('client_ecommerce_id', $tienda->id)->count();
    }

    /* ------------------------------------------------------------------------------------------
     | Los frenos
     |----------------------------------------------------------------------------------------- */

    /** Sin la clave, 401. */
    public function test_sin_clave_devuelve_401(): void
    {
        $this->postJson('/api/claude/ecommerce/installs', ['client_id' => 1])->assertStatus(401);
    }

    /**
     * 🔴 dry_run por defecto: devuelve lo que haría (cliente, dominio, paths resueltos, versión y
     * precondiciones) y no crea nada.
     */
    public function test_dry_run_por_defecto_no_crea_nada_y_dice_lo_que_haria(): void
    {
        Queue::fake();
        $this->version('1.0.0');
        $ultima = $this->version('1.1.0');
        $e      = $this->escenario('Vivero Simulado');

        $respuesta = $this->postJson('/api/claude/ecommerce/installs', [
            'client_id'           => $e['cliente']->id,
            'confirm_client_name' => 'Vivero Simulado',
        ], $this->headers());

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('dry_run', true);
        $respuesta->assertJsonPath('client_ecommerce_id', (int) $e['tienda']->id);
        $respuesta->assertJsonPath('domain', $e['tienda']->resolve_domain());
        $respuesta->assertJsonPath('paths.spa', $e['tienda']->resolve_spa_path());
        $respuesta->assertJsonPath('paths.api', $e['tienda']->resolve_api_path());
        $respuesta->assertJsonPath('ecommerce_version.id', (int) $ultima->id);
        $respuesta->assertJsonPath('se_crearia.mode', 'install');
        $this->assertNotEmpty($respuesta->json('precondiciones'));

        $this->assertSame(0, $this->corridas_de($e['tienda']));
        Queue::assertNothingPushed();
    }

    /** confirm_client_name equivocado: 422, no crea nada y no revela el nombre. */
    public function test_el_nombre_equivocado_no_crea_nada_ni_revela_el_nombre(): void
    {
        Queue::fake();
        $this->version('1.0.0');
        $e = $this->escenario('Pinturería Secreta');

        $respuesta = $this->postJson('/api/claude/ecommerce/installs', [
            'client_id'           => $e['cliente']->id,
            'confirm_client_name' => 'Otro Negocio',
            'dry_run'             => false,
        ], $this->headers());

        $respuesta->assertStatus(422);
        $this->assertStringNotContainsString('Pinturería Secreta', $this->cuerpo($respuesta));
        $this->assertSame(0, $this->corridas_de($e['tienda']));
        Queue::assertNothingPushed();
    }

    /** Una tienda ya instalada (active) no se reinstala: 422 que manda a updates. */
    public function test_una_tienda_activa_no_se_reinstala(): void
    {
        Queue::fake();
        $this->version('1.0.0');
        $e = $this->escenario('Tienda Andando', 'active');

        $respuesta = $this->postJson('/api/claude/ecommerce/installs', [
            'client_id'           => $e['cliente']->id,
            'confirm_client_name' => 'Tienda Andando',
            'dry_run'             => false,
        ], $this->headers());

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('ya está instalada', $this->cuerpo($respuesta));
        $this->assertStringContainsString('POST claude/ecommerce/updates', $this->cuerpo($respuesta));
        $this->assertSame(0, $this->corridas_de($e['tienda']));
        Queue::assertNothingPushed();
    }

    /** Una tienda en installing (otra corrida en curso): 422. */
    public function test_una_tienda_en_installing_no_se_instala(): void
    {
        Queue::fake();
        $this->version('1.0.0');
        $e = $this->escenario('Tienda En Curso', 'installing');

        $respuesta = $this->postJson('/api/claude/ecommerce/installs', [
            'client_id'           => $e['cliente']->id,
            'confirm_client_name' => 'Tienda En Curso',
            'dry_run'             => false,
        ], $this->headers());

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('installing', $this->cuerpo($respuesta));
        $this->assertSame(0, $this->corridas_de($e['tienda']));
    }

    /** Una corrida pendiente para esa tienda (aunque siga en pending) frena igual. */
    public function test_con_una_corrida_pendiente_no_se_instala(): void
    {
        Queue::fake();
        $this->version('1.0.0');
        $e = $this->escenario('Tienda Pendiente');
        ClientEcommerceInstallation::create([
            'client_ecommerce_id' => $e['tienda']->id,
            'mode'                => 'install',
            'status'              => 'pendiente',
        ]);

        $respuesta = $this->postJson('/api/claude/ecommerce/installs', [
            'client_id'           => $e['cliente']->id,
            'confirm_client_name' => 'Tienda Pendiente',
            'dry_run'             => false,
        ], $this->headers());

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('ya hay una corrida en curso', $this->cuerpo($respuesta));
        $this->assertSame(1, $this->corridas_de($e['tienda']));
    }

    /** 🔴 Sin ninguna versión publicada: 422 directo, sin crear la corrida. */
    public function test_sin_version_publicada_no_crea_nada(): void
    {
        Queue::fake();
        $this->version('2.0.0', 'draft');
        $e = $this->escenario('Tienda Sin Versión');

        $respuesta = $this->postJson('/api/claude/ecommerce/installs', [
            'client_id'           => $e['cliente']->id,
            'confirm_client_name' => 'Tienda Sin Versión',
            'dry_run'             => false,
        ], $this->headers());

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('No hay ninguna versión de ecommerce publicada', $this->cuerpo($respuesta));
        $this->assertStringContainsString('POST claude/ecommerce/versions', $this->cuerpo($respuesta));
        $this->assertSame(0, $this->corridas_de($e['tienda']));
        Queue::assertNothingPushed();
    }

    /** Una versión pedida que no está publicada: 422 y nada creado. */
    public function test_una_version_pedida_no_publicada_no_crea_nada(): void
    {
        Queue::fake();
        $this->version('1.0.0');
        $borrador = $this->version('2.0.0', 'draft');
        $e        = $this->escenario('Tienda Versión Borrador');

        $this->postJson('/api/claude/ecommerce/installs', [
            'client_id'            => $e['cliente']->id,
            'confirm_client_name'  => 'Tienda Versión Borrador',
            'ecommerce_version_id' => $borrador->id,
            'dry_run'              => false,
        ], $this->headers())->assertStatus(422);

        $this->assertSame(0, $this->corridas_de($e['tienda']));
    }

    /** Sin tienda registrada, sin API de empresa activa o con un parámetro de más: 422 y nada creado. */
    public function test_las_demas_precondiciones_no_crean_nada(): void
    {
        Queue::fake();
        $this->version('1.0.0');

        $sin_api = $this->escenario('Tienda Sin Api', 'pending', false);
        $respuesta = $this->postJson('/api/claude/ecommerce/installs', [
            'client_id'           => $sin_api['cliente']->id,
            'confirm_client_name' => 'Tienda Sin Api',
            'dry_run'             => false,
        ], $this->headers());
        $respuesta->assertStatus(422);
        $this->assertStringContainsString('API activa', $this->cuerpo($respuesta));
        $this->assertSame(0, $this->corridas_de($sin_api['tienda']));

        $sin_tienda                  = new Client();
        $sin_tienda->name            = 'Cliente Sin Tienda Registrada';
        $sin_tienda->slug            = 'sin-tienda-' . Str::random(8);
        $sin_tienda->api_url         = 'https://ejemplo.test';
        $sin_tienda->api_key         = 'clave-api';
        $sin_tienda->inbound_api_key = 'clave-inbound';
        $sin_tienda->is_active       = true;
        $sin_tienda->save();
        $respuesta = $this->postJson('/api/claude/ecommerce/installs', [
            'client_id'           => $sin_tienda->id,
            'confirm_client_name' => 'Cliente Sin Tienda Registrada',
        ], $this->headers());
        $respuesta->assertStatus(422);
        $this->assertStringContainsString('POST claude/ecommerce/stores', $this->cuerpo($respuesta));

        $e = $this->escenario('Tienda Parametro De Mas');
        $this->postJson('/api/claude/ecommerce/installs', [
            'client_id'           => $e['cliente']->id,
            'confirm_client_name' => 'Tienda Parametro De Mas',
            'dry_run'             => false,
            'api_path'            => 'otro/lado',
        ], $this->headers())->assertStatus(422);
        $this->assertSame(0, $this->corridas_de($e['tienda']));

        Queue::assertNothingPushed();
    }

    /* ------------------------------------------------------------------------------------------
     | El caso feliz
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 Con dry_run=false: UNA corrida `install`, marcada como de Claude, con la última publicada
     * (no pedida), y el job encolado en la conexión `database` (el `return` del closure NO es
     * decorativo: QueueFake::connection() no mira el nombre).
     */
    public function test_la_instalacion_crea_una_sola_corrida_install_y_la_encola_en_database(): void
    {
        Queue::fake();
        $ultima = $this->version('1.0.0');
        $e      = $this->escenario('Ferretería Nueva');

        $respuesta = $this->postJson('/api/claude/ecommerce/installs', [
            'client_id'           => $e['cliente']->id,
            'confirm_client_name' => 'Ferretería Nueva',
            'dry_run'             => false,
        ], $this->headers());

        $respuesta->assertStatus(202);
        $respuesta->assertJsonPath('dry_run', false);
        $respuesta->assertJsonPath('mode', 'install');
        $respuesta->assertJsonPath('status', 'pendiente');
        $respuesta->assertJsonPath('created_via', 'claude');
        $respuesta->assertJsonPath('conexion_de_cola', 'database');
        $respuesta->assertJsonPath('ecommerce_version.id', (int) $ultima->id);

        $corridas = ClientEcommerceInstallation::where('client_ecommerce_id', $e['tienda']->id)->get();
        $this->assertCount(1, $corridas);
        $this->assertSame('install', $corridas[0]->mode);
        $this->assertSame('pendiente', $corridas[0]->status);
        $this->assertSame('claude', $corridas[0]->created_via);
        $this->assertSame((int) $ultima->id, (int) $corridas[0]->ecommerce_version_id);
        $this->assertFalse((bool) $corridas[0]->ecommerce_version_requested, 'La última publicada no es una versión pedida.');

        Queue::assertPushed(RunEcommerceInstallationJob::class, 1);
        Queue::assertPushed(RunEcommerceInstallationJob::class, function ($job) {
            return $job->connection === 'database';
        });
    }

    /** Con una versión pedida, la corrida la guarda y la marca como pedida. */
    public function test_la_instalacion_con_version_pedida_la_guarda_como_pedida(): void
    {
        Queue::fake();
        $pedida = $this->version('1.0.0');
        $this->version('1.1.0');
        $e = $this->escenario('Librería Versión Pedida');

        $this->postJson('/api/claude/ecommerce/installs', [
            'client_id'           => $e['cliente']->id,
            'confirm_client_name' => 'Librería Versión Pedida',
            'version'             => '1.0.0',
            'dry_run'             => false,
        ], $this->headers())->assertStatus(202)->assertJsonPath('ecommerce_version.version', '1.0.0');

        $corrida = ClientEcommerceInstallation::where('client_ecommerce_id', $e['tienda']->id)->first();
        $this->assertSame((int) $pedida->id, (int) $corrida->ecommerce_version_id);
        $this->assertTrue((bool) $corrida->ecommerce_version_requested);
    }
}

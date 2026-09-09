<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientVersionUpgrade;
use App\Models\Version;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `PATCH claude/versions/{id}` (edición completa) y `DELETE claude/versions/{id}` (borrado con
 * frenos). Misión "editar-eliminar-versiones", 9/9/2026.
 *
 * Lo que estos tests protegen, en orden de importancia:
 *
 *  1. 🔴 El borrado hereda las cascadas REALES de la base (`client_version_upgrades.to_version_id`
 *     ON DELETE CASCADE, `from_version_id` y `clients.current_version_id` ON DELETE SET NULL) y
 *     los dos frenos —`confirm_version_code` siempre, `confirm_borra_historial` sólo cuando hay
 *     upgrades apuntando a esta versión como destino— tienen que impedir un borrado accidental
 *     sin impedir uno real.
 *  2. La misma excepción del panel humano en la edición: un código IDÉNTICO al ya persistido no
 *     exige el regex, para no bloquear la edición de una versión legacy con formato viejo.
 *  3. `is_hotfix` se recalcula en la edición SOLO cuando el código cambia de verdad, y nunca por
 *     override explícito (a diferencia del panel humano, que sí lo permite).
 */
class EditarYEliminarVersionPorClaudeTest extends TestCase
{
    use DatabaseTransactions;

    /** Clave de ingesta usada en las requests del test. */
    const CLAVE = 'clave-de-prueba-claude-editar-eliminar-versiones';

    /**
     * Setea la clave de ingesta: en el .env del slot está vacía y el middleware es fail-closed,
     * así que sin esto todo devolvería 401 y los tests medirían el middleware, no el endpoint.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.claude_task_ingest.key' => self::CLAVE]);
    }

    /**
     * Headers con la clave de ingesta.
     *
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'X-Claude-Task-Key' => self::CLAVE,
            'Accept'            => 'application/json',
        ];
    }

    /**
     * Código de versión válido y único (3 componentes numéricos, sin letras: el regex de
     * `VersionNumberComparator` no las acepta).
     *
     * @return string
     */
    private function codigo_unico(): string
    {
        return '9.' . random_int(10000, 99999) . '.' . random_int(0, 999);
    }

    /**
     * Código de versión de hotfix (4 componentes) válido y único.
     *
     * @return string
     */
    private function codigo_hotfix_unico(): string
    {
        return $this->codigo_unico() . '.' . random_int(0, 99);
    }

    /**
     * Versión de catálogo con código y estado dados.
     *
     * @param string      $codigo Código de versión.
     * @param string      $status draft | published | archived.
     * @param bool        $is_hotfix
     *
     * @return Version
     */
    private function crear_version(string $codigo, string $status = 'draft', bool $is_hotfix = false): Version
    {
        return Version::create([
            'version'   => $codigo,
            'status'    => $status,
            'is_hotfix' => $is_hotfix,
        ]);
    }

    /**
     * Cliente mínimo, opcionalmente con una versión actual.
     *
     * @param int|null $version_id Versión actual del cliente.
     *
     * @return Client
     */
    private function crear_cliente($version_id = null): Client
    {
        $client                     = new Client();
        $client->name               = 'Cliente ' . Str::random(8);
        $client->slug               = 'cliente-' . Str::random(10);
        $client->api_url            = 'https://ejemplo.test';
        $client->api_key            = 'clave-api';
        $client->inbound_api_key    = 'clave-inbound';
        $client->is_active          = true;
        $client->current_version_id = $version_id;
        $client->save();

        return $client;
    }

    /**
     * Upgrade de un cliente hacia (o desde) una versión.
     *
     * @param Client               $client    Cliente dueño.
     * @param array<string, mixed> $atributos Atributos (client_id se agrega solo).
     *
     * @return ClientVersionUpgrade
     */
    private function crear_upgrade(Client $client, array $atributos): ClientVersionUpgrade
    {
        return ClientVersionUpgrade::create(array_merge([
            'client_id'      => $client->id,
            'status'         => 'pendiente',
            'scheduled_date' => now()->toDateString(),
        ], $atributos));
    }

    // ------------------------------------------------------------------------------------------
    // PATCH claude/versions/{id} — edición
    // ------------------------------------------------------------------------------------------

    public function test_edicion_cambia_title_y_description_sin_tocar_version()
    {
        $version = $this->crear_version($this->codigo_unico());

        $response = $this->patchJson('/api/claude/versions/' . $version->id, [
            'title'       => 'Título nuevo',
            'description' => 'Descripción nueva',
        ], $this->headers());

        $response->assertStatus(200);
        $response->assertJsonPath('model.title', 'Título nuevo');
        $response->assertJsonPath('model.description', 'Descripción nueva');
        $response->assertJsonPath('model.version', $version->version);

        $this->assertDatabaseHas('versions', [
            'id'          => $version->id,
            'version'     => $version->version,
            'title'       => 'Título nuevo',
            'description' => 'Descripción nueva',
        ]);
    }

    public function test_edicion_cambia_version_a_codigo_nuevo_valido_y_recalcula_is_hotfix()
    {
        $version = $this->crear_version($this->codigo_unico(), 'draft', false);
        $codigo_hotfix = $this->codigo_hotfix_unico();

        $response = $this->patchJson('/api/claude/versions/' . $version->id, [
            'version' => $codigo_hotfix,
        ], $this->headers());

        $response->assertStatus(200);
        $response->assertJsonPath('model.version', $codigo_hotfix);
        $response->assertJsonPath('model.is_hotfix', true);

        $this->assertDatabaseHas('versions', [
            'id'        => $version->id,
            'version'   => $codigo_hotfix,
            'is_hotfix' => true,
        ]);
    }

    public function test_edicion_con_codigo_sin_cambios_no_exige_regex()
    {
        // Versión legacy: 2 componentes, no cumple VersionNumberComparator::VALID_REGEX.
        $version = $this->crear_version('3.3');

        $response = $this->patchJson('/api/claude/versions/' . $version->id, [
            'version' => '3.3',
            'title'   => 'Legacy editable',
        ], $this->headers());

        $response->assertStatus(200);
        $response->assertJsonPath('model.version', '3.3');
        $response->assertJsonPath('model.title', 'Legacy editable');

        $this->assertDatabaseHas('versions', [
            'id'      => $version->id,
            'version' => '3.3',
            'title'   => 'Legacy editable',
        ]);
    }

    public function test_edicion_rechaza_codigo_duplicado()
    {
        $version_a = $this->crear_version($this->codigo_unico());
        $version_b = $this->crear_version($this->codigo_unico());

        $response = $this->patchJson('/api/claude/versions/' . $version_a->id, [
            'version' => $version_b->version,
        ], $this->headers());

        $response->assertStatus(422);

        $this->assertDatabaseHas('versions', [
            'id'      => $version_a->id,
            'version' => $version_a->version,
        ]);
    }

    public function test_edicion_rechaza_codigo_con_formato_invalido()
    {
        $version = $this->crear_version($this->codigo_unico());

        $response = $this->patchJson('/api/claude/versions/' . $version->id, [
            'version' => '4.0',
        ], $this->headers());

        $response->assertStatus(422);

        $this->assertDatabaseHas('versions', [
            'id'      => $version->id,
            'version' => $version->version,
        ]);
    }

    public function test_edicion_a_published_sin_published_at_previo_lo_setea()
    {
        $version = $this->crear_version($this->codigo_unico(), 'draft');
        $this->assertNull($version->published_at);

        $response = $this->patchJson('/api/claude/versions/' . $version->id, [
            'status' => 'published',
        ], $this->headers());

        $response->assertStatus(200);
        $response->assertJsonPath('model.status', 'published');
        $this->assertNotNull($response->json('model.published_at'));
    }

    public function test_edicion_a_otra_transicion_no_pisa_published_at_existente()
    {
        $fecha_original = now()->subDays(20);
        $version        = Version::create([
            'version'      => $this->codigo_unico(),
            'status'       => 'published',
            'is_hotfix'    => false,
            'published_at' => $fecha_original,
        ]);

        $response = $this->patchJson('/api/claude/versions/' . $version->id, [
            'status' => 'archived',
        ], $this->headers());

        $response->assertStatus(200);
        $response->assertJsonPath('model.status', 'archived');
        $this->assertSame(
            $fecha_original->toIso8601String(),
            $response->json('model.published_at')
        );
    }

    public function test_edicion_sin_ningun_campo_da_422()
    {
        $version = $this->crear_version($this->codigo_unico());

        $response = $this->patchJson('/api/claude/versions/' . $version->id, [], $this->headers());

        $response->assertStatus(422);
    }

    public function test_edicion_404_si_la_version_no_existe()
    {
        $response = $this->patchJson('/api/claude/versions/999999999', [
            'title' => 'Lo que sea',
        ], $this->headers());

        $response->assertStatus(404);
    }

    public function test_edicion_resuelve_por_uuid()
    {
        $version = $this->crear_version($this->codigo_unico());

        $response = $this->patchJson('/api/claude/versions/' . $version->uuid, [
            'title' => 'Editado por uuid',
        ], $this->headers());

        $response->assertStatus(200);
        $response->assertJsonPath('model.id', $version->id);
        $response->assertJsonPath('model.title', 'Editado por uuid');
    }

    public function test_edicion_sin_header_da_401()
    {
        $version = $this->crear_version($this->codigo_unico());

        $response = $this->patchJson('/api/claude/versions/' . $version->id, [
            'title' => 'Lo que sea',
        ], ['Accept' => 'application/json']);

        $response->assertStatus(401);
    }

    // ------------------------------------------------------------------------------------------
    // DELETE claude/versions/{id} — borrado
    // ------------------------------------------------------------------------------------------

    public function test_borrado_dry_run_por_defecto_no_borra_y_reporta_el_impacto_correcto()
    {
        $version_destino = $this->crear_version($this->codigo_unico(), 'published');
        $version_otra    = $this->crear_version($this->codigo_unico(), 'published');

        $cliente_actual_1 = $this->crear_cliente($version_destino->id);
        $cliente_actual_2 = $this->crear_cliente($version_destino->id);

        // Upgrade hacia la versión (se borraría en cascada).
        $this->crear_upgrade($cliente_actual_1, ['to_version_id' => $version_destino->id]);
        // Upgrade desde la versión (quedaría con from_version_id null).
        $this->crear_upgrade($cliente_actual_2, [
            'from_version_id' => $version_destino->id,
            'to_version_id'   => $version_otra->id,
        ]);

        DB::table('version_notifications')->insert([
            'uuid' => (string) Str::uuid(), 'version_id' => $version_destino->id,
            'title' => 'N1', 'body' => 'body', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('version_seeders')->insert([
            'uuid' => (string) Str::uuid(), 'version_id' => $version_destino->id,
            'seeder_class' => 'Xxx', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('version_commands')->insert([
            'uuid' => (string) Str::uuid(), 'version_id' => $version_destino->id,
            'command' => 'php artisan algo', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('version_manual_tasks')->insert([
            'uuid' => (string) Str::uuid(), 'version_id' => $version_destino->id,
            'title' => 'T1', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Sin mandar dry_run: default true.
        $response = $this->deleteJson('/api/claude/versions/' . $version_destino->id, [], $this->headers());

        $response->assertStatus(200);
        $response->assertJsonPath('dry_run', true);
        $response->assertJsonPath('borro', false);
        $response->assertJsonPath('impacto.clientes_con_esta_como_actual', 2);
        $response->assertJsonPath('impacto.upgrades_hacia_esta_version', 1);
        $response->assertJsonPath('impacto.upgrades_desde_esta_version', 1);
        $response->assertJsonPath('impacto.notifications', 1);
        $response->assertJsonPath('impacto.seeders', 1);
        $response->assertJsonPath('impacto.commands', 1);
        $response->assertJsonPath('impacto.manual_tasks', 1);

        $this->assertDatabaseHas('versions', ['id' => $version_destino->id]);

        // Con dry_run=true explícito, mismo resultado.
        $response_explicito = $this->deleteJson('/api/claude/versions/' . $version_destino->id, [
            'dry_run' => true,
        ], $this->headers());

        $response_explicito->assertStatus(200);
        $response_explicito->assertJsonPath('dry_run', true);
        $response_explicito->assertJsonPath('borro', false);
        $this->assertDatabaseHas('versions', ['id' => $version_destino->id]);
    }

    public function test_borrado_confirm_version_code_ausente_con_dry_run_false_rechaza_sin_escribir_nada()
    {
        $version = $this->crear_version($this->codigo_unico(), 'published');

        $response = $this->deleteJson('/api/claude/versions/' . $version->id, [
            'dry_run' => false,
        ], $this->headers());

        $response->assertStatus(422);
        $this->assertDatabaseHas('versions', ['id' => $version->id]);
    }

    public function test_borrado_confirm_version_code_incorrecto_con_dry_run_false_rechaza_sin_escribir_nada()
    {
        $version = $this->crear_version($this->codigo_unico(), 'published');

        $response = $this->deleteJson('/api/claude/versions/' . $version->id, [
            'dry_run'              => false,
            'confirm_version_code' => 'esto-no-es-el-codigo',
        ], $this->headers());

        $response->assertStatus(422);
        $this->assertDatabaseHas('versions', ['id' => $version->id]);

        /* El error no revela el código correcto. */
        $this->assertStringNotContainsString($version->version, (string) $response->getContent());
    }

    public function test_borrado_sin_upgrades_asociados_no_exige_confirm_borra_historial()
    {
        $version = $this->crear_version($this->codigo_unico(), 'published');

        $response = $this->deleteJson('/api/claude/versions/' . $version->id, [
            'dry_run'              => false,
            'confirm_version_code' => $version->version,
        ], $this->headers());

        $response->assertStatus(200);
        $response->assertJsonPath('borro', true);

        $this->assertDatabaseMissing('versions', ['id' => $version->id]);
    }

    public function test_borrado_con_upgrades_asociados_sin_confirm_borra_historial_rechaza()
    {
        $version = $this->crear_version($this->codigo_unico(), 'published');
        $cliente = $this->crear_cliente();
        $this->crear_upgrade($cliente, ['to_version_id' => $version->id]);

        $response = $this->deleteJson('/api/claude/versions/' . $version->id, [
            'dry_run'              => false,
            'confirm_version_code' => $version->version,
        ], $this->headers());

        $response->assertStatus(422);

        $this->assertDatabaseHas('versions', ['id' => $version->id]);
        $this->assertDatabaseHas('client_version_upgrades', ['to_version_id' => $version->id]);
    }

    public function test_borrado_con_los_dos_frenos_satisfechos_borra_de_verdad_y_aplica_las_cascadas()
    {
        $version_destino = $this->crear_version($this->codigo_unico(), 'published');
        $version_otra    = $this->crear_version($this->codigo_unico(), 'published');

        $cliente_actual = $this->crear_cliente($version_destino->id);
        $cliente_origen = $this->crear_cliente($version_otra->id);

        $upgrade_hacia  = $this->crear_upgrade($cliente_actual, ['to_version_id' => $version_destino->id]);
        $upgrade_desde  = $this->crear_upgrade($cliente_origen, [
            'from_version_id' => $version_destino->id,
            'to_version_id'   => $version_otra->id,
        ]);

        $response = $this->deleteJson('/api/claude/versions/' . $version_destino->id, [
            'dry_run'                  => false,
            'confirm_version_code'     => $version_destino->version,
            'confirm_borra_historial'  => true,
        ], $this->headers());

        $response->assertStatus(200);
        $response->assertJsonPath('borro', true);
        $response->assertJsonPath('impacto.upgrades_hacia_esta_version', 1);

        // La fila de versions desaparece.
        $this->assertDatabaseMissing('versions', ['id' => $version_destino->id]);

        // El upgrade que apuntaba a esta versión como destino se borró en cascada.
        $this->assertDatabaseMissing('client_version_upgrades', ['id' => $upgrade_hacia->id]);

        // El upgrade que la tenía como origen queda con from_version_id null, pero sigue existiendo.
        $this->assertDatabaseHas('client_version_upgrades', [
            'id'              => $upgrade_desde->id,
            'from_version_id' => null,
        ]);

        // El cliente que la tenía como actual queda con current_version_id null.
        $this->assertDatabaseHas('clients', [
            'id'                  => $cliente_actual->id,
            'current_version_id'  => null,
        ]);
    }

    public function test_borrado_404_si_la_version_no_existe()
    {
        $response = $this->deleteJson('/api/claude/versions/999999999', [
            'dry_run' => true,
        ], $this->headers());

        $response->assertStatus(404);
    }

    public function test_borrado_sin_header_da_401()
    {
        $version = $this->crear_version($this->codigo_unico(), 'published');

        $response = $this->deleteJson('/api/claude/versions/' . $version->id, [], ['Accept' => 'application/json']);

        $response->assertStatus(401);
    }
}

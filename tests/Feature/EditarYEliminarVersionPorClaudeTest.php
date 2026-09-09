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
 *  1. 🔴 Una versión con filas en `demo_updates` NO SE PUEDE BORRAR: esa FK se declaró sin
 *     `onDelete`, o sea RESTRICT, y sin este bloqueo el endpoint devolvía un 500 crudo con stack
 *     trace después de un dry_run que decía que el borrado era inocuo.
 *  2. 🔴 El borrado hereda las cascadas REALES de la base (`client_version_upgrades.to_version_id`
 *     ON DELETE CASCADE, `from_version_id` y `clients.current_version_id` ON DELETE SET NULL),
 *     incluidas las de SEGUNDO orden (`update_seeders`, `update_commands`,
 *     `client_notification_reads`), y los dos frenos —`confirm_version_code` siempre,
 *     `confirm_borra_historial` cuando hay historial de terceros de CUALQUIERA de esas cuatro
 *     tablas— tienen que impedir un borrado accidental sin impedir uno real.
 *  3. La misma excepción del panel humano en la edición: un código IDÉNTICO al ya persistido no
 *     exige el regex, para no bloquear la edición de una versión legacy con formato viejo.
 *  4. `is_hotfix` se recalcula en la edición SOLO cuando el código cambia de verdad, y nunca por
 *     override explícito (a diferencia del panel humano, que sí lo permite) — un override humano
 *     ya puesto en la base sobrevive a un PATCH que no toca el código.
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

    /**
     * Una demo y una actualización de demo apuntando a esta versión. Es el caso que la base NO deja
     * borrar: `demo_updates.version_id` se declaró sin `onDelete`, o sea RESTRICT.
     *
     * @param Version $version Versión destino de la actualización de demo.
     *
     * @return int Id de la fila de demo_updates.
     */
    private function crear_demo_update(Version $version): int
    {
        $demo_id = DB::table('demos')->insertGetId([
            'uuid'              => (string) Str::uuid(),
            'erp_spa_url'       => 'https://demo-' . Str::random(6) . '.test',
            'erp_api_url'       => 'https://api-demo-' . Str::random(6) . '.test',
            'ecommerce_spa_url' => 'https://tienda-' . Str::random(6) . '.test',
            'ecommerce_api_url' => 'https://api-tienda-' . Str::random(6) . '.test',
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        return (int) DB::table('demo_updates')->insertGetId([
            'uuid'       => (string) Str::uuid(),
            'demo_id'    => $demo_id,
            'version_id' => $version->id,
            'status'     => 'completado',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Notificación de una versión.
     *
     * @param Version $version Versión dueña.
     *
     * @return int Id de version_notifications.
     */
    private function crear_notificacion(Version $version): int
    {
        return (int) DB::table('version_notifications')->insertGetId([
            'uuid'       => (string) Str::uuid(),
            'version_id' => $version->id,
            'title'      => 'Novedad ' . Str::random(6),
            'body'       => 'Cuerpo de la novedad.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Lectura de una notificación por un usuario de un cliente: cascada de SEGUNDO orden, cuelga de
     * `version_notifications` y no tiene ninguna FK contra `versions`.
     *
     * @param int    $notification_id Id de version_notifications.
     * @param Client $client          Cliente que la leyó.
     *
     * @return void
     */
    private function crear_lectura_de_notificacion(int $notification_id, Client $client): void
    {
        DB::table('client_notification_reads')->insert([
            'uuid'                    => (string) Str::uuid(),
            'client_id'               => $client->id,
            'version_notification_id' => $notification_id,
            'client_user_id'          => random_int(1, 999999),
            'client_user_name'        => 'Usuario de prueba',
            'read_at'                 => now(),
            'created_at'              => now(),
            'updated_at'              => now(),
        ]);
    }

    /**
     * Seeder declarado en una versión.
     *
     * @param Version $version Versión dueña.
     *
     * @return int Id de version_seeders.
     */
    private function crear_version_seeder(Version $version): int
    {
        return (int) DB::table('version_seeders')->insertGetId([
            'uuid'         => (string) Str::uuid(),
            'version_id'   => $version->id,
            'seeder_class' => 'SeederDePrueba' . Str::random(6),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    /**
     * Comando declarado en una versión.
     *
     * @param Version $version Versión dueña.
     *
     * @return int Id de version_commands.
     */
    private function crear_version_command(Version $version): int
    {
        return (int) DB::table('version_commands')->insertGetId([
            'uuid'       => (string) Str::uuid(),
            'version_id' => $version->id,
            'command'    => 'php artisan prueba:' . Str::random(6),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Registro de ejecución de un seeder en una actualización concreta de un cliente: cascada de
     * SEGUNDO orden por DOS rutas (el `version_seeder` de la versión, y el upgrade que la tiene
     * como destino).
     *
     * @param ClientVersionUpgrade $upgrade          Actualización dueña.
     * @param int                  $version_seeder_id Seeder ejecutado.
     *
     * @return void
     */
    private function crear_update_seeder(ClientVersionUpgrade $upgrade, int $version_seeder_id): void
    {
        DB::table('update_seeders')->insert([
            'uuid'                      => (string) Str::uuid(),
            'client_version_upgrade_id' => $upgrade->id,
            'version_seeder_id'         => $version_seeder_id,
            'status'                    => 'exitoso',
            'created_at'                => now(),
            'updated_at'                => now(),
        ]);
    }

    /**
     * Igual que `crear_update_seeder()`, para comandos.
     *
     * @param ClientVersionUpgrade $upgrade            Actualización dueña.
     * @param int                  $version_command_id Comando ejecutado.
     *
     * @return void
     */
    private function crear_update_command(ClientVersionUpgrade $upgrade, int $version_command_id): void
    {
        DB::table('update_commands')->insert([
            'uuid'                      => (string) Str::uuid(),
            'client_version_upgrade_id' => $upgrade->id,
            'version_command_id'        => $version_command_id,
            'status'                    => 'exitoso',
            'created_at'                => now(),
            'updated_at'                => now(),
        ]);
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

        /* 🔴 El valor se verifica en la BASE, no sólo en la respuesta: un `published_at` que sale
           bien en el JSON pero nunca se persiste es exactamente el defecto que este test tiene que
           agarrar, y la respuesta lo esconde porque se arma del mismo modelo en memoria. */
        $persistida = Version::find($version->id);
        $this->assertSame('published', $persistida->status);
        $this->assertNotNull($persistida->published_at);
        $this->assertLessThanOrEqual(60, abs(now()->diffInSeconds($persistida->published_at)));
    }

    public function test_edicion_acepta_published_at_explicito_y_le_gana_al_now_automatico()
    {
        $version = $this->crear_version($this->codigo_unico(), 'draft');
        $this->assertNull($version->published_at);

        /* Una versión ya publicada a la que hay que corregirle la fecha: sin este parámetro, el
           `now()` que estampó el pase a published quedaba congelado para siempre. */
        $response = $this->patchJson('/api/claude/versions/' . $version->id, [
            'status'       => 'published',
            'published_at' => '2026-01-15 10:30:00',
        ], $this->headers());

        $response->assertStatus(200);
        $response->assertJsonPath('model.status', 'published');
        $this->assertStringStartsWith('2026-01-15T10:30:00', (string) $response->json('model.published_at'));

        $this->assertDatabaseHas('versions', [
            'id'           => $version->id,
            'published_at' => '2026-01-15 10:30:00',
        ]);

        /* Y se puede volver a corregir sobre una versión que ya tenía fecha, sin tocar el status. */
        $segunda = $this->patchJson('/api/claude/versions/' . $version->id, [
            'published_at' => '2026-02-20 08:00:00',
        ], $this->headers());

        $segunda->assertStatus(200);
        $this->assertDatabaseHas('versions', [
            'id'           => $version->id,
            'published_at' => '2026-02-20 08:00:00',
        ]);
    }

    public function test_edicion_con_published_at_vacio_o_null_no_borra_la_fecha_existente()
    {
        /* 🔴 El hueco que encontró la segunda ronda de chequeo: `published_at: null` (o vacío) SIN
           `status` no puede limpiar la fecha de una versión ya publicada — el panel humano no
           tiene forma de mandar esa clave sin valor, así que `claude/*` tampoco puede tener ese
           poder de rebote de leer con `has()` en vez de `filled()`. */
        $fecha   = now()->subDays(5);
        $version = Version::create([
            'version'      => $this->codigo_unico(),
            'status'       => 'published',
            'published_at' => $fecha,
            'is_hotfix'    => false,
        ]);

        $con_null = $this->patchJson('/api/claude/versions/' . $version->id, [
            'published_at' => null,
            'title'        => 'Título nuevo, sin tocar la fecha',
        ], $this->headers());

        $con_null->assertStatus(200);
        $this->assertNotNull($con_null->json('model.published_at'));
        $this->assertDatabaseHas('versions', [
            'id'     => $version->id,
            'status' => 'published',
        ]);
        $this->assertNotNull(Version::find($version->id)->published_at);

        $con_vacio = $this->patchJson('/api/claude/versions/' . $version->id, [
            'published_at' => '',
            'description'  => 'Otra edición que tampoco toca la fecha',
        ], $this->headers());

        $con_vacio->assertStatus(200);
        $this->assertNotNull(Version::find($version->id)->published_at);
    }

    public function test_edicion_no_recalcula_is_hotfix_si_el_codigo_no_cambia()
    {
        /* Versión de 3 componentes: por cálculo automático, is_hotfix daría FALSE. */
        $codigo  = $this->codigo_unico();
        $version = $this->crear_version($codigo, 'draft', false);

        /* Override humano puesto a mano en la base, como el checkbox del panel: el panel humano SÍ
           deja forzarlo, y `claude/*` no tiene por qué pisarlo al editar otra cosa. */
        DB::table('versions')->where('id', $version->id)->update(['is_hotfix' => true]);

        $response = $this->patchJson('/api/claude/versions/' . $version->id, [
            'title' => 'Título nuevo sin tocar el código',
        ], $this->headers());

        $response->assertStatus(200);
        $response->assertJsonPath('model.is_hotfix', true);
        $this->assertTrue((bool) Version::find($version->id)->is_hotfix);

        /* Mandar el MISMO código tampoco es un cambio: tampoco recalcula. */
        $segunda = $this->patchJson('/api/claude/versions/' . $version->id, [
            'version' => $codigo,
            'title'   => 'Otro título más',
        ], $this->headers());

        $segunda->assertStatus(200);
        $segunda->assertJsonPath('model.is_hotfix', true);
        $this->assertTrue((bool) Version::find($version->id)->is_hotfix);
    }

    public function test_edicion_de_version_inexistente_con_cuerpo_vacio_da_404_no_422()
    {
        /* 🔴 El id manda sobre el cuerpo: contestar 422 ("no mandaste campos") sobre un id que no
           existe manda a corregir el cuerpo cuando el problema es la fila que no está. */
        $response = $this->patchJson('/api/claude/versions/999999999', [], $this->headers());

        $response->assertStatus(404);
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
        $response->assertJsonPath('impacto.demo_updates', 0);
        $response->assertJsonPath('se_puede_borrar', true);

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

    // ------------------------------------------------------------------------------------------
    // El bloqueo duro y las cascadas de segundo orden
    // ------------------------------------------------------------------------------------------

    /**
     * 🔴 EL CASO QUE TIRABA UN 500 CRUDO. `demo_updates.version_id` se declaró sin `onDelete`, o sea
     * RESTRICT: el `delete()` moría con una QueryException 1451 y la respuesta salía con stack trace
     * y rutas del disco. Y antes de eso, el dry_run reportaba los siete conteos en cero y decía que
     * `confirm_borra_historial` no hacía falta: un proceso automático leía "esto es inocuo" y se
     * comía el 500 en la llamada siguiente.
     *
     * @return void
     */
    public function test_borrado_de_version_con_demo_updates_se_bloquea_en_dry_run_y_en_el_borrado_real()
    {
        $version = $this->crear_version($this->codigo_unico(), 'published');
        $this->crear_demo_update($version);

        /* El dry_run tiene que ser honesto: no se puede borrar, y lo dice. */
        $dry_run = $this->deleteJson('/api/claude/versions/' . $version->id, [], $this->headers());

        $dry_run->assertStatus(200);
        $dry_run->assertJsonPath('dry_run', true);
        $dry_run->assertJsonPath('borro', false);
        $dry_run->assertJsonPath('se_puede_borrar', false);
        $dry_run->assertJsonPath('impacto.demo_updates', 1);
        $this->assertStringContainsString('demo_updates', (string) $dry_run->json('nota'));
        $this->assertStringContainsString('NO SE PUEDE BORRAR', (string) $dry_run->json('nota'));

        /* Y el borrado real rechaza con 422 ANTES de tocar la base, aun con los dos frenos
           satisfechos: no hay confirmación que destrabe una FK RESTRICT. */
        $real = $this->deleteJson('/api/claude/versions/' . $version->id, [
            'dry_run'                 => false,
            'confirm_version_code'    => $version->version,
            'confirm_borra_historial' => true,
        ], $this->headers());

        $real->assertStatus(422);
        $real->assertJsonPath('impacto.demo_updates', 1);

        $this->assertDatabaseHas('versions', ['id' => $version->id]);
        $this->assertDatabaseHas('demo_updates', ['version_id' => $version->id]);
    }

    /**
     * El `impacto` mide las cascadas de SEGUNDO orden —las que no tienen ninguna FK contra
     * `versions` y desaparecen igual— más la tabla puente sin FK, que se informa y nada más.
     *
     * `update_seeders`/`update_commands` entran por DOS rutas y este test arma una de cada una: un
     * registro de ejecución que cuelga de un seeder/comando DE ESTA VERSIÓN (aunque la actualización
     * sea de otra), y otro que cuelga de una actualización que tiene a ESTA VERSIÓN como destino
     * (aunque el seeder/comando sea de otra). El conteo es uno solo por tabla: 2 y 2.
     *
     * @return void
     */
    public function test_el_impacto_incluye_las_cascadas_de_segundo_orden_y_la_tabla_puente_sin_fk()
    {
        $version = $this->crear_version($this->codigo_unico(), 'published');
        $otra    = $this->crear_version($this->codigo_unico(), 'published');
        $cliente = $this->crear_cliente();

        /* Ruta A: upgrade hacia ESTA versión (se borra en cascada, y con él sus update_*). */
        $upgrade_hacia = $this->crear_upgrade($cliente, ['to_version_id' => $version->id]);
        /* Ruta B: upgrade ajeno, que ejecutó un seeder/comando DE ESTA versión. */
        $upgrade_ajeno = $this->crear_upgrade($cliente, ['to_version_id' => $otra->id]);

        $seeder_propio = $this->crear_version_seeder($version);
        $seeder_ajeno  = $this->crear_version_seeder($otra);
        $this->crear_update_seeder($upgrade_ajeno, $seeder_propio);
        $this->crear_update_seeder($upgrade_hacia, $seeder_ajeno);

        $command_propio = $this->crear_version_command($version);
        $command_ajeno  = $this->crear_version_command($otra);
        $this->crear_update_command($upgrade_ajeno, $command_propio);
        $this->crear_update_command($upgrade_hacia, $command_ajeno);

        $notificacion = $this->crear_notificacion($version);
        $this->crear_lectura_de_notificacion($notificacion, $cliente);

        DB::table('client_version_upgrade_versions')->insert([
            'client_version_upgrade_id' => $upgrade_ajeno->id,
            'version_id'                => $version->id,
        ]);

        $response = $this->deleteJson('/api/claude/versions/' . $version->id, [], $this->headers());

        $response->assertStatus(200);
        $response->assertJsonPath('impacto.update_seeders', 2);
        $response->assertJsonPath('impacto.update_commands', 2);
        $response->assertJsonPath('impacto.client_notification_reads', 1);
        $response->assertJsonPath('impacto.client_version_upgrade_versions', 1);
        $response->assertJsonPath('impacto.demo_updates', 0);

        /* La nota del dry_run nombra los motivos, no sólo los upgrades. */
        $nota = (string) $response->json('nota');
        $this->assertStringContainsString('update_seeders', $nota);
        $this->assertStringContainsString('update_commands', $nota);
        $this->assertStringContainsString('client_notification_reads', $nota);
    }

    /**
     * 🔴 EL AGUJERO DEL FRENO. El disparador de `confirm_borra_historial` estaba atado a
     * `upgrades_hacia_esta_version`, que es la tabla equivocada: una versión que sólo es ORIGEN de
     * upgrades (cero upgrades hacia ella) pero que sí tiene lecturas de sus notificaciones borraba
     * historial real de clientes sin que nadie lo confirmara.
     *
     * @return void
     */
    public function test_confirm_borra_historial_se_exige_por_lecturas_de_notificacion_sin_upgrades_hacia_la_version()
    {
        $version = $this->crear_version($this->codigo_unico(), 'published');
        $otra    = $this->crear_version($this->codigo_unico(), 'published');
        $cliente = $this->crear_cliente();

        /* Sólo como ORIGEN: upgrades_hacia_esta_version queda en cero. */
        $this->crear_upgrade($cliente, [
            'from_version_id' => $version->id,
            'to_version_id'   => $otra->id,
        ]);

        $notificacion = $this->crear_notificacion($version);
        $this->crear_lectura_de_notificacion($notificacion, $cliente);

        $dry_run = $this->deleteJson('/api/claude/versions/' . $version->id, [], $this->headers());

        $dry_run->assertStatus(200);
        $dry_run->assertJsonPath('impacto.upgrades_hacia_esta_version', 0);
        $dry_run->assertJsonPath('impacto.client_notification_reads', 1);
        $this->assertStringContainsString('confirm_borra_historial=true', (string) $dry_run->json('nota'));

        /* Con el código confirmado pero sin confirm_borra_historial: rechaza igual. */
        $sin_confirmar = $this->deleteJson('/api/claude/versions/' . $version->id, [
            'dry_run'              => false,
            'confirm_version_code' => $version->version,
        ], $this->headers());

        $sin_confirmar->assertStatus(422);
        $this->assertDatabaseHas('versions', ['id' => $version->id]);
        $this->assertDatabaseHas('client_notification_reads', ['version_notification_id' => $notificacion]);

        /* Con el flag: borra, y las lecturas se van con la notificación. */
        $con_confirmacion = $this->deleteJson('/api/claude/versions/' . $version->id, [
            'dry_run'                 => false,
            'confirm_version_code'    => $version->version,
            'confirm_borra_historial' => true,
        ], $this->headers());

        $con_confirmacion->assertStatus(200);
        $con_confirmacion->assertJsonPath('borro', true);
        $this->assertDatabaseMissing('versions', ['id' => $version->id]);
        $this->assertDatabaseMissing('client_notification_reads', ['version_notification_id' => $notificacion]);
    }

    /**
     * El mismo agujero, por la otra punta: registros de ejecución de seeders/comandos sin ningún
     * upgrade hacia esta versión.
     *
     * @return void
     */
    public function test_confirm_borra_historial_se_exige_por_update_seeders_sin_upgrades_hacia_la_version()
    {
        $version = $this->crear_version($this->codigo_unico(), 'published');
        $otra    = $this->crear_version($this->codigo_unico(), 'published');
        $cliente = $this->crear_cliente();

        $upgrade_ajeno = $this->crear_upgrade($cliente, ['to_version_id' => $otra->id]);
        $this->crear_update_seeder($upgrade_ajeno, $this->crear_version_seeder($version));

        $sin_confirmar = $this->deleteJson('/api/claude/versions/' . $version->id, [
            'dry_run'              => false,
            'confirm_version_code' => $version->version,
        ], $this->headers());

        $sin_confirmar->assertStatus(422);
        $sin_confirmar->assertJsonPath('impacto.upgrades_hacia_esta_version', 0);
        $sin_confirmar->assertJsonPath('impacto.update_seeders', 1);
        $this->assertDatabaseHas('versions', ['id' => $version->id]);
    }
}

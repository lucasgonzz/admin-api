<?php

namespace Tests\Feature;

use App\Models\Version;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * `POST claude/versions` (alta) y `POST claude/versions/{id}/status` (cambio de estado).
 *
 * Lo que estos tests protegen, en orden de importancia:
 *
 *  1. `is_hotfix` se autocalcula SIEMPRE en el alta, sin override posible — es el mismo bug
 *     que ya rompió el panel humano el 18/8/2026 (toda versión creada desde el SPA quedaba
 *     is_hotfix=false porque el request traía la clave con valor false).
 *  2. El cambio de estado sigue el mismo criterio que `VersionController::update_json`:
 *     `published_at` se setea a `now()` sólo la PRIMERA vez que pasa a `published`, y no se
 *     toca en ninguna otra transición.
 *  3. El código de versión respeta el mismo regex y la misma unicidad que el alta desde el
 *     panel humano.
 */
class AltaYEstadoDeVersionPorClaudeTest extends TestCase
{
    use DatabaseTransactions;

    /** Clave de ingesta usada en las requests del test. */
    const CLAVE = 'clave-de-prueba-claude-versions';

    /**
     * Setea la clave de ingesta: en el .env del slot está vacía y el middleware es
     * fail-closed, así que sin esto todo devolvería 401 y los tests medirían el
     * middleware, no el endpoint.
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
     * Código de versión válido y único para el test (3 componentes numéricos, sin letras:
     * el regex de `VersionNumberComparator` no las acepta).
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

    // --- Alta ---

    public function test_alta_crea_en_borrador_por_defecto_y_autocalcula_is_hotfix()
    {
        $codigo = $this->codigo_unico();

        $response = $this->postJson('/api/claude/versions', [
            'version' => $codigo,
        ], $this->headers());

        $response->assertStatus(201);
        $response->assertJsonPath('model.version', $codigo);
        $response->assertJsonPath('model.status', 'draft');
        $response->assertJsonPath('model.is_hotfix', false);
        $response->assertJsonPath('model.published_at', null);

        $this->assertDatabaseHas('versions', [
            'version'   => $codigo,
            'status'    => 'draft',
            'is_hotfix' => false,
        ]);
    }

    public function test_alta_con_status_published_setea_published_at()
    {
        $codigo = $this->codigo_unico();

        $response = $this->postJson('/api/claude/versions', [
            'version' => $codigo,
            'title'   => 'Título de prueba',
            'status'  => 'published',
        ], $this->headers());

        $response->assertStatus(201);
        $response->assertJsonPath('model.status', 'published');
        $response->assertJsonPath('model.title', 'Título de prueba');
        $this->assertNotNull($response->json('model.published_at'));
    }

    public function test_alta_autocalcula_hotfix_e_ignora_cualquier_intento_de_override()
    {
        $codigo = $this->codigo_hotfix_unico();

        $response = $this->postJson('/api/claude/versions', [
            'version'   => $codigo,
            /* Aunque venga explícito, el alta lo ignora: se autocalcula siempre. */
            'is_hotfix' => false,
        ], $this->headers());

        $response->assertStatus(201);
        $response->assertJsonPath('model.is_hotfix', true);
    }

    public function test_alta_rechaza_codigo_con_menos_de_tres_componentes()
    {
        $response = $this->postJson('/api/claude/versions', [
            'version' => '4.0',
        ], $this->headers());

        $response->assertStatus(422);
        $this->assertDatabaseMissing('versions', ['version' => '4.0']);
    }

    public function test_alta_rechaza_codigo_duplicado()
    {
        $codigo = $this->codigo_unico();
        Version::create(['version' => $codigo, 'status' => 'draft', 'is_hotfix' => false]);

        $response = $this->postJson('/api/claude/versions', [
            'version' => $codigo,
        ], $this->headers());

        $response->assertStatus(422);
    }

    public function test_alta_sin_header_da_401()
    {
        $response = $this->postJson('/api/claude/versions', [
            'version' => $this->codigo_unico(),
        ], ['Accept' => 'application/json']);

        $response->assertStatus(401);
    }

    // --- Cambio de estado ---

    public function test_cambiar_a_published_setea_published_at_si_no_tenia()
    {
        $version = Version::create([
            'version'      => $this->codigo_unico(),
            'status'       => 'draft',
            'is_hotfix'    => false,
            'published_at' => null,
        ]);

        $response = $this->postJson('/api/claude/versions/' . $version->id . '/status', [
            'status' => 'published',
        ], $this->headers());

        $response->assertStatus(200);
        $response->assertJsonPath('model.status', 'published');
        $this->assertNotNull($response->json('model.published_at'));
    }

    public function test_cambiar_estado_no_pisa_published_at_ya_existente()
    {
        $fecha_original = now()->subDays(10);
        $version        = Version::create([
            'version'      => $this->codigo_unico(),
            'status'       => 'published',
            'is_hotfix'    => false,
            'published_at' => $fecha_original,
        ]);

        $response = $this->postJson('/api/claude/versions/' . $version->id . '/status', [
            'status' => 'archived',
        ], $this->headers());

        $response->assertStatus(200);
        $response->assertJsonPath('model.status', 'archived');
        $this->assertSame(
            $fecha_original->toIso8601String(),
            $response->json('model.published_at')
        );
    }

    public function test_cambiar_estado_acepta_uuid_en_la_ruta()
    {
        $version = Version::create([
            'version'   => $this->codigo_unico(),
            'status'    => 'draft',
            'is_hotfix' => false,
        ]);

        $response = $this->postJson('/api/claude/versions/' . $version->uuid . '/status', [
            'status' => 'archived',
        ], $this->headers());

        $response->assertStatus(200);
        $response->assertJsonPath('model.id', $version->id);
        $response->assertJsonPath('model.status', 'archived');
    }

    public function test_cambiar_estado_rechaza_valor_no_valido()
    {
        $version = Version::create([
            'version'   => $this->codigo_unico(),
            'status'    => 'draft',
            'is_hotfix' => false,
        ]);

        $response = $this->postJson('/api/claude/versions/' . $version->id . '/status', [
            'status' => 'no_existe',
        ], $this->headers());

        $response->assertStatus(422);
        $this->assertDatabaseHas('versions', ['id' => $version->id, 'status' => 'draft']);
    }

    public function test_cambiar_estado_404_si_la_version_no_existe()
    {
        $response = $this->postJson('/api/claude/versions/999999999/status', [
            'status' => 'archived',
        ], $this->headers());

        $response->assertStatus(404);
    }
}

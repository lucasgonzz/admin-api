<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Client;
use App\Models\Version;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Contrato JSON del paso de confirmación para el SPA (`admin-spa`): `POST
 * /api/admin/update/preview` y `POST /api/admin/update` con `confirmed_version_ids`.
 *
 * Es el mismo mecanismo que el flujo web (Bloque C), pero acá lo que importa es el
 * CONTRATO explícito que consume `UpdateVersionRangeModal.vue`: `is_hotfix`,
 * `default_checked` y `is_target` por candidata, y el fallback documentado cuando el
 * SPA no manda `confirmed_version_ids`.
 */
class ActualizacionApiPreviewYConfirmacionTest extends TestCase
{
    use DatabaseTransactions;

    private function autenticar(): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Admin de prueba';
        $admin->email    = 'api-preview-' . Str::random(8) . '@test.local';
        $admin->password = bcrypt('secret');
        $admin->save();

        Sanctum::actingAs($admin);

        return $admin;
    }

    private function crear_cliente(?int $current_version_id): Client
    {
        $client                     = new Client();
        $client->name               = 'Cliente API de prueba';
        $client->slug                = 'cliente-api-preview-' . Str::random(8);
        $client->api_key            = 'clave-api';
        $client->inbound_api_key    = 'clave-inbound';
        $client->current_version_id = $current_version_id;
        $client->is_active          = true;
        $client->save();

        return $client;
    }

    private function crear_version(string $codigo, bool $is_hotfix = false, string $status = 'published'): Version
    {
        $version            = new Version();
        $version->uuid      = (string) Str::uuid();
        $version->version   = $codigo;
        $version->title     = 'Versión ' . $codigo;
        $version->status    = $status;
        $version->is_hotfix = $is_hotfix;
        if ($status === 'published') {
            $version->published_at = now();
        }
        $version->save();

        return $version;
    }

    /**
     * Escenario base: cliente en 3.6.0, rango publicado hasta 3.6.3 con un hotfix
     * intermedio (3.6.1.1).
     *
     * @return array{admin: Admin, client: Client, v_3_6_1: Version, v_3_6_1_1: Version, v_3_6_2: Version, v_3_6_3: Version}
     */
    private function armar_escenario(): array
    {
        $from = $this->crear_version('3.6.0');

        $v_3_6_1   = $this->crear_version('3.6.1');
        $v_3_6_1_1 = $this->crear_version('3.6.1.1', true);
        $v_3_6_2   = $this->crear_version('3.6.2');
        $v_3_6_3   = $this->crear_version('3.6.3');

        $admin  = $this->autenticar();
        $client = $this->crear_cliente($from->id);

        return compact('admin', 'client', 'v_3_6_1', 'v_3_6_1_1', 'v_3_6_2', 'v_3_6_3');
    }

    /**
     * `POST /api/admin/update/preview` devuelve el contrato exacto: `is_hotfix`,
     * `default_checked` (false en hotfixes, true en el resto) y `is_target` (sólo la
     * destino).
     *
     * @return void
     */
    public function test_preview_devuelve_el_contrato_con_default_checked_e_is_target(): void
    {
        $e = $this->armar_escenario();

        $response = $this->postJson('/api/admin/update/preview', [
            'client_id'     => $e['client']->id,
            'to_version_id' => $e['v_3_6_3']->id,
        ]);

        $response->assertStatus(200);

        $candidates = collect($response->json('candidates'))->keyBy('version');

        $this->assertFalse($candidates['3.6.1']['is_hotfix']);
        $this->assertTrue($candidates['3.6.1']['default_checked']);
        $this->assertFalse($candidates['3.6.1']['is_target']);

        $this->assertTrue($candidates['3.6.1.1']['is_hotfix']);
        $this->assertFalse($candidates['3.6.1.1']['default_checked'], 'Un hotfix no debe venir tildado por defecto.');
        $this->assertFalse($candidates['3.6.1.1']['is_target']);

        $this->assertFalse($candidates['3.6.2']['is_hotfix']);
        $this->assertTrue($candidates['3.6.2']['default_checked']);

        $this->assertFalse($candidates['3.6.3']['is_hotfix']);
        $this->assertTrue($candidates['3.6.3']['default_checked']);
        $this->assertTrue($candidates['3.6.3']['is_target'], 'La versión destino debe venir marcada is_target.');
    }

    /**
     * Si la versión destino está en `draft`, el preview devuelve 422: no se puede armar
     * una actualización hacia algo que todavía no está publicado.
     *
     * @return void
     */
    public function test_preview_con_destino_en_draft_devuelve_422(): void
    {
        $e = $this->armar_escenario();

        $borrador = $this->crear_version('3.6.9', false, 'draft');

        $response = $this->postJson('/api/admin/update/preview', [
            'client_id'     => $e['client']->id,
            'to_version_id' => $borrador->id,
        ]);

        $response->assertStatus(422);
    }

    /**
     * `POST /api/admin/update` con `confirmed_version_ids` persiste exactamente ese
     * conjunto en la pivot, sin recalcular ni agregar nada de más.
     *
     * @return void
     */
    public function test_store_con_confirmed_version_ids_persiste_exactamente_eso(): void
    {
        $e = $this->armar_escenario();

        $confirmados = [$e['v_3_6_1']->id, $e['v_3_6_1_1']->id, $e['v_3_6_3']->id];

        $response = $this->postJson('/api/admin/update', [
            'client_id'             => $e['client']->id,
            'to_version_id'         => $e['v_3_6_3']->id,
            'confirmed_version_ids' => $confirmados,
        ]);

        $response->assertStatus(201);
        $upgrade_id = (int) $response->json('model.id');

        $ids_en_pivot = DB::table('client_version_upgrade_versions')
            ->where('client_version_upgrade_id', $upgrade_id)
            ->pluck('version_id')
            ->map('intval')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(collect($confirmados)->sort()->values()->all(), $ids_en_pivot);
        $this->assertNotContains((int) $e['v_3_6_2']->id, $ids_en_pivot, 'Se coló una versión que no estaba en confirmed_version_ids.');
    }

    /**
     * Sin `confirmed_version_ids` (clave ausente o vacía) aplica el fallback documentado:
     * todas las troncales (sin hotfix) más la destino, que es el mismo default que
     * `default_checked` en el preview.
     *
     * @return void
     */
    public function test_store_sin_confirmed_version_ids_aplica_el_fallback_troncal_sin_hotfix(): void
    {
        $e = $this->armar_escenario();

        $response = $this->postJson('/api/admin/update', [
            'client_id'     => $e['client']->id,
            'to_version_id' => $e['v_3_6_3']->id,
        ]);

        $response->assertStatus(201);
        $upgrade_id = (int) $response->json('model.id');

        $ids_en_pivot = DB::table('client_version_upgrade_versions')
            ->where('client_version_upgrade_id', $upgrade_id)
            ->pluck('version_id')
            ->map('intval')
            ->sort()
            ->values()
            ->all();

        $esperados = collect([$e['v_3_6_1']->id, $e['v_3_6_2']->id, $e['v_3_6_3']->id])->sort()->values()->all();
        $this->assertSame($esperados, $ids_en_pivot);
        $this->assertNotContains((int) $e['v_3_6_1_1']->id, $ids_en_pivot, 'El fallback no debe incluir hotfixes.');
    }
}

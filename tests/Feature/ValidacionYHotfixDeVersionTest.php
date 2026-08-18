<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Version;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Validación del código de versión (los cuatro caminos: `versions.store`,
 * `versions.update`, `POST /api/admin/version`, `PUT /api/admin/version/{id}`) y el
 * autocálculo de `is_hotfix` con override manual.
 *
 * Antes de esta misión, el único límite era `maxlength=30` en el form Blade — nada
 * rechazaba "3.3" o "abc". `VersionController::validate_version_payload()` es el método
 * protegido compartido que ahora corre en los cuatro entry points (patrón del repo:
 * `$request->validate([...])` inline, no hay `FormRequest` en `app/`).
 */
class ValidacionYHotfixDeVersionTest extends TestCase
{
    use DatabaseTransactions;

    private function crear_admin_web(): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Admin de prueba';
        $admin->email    = 'validacion-web-' . Str::random(8) . '@test.local';
        $admin->password = bcrypt('secret');
        $admin->save();

        return $admin;
    }

    private function autenticar_api(): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Admin de prueba';
        $admin->email    = 'validacion-api-' . Str::random(8) . '@test.local';
        $admin->password = bcrypt('secret');
        $admin->save();

        Sanctum::actingAs($admin);

        return $admin;
    }

    private function crear_version_publicada(string $codigo): Version
    {
        $version              = new Version();
        $version->uuid        = (string) Str::uuid();
        $version->version     = $codigo;
        $version->title       = 'Versión ' . $codigo;
        $version->status      = 'draft';
        $version->save();

        return $version;
    }

    /**
     * @return array<int, string>
     */
    private function codigos_invalidos(): array
    {
        return ['3.3', 'abc', '3.3.x'];
    }

    /**
     * `POST versions` (web) rechaza códigos inválidos: sin al menos 3 componentes
     * numéricos separados por punto no hay alta.
     *
     * @return void
     */
    public function test_store_web_rechaza_codigos_invalidos(): void
    {
        $admin = $this->crear_admin_web();

        foreach ($this->codigos_invalidos() as $codigo) {
            $response = $this->actingAs($admin)->post('versions', [
                'version' => $codigo,
                'status'  => 'draft',
            ]);

            $response->assertSessionHasErrors('version');
            $this->assertSame(0, Version::where('version', $codigo)->count(), "\"$codigo\" no debía persistirse.");
        }
    }

    /**
     * `PUT versions/{id}` (web) rechaza los mismos códigos inválidos y no toca la fila.
     *
     * @return void
     */
    public function test_update_web_rechaza_codigos_invalidos(): void
    {
        $admin   = $this->crear_admin_web();
        $version = $this->crear_version_publicada('4.1.0');

        foreach ($this->codigos_invalidos() as $codigo) {
            $response = $this->actingAs($admin)->put('versions/' . $version->id, [
                'version' => $codigo,
                'status'  => 'draft',
            ]);

            $response->assertSessionHasErrors('version');
            $this->assertSame('4.1.0', $version->fresh()->version, "\"$codigo\" no debía sobreescribir el código original.");
        }
    }

    /**
     * `POST /api/admin/version` rechaza los mismos códigos con 422 y el error en `version`.
     *
     * @return void
     */
    public function test_store_json_rechaza_codigos_invalidos(): void
    {
        $this->autenticar_api();

        foreach ($this->codigos_invalidos() as $codigo) {
            $response = $this->postJson('/api/admin/version', [
                'version' => $codigo,
                'status'  => 'draft',
            ]);

            $response->assertStatus(422);
            $response->assertJsonValidationErrors('version');
            $this->assertSame(0, Version::where('version', $codigo)->count());
        }
    }

    /**
     * `PUT /api/admin/version/{id}` rechaza los mismos códigos con 422.
     *
     * @return void
     */
    public function test_update_json_rechaza_codigos_invalidos(): void
    {
        $this->autenticar_api();
        $version = $this->crear_version_publicada('4.2.0');

        foreach ($this->codigos_invalidos() as $codigo) {
            $response = $this->putJson('/api/admin/version/' . $version->id, [
                'version' => $codigo,
            ]);

            $response->assertStatus(422);
            $response->assertJsonValidationErrors('version');
            $this->assertSame('4.2.0', $version->fresh()->version);
        }
    }

    /**
     * Sin mandar `is_hotfix` explícito, se autocalcula: "3.3.1" (3 componentes) no es
     * hotfix.
     *
     * @return void
     */
    public function test_is_hotfix_se_autocalcula_false_para_tres_componentes(): void
    {
        $this->autenticar_api();

        $response = $this->postJson('/api/admin/version', [
            'version' => '5.1.1',
            'status'  => 'draft',
        ]);

        $response->assertStatus(201);
        $this->assertFalse((bool) $response->json('model.is_hotfix'));
    }

    /**
     * Sin mandar `is_hotfix` explícito, "3.3.1.2" (4 componentes) se autocalcula como
     * hotfix.
     *
     * @return void
     */
    public function test_is_hotfix_se_autocalcula_true_para_cuatro_componentes(): void
    {
        $this->autenticar_api();

        $response = $this->postJson('/api/admin/version', [
            'version' => '5.1.2.1',
            'status'  => 'draft',
        ]);

        $response->assertStatus(201);
        $this->assertTrue((bool) $response->json('model.is_hotfix'));
    }

    /**
     * Mandando `is_hotfix=0` explícito sobre un código de 4 componentes, el override
     * manual del admin gana por sobre el cálculo automático: queda en `false`.
     *
     * @return void
     */
    public function test_is_hotfix_explicito_en_false_gana_sobre_el_calculo_automatico(): void
    {
        $this->autenticar_api();

        $response = $this->postJson('/api/admin/version', [
            'version'   => '5.1.3.1',
            'status'    => 'draft',
            'is_hotfix' => 0,
        ]);

        $response->assertStatus(201);
        $this->assertFalse(
            (bool) $response->json('model.is_hotfix'),
            'El override manual (is_hotfix=0) debía ganarle al cálculo automático (4 componentes ⇒ hotfix).'
        );
    }
}

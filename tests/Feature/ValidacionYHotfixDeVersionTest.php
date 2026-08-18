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
     * 🔴 En el ALTA no hay override: `is_hotfix` se autocalcula SIEMPRE, aunque el request
     * traiga la clave. Es el caso del modal genérico de creación del SPA (`common-vue`),
     * que inicializa el draft con todas las propiedades declaradas y por lo tanto manda
     * `is_hotfix: false` en todo POST de alta. Decidir por presencia de la clave dejaba
     * toda versión creada desde el SPA en `is_hotfix = false` sin importar el código.
     *
     * @return void
     */
    public function test_en_el_alta_json_el_is_hotfix_enviado_se_ignora_y_se_autocalcula(): void
    {
        $this->autenticar_api();

        $response = $this->postJson('/api/admin/version', [
            'version'   => '5.1.3.1',
            'status'    => 'draft',
            'is_hotfix' => false,
        ]);

        $response->assertStatus(201);
        $this->assertTrue(
            (bool) $response->json('model.is_hotfix'),
            'En el alta, el is_hotfix del request debe ignorarse: 4 componentes ⇒ hotfix.'
        );
    }

    /**
     * Mismo caso en el ALTA web (`POST versions`): el campo que llegue se ignora.
     *
     * @return void
     */
    public function test_en_el_alta_web_el_is_hotfix_enviado_se_ignora_y_se_autocalcula(): void
    {
        $admin = $this->crear_admin_web();

        $codigo = '5.1.4.1';

        $response = $this->actingAs($admin)->post('versions', [
            'version'   => $codigo,
            'status'    => 'draft',
            'is_hotfix' => 0,
        ]);

        $response->assertStatus(302);
        $this->assertTrue(
            (bool) Version::where('version', $codigo)->first()->is_hotfix,
            'En el alta web, el is_hotfix del request debe ignorarse: 4 componentes ⇒ hotfix.'
        );
    }

    /**
     * En la EDICIÓN el override manual sí existe (es el checkbox del form de edición):
     * `is_hotfix=0` sobre un código de 4 componentes gana sobre el cálculo automático.
     *
     * @return void
     */
    public function test_en_la_edicion_json_el_is_hotfix_explicito_gana_sobre_el_calculo(): void
    {
        $this->autenticar_api();
        $version = $this->crear_version_publicada('5.1.5.1');

        $response = $this->putJson('/api/admin/version/' . $version->id, [
            'version'   => '5.1.5.1',
            'is_hotfix' => false,
        ]);

        $response->assertStatus(200);
        $this->assertFalse(
            (bool) $version->fresh()->is_hotfix,
            'En la edición, el override manual (is_hotfix=0) debía ganarle al cálculo automático.'
        );
    }

    /**
     * 🔴 Una versión legacy con un código que NO cumple el regex nuevo (por ejemplo "3.3",
     * cargada antes de que existiera esta validación) tiene que poder seguir editándose en
     * el resto de sus campos mientras el código no se toque. Si el código sí cambia, el
     * regex vuelve a aplicarse.
     *
     * @return void
     */
    public function test_edicion_json_de_una_version_legacy_no_bloquea_si_el_codigo_no_cambia(): void
    {
        $this->autenticar_api();

        // Se inserta salteando el controlador: así se simula la fila legacy que ya está
        // en la base y que ninguna validación de hoy podría haber creado.
        $version          = new Version();
        $version->uuid    = (string) Str::uuid();
        $version->version = '3.3';
        $version->title   = 'Versión legacy';
        $version->status  = 'draft';
        $version->save();

        $response = $this->putJson('/api/admin/version/' . $version->id, [
            'version' => '3.3',
            'title'   => 'Título corregido',
        ]);

        $response->assertStatus(200);
        $this->assertSame('Título corregido', $version->fresh()->title);
        $this->assertSame('3.3', $version->fresh()->version);

        // Pero cambiar el código a otro inválido sigue rechazándose.
        $rechazo = $this->putJson('/api/admin/version/' . $version->id, [
            'version' => '3.4',
        ]);

        $rechazo->assertStatus(422);
        $rechazo->assertJsonValidationErrors('version');
        $this->assertSame('3.3', $version->fresh()->version);
    }

    /**
     * Mismo caso legacy en el camino web (`PUT versions/{id}`).
     *
     * @return void
     */
    public function test_edicion_web_de_una_version_legacy_no_bloquea_si_el_codigo_no_cambia(): void
    {
        $admin = $this->crear_admin_web();

        $version          = new Version();
        $version->uuid    = (string) Str::uuid();
        $version->version = '4.2';
        $version->title   = 'Versión legacy web';
        $version->status  = 'draft';
        $version->save();

        $response = $this->actingAs($admin)->put('versions/' . $version->id, [
            'version' => '4.2',
            'title'   => 'Título corregido',
            'status'  => 'draft',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame('Título corregido', $version->fresh()->title);
        $this->assertSame('4.2', $version->fresh()->version);
    }
}

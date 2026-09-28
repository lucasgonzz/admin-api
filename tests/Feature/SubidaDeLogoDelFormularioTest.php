<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Implementation;
use App\Models\ImplementationStage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Endpoint público de subida del logo del negocio en el formulario de configuración
 * (misión logo-negocio-en-user-setup, 28/9/2026): POST /form/implementation/{token}/logo.
 *
 * No existía suite de Feature para ImplementationFormController antes de esta misión (se
 * confirmó con `ls tests/Feature/` antes de escribir este archivo, tal como pide la Fase 8
 * de mision-directa) — así que este test también deja sentado el patrón mínimo para crear
 * una Implementation + ImplementationStage de prueba, por si alguien suma más casos después.
 *
 * Lo que importa, en orden:
 * 1. Token inválido → 404, mismo criterio que show()/save()/submit().
 * 2. Formulario ya enviado → 422: no se puede seguir editando después de form_submitted_at.
 * 3. Archivo que no es imagen → 422, con mensaje claro.
 * 4. Archivo válido → 200 con logo_url, y esa URL queda persistida en
 *    implementation_stages.data.form_responses.logo_url (lo que después lee
 *    ImplementationFormMapper::build_setup_data()).
 */
class SubidaDeLogoDelFormularioTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 🔴 Sin este fake, la suite escribiría logos de prueba en storage/app/public real.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    /**
     * Cliente mínimo para colgarle una implementación. No hay `database/factories/` en este
     * repo (mismo criterio que el resto de la suite de Feature).
     *
     * @return Client
     */
    private function crear_cliente(): Client
    {
        $client               = new Client();
        $client->name         = 'Ferretería de prueba';
        $client->company_name = 'Ferretería de prueba S.R.L.';
        $client->is_active    = true;
        $client->save();

        return $client;
    }

    /**
     * Implementación con su stage 1 ya creado (donde viven form_responses), lista para
     * recibir el formulario público.
     *
     * @param string $token           Token público del formulario.
     * @param bool   $ya_enviado      Si el formulario ya fue enviado (form_submitted_at seteado).
     *
     * @return Implementation
     */
    private function crear_implementacion(string $token = 'token-de-prueba-1234', bool $ya_enviado = false): Implementation
    {
        $client = $this->crear_cliente();

        $implementation                    = new Implementation();
        $implementation->client_id         = $client->id;
        $implementation->current_stage     = 1;
        $implementation->status            = 'in_progress';
        $implementation->automation_mode   = 'manual';
        $implementation->form_token        = $token;
        $implementation->form_submitted_at = $ya_enviado ? now() : null;
        $implementation->save();

        $stage                    = new ImplementationStage();
        $stage->implementation_id = $implementation->id;
        $stage->stage_number      = 1;
        $stage->status            = 'in_progress';
        $stage->data              = [];
        $stage->save();

        return $implementation->fresh();
    }

    /**
     * Ruta del endpoint para un token dado.
     *
     * @param string $token
     *
     * @return string
     */
    private function ruta(string $token): string
    {
        return '/api/form/implementation/' . $token . '/logo';
    }

    /**
     * Un token que no existe en ninguna implementación → 404, igual que show()/save()/submit().
     *
     * @return void
     */
    public function test_un_token_invalido_da_404(): void
    {
        $response = $this->postJson($this->ruta('token-que-no-existe'), [
            'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
        ]);

        $response->assertStatus(404);
    }

    /**
     * Si el formulario ya fue enviado no se puede seguir editando: subir un logo después
     * también es un 422, mismo criterio que save() y submit().
     *
     * @return void
     */
    public function test_el_formulario_ya_enviado_da_422(): void
    {
        $implementation = $this->crear_implementacion('token-ya-enviado', true);

        $response = $this->postJson($this->ruta($implementation->form_token), [
            'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
        ]);

        $response->assertStatus(422);
    }

    /**
     * Un archivo que no es imagen (un PDF, por ejemplo) se rechaza con 422 y un mensaje claro,
     * y no se llega a guardar nada en form_responses.
     *
     * @return void
     */
    public function test_un_archivo_no_imagen_da_422(): void
    {
        $implementation = $this->crear_implementacion('token-archivo-invalido');

        $response = $this->postJson($this->ruta($implementation->form_token), [
            'logo' => UploadedFile::fake()->create('no-es-imagen.pdf', 100, 'application/pdf'),
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('imagen', (string) $response->json('message'));

        $stage = ImplementationStage::where('implementation_id', $implementation->id)
            ->where('stage_number', 1)
            ->first();

        $this->assertArrayNotHasKey('logo_url', (array) ($stage->data['form_responses'] ?? []));
    }

    /**
     * El camino feliz: una imagen válida se guarda en el disco `public`, el endpoint devuelve
     * su URL pública, y esa misma URL queda en form_responses.logo_url — de ahí la lee después
     * ImplementationFormMapper::build_setup_data() para mandarla a empresa-api.
     *
     * @return void
     */
    public function test_un_archivo_valido_da_200_y_logo_url_queda_en_form_responses(): void
    {
        $implementation = $this->crear_implementacion('token-logo-valido');

        $response = $this->postJson($this->ruta($implementation->form_token), [
            'logo' => UploadedFile::fake()->image('logo.png', 300, 300),
        ]);

        $response->assertStatus(200);

        $logo_url = $response->json('logo_url');

        $this->assertNotEmpty($logo_url);
        $this->assertStringContainsString('/storage/implementation_logos/' . $implementation->id . '/', $logo_url);

        // Ancla contra la regresión del chequeo de contrato: la URL tiene que salir de
        // AdminApiPublicUrl::base(), no de Storage::disk('public')->url() a secas (esa arma
        // APP_URL + /storage sin pasar por /public en el shared hosting — 404 silencioso en
        // producción, ver el comentario del controller). Si algún día vuelve a usarse
        // Storage::url() acá, este assert es el que tiene que fallar primero.
        $base_esperada = \App\Helpers\AdminApiPublicUrl::base();
        $this->assertStringStartsWith($base_esperada . '/storage/', $logo_url);

        $stage = ImplementationStage::where('implementation_id', $implementation->id)
            ->where('stage_number', 1)
            ->first();

        $this->assertSame($logo_url, $stage->data['form_responses']['logo_url'] ?? null);

        // El archivo efectivamente quedó en el disco (fake), no solo la URL en la respuesta.
        // Se descarta todo hasta '/storage/' (inclusive) en vez de anclar al principio de la
        // cadena, porque AdminApiPublicUrl::base() puede devolver '' (sin APP_URL configurada)
        // o una URL absoluta con o sin /public — las tres formas terminan en '.../storage/<path>'.
        $stored_path = preg_replace('#^.*?/storage/#', '', $logo_url);
        Storage::disk('public')->assertExists($stored_path);
    }
}

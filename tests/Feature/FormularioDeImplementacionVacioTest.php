<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Implementation;
use App\Models\ImplementationStage;
use App\Models\Lead;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * GET /api/form/implementation/{token} con el formulario todavía sin responder
 * (misión form-implementacion-500, 13/10/2026).
 *
 * En producción el link del formulario de Los Peña (implementación 8) daba 500:
 * ImplementationFormController::read_form_responses() está tipada `: array` y devolvía un
 * stdClass cuando no había respuestas, así que PHP tiraba TypeError. Pasaba siempre que el
 * stage 1 tuviera `data` sin `form_responses`: `{"actions": [...]}` (lo que escribe
 * POST claude/implementations/{id}/actions al registrar el WhatsApp con el link, o sea toda
 * implementación llevada por /implementar) o `[]`. Ningún test le pegaba a show().
 *
 * Lo que importa:
 * 1. Sin respuestas, el GET da 200 y `form_data` llega como objeto vacío ({}), que es lo que
 *    espera el SPA — con data con actions, con data vacía y con data null.
 * 2. La precarga del mail del lead sigue andando sobre un stage con actions.
 * 3. Las respuestas guardadas se devuelven tal cual.
 * 4. El autoguardado sobre un stage con actions guarda en form_responses y no borra las actions.
 */
class FormularioDeImplementacionVacioTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Lo que deja POST claude/implementations/{id}/actions en el stage 1 al registrar el
     * WhatsApp del link (forma medida en producción: la única clave es `actions`).
     *
     * @var array<string, mixed>
     */
    private const DATA_CON_ACCIONES = [
        'actions' => [
            ['accion' => 'form_link', 'canal' => 'whatsapp_web', 'at' => '2026-10-13T21:29:47Z'],
            ['accion' => 'presentacion', 'canal' => 'otro', 'at' => '2026-10-13T21:29:48Z'],
        ],
    ];

    /**
     * Cliente mínimo para colgarle una implementación (no hay factories en este repo).
     *
     * @return Client
     */
    private function crear_cliente(): Client
    {
        $client               = new Client();
        $client->name         = 'Alina de prueba';
        $client->company_name = 'Distribuidora de prueba';
        $client->is_active    = true;
        $client->save();

        return $client;
    }

    /**
     * Implementación en la etapa 1, sin formulario enviado, con el `data` del stage 1 que se pida.
     *
     * @param string     $token Token público del formulario.
     * @param array|null $data  Contenido de implementation_stages.data del stage 1.
     *
     * @return Implementation
     */
    private function crear_implementacion(string $token, ?array $data): Implementation
    {
        $client = $this->crear_cliente();

        $implementation                    = new Implementation();
        $implementation->client_id         = $client->id;
        $implementation->current_stage     = 1;
        $implementation->status            = 'in_progress';
        $implementation->automation_mode   = 'manual';
        $implementation->form_token        = $token;
        $implementation->form_submitted_at = null;
        $implementation->save();

        $stage                    = new ImplementationStage();
        $stage->implementation_id = $implementation->id;
        $stage->stage_number      = 1;
        $stage->status            = 'in_progress';
        $stage->data              = $data;
        $stage->save();

        return $implementation->fresh();
    }

    /**
     * `form_data` de la respuesta decodificado SIN pasar a array, para distinguir {} de [].
     *
     * @param \Illuminate\Testing\TestResponse $response
     *
     * @return mixed
     */
    private function form_data_crudo($response)
    {
        return json_decode($response->getContent())->form_data;
    }

    /**
     * El caso de producción: stage 1 con las actions de Claude y sin respuestas → 200 con {}.
     *
     * @return void
     */
    public function test_con_acciones_registradas_y_sin_respuestas_abre_con_form_data_vacio(): void
    {
        $this->crear_implementacion('token-form-vacio-acciones', self::DATA_CON_ACCIONES);

        $response = $this->getJson('/api/form/implementation/token-form-vacio-acciones');

        $response->assertStatus(200);
        $response->assertJson(['submitted' => false, 'client_name' => 'Distribuidora de prueba']);
        $this->assertInstanceOf(\stdClass::class, $this->form_data_crudo($response));
        $this->assertSame([], (array) $this->form_data_crudo($response));
    }

    /**
     * Stage 1 con data vacía ([]): también caía en el stdClass y daba 500.
     *
     * @return void
     */
    public function test_con_data_vacia_abre_con_form_data_vacio(): void
    {
        $this->crear_implementacion('token-form-vacio-array', []);

        $response = $this->getJson('/api/form/implementation/token-form-vacio-array');

        $response->assertStatus(200);
        $this->assertInstanceOf(\stdClass::class, $this->form_data_crudo($response));
        $this->assertSame([], (array) $this->form_data_crudo($response));
    }

    /**
     * Stage 1 con data null: ya andaba, pero devolvía [] en vez de {}. Ahora es {} como los demás.
     *
     * @return void
     */
    public function test_con_data_null_abre_con_form_data_vacio(): void
    {
        $this->crear_implementacion('token-form-vacio-null', null);

        $response = $this->getJson('/api/form/implementation/token-form-vacio-null');

        $response->assertStatus(200);
        $this->assertInstanceOf(\stdClass::class, $this->form_data_crudo($response));
    }

    /**
     * Con el mail en el lead del que salió el cliente, el formulario lo trae precargado aunque el
     * stage tenga actions y ninguna respuesta.
     *
     * @return void
     */
    public function test_precarga_el_mail_del_lead_con_acciones_registradas(): void
    {
        $implementation = $this->crear_implementacion('token-form-vacio-mail', self::DATA_CON_ACCIONES);

        $lead                     = new Lead();
        $lead->contact_name       = 'Alina';
        $lead->company_name       = 'Distribuidora de prueba';
        $lead->phone              = '+5491155550040';
        $lead->email              = 'alina.prueba@test.local';
        $lead->status             = 'cliente';
        $lead->promoted_client_id = $implementation->client_id;
        $lead->save();

        $response = $this->getJson('/api/form/implementation/token-form-vacio-mail');

        $response->assertStatus(200);
        $response->assertJsonPath('form_data.email', 'alina.prueba@test.local');
    }

    /**
     * Con respuestas ya autoguardadas se devuelven tal cual (y las actions no se cuelan).
     *
     * @return void
     */
    public function test_devuelve_las_respuestas_guardadas(): void
    {
        $data                   = self::DATA_CON_ACCIONES;
        $data['form_responses'] = ['company_name' => 'Los de prueba', 'price_mode' => 'single'];
        $this->crear_implementacion('token-form-con-respuestas', $data);

        $response = $this->getJson('/api/form/implementation/token-form-con-respuestas');

        $response->assertStatus(200);
        $response->assertJsonPath('form_data.company_name', 'Los de prueba');
        $response->assertJsonPath('form_data.price_mode', 'single');
        $this->assertArrayNotHasKey('actions', $response->json('form_data'));
    }

    /**
     * El autoguardado sobre un stage con actions guarda las respuestas en form_responses y
     * conserva las actions (el registro del panel).
     *
     * @return void
     */
    public function test_el_autoguardado_conserva_las_acciones(): void
    {
        $implementation = $this->crear_implementacion('token-form-autoguardado', self::DATA_CON_ACCIONES);

        $response = $this->patchJson('/api/form/implementation/token-form-autoguardado', [
            'fields' => ['company_name' => 'Los de prueba'],
        ]);

        $response->assertStatus(200);

        $data = ImplementationStage::where('implementation_id', $implementation->id)
            ->where('stage_number', 1)
            ->first()
            ->data;

        // assertEquals y no assertSame: la columna JSON de MySQL reordena las claves de cada acción.
        $this->assertEquals(self::DATA_CON_ACCIONES['actions'], $data['actions']);
        $this->assertSame(['company_name' => 'Los de prueba'], $data['form_responses']);

        $this->getJson('/api/form/implementation/token-form-autoguardado')
            ->assertStatus(200)
            ->assertJsonPath('form_data.company_name', 'Los de prueba');
    }
}

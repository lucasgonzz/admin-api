<?php

namespace Tests\Feature\ClaudeImplementaciones;

use App\Models\Admin;
use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\Implementation;
use App\Models\ImplementationMessage;
use App\Models\ImplementationStage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El botón "Iniciar implementación" del PANEL (`POST admin/client/{id}/implementation/start`).
 *
 * 🔴 Este test existe por la misión `implementar-cliente` (5/10/2026): la lógica del `start` se
 * extrajo del controlador a `ImplementationStartService` para que la use también
 * `POST claude/implementations`. La regla de esa extracción es que **el panel no cambia de
 * comportamiento**, y hasta entonces no había NINGÚN test que lo fijara. Se escribió ANTES de mover
 * una sola línea y se corrió en verde contra el código original: lo que afirma es lo que el botón
 * hace hoy, no lo que debería hacer.
 *
 * Lo que fija, en orden de importancia:
 *  1. La implementación nace `in_progress`, en la etapa 1, con sus OCHO etapas (la 1 en curso y las
 *     otras siete pendientes) y con su `form_token`.
 *  2. El admin asignado sale del setting global y el modo de automatización es `manual` salvo que el
 *     setting diga `auto` (cualquier otro valor cae a manual).
 *  3. Un cliente que ya tiene implementación recibe 422 con el mensaje de siempre y no se crea otra.
 *  4. En modo `auto` —y solo ahí— sale la plantilla de bienvenida; en `manual` no se manda nada.
 *  5. La forma de la respuesta: 201 con `model` (la implementación con `stages` y `client`).
 */
class InicioDeImplementacionDelPanelTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * El memo de admin_settings es estático y sobrevive al rollback: se vacía antes y después.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        AdminSetting::whereIn('key', ['implementation_assigned_admin_id', 'implementation_automation_mode'])->delete();
        AdminSetting::flush_memo();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        AdminSetting::flush_memo();

        parent::tearDown();
    }

    /**
     * Admin autenticado del panel.
     *
     * @return Admin
     */
    private function crear_admin(): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Lucas';
        $admin->email    = 'lucas+' . Str::random(10) . '@comerciocity.com';
        $admin->password = bcrypt('secret');
        $admin->save();

        return $admin;
    }

    /**
     * Cliente sin implementación.
     *
     * @return Client
     */
    private function crear_cliente(): Client
    {
        $client               = new Client();
        $client->name         = 'Panchito Gómez';
        $client->company_name = 'Panchito S.A.';
        $client->slug         = 'panchito-' . Str::random(6);
        $client->phone        = '+5493415550000';
        $client->is_active    = true;
        $client->save();

        return $client->refresh();
    }

    /**
     * El botón del panel: pega a la ruta real, con el admin logueado.
     *
     * @param Client $client
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function iniciar(Client $client)
    {
        return $this->actingAs($this->crear_admin(), 'sanctum')
            ->postJson('/api/admin/client/' . $client->id . '/implementation/start');
    }

    /**
     * 1. La implementación nace en la etapa 1, en curso, con sus ocho etapas y su token.
     *
     * @return void
     */
    public function test_el_panel_crea_la_implementacion_con_sus_ocho_etapas_y_su_token(): void
    {
        $client = $this->crear_cliente();

        $respuesta = $this->iniciar($client);

        $respuesta->assertStatus(201);
        $respuesta->assertJsonPath('model.client_id', (int) $client->id);
        $respuesta->assertJsonPath('model.status', 'in_progress');
        $respuesta->assertJsonPath('model.current_stage', 1);
        $respuesta->assertJsonPath('model.automation_mode', 'manual');

        /* La respuesta trae la implementación con sus etapas y el cliente. */
        $this->assertCount(8, $respuesta->json('model.stages'));
        $this->assertSame((int) $client->id, (int) $respuesta->json('model.client.id'));

        $implementation = Implementation::where('client_id', $client->id)->first();
        $this->assertNotNull($implementation);
        $this->assertNotNull($implementation->started_at);
        $this->assertNull($implementation->completed_at);

        /* El token es un UUID v4 y es el mismo que viaja en la respuesta. */
        $this->assertSame(36, strlen((string) $implementation->form_token));
        $this->assertSame($implementation->form_token, $respuesta->json('model.form_token'));

        /* Las ocho etapas: la 1 en curso con su cronómetro, las otras siete pendientes. */
        $etapas = ImplementationStage::where('implementation_id', $implementation->id)->orderBy('stage_number')->get();
        $this->assertCount(8, $etapas);

        $this->assertSame('in_progress', $etapas[0]->status);
        $this->assertNotNull($etapas[0]->started_at);

        for ($i = 1; $i < 8; $i++) {
            $this->assertSame($i + 1, (int) $etapas[$i]->stage_number);
            $this->assertSame('pending', $etapas[$i]->status);
            $this->assertNull($etapas[$i]->started_at);
        }
    }

    /**
     * 2. El admin asignado sale del setting global; sin setting queda en null.
     *
     * @return void
     */
    public function test_el_admin_asignado_sale_del_setting_global(): void
    {
        $sin_setting = $this->crear_cliente();
        $this->iniciar($sin_setting)->assertStatus(201);
        $this->assertNull(Implementation::where('client_id', $sin_setting->id)->value('assigned_admin_id'));

        $admin = $this->crear_admin();
        AdminSetting::set('implementation_assigned_admin_id', (string) $admin->id);

        $con_setting = $this->crear_cliente();
        $this->iniciar($con_setting)->assertStatus(201);
        $this->assertSame((int) $admin->id, (int) Implementation::where('client_id', $con_setting->id)->value('assigned_admin_id'));
    }

    /**
     * 2 bis. El modo de automatización: `auto` solo si el setting lo dice; cualquier otro valor cae
     * a `manual`.
     *
     * @return void
     */
    public function test_el_modo_de_automatizacion_sale_del_setting_y_cualquier_otro_valor_cae_a_manual(): void
    {
        AdminSetting::set('implementation_automation_mode', 'cualquier-cosa');
        $a_manual = $this->crear_cliente();
        $this->iniciar($a_manual)->assertStatus(201)->assertJsonPath('model.automation_mode', 'manual');

        AdminSetting::set('implementation_automation_mode', 'auto');
        $auto = $this->crear_cliente();
        $this->iniciar($auto)->assertStatus(201)->assertJsonPath('model.automation_mode', 'auto');
    }

    /**
     * 3. Un cliente con implementación no puede iniciar otra: 422 con el mensaje de siempre.
     *
     * @return void
     */
    public function test_un_cliente_con_implementacion_recibe_422_y_no_se_crea_otra(): void
    {
        $client = $this->crear_cliente();
        $this->iniciar($client)->assertStatus(201);

        $this->iniciar($client)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Este cliente ya tiene una implementación iniciada.');

        $this->assertSame(1, Implementation::where('client_id', $client->id)->count());
    }

    /**
     * 4. En modo manual no sale ningún mensaje: la presentación la manda una persona desde el panel.
     *
     * @return void
     */
    public function test_en_modo_manual_no_se_manda_la_plantilla_de_bienvenida(): void
    {
        $client = $this->crear_cliente();

        $this->iniciar($client)->assertStatus(201);

        $implementation = Implementation::where('client_id', $client->id)->first();
        $this->assertSame(0, ImplementationMessage::where('implementation_id', $implementation->id)->count());
    }

    /**
     * 4 bis. En modo `auto` sí se intenta la plantilla de bienvenida (y deja su fila en el hilo).
     *
     * El envío real no sale: en la base de testing no hay configuración de WhatsApp activa, así que
     * `send_template` devuelve null. Lo que se afirma es que el camino se recorrió: la fila saliente
     * de la etapa 1 con el teléfono del cliente.
     *
     * @return void
     */
    public function test_en_modo_auto_se_intenta_la_plantilla_de_bienvenida(): void
    {
        AdminSetting::set('implementation_automation_mode', 'auto');
        $client = $this->crear_cliente();

        $this->iniciar($client)->assertStatus(201);

        $implementation = Implementation::where('client_id', $client->id)->first();
        $saliente       = ImplementationMessage::where('implementation_id', $implementation->id)
            ->where('direction', 'outbound')
            ->first();

        $this->assertNotNull($saliente, 'En modo auto el start tenía que dejar la presentación en el hilo.');
        $this->assertSame(1, (int) $saliente->stage_number);
        $this->assertSame('+5493415550000', $saliente->phone);
    }

    /**
     * 5. Sin sesión de admin el panel contesta 401: la ruta sigue detrás de auth:sanctum.
     *
     * @return void
     */
    public function test_sin_sesion_el_panel_contesta_401(): void
    {
        $client = $this->crear_cliente();

        $this->postJson('/api/admin/client/' . $client->id . '/implementation/start')->assertStatus(401);

        $this->assertSame(0, Implementation::where('client_id', $client->id)->count());
    }
}

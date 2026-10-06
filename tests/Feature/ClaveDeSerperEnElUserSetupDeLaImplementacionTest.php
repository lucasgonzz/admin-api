<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\ClientApi;
use App\Models\ClientInstallation;
use App\Models\Implementation;
use App\Services\ImplementationUserSetupService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * La clave de Serper en el UserSetup de la Etapa 3 de la implementación (agregado a la misión
 * serper-en-user-setup, 28/9/2026).
 *
 * Además del botón de user-setup del lead (RunUserSetupService), un cliente nace por el flujo de
 * implementación: ImplementationUserSetupService arma el payload y lo manda a
 * admin-sync/user-setup. Ese mismo payload lo muestra el panel en el preview de la acción
 * 'user_setup'.
 *
 * Lo que estas pruebas protegen, en orden de importancia:
 *
 *  1. 🔴 **Que el preview del panel NO muestre la clave en claro**, y que taparla ahí NO cambie lo
 *     que viaja: el POST a empresa-api lleva la clave entera.
 *  2. **Que la clave viaje cuando está cargada**, y que sin clave el campo no viaje (ni vacío ni en
 *     null).
 *  3. **Que la clave salga solo de la configuración del admin**: setup_data se desparrama entero en
 *     el payload, y una serper_api_key que viniera ahí adentro no puede viajar.
 *
 * El memo de admin_settings es estático y sobrevive al rollback de la transacción, así que se
 * vacía antes y después de cada test.
 */
class ClaveDeSerperEnElUserSetupDeLaImplementacionTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Clave de clientes de ejemplo: 40 caracteres hexadecimales, la forma de las claves de Serper.
     */
    const CLAVE_CLIENTES = '0123456789abcdef0123456789abcdef01234567';

    /**
     * Clave de demos de ejemplo, distinta de la de clientes para poder distinguir cuál viajó.
     */
    const CLAVE_DEMOS = 'fedcba9876543210fedcba9876543210fedcba98';

    /**
     * Lo que muestra el preview del panel en lugar de CLAVE_CLIENTES.
     */
    const CLAVE_CLIENTES_TAPADA = 'cargada, termina en 4567';

    /**
     * URL de la empresa-api del cliente de prueba (destino del POST del UserSetup).
     */
    const URL_API_DEL_CLIENTE = 'https://api-comercio-serper.test';

    /**
     * Arranca sin ninguna clave de Serper cargada, sea cual sea el estado de la base del slot.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Borrado masivo: no dispara los eventos del modelo, por eso el flush va explícito después.
        AdminSetting::whereIn('key', [
            'implementation_serper_api_key_default',
            'implementation_serper_api_key_demo',
        ])->delete();

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

    // ─────────────────────────────────────────────────────────────────────────
    // El payload
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Con la clave de clientes cargada, el payload de la Etapa 3 la lleva en `serper_api_key`.
     *
     * @return void
     */
    public function test_el_payload_lleva_la_clave_de_clientes_si_esta_cargada(): void
    {
        AdminSetting::set('implementation_serper_api_key_default', self::CLAVE_CLIENTES);

        $payload = (new ImplementationUserSetupService())->build_payload($this->crear_cliente());

        $this->assertArrayHasKey('serper_api_key', $payload, 'El UserSetup de la implementación no manda la clave de Serper.');
        $this->assertSame(self::CLAVE_CLIENTES, $payload['serper_api_key']);
    }

    /**
     * Sin ninguna clave cargada, el campo no viaja: el sistema usa la SERPER_API_KEY de su .env.
     *
     * @return void
     */
    public function test_sin_clave_el_payload_no_lleva_el_campo(): void
    {
        $payload = (new ImplementationUserSetupService())->build_payload($this->crear_cliente());

        $this->assertArrayNotHasKey('serper_api_key', $payload);
    }

    /**
     * La clave de demos no llega a un cliente real por este camino, aunque sea la única cargada.
     *
     * @return void
     */
    public function test_la_clave_de_demos_no_viaja_por_este_camino(): void
    {
        AdminSetting::set('implementation_serper_api_key_demo', self::CLAVE_DEMOS);

        $payload = (new ImplementationUserSetupService())->build_payload($this->crear_cliente());

        $this->assertArrayNotHasKey('serper_api_key', $payload);
    }

    /**
     * 🔴 Una serper_api_key metida en setup_data no viaja: la única fuente es la configuración del
     * admin. Sin clave cargada el campo se descarta; con clave, gana la del admin.
     *
     * setup_data se desparrama entero en el payload y se mezcla sobre lo que el cliente ya tuviera,
     * así que sin el descarte una clave que nadie validó terminaría en la base del cliente.
     *
     * @return void
     */
    public function test_una_serper_api_key_en_setup_data_no_viaja(): void
    {
        $colada = str_repeat('9', 40);
        $client = $this->crear_cliente(['serper_api_key' => $colada]);

        $sin_clave_en_el_admin = (new ImplementationUserSetupService())->build_payload($client);
        $this->assertArrayNotHasKey('serper_api_key', $sin_clave_en_el_admin, 'Viajó la serper_api_key que venía en setup_data.');

        AdminSetting::set('implementation_serper_api_key_default', self::CLAVE_CLIENTES);

        $con_clave_en_el_admin = (new ImplementationUserSetupService())->build_payload($client);
        $this->assertSame(self::CLAVE_CLIENTES, $con_clave_en_el_admin['serper_api_key'], 'La de setup_data le ganó a la del admin.');
    }

    /**
     * Aditivo: sumar la clave agrega `serper_api_key` y nada más. El resto del payload queda con el
     * mismo nombre, el mismo valor y el mismo orden.
     *
     * @return void
     */
    public function test_sumar_la_clave_no_cambia_ningun_otro_campo_del_payload(): void
    {
        $client = $this->crear_cliente();

        $sin = (new ImplementationUserSetupService())->build_payload($client);

        AdminSetting::set('implementation_serper_api_key_default', self::CLAVE_CLIENTES);

        $con = (new ImplementationUserSetupService())->build_payload($client);

        $this->assertSame(self::CLAVE_CLIENTES, $con['serper_api_key']);

        unset($con['serper_api_key']);
        $this->assertSame($sin, $con, 'Sumar la clave de Serper cambió otro campo del payload de la implementación.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // El preview del panel
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * 🔴 El preview de la acción 'user_setup' muestra la clave tapada ("cargada, termina en XXXX") y
     * la clave entera no aparece en ningún lado de la respuesta.
     *
     * Y se tapa SOLO la clave: el resto del body es exactamente el payload real, que es para lo que
     * existe el preview.
     *
     * @return void
     */
    public function test_el_preview_del_panel_muestra_la_clave_tapada(): void
    {
        AdminSetting::set('implementation_serper_api_key_default', self::CLAVE_CLIENTES);

        $implementation = $this->crear_implementacion();

        $respuesta = $this->pedir_el_preview($implementation);

        $this->assertStringNotContainsString(
            self::CLAVE_CLIENTES,
            $respuesta->getContent(),
            'El preview del panel muestra la clave de Serper en claro.'
        );

        $body = json_decode((string) $respuesta->json('body'), true);
        $this->assertIsArray($body, 'El body del preview no es el payload en JSON.');
        $this->assertSame(self::CLAVE_CLIENTES_TAPADA, $body['serper_api_key']);

        // Lo único distinto del payload real es la clave tapada.
        $real = (new ImplementationUserSetupService())->build_payload($implementation->client()->first());
        $real['serper_api_key'] = self::CLAVE_CLIENTES_TAPADA;
        $this->assertSame($real, $body, 'El preview cambió algo más que la clave.');
    }

    /**
     * Sin clave cargada, el preview no muestra el campo (no inventa un "cargada").
     *
     * @return void
     */
    public function test_sin_clave_el_preview_no_muestra_el_campo(): void
    {
        $respuesta = $this->pedir_el_preview($this->crear_implementacion());

        $body = json_decode((string) $respuesta->json('body'), true);
        $this->assertIsArray($body);
        $this->assertArrayNotHasKey('serper_api_key', $body);
    }

    /**
     * Una clave tan corta que sus cuatro finales serían buena parte de ella se muestra solo como
     * "cargada". Por el PUT no puede entrar una así (32 caracteres como mínimo), pero admin_settings
     * se puede escribir por otros caminos y el preview no puede depender de eso.
     *
     * @return void
     */
    public function test_una_clave_corta_se_muestra_solo_como_cargada(): void
    {
        AdminSetting::set('implementation_serper_api_key_default', 'abc123');

        $respuesta = $this->pedir_el_preview($this->crear_implementacion());

        $this->assertStringNotContainsString('abc123', $respuesta->getContent());

        $body = json_decode((string) $respuesta->json('body'), true);
        $this->assertSame('cargada', $body['serper_api_key']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Lo que viaja de verdad
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * 🔴 Taparla en el preview no cambia lo que viaja: el botón del panel ejecuta el UserSetup y el
     * POST a empresa-api lleva la clave ENTERA. En la misma prueba, el preview de esa implementación
     * la muestra tapada, y la respuesta del botón tampoco la trae en claro.
     *
     * Entra por la ruta real del panel (POST .../actions/user_setup), con el gate cumplido:
     * formulario enviado, Etapa 2 e instalación completada.
     *
     * @return void
     */
    public function test_el_boton_del_panel_manda_la_clave_entera_aunque_el_preview_la_tape(): void
    {
        AdminSetting::set('implementation_serper_api_key_default', self::CLAVE_CLIENTES);

        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $implementation = $this->crear_implementacion([], true);

        $body_del_preview = json_decode((string) $this->pedir_el_preview($implementation)->json('body'), true);
        $this->assertSame(self::CLAVE_CLIENTES_TAPADA, $body_del_preview['serper_api_key']);

        $respuesta = $this->actingAs($this->crear_admin(), 'sanctum')
            ->postJson('/api/admin/implementation/' . $implementation->id . '/actions/user_setup')
            ->assertStatus(200);

        $this->assertStringNotContainsString(
            self::CLAVE_CLIENTES,
            $respuesta->getContent(),
            'La respuesta del botón del panel trae la clave de Serper en claro.'
        );

        // La API de este cliente es de shared hosting (ver crear_cliente): vive bajo /public, igual que en todos
        // los demás llamados del admin a un cliente (ClientEmpresaApiUrlResolver).
        $enviado = $this->cuerpo_del_post(self::URL_API_DEL_CLIENTE . '/public/api/admin-sync/user-setup');

        $this->assertSame(self::CLAVE_CLIENTES, $enviado['serper_api_key'], 'Al cliente no le llegó la clave entera.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

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
     * Cliente con su empresa-api activa y el setup_data que deja el formulario de la Etapa 1.
     *
     * El email va en setup_data para que el armado no tenga que buscar un lead promovido.
     *
     * @param array<string, mixed> $setup_data_extra Claves a sumar o pisar en setup_data.
     *
     * @return Client
     */
    private function crear_cliente(array $setup_data_extra = []): Client
    {
        $client               = new Client();
        $client->name         = 'Comercio Serper';
        $client->company_name = 'Comercio Serper S.R.L.';
        $client->phone        = '+5493410000000';
        $client->is_active    = true;
        $client->user_id      = 12300;
        $client->setup_data   = array_merge([
            'company_name'    => 'Comercio Serper S.R.L.',
            'email'           => 'serper@test.local',
            'doc_number'      => '20304050607',
            'use_price_lists' => true,
            'price_lists'     => "Minorista\nMayorista",
            'use_deposits'    => false,
            'deposit_names'   => '',
            'iva_included'    => true,
        ], $setup_data_extra);
        $client->save();

        $api               = new ClientApi();
        $api->client_id    = $client->id;
        $api->url          = self::URL_API_DEL_CLIENTE;
        $api->path         = 'comercio-serper/api';
        $api->hosting_type = 'shared_hosting';
        $api->save();

        $client->active_client_api_id = $api->id;
        $client->save();

        return $client->refresh();
    }

    /**
     * Implementación de un cliente nuevo.
     *
     * @param array<string, mixed> $setup_data_extra Claves a sumar o pisar en setup_data.
     * @param bool                 $con_gate_cumplido true = formulario enviado, Etapa 2 e
     *                                                instalación completada (lo que exige el botón).
     *
     * @return Implementation
     */
    private function crear_implementacion(array $setup_data_extra = [], bool $con_gate_cumplido = false): Implementation
    {
        $client = $this->crear_cliente($setup_data_extra);

        $implementation            = new Implementation();
        $implementation->client_id = $client->id;

        if ($con_gate_cumplido) {
            $implementation->form_submitted_at = now();
            $implementation->current_stage     = 2;

            $installation            = new ClientInstallation();
            $installation->client_id = $client->id;
            $installation->status    = 'completada';
            $installation->save();
        }

        $implementation->save();

        return $implementation->refresh();
    }

    /**
     * Pide el preview de la acción 'user_setup' por la ruta real del panel.
     *
     * @param Implementation $implementation
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function pedir_el_preview(Implementation $implementation)
    {
        return $this->actingAs($this->crear_admin(), 'sanctum')
            ->getJson('/api/admin/implementation/' . $implementation->id . '/actions/user_setup/preview')
            ->assertStatus(200)
            ->assertJsonPath('action', 'user_setup');
    }

    /**
     * Cuerpo del POST que se mandó a una URL. Si no salió ninguno, el test falla acá, con un
     * mensaje que lo dice.
     *
     * @param string $url URL completa del destino.
     *
     * @return array<string, mixed>
     */
    private function cuerpo_del_post(string $url): array
    {
        $cuerpo = null;

        Http::assertSent(function ($request) use ($url, &$cuerpo) {
            if ($request->method() !== 'POST' || $request->url() !== $url) {
                return false;
            }

            $cuerpo = $request->data();

            return true;
        });

        $this->assertIsArray($cuerpo, "No salió ningún POST a {$url}.");

        return $cuerpo;
    }
}

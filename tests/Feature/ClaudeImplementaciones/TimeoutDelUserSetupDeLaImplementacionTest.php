<?php

namespace Tests\Feature\ClaudeImplementaciones;

use App\Models\Admin;
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
 * El techo de la llamada HTTP del user setup de la implementación (misión `implementar-cliente`,
 * 5/10/2026).
 *
 * `ImplementationUserSetupService::trigger_user_setup()` ganó un segundo parámetro, OPCIONAL, con el
 * techo en segundos. Lo usa el job de `POST claude/implementations/{id}/user-setup` (1200 s, porque
 * del otro lado el setup arranca con `migrate:fresh` y tarda minutos); el panel sigue llamando sin
 * él. Lo que se protege acá, en orden de importancia:
 *
 *  1. 🔴 Que el panel NO cambie: sin el parámetro, el techo es el de siempre
 *     (`services.client_api.timeout`).
 *  2. Que con el parámetro el techo sea ese, y que un valor que no sirve (cero, negativo) no deje la
 *     llamada sin límite: cae al de siempre.
 *  3. Que lo anterior valga para la llamada HTTP de verdad y no solo para el método que resuelve el
 *     número: se mira la opción `timeout` que recibe el cliente HTTP.
 */
class TimeoutDelUserSetupDeLaImplementacionTest extends TestCase
{
    use DatabaseTransactions;

    /** URL de la empresa-api del cliente de prueba. */
    const URL_API_DEL_CLIENTE = 'https://api-timeout.test';

    /**
     * Techo de los demás tests: el del `.env` del slot no está definido, así que se fija acá.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.client_api.timeout' => 15]);
    }

    /* ------------------------------------------------------------------------------------------
     | El número
     |----------------------------------------------------------------------------------------- */

    /**
     * Sin parámetro: el techo de siempre, el de la config.
     *
     * @return void
     */
    public function test_sin_parametro_el_techo_es_el_de_la_config(): void
    {
        $servicio = new ImplementationUserSetupService();

        $this->assertSame(15, $servicio->resolver_timeout());
        $this->assertSame(15, $servicio->resolver_timeout(null));

        config(['services.client_api.timeout' => 40]);
        $this->assertSame(40, $servicio->resolver_timeout());
    }

    /**
     * Con parámetro: ese número, aunque la config diga otra cosa.
     *
     * @return void
     */
    public function test_con_parametro_el_techo_es_ese(): void
    {
        $servicio = new ImplementationUserSetupService();

        $this->assertSame(600, $servicio->resolver_timeout(600));

        config(['services.client_api.timeout' => 900]);
        $this->assertSame(600, $servicio->resolver_timeout(600));
    }

    /**
     * Cero y negativos no sirven: un techo de 0 es "sin límite" para Guzzle, y eso es lo último que
     * se quiere si el llamador se equivoca. Caen al de siempre.
     *
     * @return void
     */
    public function test_un_techo_que_no_sirve_cae_al_de_siempre(): void
    {
        $servicio = new ImplementationUserSetupService();

        $this->assertSame(15, $servicio->resolver_timeout(0));
        $this->assertSame(15, $servicio->resolver_timeout(-30));
    }

    /* ------------------------------------------------------------------------------------------
     | La llamada de verdad
     |----------------------------------------------------------------------------------------- */

    /**
     * Sin parámetro, el POST a empresa-api sale con el techo de la config.
     *
     * @return void
     */
    public function test_sin_parametro_el_post_sale_con_el_techo_de_la_config(): void
    {
        $techos = $this->falsear_la_api_y_registrar_el_techo();

        $resultado = (new ImplementationUserSetupService())->trigger_user_setup($this->crear_implementacion());

        $this->assertTrue($resultado['ok']);
        $this->assertSame([15], $techos->techos);
    }

    /**
     * Con parámetro, el POST sale con ese techo.
     *
     * @return void
     */
    public function test_con_parametro_el_post_sale_con_ese_techo(): void
    {
        $techos = $this->falsear_la_api_y_registrar_el_techo();

        $resultado = (new ImplementationUserSetupService())->trigger_user_setup($this->crear_implementacion(), 600);

        $this->assertTrue($resultado['ok']);
        $this->assertSame([600], $techos->techos);
    }

    /**
     * 🔴 El botón del panel no pasa el parámetro: sale con el techo de siempre.
     *
     * Entra por la ruta real del panel (POST .../actions/user_setup) con el gate cumplido.
     *
     * @return void
     */
    public function test_el_boton_del_panel_sigue_saliendo_con_el_techo_de_la_config(): void
    {
        $techos = $this->falsear_la_api_y_registrar_el_techo();

        $implementation = $this->crear_implementacion(true);

        $this->actingAs($this->crear_admin(), 'sanctum')
            ->postJson('/api/admin/implementation/' . $implementation->id . '/actions/user_setup')
            ->assertStatus(200);

        $this->assertSame([15], $techos->techos);
    }

    /* ------------------------------------------------------------------------------------------
     | Helpers
     |----------------------------------------------------------------------------------------- */

    /**
     * Fakea cualquier llamada HTTP con un 200 y anota el techo (`timeout`) con el que salió cada una.
     *
     * Devuelve un objeto con la lista porque el closure la llena después de que este método
     * devuelve: un array por valor quedaría vacío.
     *
     * @return \stdClass Con `techos`: array<int, mixed>.
     */
    private function falsear_la_api_y_registrar_el_techo(): \stdClass
    {
        $registro         = new \stdClass();
        $registro->techos = [];

        Http::fake(function ($request, $opciones) use ($registro) {
            $registro->techos[] = isset($opciones['timeout']) ? $opciones['timeout'] : null;

            return Http::response(['ok' => true], 200);
        });

        return $registro;
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
     * Implementación de un cliente con su empresa-api activa.
     *
     * @param bool $con_gate_cumplido true = formulario enviado, Etapa 2 e instalación completada
     *                                (lo que exige el botón del panel).
     *
     * @return Implementation
     */
    private function crear_implementacion(bool $con_gate_cumplido = false): Implementation
    {
        $client               = new Client();
        $client->name         = 'Comercio Timeout';
        $client->company_name = 'Comercio Timeout S.R.L.';
        $client->phone        = '+5493410000001';
        $client->is_active    = true;
        $client->user_id      = 12400;
        $client->setup_data   = ['company_name' => 'Comercio Timeout S.R.L.', 'email' => 'timeout@test.local', 'doc_number' => '20304050607'];
        $client->save();

        $api               = new ClientApi();
        $api->client_id    = $client->id;
        $api->url          = self::URL_API_DEL_CLIENTE;
        $api->path         = 'comercio-timeout/api';
        $api->hosting_type = 'shared_hosting';
        $api->save();

        $client->active_client_api_id = $api->id;
        $client->save();

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
}

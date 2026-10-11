<?php

namespace Tests\Feature\CerrarAdminSync;

use App\Models\Client;
use App\Models\ClientApi;
use App\Models\Lead;
use App\Services\ImplementationUserSetupService;
use App\Services\RunUserSetupService;
use Illuminate\Support\Facades\Http;
use Tests\Feature\PuertasDelUserSetup\BaseDeLasPuertasDelUserSetup;
use Tests\Fakes\HttpFactorySinSalida;

/**
 * El user setup manda `X-Admin-Api-Key` (misión `cerrar-admin-sync`, 10/10/2026, tarea A1).
 *
 * 🔴 El `admin.setup.key` del empresa-api exige la clave del cliente en ese header cuando la instancia tiene prendido
 * `ADMIN_SYNC_SETUP_REQUIRE_API_KEY`. Los dos llamadores del admin —el botón "Crear sistema" de LEADS (`RunUserSetupService`) y
 * las implementaciones (`ImplementationUserSetupService`)— no mandaban ningún header propio. Lo que estos tests fijan:
 *
 *  1. Con `clients.api_key` cargada, el pedido lleva el header con ese valor (y en el user setup de leads, `Accept` sigue).
 *  2. Sin clave (vacía o solo espacios), el pedido sale EXACTAMENTE como siempre: sin el header.
 *  3. Lo demás no cambia: el payload es el mismo y el 409 sigue siendo `sin_confirmar` en leads.
 *
 * Todo con un `Http::fake()` que anota los headers; el `HttpFactorySinSalida` garantiza que ningún pedido sale a internet.
 */
class ElUserSetupMandaLaClaveDelClienteTest extends BaseDeLasPuertasDelUserSetup
{
    /** El nombre del header. */
    const HEADER = 'X-Admin-Api-Key';

    /* ------------------------------------------------------------------------------------------
     | Escenarios
     |----------------------------------------------------------------------------------------- */

    /**
     * Falsea al sistema del cliente anotando, de cada pedido, la URL, los headers y el cuerpo.
     *
     * @param int $status Status con el que contesta.
     *
     * @return \stdClass Con `pedidos`: lista de `['url', 'tiene_header', 'clave', 'accept', 'cuerpo']`.
     */
    private function sistema_que_anota_los_headers(int $status = 200): \stdClass
    {
        $registro          = new \stdClass();
        $registro->pedidos = [];

        Http::swap(new HttpFactorySinSalida());

        Http::fake(function ($pedido) use ($registro, $status) {
            $registro->pedidos[] = [
                'url'          => $pedido->url(),
                'tiene_header' => $pedido->hasHeader(self::HEADER),
                'clave'        => $pedido->header(self::HEADER),
                'accept'       => $pedido->header('Accept'),
                'cuerpo'       => $pedido->data(),
            ];

            return Http::response(['ok' => $status < 400], $status);
        });

        return $registro;
    }

    /**
     * Un lead cerrado ganado, promovido a un cliente con su API activa en una URL `.test`.
     *
     * @param string|null $api_key Clave del cliente; null deja la que arma el constructor.
     *
     * @return array{lead: Lead, cliente: Client}
     */
    private function lead_listo(?string $api_key = null): array
    {
        $cliente = $this->crear_cliente('Panchito Gómez', ['company_name' => self::NEGOCIO, 'is_active' => true]);
        $cliente = $this->crear_las_dos_apis($cliente, 'panchito');

        ClientApi::where('id', $cliente->active_client_api_id)->update(['url' => self::URL_API]);

        if ($api_key !== null) {
            $cliente->api_key = $api_key;
            $cliente->save();
        }

        $lead = $this->crear_lead([
            'status'             => 'cerrado_ganado',
            'promoted_client_id' => $cliente->id,
            'user_setup_status'  => 'pendiente',
        ]);

        return ['lead' => $lead, 'cliente' => $cliente->refresh()];
    }

    /* ------------------------------------------------------------------------------------------
     | Leads: RunUserSetupService
     |----------------------------------------------------------------------------------------- */

    /**
     * Con clave: el pedido lleva `X-Admin-Api-Key` con el valor de `clients.api_key`, y el `Accept` de siempre.
     *
     * @return void
     */
    public function test_leads_con_clave_el_pedido_lleva_el_header(): void
    {
        $sistema = $this->sistema_que_anota_los_headers();
        $e       = $this->lead_listo('clave-del-cliente-de-prueba-0123456789');

        app(RunUserSetupService::class)->run($e['lead']);

        $this->assertCount(1, $sistema->pedidos);
        $this->assertStringEndsWith('/api/admin-sync/user-setup', $sistema->pedidos[0]['url']);
        $this->assertTrue($sistema->pedidos[0]['tiene_header']);
        $this->assertSame(['clave-del-cliente-de-prueba-0123456789'], $sistema->pedidos[0]['clave']);
        $this->assertSame(['application/json'], $sistema->pedidos[0]['accept']);
        $this->assertSame('exitoso', $e['lead']->refresh()->user_setup_status);
    }

    /**
     * Sin clave —vacía o solo espacios—: el pedido sale como siempre, sin el header.
     *
     * @return void
     */
    public function test_leads_sin_clave_el_pedido_sale_sin_el_header(): void
    {
        foreach (['', '   '] as $vacia) {
            $sistema = $this->sistema_que_anota_los_headers();
            $e       = $this->lead_listo($vacia);

            app(RunUserSetupService::class)->run($e['lead']);

            $this->assertCount(1, $sistema->pedidos, 'api_key="' . $vacia . '"');
            $this->assertFalse($sistema->pedidos[0]['tiene_header'], 'api_key="' . $vacia . '"');
            $this->assertSame(['application/json'], $sistema->pedidos[0]['accept']);
            $this->assertSame('exitoso', $e['lead']->refresh()->user_setup_status);
        }
    }

    /**
     * El payload no cambia por mandar el header: la clave viaja SOLO en el header, no en el cuerpo del setup por esta vía (el cuerpo ya
     * lleva `api_key` del cliente como dato del setup, y eso no se toca).
     *
     * @return void
     */
    public function test_leads_el_payload_es_el_mismo_con_y_sin_clave(): void
    {
        $con = $this->sistema_que_anota_los_headers();
        $e1  = $this->lead_listo('clave-del-cliente-de-prueba-0123456789');
        app(RunUserSetupService::class)->run($e1['lead']);

        $sin = $this->sistema_que_anota_los_headers();
        $e2  = $this->lead_listo('');
        app(RunUserSetupService::class)->run($e2['lead']);

        $this->assertSame(array_keys($con->pedidos[0]['cuerpo']), array_keys($sin->pedidos[0]['cuerpo']));
    }

    /**
     * El 409 de la instancia ("ya hay un setup corriendo") sigue siendo `sin_confirmar`, con y sin clave.
     *
     * @return void
     */
    public function test_leads_el_409_sigue_siendo_sin_confirmar(): void
    {
        foreach (['clave-del-cliente-de-prueba-0123456789', ''] as $clave) {
            $this->sistema_que_anota_los_headers(409);
            $e = $this->lead_listo($clave);

            app(RunUserSetupService::class)->run($e['lead']);

            $this->assertSame('sin_confirmar', $e['lead']->refresh()->user_setup_status, 'api_key="' . $clave . '"');
        }
    }

    /* ------------------------------------------------------------------------------------------
     | Implementaciones: ImplementationUserSetupService
     |----------------------------------------------------------------------------------------- */

    /**
     * Con clave: el pedido lleva `X-Admin-Api-Key` con el valor de `clients.api_key`.
     *
     * @return void
     */
    public function test_implementaciones_con_clave_el_pedido_lleva_el_header(): void
    {
        $sistema = $this->sistema_que_anota_los_headers();
        $e       = $this->escenario(['cliente' => ['api_key' => 'clave-del-cliente-de-prueba-0123456789']]);

        $resultado = (new ImplementationUserSetupService())->trigger_user_setup($e['implementacion']);

        $this->assertTrue($resultado['ok'], $resultado['message']);
        $this->assertCount(1, $sistema->pedidos);
        $this->assertStringEndsWith('/api/admin-sync/user-setup', $sistema->pedidos[0]['url']);
        $this->assertTrue($sistema->pedidos[0]['tiene_header']);
        $this->assertSame(['clave-del-cliente-de-prueba-0123456789'], $sistema->pedidos[0]['clave']);
    }

    /**
     * Sin clave —vacía o solo espacios—: el pedido sale como siempre, sin el header.
     *
     * @return void
     */
    public function test_implementaciones_sin_clave_el_pedido_sale_sin_el_header(): void
    {
        foreach (['', '   '] as $vacia) {
            $sistema = $this->sistema_que_anota_los_headers();
            $e       = $this->escenario(['cliente' => ['api_key' => $vacia]]);

            $resultado = (new ImplementationUserSetupService())->trigger_user_setup($e['implementacion']);

            $this->assertTrue($resultado['ok'], $resultado['message']);
            $this->assertCount(1, $sistema->pedidos, 'api_key="' . $vacia . '"');
            $this->assertFalse($sistema->pedidos[0]['tiene_header'], 'api_key="' . $vacia . '"');
        }
    }

    /**
     * Un error del cliente (409 / 500) se informa igual que antes: `ok = false` con el status en el mensaje; el header no cambia el manejo.
     *
     * @return void
     */
    public function test_implementaciones_un_error_del_cliente_se_informa_igual(): void
    {
        $this->sistema_que_anota_los_headers(409);
        $e = $this->escenario(['cliente' => ['api_key' => 'clave-del-cliente-de-prueba-0123456789']]);

        $resultado = (new ImplementationUserSetupService())->trigger_user_setup($e['implementacion']);

        $this->assertFalse($resultado['ok']);
        $this->assertStringContainsString('status 409', $resultado['message']);
    }
}

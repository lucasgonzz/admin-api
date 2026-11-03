<?php

namespace Tests\Feature\Cobranzas;

use App\Models\ClientEcommerce;
use Illuminate\Support\Facades\Http;

/**
 * «Traer datos del cliente» de la mensualidad y el check «Tiene e-commerce» (Ferre Total y Masquito,
 * 3/11/2026): el conteo vivo del empresa-api sale de la propiedad `online` del dueño, que puede estar
 * vacía aunque la tienda exista, y el botón desmarcaba el check. La ficha del cliente en admin (su
 * tienda con la URL cargada) es la otra fuente: alcanza con una de las dos.
 */
class TraerDelClienteEcommerceDeLaFichaTest extends BaseDeCobranzas
{
    /** Cliente con API configurada y, si se pide, tienda cargada en la ficha. */
    private function cliente(array $tienda = null)
    {
        $client = $this->crear_cliente([
            'api_url' => 'https://api-ecommerce-ficha.test',
            'api_key' => 'clave-de-prueba',
        ]);

        if ($tienda !== null) {
            ClientEcommerce::create(array_merge(['client_id' => $client->id], $tienda));
        }

        return $client;
    }

    /** Responde el empresa-api con el conteo de e-commerce indicado. */
    private function responder_con_conteo(int $ecommerce): void
    {
        $this->fakear_http([
            '*/api/admin-sync/mensualidad-info*' => Http::response([
                'conteos' => ['empleados' => 2, 'ecommerce' => $ecommerce, 'mercado_libre' => 0, 'tienda_nube' => 0],
            ], 200),
        ]);
    }

    public function test_con_la_tienda_en_la_ficha_el_check_queda_aunque_el_cliente_informe_cero(): void
    {
        $this->admin_logueado();
        $client = $this->cliente(['spa_url' => 'https://tienda-ficha.test']);
        $this->responder_con_conteo(0);

        $response = $this->postJson('/api/admin/client/' . $client->id . '/mensualidad/traer-del-cliente');

        $response->assertStatus(200);
        $this->assertTrue($response->json('tiene_ecommerce'));
    }

    public function test_con_conteo_vivo_y_sin_ficha_el_check_sigue_marcado(): void
    {
        $this->admin_logueado();
        $client = $this->cliente();
        $this->responder_con_conteo(1);

        $response = $this->postJson('/api/admin/client/' . $client->id . '/mensualidad/traer-del-cliente');

        $this->assertTrue($response->json('tiene_ecommerce'));
    }

    public function test_el_check_ya_guardado_no_se_apaga_con_conteo_cero_y_sin_ficha(): void
    {
        $this->admin_logueado();
        $client = $this->cliente();
        $client->tiene_ecommerce = true;
        $client->save();
        $this->responder_con_conteo(0);

        $response = $this->postJson('/api/admin/client/' . $client->id . '/mensualidad/traer-del-cliente');

        $this->assertTrue($response->json('tiene_ecommerce'));
    }

    public function test_sin_conteo_y_sin_ficha_el_check_queda_apagado(): void
    {
        $this->admin_logueado();
        $client = $this->cliente();
        $this->responder_con_conteo(0);

        $response = $this->postJson('/api/admin/client/' . $client->id . '/mensualidad/traer-del-cliente');

        $this->assertFalse($response->json('tiene_ecommerce'));
    }

    public function test_una_ficha_de_tienda_sin_ninguna_url_no_cuenta(): void
    {
        $this->admin_logueado();
        $client = $this->cliente(['domain' => 'tienda-sin-url.test']);
        $this->responder_con_conteo(0);

        $response = $this->postJson('/api/admin/client/' . $client->id . '/mensualidad/traer-del-cliente');

        $this->assertFalse($response->json('tiene_ecommerce'));
    }
}

<?php

namespace Tests\Feature\Cobranzas;

/**
 * El link público y durable del PDF de una Factura C (pedido 10, misión cobranzas-mejoras,
 * 18/9/2026): el botón "Enviar por WhatsApp" necesita un link que se pueda volver a abrir "desde
 * cualquier dispositivo, en cualquier momento" — a diferencia de `MensualidadInvoicePdfAccessToken`
 * (de un solo uso, vive 2 minutos), que sigue existiendo intacto y no se toca acá.
 *
 * Lo que estas pruebas protegen:
 *  1. Que `factura_link_whatsapp_json` sea idempotente (el mismo botón, apretado dos veces, manda
 *     el mismo link) y rechace una factura sin CAE.
 *  2. 🔴 Que `factura_pdf_publico` sirva el PDF sin autenticación y NO sea de un solo uso — el
 *     punto central que lo distingue del mecanismo viejo (`pdf-view`).
 *  3. Que un token que no corresponde a esa factura/cliente dé 404 liso (nunca 403, nunca un
 *     mensaje que confirme "existe pero...").
 *
 * Los tests que piden el PDF de verdad (2 y 3) ejercitan `MensualidadFacturaPdf` completo, que
 * intenta traer el QR de AFIP por una URL real (`api.qrserver.com`, no pasa por el facade `Http` y
 * por eso `Http::fake()` no lo tapa) — si esa consulta falla, el código ya la tolera sin romper
 * (`url_exists()` devuelve false y el QR simplemente no se embebe), así que el PDF se sigue
 * sirviendo con 200 aunque no haya red.
 */
class LinkPublicoDeFacturaTest extends BaseDeCobranzas
{
    /**
     * 1a. Sobre una factura autorizada: devuelve un token, y pedirlo de nuevo da el MISMO token.
     *
     * @return void
     */
    public function test_el_link_es_idempotente(): void
    {
        $this->admin_logueado();
        $client = $this->crear_cliente();
        $factura = $this->sembrar_factura($client, '2026-09');

        $primera = $this->postJson('/api/admin/client/' . $client->id . '/factura/' . $factura->id . '/link-whatsapp');
        $primera->assertStatus(200);
        $token = $primera->json('token');
        $this->assertNotEmpty($token);

        $segunda = $this->postJson('/api/admin/client/' . $client->id . '/factura/' . $factura->id . '/link-whatsapp');
        $segunda->assertStatus(200);
        $this->assertSame($token, $segunda->json('token'), 'El mismo botón, apretado dos veces, manda el mismo link.');

        $factura->refresh();
        $this->assertSame($token, $factura->public_token);
    }

    /**
     * 1b. Sobre una factura sin CAE (rechazada): 422, sin generar token.
     *
     * @return void
     */
    public function test_sin_cae_no_se_genera_link(): void
    {
        $this->admin_logueado();
        $client = $this->crear_cliente();
        $factura = $this->sembrar_factura($client, '2026-09', false);

        $response = $this->postJson('/api/admin/client/' . $client->id . '/factura/' . $factura->id . '/link-whatsapp');
        $response->assertStatus(422);

        $factura->refresh();
        $this->assertNull($factura->public_token);
    }

    /**
     * 2. `factura_pdf_publico` sirve el PDF sin autenticación, y NO es de un solo uso: pedirlo dos
     * veces seguidas da 200 las dos veces (a diferencia de `pdf-view`, que a la segunda daría 403
     * porque el token ya se marcó usado).
     *
     * @return void
     */
    public function test_el_pdf_publico_no_es_de_un_solo_uso(): void
    {
        // Sin admin_logueado(): esta ruta es pública a propósito, tiene que andar sin sesión.
        $client = $this->crear_cliente();
        $factura = $this->sembrar_factura($client, '2026-09');
        $factura->public_token = bin2hex(random_bytes(32));
        $factura->save();

        $url = '/api/client/' . $client->id . '/factura/' . $factura->id . '/pdf-publico/' . $factura->public_token;

        $primera = $this->get($url);
        $primera->assertStatus(200);
        $primera->assertHeader('Content-Type', 'application/pdf');

        $segunda = $this->get($url);
        $segunda->assertStatus(200, 'A diferencia de pdf-view (un solo uso), este link se puede volver a abrir.');
    }

    /**
     * 3. Un token que no corresponde a esa factura/cliente da 404 liso.
     *
     * @return void
     */
    public function test_un_token_que_no_corresponde_da_404(): void
    {
        $client = $this->crear_cliente();
        $factura = $this->sembrar_factura($client, '2026-09');
        $factura->public_token = bin2hex(random_bytes(32));
        $factura->save();

        $otro_cliente = $this->crear_cliente();

        // Token que no existe.
        $this->get('/api/client/' . $client->id . '/factura/' . $factura->id . '/pdf-publico/token-que-no-existe')
            ->assertStatus(404);

        // Token correcto, pero cliente equivocado.
        $this->get('/api/client/' . $otro_cliente->id . '/factura/' . $factura->id . '/pdf-publico/' . $factura->public_token)
            ->assertStatus(404);
    }
}

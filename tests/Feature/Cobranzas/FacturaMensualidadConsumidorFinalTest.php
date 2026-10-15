<?php

namespace Tests\Feature\Cobranzas;

use App\Http\Controllers\Pdf\MensualidadFacturaPdf;
use App\Models\Client;
use App\Models\ComerciocityAfipConfig;
use App\Models\MensualidadInvoice;
use App\Services\Afip\AfipFacturacionService;

/**
 * Factura C de la mensualidad a Consumidor Final, sin CUIT (misión mensualidad-consumidor-final,
 * 13/10/2026).
 *
 * El pedido: poder facturar la mensualidad de un cliente SIN indicar su CUIT. Se resuelve con un
 * tilde por cliente (`clients.facturar_consumidor_final`): con el tilde, la Factura C sale con
 * DocTipo 99 / DocNro 0 / CondicionIVAReceptorId 5 y no se mira ni el CUIT ni la condición IVA que
 * el cliente tenga cargados.
 *
 * Nada sale a la red: lo que se prueba es la decisión del receptor (`resolver_receptor()`), los
 * cortes previos a ARCA de `emitir()`, el guardado y la lectura del tilde por HTTP (con el mismo
 * `Http::fake()` comodín de `BaseDeCobranzas`), la fila de la tabla de cobranzas, la respuesta del
 * servicio y el PDF (con la descarga del QR anulada, que es lo único que en ese camino pegaría
 * a internet).
 */
class FacturaMensualidadConsumidorFinalTest extends BaseDeCobranzas
{
    /**
     * Deja la config fiscal del emisor con CUIT y punto de venta, para que `emitir()` llegue a
     * mirar el receptor (antes de eso corta por falta de config).
     *
     * @return ComerciocityAfipConfig
     */
    protected function config_fiscal()
    {
        $config = ComerciocityAfipConfig::current();
        $config->cuit = '20423548984';
        $config->punto_venta = 1;
        $config->afip_produccion = false;
        $config->save();

        return $config->fresh();
    }

    // ------------------------------------------------------------------------------------------
    // (a) El receptor con el tilde
    // ------------------------------------------------------------------------------------------

    public function test_con_el_tilde_el_receptor_es_consumidor_final_aunque_haya_cuit_y_condicion()
    {
        $client = $this->crear_cliente([
            'facturar_consumidor_final' => true,
            // Un CUIT válido y una condición que daría otro id si se miraran: el tilde manda.
            'afip_cuit' => '30-71886362-3',
            'afip_condicion_iva' => 'Responsable inscripto',
        ]);

        $receptor = (new AfipFacturacionService())->resolver_receptor($client);

        $this->assertNull($receptor['error']);
        $this->assertSame(99, $receptor['doc_tipo']);
        $this->assertSame('0', $receptor['doc_nro']);
        $this->assertNull($receptor['cuit_cliente']);
        $this->assertSame(5, $receptor['condicion_iva_id']);
        $this->assertTrue($receptor['consumidor_final']);
    }

    public function test_con_el_tilde_el_receptor_no_pide_nada_del_cliente()
    {
        // Sin CUIT, sin condición, con un CUIT que no tendría 11 dígitos: nada de eso importa.
        $client = $this->crear_cliente(['facturar_consumidor_final' => true, 'afip_cuit' => '123']);

        $receptor = (new AfipFacturacionService())->resolver_receptor($client);

        $this->assertNull($receptor['error']);
        $this->assertSame(99, $receptor['doc_tipo']);
    }

    // ------------------------------------------------------------------------------------------
    // (b) Sin el tilde, el camino de hoy
    // ------------------------------------------------------------------------------------------

    public function test_sin_el_tilde_el_receptor_sigue_siendo_el_cuit()
    {
        $client = $this->crear_cliente(['afip_cuit' => '30-71886362-3', 'afip_condicion_iva' => 'Responsable inscripto']);

        $receptor = (new AfipFacturacionService())->resolver_receptor($client);

        $this->assertNull($receptor['error']);
        $this->assertSame(80, $receptor['doc_tipo']);
        $this->assertSame('30718863623', $receptor['doc_nro']);
        $this->assertSame('30718863623', $receptor['cuit_cliente']);
        $this->assertSame(1, $receptor['condicion_iva_id']);
        $this->assertFalse($receptor['consumidor_final']);
    }

    public function test_sin_el_tilde_un_cuit_vacio_corta_con_el_mensaje_de_siempre()
    {
        $client = $this->crear_cliente(['afip_cuit' => null]);

        $receptor = (new AfipFacturacionService())->resolver_receptor($client);

        $this->assertSame('Cargá el CUIT fiscal del cliente antes de facturar.', $receptor['error']);
        $this->assertFalse($receptor['consumidor_final']);
    }

    public function test_sin_el_tilde_un_cuit_de_diez_digitos_corta_con_el_mensaje_de_siempre()
    {
        $client = $this->crear_cliente(['afip_cuit' => '30-7188636-3']);

        $receptor = (new AfipFacturacionService())->resolver_receptor($client);

        $this->assertSame('El CUIT fiscal del cliente tiene 10 dígitos y un CUIT tiene 11. Revisalo antes de facturar.', $receptor['error']);
    }

    public function test_emitir_sin_el_tilde_corta_por_el_cuit_antes_que_por_el_total()
    {
        // El orden de los cortes previos no cambió: config del emisor → receptor → total.
        $this->config_fiscal();
        $client = $this->crear_cliente(['afip_cuit' => null, 'total_mensualidad' => 0]);

        $resultado = (new AfipFacturacionService())->emitir($client, '2026-10');

        $this->assertFalse($resultado['ok']);
        $this->assertStringContainsString('Cargá el CUIT fiscal', $resultado['error_message']);
        $this->assertArrayNotHasKey('consumidor_final', $resultado, 'Un corte previo no es una factura: no marca nada.');
        $this->assertSame(0, MensualidadInvoice::where('client_id', $client->id)->count());
    }

    // ------------------------------------------------------------------------------------------
    // (c) Con el tilde y sin CUIT no corta por el CUIT
    // ------------------------------------------------------------------------------------------

    public function test_con_el_tilde_y_sin_cuit_el_corte_es_el_del_total_y_no_el_del_cuit()
    {
        $this->config_fiscal();
        $client = $this->crear_cliente(['facturar_consumidor_final' => true, 'afip_cuit' => null, 'total_mensualidad' => 0]);

        $resultado = (new AfipFacturacionService())->emitir($client, '2026-10');

        $this->assertFalse($resultado['ok']);
        $this->assertStringNotContainsString('CUIT', $resultado['error_message']);
        $this->assertStringContainsString('El total de la mensualidad debe ser mayor a 0', $resultado['error_message']);
        $this->assertSame(0, MensualidadInvoice::where('client_id', $client->id)->count());
    }

    public function test_con_el_tilde_la_falta_de_config_del_emisor_sigue_cortando_primero()
    {
        // Sin CUIT ni punto de venta del emisor (la fila nace vacía): ese corte va antes que el receptor.
        $config = ComerciocityAfipConfig::current();
        $config->cuit = null;
        $config->punto_venta = null;
        $config->save();

        $client = $this->crear_cliente(['facturar_consumidor_final' => true, 'total_mensualidad' => 12000]);

        $resultado = (new AfipFacturacionService())->emitir($client, '2026-10');

        $this->assertFalse($resultado['ok']);
        $this->assertStringContainsString('Falta configurar el CUIT o el punto de venta de ComercioCity', $resultado['error_message']);
    }

    // ------------------------------------------------------------------------------------------
    // (d) Guardado y estado
    // ------------------------------------------------------------------------------------------

    /**
     * Payload mínimo del PUT de mensualidad (lo que ya mandaba el SPA antes del tilde).
     *
     * @return array<string, mixed>
     */
    protected function payload_base()
    {
        return ['precio_plan' => 10000, 'precio_por_cuenta' => 1000, 'cantidad_empleados' => 2];
    }

    public function test_el_put_con_el_tilde_lo_guarda_y_el_estado_lo_devuelve()
    {
        $this->admin_logueado();
        $client = $this->crear_cliente();
        $this->assertFalse((bool) $client->facturar_consumidor_final, 'Por defecto el tilde está apagado.');

        $response = $this->putJson('/api/admin/client/'.$client->id.'/mensualidad', array_merge($this->payload_base(), [
            'facturar_consumidor_final' => true,
        ]));

        $response->assertStatus(200);
        $this->assertTrue($response->json('facturar_consumidor_final'));
        $this->assertTrue((bool) Client::find($client->id)->facturar_consumidor_final);

        // Y el GET del estado también lo trae.
        $estado = $this->getJson('/api/admin/client/'.$client->id.'/mensualidad');
        $estado->assertStatus(200);
        $this->assertTrue($estado->json('facturar_consumidor_final'));
    }

    public function test_el_put_sin_la_clave_no_toca_el_tilde()
    {
        // Compatibilidad hacia atrás: un SPA anterior no manda la clave y no puede apagar el tilde.
        $this->admin_logueado();
        $client = $this->crear_cliente(['facturar_consumidor_final' => true]);

        $response = $this->putJson('/api/admin/client/'.$client->id.'/mensualidad', $this->payload_base());

        $response->assertStatus(200);
        $this->assertTrue($response->json('facturar_consumidor_final'));
        $this->assertTrue((bool) Client::find($client->id)->facturar_consumidor_final);
    }

    public function test_el_put_con_la_clave_en_null_tampoco_toca_el_tilde()
    {
        $this->admin_logueado();
        $client = $this->crear_cliente(['facturar_consumidor_final' => true]);

        $response = $this->putJson('/api/admin/client/'.$client->id.'/mensualidad', array_merge($this->payload_base(), [
            'facturar_consumidor_final' => null,
        ]));

        $response->assertStatus(200);
        $this->assertTrue((bool) Client::find($client->id)->facturar_consumidor_final);
    }

    public function test_el_put_con_la_clave_en_false_apaga_el_tilde()
    {
        $this->admin_logueado();
        $client = $this->crear_cliente(['facturar_consumidor_final' => true]);

        $response = $this->putJson('/api/admin/client/'.$client->id.'/mensualidad', array_merge($this->payload_base(), [
            'facturar_consumidor_final' => false,
        ]));

        $response->assertStatus(200);
        $this->assertFalse($response->json('facturar_consumidor_final'));
        $this->assertFalse((bool) Client::find($client->id)->facturar_consumidor_final);
    }

    public function test_el_put_con_un_valor_que_no_es_booleano_se_rechaza()
    {
        $this->admin_logueado();
        $client = $this->crear_cliente();

        $response = $this->putJson('/api/admin/client/'.$client->id.'/mensualidad', array_merge($this->payload_base(), [
            'facturar_consumidor_final' => 'quizás',
        ]));

        $response->assertStatus(422);
    }

    public function test_guardar_el_tilde_no_borra_los_datos_fiscales_del_cliente()
    {
        // El CUIT y la condición quedan cargados por si el tilde se destilda.
        $this->admin_logueado();
        $client = $this->crear_cliente(['afip_cuit' => '30718863623', 'afip_condicion_iva' => 'Monotributista']);

        $this->putJson('/api/admin/client/'.$client->id.'/mensualidad', array_merge($this->payload_base(), [
            'facturar_consumidor_final' => true,
        ]))->assertStatus(200);

        $fresco = Client::find($client->id);
        $this->assertSame('30718863623', $fresco->afip_cuit);
        $this->assertSame('Monotributista', $fresco->afip_condicion_iva);
    }

    // ------------------------------------------------------------------------------------------
    // (e) La fila de la tabla de cobranzas
    // ------------------------------------------------------------------------------------------

    public function test_la_fila_de_cobranzas_expone_el_tilde()
    {
        $this->admin_logueado();
        $con_tilde = $this->crear_cliente(['facturar_consumidor_final' => true]);
        $sin_tilde = $this->crear_cliente();

        $response = $this->getJson('/api/admin/cobranzas/mensualidades?meses=2026-09');
        $response->assertStatus(200);

        $por_id = [];
        foreach ($response->json('clientes') as $fila) {
            $por_id[(int) $fila['id']] = $fila;
        }

        $this->assertArrayHasKey($con_tilde->id, $por_id);
        $this->assertArrayHasKey($sin_tilde->id, $por_id);
        $this->assertTrue($por_id[$con_tilde->id]['facturar_consumidor_final']);
        $this->assertFalse($por_id[$sin_tilde->id]['facturar_consumidor_final']);
    }

    // ------------------------------------------------------------------------------------------
    // (f) La respuesta del servicio
    // ------------------------------------------------------------------------------------------

    public function test_la_respuesta_marca_consumidor_final_segun_el_doc_tipo_de_la_factura()
    {
        $servicio = new AfipFacturacionService();

        $cf = new MensualidadInvoice(['doc_tipo' => 99, 'doc_nro' => '0', 'resultado' => 'A', 'cae' => '71234567890123']);
        $con_cuit = new MensualidadInvoice(['doc_tipo' => 80, 'doc_nro' => '30718863623', 'resultado' => 'A', 'cae' => '71234567890123']);
        $vieja = new MensualidadInvoice(['resultado' => 'A', 'cae' => '71234567890123']);

        $this->assertTrue($this->respuesta($servicio, $cf)['consumidor_final']);
        $this->assertFalse($this->respuesta($servicio, $con_cuit)['consumidor_final']);
        $this->assertFalse($this->respuesta($servicio, $vieja)['consumidor_final'], 'Una factura anterior a las columnas de documento es con CUIT.');
    }

    /**
     * `respuesta()` es protected: se llama por reflexión, igual que `aviso_de_homologacion()` en
     * `FacturaMensualidadAmbienteTest`.
     *
     * @param  AfipFacturacionService $servicio
     * @param  MensualidadInvoice     $invoice
     * @return array
     */
    protected function respuesta(AfipFacturacionService $servicio, MensualidadInvoice $invoice)
    {
        $metodo = new \ReflectionMethod(AfipFacturacionService::class, 'respuesta');
        $metodo->setAccessible(true);

        return $metodo->invoke($servicio, $invoice, false);
    }

    // ------------------------------------------------------------------------------------------
    // (g) El PDF
    // ------------------------------------------------------------------------------------------

    /**
     * Arma una factura persistida (con su cliente) y la imprime con el PDF real. La descarga del
     * QR (`url_exists()`) se anula y se anota la URL que se habría pedido: ahí viaja el JSON del QR
     * de AFIP, que es lo que se quiere inspeccionar.
     *
     * @param  array<string, mixed> $atributos_invoice Pisan los de la factura de prueba.
     * @param  array<string, mixed> $atributos_client  Pisan los del cliente de prueba.
     * @return array{pdf: string, qr: array<string, mixed>|null}
     */
    protected function imprimir($atributos_invoice, $atributos_client = [])
    {
        $config = $this->config_fiscal();
        $client = $this->crear_cliente(array_merge([
            'afip_cuit' => '27111111118',
            'afip_condicion_iva' => 'Responsable inscripto',
            'afip_razon_social' => 'RAZON SOCIAL DEL CLIENTE SA',
            'afip_domicilio' => 'Calle Falsa 123, Rosario',
        ], $atributos_client));

        $invoice = MensualidadInvoice::create(array_merge([
            'client_id' => $client->id,
            'periodo' => '2026-09',
            'cbte_tipo' => 11,
            'cbte_letra' => 'C',
            'cbte_numero' => 321,
            'punto_venta' => 1,
            'cuit_negocio' => $config->cuit,
            'importe_total' => 12000,
            'imp_neto' => 12000,
            'imp_iva' => 0,
            'cae' => '71234567890123',
            'cae_expired_at' => '2026-10-20',
            'resultado' => 'A',
            'afip_produccion' => false,
        ], $atributos_invoice))->load('client');

        $pdf = new class($invoice, $config) extends MensualidadFacturaPdf {
            /** @var array<int, string> URLs de QR que se habrían descargado. */
            public $urls_de_qr = [];

            protected function url_exists($url)
            {
                $this->urls_de_qr[] = $url;

                // Sin red en el test: el QR no se dibuja.
                return false;
            }
        };

        // Sin compresión, el texto de las celdas queda legible dentro del PDF.
        $pdf->SetCompression(false);

        $qr = null;
        if (! empty($pdf->urls_de_qr) && preg_match('~/qr/\?([A-Za-z0-9+/=]+)&format~', $pdf->urls_de_qr[0], $m)) {
            $qr = json_decode(base64_decode($m[1]), true);
        }

        return ['pdf' => $pdf->contenido(), 'qr' => $qr];
    }

    public function test_el_pdf_de_una_factura_a_consumidor_final_no_lleva_el_cuit_ni_el_domicilio()
    {
        $salida = $this->imprimir(['doc_tipo' => 99, 'doc_nro' => '0', 'cuit_cliente' => null]);

        $this->assertStringStartsWith('%PDF', $salida['pdf']);
        $this->assertStringContainsString('Consumidor Final', $salida['pdf']);

        // Nada de la ficha del cliente (que sí tiene CUIT, condición, razón social y domicilio).
        $this->assertStringNotContainsString('27111111118', $salida['pdf']);
        $this->assertStringNotContainsString('RAZON SOCIAL DEL CLIENTE', $salida['pdf']);
        $this->assertStringNotContainsString('Calle Falsa', $salida['pdf']);
        $this->assertStringNotContainsString('Responsable inscripto', $salida['pdf']);
        $this->assertStringNotContainsString('Domicilio Comercial', $salida['pdf']);

        // El QR de AFIP identifica al receptor como Consumidor Final.
        $this->assertNotNull($salida['qr'], 'El PDF tiene que haber armado la URL del QR.');
        $this->assertSame(99, $salida['qr']['tipoDocRec']);
        $this->assertSame(0, $salida['qr']['nroDocRec']);
    }

    public function test_el_pdf_de_una_factura_con_cuit_sigue_igual()
    {
        $salida = $this->imprimir(['doc_tipo' => 80, 'doc_nro' => '27111111118', 'cuit_cliente' => '27111111118']);

        $this->assertStringStartsWith('%PDF', $salida['pdf']);
        $this->assertStringContainsString('27111111118', $salida['pdf']);
        $this->assertStringContainsString('RAZON SOCIAL DEL CLIENTE', $salida['pdf']);
        $this->assertStringContainsString('Domicilio Comercial', $salida['pdf']);

        $this->assertSame(80, $salida['qr']['tipoDocRec']);
        $this->assertSame('27111111118', $salida['qr']['nroDocRec']);
    }

    public function test_el_pdf_de_una_factura_anterior_a_las_columnas_de_documento_usa_el_cuit_en_el_qr()
    {
        // Registros viejos: sin doc_tipo ni doc_nro, solo con cuit_cliente.
        $salida = $this->imprimir(['doc_tipo' => null, 'doc_nro' => null, 'cuit_cliente' => '27111111118']);

        $this->assertStringContainsString('27111111118', $salida['pdf']);
        $this->assertSame(80, $salida['qr']['tipoDocRec']);
        $this->assertSame('27111111118', $salida['qr']['nroDocRec']);
    }

    public function test_el_pdf_de_una_factura_ya_emitida_a_consumidor_final_no_cambia_si_se_destilda_el_cliente()
    {
        // El PDF decide por lo que quedó guardado en la factura, no por el tilde de hoy.
        $salida = $this->imprimir(
            ['doc_tipo' => 99, 'doc_nro' => '0', 'cuit_cliente' => null],
            ['facturar_consumidor_final' => false]
        );

        $this->assertStringContainsString('Consumidor Final', $salida['pdf']);
        $this->assertStringNotContainsString('27111111118', $salida['pdf']);
    }
}

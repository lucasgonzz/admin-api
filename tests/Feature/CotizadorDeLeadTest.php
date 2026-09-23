<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminSetting;
use App\Models\Lead;
use App\Services\CotizadorSettings;
use App\Services\MercadoPagoLinkService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * El cotizador del sistema de la solapa Contrato de un lead, y su link de pago de Mercado Pago
 * (misión `cotizador-lead-mercado-pago`, 22/9/2026).
 *
 * Lo que estos tests protegen, en orden de importancia:
 *
 *  1. 🔴 QUE EL TOTAL LO REHAGA EL SERVIDOR. El navegador manda los precios y el dólar —eso es la
 *     funcionalidad: quien vende decide el precio de cada lead—, pero el importe que termina en la
 *     preferencia de Mercado Pago lo calcula `CotizadorLeadService` desde cero. Un `total` que
 *     venga en el body no lo lee nadie. Si esto se rompiera, el lead podría recibir un link por un
 *     importe que nunca se calculó.
 *  2. 🔴 QUE LAS SEIS FÓRMULAS DE REDONDEO DEN EXACTAMENTE LO ESPERADO. El front hace esta misma
 *     cuenta en vivo mientras el usuario tipea; si las dos divergen aunque sea en un centavo,
 *     quien vende ve un número y el lead paga otro, y se entera recién en el checkout. En especial
 *     `total_ars`, que se suma a partir de los `precio_ars` YA redondeados y no como
 *     `total_usd * dolar` (las dos formas difieren en centavos).
 *  3. 🔴 QUE EL LEAD NO SE TOQUE SI EL LINK NO SE PUDO GENERAR. Una cotización a medias guardada es
 *     peor que ninguna: nadie sabe si ese link existe o no.
 *  4. 🔴 QUE EL ACCESS TOKEN NO SALGA NUNCA hacia el front. Lo único que viaja es el booleano
 *     `mercado_pago_configurado`.
 *  5. Que los defaults sean 1500 / 600 / 600 USD y que se puedan cambiar desde la configuración.
 */
class CotizadorDeLeadTest extends TestCase
{
    use DatabaseTransactions;

    /** URL del checkout que devuelve el Mercado Pago fakeado. */
    const LINK_FAKE = 'https://www.mercadopago.com.ar/checkout/v1/redirect?pref_id=1234-abcd';

    /** Id de preferencia que devuelve el Mercado Pago fakeado. */
    const PREFERENCE_FAKE = '163250661-1234-abcd';

    /** Access token de mentira: sirve para probar que NUNCA aparece en una respuesta. */
    const TOKEN_FAKE = 'APP_USR-token-de-prueba-que-no-tiene-que-salir-jamas';

    /**
     * Deja el entorno determinista: sin claves del cotizador en `admin_settings` y sin credencial
     * de Mercado Pago cargada.
     *
     * Las claves se borran a mano porque `admin_settings` es una tabla de configuración global y
     * una corrida anterior (o el seeder) pudo haber dejado valores commiteados: sin esto, el test
     * de los defaults pasaría o fallaría según el orden en que se corran los tests.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        AdminSetting::whereIn('key', [
            CotizadorSettings::KEY_PRECIO_GESTION,
            CotizadorSettings::KEY_PRECIO_ECOMMERCE,
            CotizadorSettings::KEY_PRECIO_AGENTES,
            CotizadorSettings::KEY_DESCUENTO_TRANSFERENCIA,
            CotizadorSettings::KEY_LINK_VENCE_DIAS,
        ])->delete();

        config(['services.mercadopago.admin_access_token' => '']);
    }

    /**
     * Crea un admin y lo autentica por Sanctum, igual que el resto de la suite.
     *
     * @return Admin
     */
    private function autenticar(): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Admin de prueba';
        $admin->email    = 'cotizador-' . Str::random(8) . '@test.local';
        $admin->password = bcrypt('secret');
        $admin->save();

        Sanctum::actingAs($admin);

        return $admin;
    }

    /**
     * Crea un lead mínimo para cotizarle.
     *
     * @return Lead
     */
    private function crear_lead(): Lead
    {
        $lead               = new Lead();
        $lead->uuid         = (string) Str::uuid();
        $lead->contact_name = 'Lead de prueba';
        $lead->company_name = 'Empresa de prueba';
        $lead->phone        = '5493511234567';
        $lead->save();

        return $lead;
    }

    /**
     * Carga una credencial de mentira y fakea la respuesta exitosa de Mercado Pago.
     *
     * @return void
     */
    private function con_mercado_pago_ok(): void
    {
        config(['services.mercadopago.admin_access_token' => self::TOKEN_FAKE]);

        Http::fake([
            'api.mercadopago.com/*' => Http::response([
                'id'         => self::PREFERENCE_FAKE,
                'init_point' => self::LINK_FAKE,
            ], 201),
        ]);
    }

    // -----------------------------------------------------------------------------------------
    // 1 y 2 — Los defaults y su configuración
    // -----------------------------------------------------------------------------------------

    /** @test */
    public function los_precios_por_defecto_son_1500_600_y_600_dolares()
    {
        $this->autenticar();

        $response = $this->getJson('/api/admin/settings/cotizador');

        $response->assertStatus(200)
            ->assertJson([
                'precio_gestion'          => 1500,
                'precio_ecommerce'        => 600,
                'precio_agentes'          => 600,
                'descuento_transferencia' => 10,
                'cuotas'                  => 3,
            ]);

        /* El catálogo viaja armado, con la etiqueta que el lead va a leer en el checkout. */
        $sistemas = $response->json('sistemas');
        $this->assertCount(3, $sistemas);
        $this->assertSame('gestion', $sistemas[0]['key']);
        $this->assertSame('ComercioCity Gestión', $sistemas[0]['label']);
        $this->assertSame(1500.0, (float) $sistemas[0]['precio_usd']);
    }

    /** @test */
    public function los_precios_se_pueden_cambiar_desde_la_configuracion()
    {
        $this->autenticar();

        $this->putJson('/api/admin/settings/cotizador', [
            'precio_gestion'          => 1800,
            'precio_ecommerce'        => 750.5,
            'precio_agentes'          => 640,
            'descuento_transferencia' => 15,
            'link_vence_dias'         => 3,
        ])->assertStatus(200);

        $this->getJson('/api/admin/settings/cotizador')
            ->assertStatus(200)
            ->assertJson([
                'precio_gestion'          => 1800,
                'precio_ecommerce'        => 750.5,
                'precio_agentes'          => 640,
                'descuento_transferencia' => 15,
                'link_vence_dias'         => 3,
            ]);
    }

    /** @test */
    public function el_access_token_nunca_viaja_en_la_configuracion_solo_el_booleano()
    {
        $this->autenticar();
        config(['services.mercadopago.admin_access_token' => self::TOKEN_FAKE]);

        $response = $this->getJson('/api/admin/settings/cotizador');

        $response->assertStatus(200)->assertJson(['mercado_pago_configurado' => true]);
        $this->assertStringNotContainsString(self::TOKEN_FAKE, $response->getContent());
    }

    /** @test */
    public function sin_credencial_la_configuracion_lo_declara_en_el_booleano()
    {
        $this->autenticar();

        $this->getJson('/api/admin/settings/cotizador')
            ->assertStatus(200)
            ->assertJson(['mercado_pago_configurado' => false]);
    }

    // -----------------------------------------------------------------------------------------
    // 3, 4 y 5 — El cálculo
    // -----------------------------------------------------------------------------------------

    /** @test */
    public function un_solo_sistema_se_cotiza_con_su_precio_por_la_cotizacion_del_dolar()
    {
        $this->autenticar();
        $lead = $this->crear_lead();
        $this->con_mercado_pago_ok();

        $response = $this->postJson('/api/admin/lead/' . $lead->id . '/cotizacion/link-pago', [
            'dolar'    => 1000,
            'sistemas' => [
                ['key' => 'gestion', 'precio_usd' => 1500],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'cotizacion' => [
                    'total_usd'         => 1500,
                    'total_ars'         => 1500000,
                    'cuotas'            => 3,
                    'cuota_ars'         => 500000,
                    'transferencia_usd' => 1350,     // 1500 - 10%
                    'transferencia_ars' => 1350000,  // 1.500.000 - 10%
                ],
            ]);
    }

    /** @test */
    public function los_tres_sistemas_suman_y_la_cuota_es_el_total_dividido_tres()
    {
        $this->autenticar();
        $lead = $this->crear_lead();
        $this->con_mercado_pago_ok();

        $response = $this->postJson('/api/admin/lead/' . $lead->id . '/cotizacion/link-pago', [
            'dolar'    => 1450.5,
            'sistemas' => [
                ['key' => 'gestion',   'precio_usd' => 1500],
                ['key' => 'ecommerce', 'precio_usd' => 600],
                ['key' => 'agentes',   'precio_usd' => 600],
            ],
        ]);

        $response->assertStatus(200);

        /* 2700 USD. Cada item se redondea antes de sumar:
           1500 * 1450.5 = 2.175.750 · 600 * 1450.5 = 870.300 (x2) → 3.916.350 */
        $cotizacion = $response->json('cotizacion');
        $this->assertSame(2700.0, (float) $cotizacion['total_usd']);
        $this->assertSame(3916350.0, (float) $cotizacion['total_ars']);
        $this->assertSame(1305450.0, (float) $cotizacion['cuota_ars']);
        $this->assertSame(2430.0, (float) $cotizacion['transferencia_usd']);
        $this->assertSame(3524715.0, (float) $cotizacion['transferencia_ars']);
        $this->assertCount(3, $cotizacion['items']);
    }

    /** @test */
    public function el_total_ars_se_suma_de_los_items_ya_redondeados_y_no_del_total_en_dolares()
    {
        $this->autenticar();
        $lead = $this->crear_lead();
        $this->con_mercado_pago_ok();

        /* 🔴 Los valores NO son decorativos: son un caso donde las dos formas de calcular dan
           distinto de verdad, buscado a propósito. Con dólar 1000,05 y precios 1250,55 + 333,33:

           - por item (lo correcto):  round(1250.55 * 1000.05, 2) + round(333.33 * 1000.05, 2)
                                      = 1.250.612,53 + 333.346,67 = 1.583.959,20
           - por total (lo incorrecto): round(1583.88 * 1000.05, 2) = 1.583.959,19

           Un centavo de diferencia. Con valores donde las dos formas coinciden, este test pasaría
           igual aunque el servidor calculara el total de la forma equivocada — o sea que no
           probaría nada. La versión anterior de este test tenía justamente ese defecto y lo
           encontró el chequeo independiente del 22/9/2026. */
        $response = $this->postJson('/api/admin/lead/' . $lead->id . '/cotizacion/link-pago', [
            'dolar'    => 1000.05,
            'sistemas' => [
                ['key' => 'gestion',   'precio_usd' => 1250.55],
                ['key' => 'ecommerce', 'precio_usd' => 333.33],
            ],
        ]);

        $response->assertStatus(200);
        $cotizacion = $response->json('cotizacion');

        /* El total es el de la suma por item, y NO el de multiplicar el total en dólares. */
        $this->assertSame(1583959.20, (float) $cotizacion['total_ars']);
        $this->assertNotSame(1583959.19, (float) $cotizacion['total_ars']);

        /* Y el detalle que ve quien vende cierra exactamente con ese total. */
        $suma_de_items = 0.0;
        foreach ($cotizacion['items'] as $item) {
            $suma_de_items += (float) $item['precio_ars'];
        }

        $this->assertSame(round($suma_de_items, 2), (float) $cotizacion['total_ars']);

        /* Y es también lo que se le pide cobrar a Mercado Pago. */
        Http::assertSent(function ($request) {
            $items = $request->data()['items'];

            return (float) $items[0]['unit_price'] === 1250612.53
                && (float) $items[1]['unit_price'] === 333346.67;
        });
    }

    /** @test */
    public function un_precio_con_mas_de_dos_decimales_se_redondea_antes_de_multiplicar()
    {
        $this->autenticar();
        $lead = $this->crear_lead();
        $this->con_mercado_pago_ok();

        /* 🔴 El caso que encontraron dos chequeos independientes el 22/9/2026. El input del
           front es `type="number" step="0.01"`, pero un valor que viola el `step` se puede
           tipear y pegar igual, y la API valida `numeric` sin regla de decimales.

           Redondear el precio ANTES de multiplicar (1500.555 → 1500.56) da 2.176.562,28.
           Multiplicar crudo y redondear después da 2.176.555,03: $7,25 menos.

           El front hace exactamente lo mismo, así que el número que se ve en pantalla y el que
           cobra el link coinciden. Si alguien saca este `round()` de un lado solo, este test
           marca el lado del servidor y el preview queda mintiendo en silencio. */
        $response = $this->postJson('/api/admin/lead/' . $lead->id . '/cotizacion/link-pago', [
            'dolar'    => 1450.5,
            'sistemas' => [
                ['key' => 'gestion', 'precio_usd' => 1500.555],
            ],
        ]);

        $response->assertStatus(200);
        $cotizacion = $response->json('cotizacion');

        $this->assertSame(1500.56, (float) $cotizacion['items'][0]['precio_usd']);
        $this->assertSame(2176562.28, (float) $cotizacion['total_ars']);
        $this->assertNotSame(2176555.03, (float) $cotizacion['total_ars']);
    }

    /** @test */
    public function el_total_cotizado_queda_guardado_en_el_precio_del_contrato()
    {
        $this->autenticar();
        $lead = $this->crear_lead();
        $this->con_mercado_pago_ok();

        /* Lucas pidió que el total llene el campo "Precio total (licencia + implementación)".
           🔴 Se persiste en el mismo save() que la cotización y no queda como borrador del
           formulario: si viviera solo en el modal, cerrar el lead sin apretar "Guardar datos del
           contrato" dejaría el link ya generado por un importe y el contrato diciendo otro. */
        $this->postJson('/api/admin/lead/' . $lead->id . '/cotizacion/link-pago', [
            'dolar'    => 1000,
            'sistemas' => [
                ['key' => 'gestion',   'precio_usd' => 1500],
                ['key' => 'ecommerce', 'precio_usd' => 600],
                ['key' => 'agentes',   'precio_usd' => 600],
            ],
        ])->assertStatus(200);

        $lead->refresh();
        $this->assertSame(2700.0, (float) $lead->contract_precio_licencia);
        $this->assertSame('USD', $lead->contract_currency);
    }

    /** @test */
    public function un_valor_ilegible_guardado_a_mano_en_la_configuracion_cae_al_default()
    {
        $this->autenticar();

        /* `admin_settings.value` es TEXT y se puede editar contra la base. Sin la guarda, un
           "abc" se vuelve (float) 0.0, el clamp lo sube al mínimo, y ComercioCity Gestión
           pasa a ofrecerse por USD 0,01 en vez de por 1500 — sin un solo error en ningún lado. */
        AdminSetting::create(['key' => CotizadorSettings::KEY_PRECIO_GESTION, 'value' => 'abc']);

        $this->getJson('/api/admin/settings/cotizador')
            ->assertStatus(200)
            ->assertJson(['precio_gestion' => 1500]);
    }

    /** @test */
    public function un_total_inventado_en_el_body_se_ignora_y_el_servidor_rehace_la_cuenta()
    {
        $this->autenticar();
        $lead = $this->crear_lead();
        $this->con_mercado_pago_ok();

        $response = $this->postJson('/api/admin/lead/' . $lead->id . '/cotizacion/link-pago', [
            'dolar'             => 1000,
            'total_ars'         => 1,      // ← lo que un navegador manipulado mandaría
            'total_usd'         => 1,
            'transferencia_ars' => 1,
            'sistemas'          => [
                ['key' => 'gestion', 'precio_usd' => 1500],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJson(['cotizacion' => ['total_ars' => 1500000, 'total_usd' => 1500]]);

        /* Y el importe que efectivamente se le pide a Mercado Pago es el recalculado. */
        Http::assertSent(function ($request) {
            $items = $request->data()['items'];

            return count($items) === 1 && (float) $items[0]['unit_price'] === 1500000.0;
        });
    }

    /** @test */
    public function la_etiqueta_del_sistema_sale_del_catalogo_y_no_del_navegador()
    {
        $this->autenticar();
        $lead = $this->crear_lead();
        $this->con_mercado_pago_ok();

        $this->postJson('/api/admin/lead/' . $lead->id . '/cotizacion/link-pago', [
            'dolar'    => 1000,
            'sistemas' => [
                ['key' => 'gestion', 'precio_usd' => 1500, 'label' => 'Regalo gratis'],
            ],
        ])->assertStatus(200);

        /* Es lo que el lead lee en el checkout de Mercado Pago: no puede depender del request. */
        Http::assertSent(function ($request) {
            return $request->data()['items'][0]['title'] === 'ComercioCity Gestión';
        });
    }

    // -----------------------------------------------------------------------------------------
    // 6 — Validaciones
    // -----------------------------------------------------------------------------------------

    /** @test */
    public function sin_ningun_sistema_elegido_no_se_genera_ningun_link()
    {
        $this->autenticar();
        $lead = $this->crear_lead();
        $this->con_mercado_pago_ok();

        $this->postJson('/api/admin/lead/' . $lead->id . '/cotizacion/link-pago', [
            'dolar'    => 1000,
            'sistemas' => [],
        ])->assertStatus(422);

        Http::assertNothingSent();
    }

    /** @test */
    public function un_dolar_en_cero_o_negativo_se_rechaza()
    {
        $this->autenticar();
        $lead = $this->crear_lead();
        $this->con_mercado_pago_ok();

        foreach ([0, -1500] as $dolar) {
            $this->postJson('/api/admin/lead/' . $lead->id . '/cotizacion/link-pago', [
                'dolar'    => $dolar,
                'sistemas' => [['key' => 'gestion', 'precio_usd' => 1500]],
            ])->assertStatus(422);
        }

        Http::assertNothingSent();
    }

    /** @test */
    public function un_precio_negativo_o_por_encima_del_techo_se_rechaza()
    {
        $this->autenticar();
        $lead = $this->crear_lead();
        $this->con_mercado_pago_ok();

        foreach ([-100, CotizadorSettings::MAX_PRECIO + 1] as $precio) {
            $this->postJson('/api/admin/lead/' . $lead->id . '/cotizacion/link-pago', [
                'dolar'    => 1000,
                'sistemas' => [['key' => 'gestion', 'precio_usd' => $precio]],
            ])->assertStatus(422);
        }

        Http::assertNothingSent();
    }

    /** @test */
    public function un_sistema_que_no_existe_en_el_catalogo_se_rechaza()
    {
        $this->autenticar();
        $lead = $this->crear_lead();
        $this->con_mercado_pago_ok();

        $this->postJson('/api/admin/lead/' . $lead->id . '/cotizacion/link-pago', [
            'dolar'    => 1000,
            'sistemas' => [['key' => 'sistema_inventado', 'precio_usd' => 1500]],
        ])->assertStatus(422);

        Http::assertNothingSent();
    }

    /** @test */
    public function el_mismo_sistema_dos_veces_se_rechaza_en_vez_de_duplicar_el_importe()
    {
        $this->autenticar();
        $lead = $this->crear_lead();
        $this->con_mercado_pago_ok();

        $this->postJson('/api/admin/lead/' . $lead->id . '/cotizacion/link-pago', [
            'dolar'    => 1000,
            'sistemas' => [
                ['key' => 'gestion', 'precio_usd' => 1500],
                ['key' => 'gestion', 'precio_usd' => 1500],
            ],
        ])->assertStatus(422);

        Http::assertNothingSent();
    }

    // -----------------------------------------------------------------------------------------
    // 7, 8, 9 y 10 — El link de pago
    // -----------------------------------------------------------------------------------------

    /** @test */
    public function sin_credencial_responde_422_con_el_motivo_y_no_toca_el_lead()
    {
        $this->autenticar();
        $lead = $this->crear_lead();

        Http::fake();
        config(['services.mercadopago.admin_access_token' => '']);

        $response = $this->postJson('/api/admin/lead/' . $lead->id . '/cotizacion/link-pago', [
            'dolar'    => 1000,
            'sistemas' => [['key' => 'gestion', 'precio_usd' => 1500]],
        ]);

        $response->assertStatus(422)
            ->assertJson(['message' => MercadoPagoLinkService::MOTIVO_SIN_CREDENCIAL]);

        /* El motivo nombra la variable del .env para que se sepa qué cargar. */
        $this->assertStringContainsString('MP_ADMIN_ACCESS_TOKEN', $response->json('message'));

        /* Y el lead queda exactamente como estaba: ni siquiera se salió a la red. */
        Http::assertNothingSent();
        $lead->refresh();
        $this->assertNull($lead->contract_cotizacion_link_pago);
        $this->assertNull($lead->contract_cotizacion_total_ars);
        $this->assertNull($lead->contract_cotizacion_generada_at);
    }

    /** @test */
    public function la_preferencia_va_con_tres_cuotas_un_item_por_sistema_y_vencimiento()
    {
        $this->autenticar();
        $lead = $this->crear_lead();
        $this->con_mercado_pago_ok();

        Carbon::setTestNow(Carbon::parse('2026-09-22 10:00:00'));

        $this->postJson('/api/admin/lead/' . $lead->id . '/cotizacion/link-pago', [
            'dolar'    => 1000,
            'sistemas' => [
                ['key' => 'gestion',   'precio_usd' => 1500],
                ['key' => 'ecommerce', 'precio_usd' => 600],
            ],
        ])->assertStatus(200);

        Http::assertSent(function ($request) use ($lead) {
            $data = $request->data();

            /* Las tres cuotas van en los dos campos: `installments` es el techo y
               `default_installments` es la opción que aparece elegida al abrir el checkout. */
            $cuotas_ok = $data['payment_methods']['installments'] === 3
                && $data['payment_methods']['default_installments'] === 3;

            /* Un item por sistema, en pesos, con la etiqueta del catálogo. */
            $items_ok = count($data['items']) === 2
                && $data['items'][0]['currency_id'] === 'ARS'
                && (float) $data['items'][0]['unit_price'] === 1500000.0
                && (float) $data['items'][1]['unit_price'] === 600000.0;

            /* Lo único que ata el cobro al lead mientras no haya webhook. */
            $referencia_ok = $data['external_reference'] === 'lead-' . $lead->id
                && (int) $data['metadata']['lead_id'] === (int) $lead->id;

            /* El link vence a los 7 días, en el formato que documenta Mercado Pago. */
            $vencimiento_ok = $data['expires'] === true
                && strpos($data['expiration_date_to'], '2026-09-29T10:00:00.000') === 0;

            /* Y el token va en el header, jamás en la URL ni en el cuerpo. */
            $token_ok = $request->hasHeader('Authorization', 'Bearer ' . self::TOKEN_FAKE)
                && strpos($request->url(), self::TOKEN_FAKE) === false;

            return $cuotas_ok && $items_ok && $referencia_ok && $vencimiento_ok && $token_ok;
        });

        Carbon::setTestNow();
    }

    /** @test */
    public function un_rechazo_de_mercado_pago_se_propaga_con_su_mensaje_y_no_persiste_nada()
    {
        $this->autenticar();
        $lead = $this->crear_lead();

        config(['services.mercadopago.admin_access_token' => self::TOKEN_FAKE]);
        Http::fake([
            'api.mercadopago.com/*' => Http::response([
                'message' => 'invalid_collector_id',
            ], 400),
        ]);

        $response = $this->postJson('/api/admin/lead/' . $lead->id . '/cotizacion/link-pago', [
            'dolar'    => 1000,
            'sistemas' => [['key' => 'gestion', 'precio_usd' => 1500]],
        ]);

        $response->assertStatus(422);

        /* El texto que devolvió Mercado Pago, no un "algo salió mal": el 99% de los rechazos de
           una preferencia son un campo del payload, y ese texto dice cuál. */
        $this->assertStringContainsString('invalid_collector_id', $response->json('message'));

        $lead->refresh();
        $this->assertNull($lead->contract_cotizacion_link_pago);
        $this->assertNull($lead->contract_cotizacion_preference_id);
        $this->assertNull($lead->contract_cotizacion_generada_at);
    }

    /** @test */
    public function una_respuesta_sin_link_de_mercado_pago_no_se_da_por_buena()
    {
        $this->autenticar();
        $lead = $this->crear_lead();

        config(['services.mercadopago.admin_access_token' => self::TOKEN_FAKE]);
        /* 201 pero sin `init_point`: es el caso que en la tienda dejaba el botón muerto
           respondiendo éxito con el link en null (informe del 5/9/2026). */
        Http::fake([
            'api.mercadopago.com/*' => Http::response(['id' => self::PREFERENCE_FAKE], 201),
        ]);

        $this->postJson('/api/admin/lead/' . $lead->id . '/cotizacion/link-pago', [
            'dolar'    => 1000,
            'sistemas' => [['key' => 'gestion', 'precio_usd' => 1500]],
        ])->assertStatus(422);

        $lead->refresh();
        $this->assertNull($lead->contract_cotizacion_link_pago);
    }

    /** @test */
    public function el_camino_feliz_guarda_la_foto_completa_de_la_cotizacion_en_el_lead()
    {
        $this->autenticar();
        $lead = $this->crear_lead();
        $this->con_mercado_pago_ok();

        $response = $this->postJson('/api/admin/lead/' . $lead->id . '/cotizacion/link-pago', [
            'dolar'    => 1450.5,
            'sistemas' => [
                ['key' => 'gestion',   'precio_usd' => 1500],
                ['key' => 'ecommerce', 'precio_usd' => 600],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'cotizacion' => [
                    'link_pago'     => self::LINK_FAKE,
                    'preference_id' => self::PREFERENCE_FAKE,
                ],
            ]);
        $this->assertNotEmpty($response->json('model'));

        $lead->refresh();
        $this->assertSame(self::LINK_FAKE, $lead->contract_cotizacion_link_pago);
        $this->assertSame(self::PREFERENCE_FAKE, $lead->contract_cotizacion_preference_id);
        $this->assertSame(1450.5, (float) $lead->contract_cotizacion_dolar);
        $this->assertSame(2100.0, (float) $lead->contract_cotizacion_total_usd);
        $this->assertSame(3046050.0, (float) $lead->contract_cotizacion_total_ars);
        $this->assertNotNull($lead->contract_cotizacion_generada_at);

        /* La foto del detalle, para poder auditar contra qué se le ofreció al lead. */
        $items = $lead->contract_cotizacion_items;
        $this->assertIsArray($items);
        $this->assertCount(2, $items);
        $this->assertSame('ComercioCity Gestión', $items[0]['label']);
        $this->assertSame(1500.0, (float) $items[0]['precio_usd']);
    }

    /** @test */
    public function el_access_token_no_aparece_nunca_en_la_respuesta_del_link()
    {
        $this->autenticar();
        $lead = $this->crear_lead();
        $this->con_mercado_pago_ok();

        $response = $this->postJson('/api/admin/lead/' . $lead->id . '/cotizacion/link-pago', [
            'dolar'    => 1000,
            'sistemas' => [['key' => 'gestion', 'precio_usd' => 1500]],
        ]);

        $response->assertStatus(200);
        $this->assertStringNotContainsString(self::TOKEN_FAKE, $response->getContent());
    }

    /** @test */
    public function el_descuento_configurado_manda_sobre_el_del_navegador()
    {
        $this->autenticar();
        $lead = $this->crear_lead();
        $this->con_mercado_pago_ok();

        $this->putJson('/api/admin/settings/cotizador', ['descuento_transferencia' => 20])
            ->assertStatus(200);

        $response = $this->postJson('/api/admin/lead/' . $lead->id . '/cotizacion/link-pago', [
            'dolar'                   => 1000,
            'descuento_transferencia' => 90,   // ← el navegador no decide esto
            'sistemas'                => [['key' => 'gestion', 'precio_usd' => 1500]],
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'cotizacion' => [
                    'descuento_transferencia' => 20,
                    'transferencia_usd'       => 1200,     // 1500 - 20%
                    'transferencia_ars'       => 1200000,
                ],
            ]);
    }
}

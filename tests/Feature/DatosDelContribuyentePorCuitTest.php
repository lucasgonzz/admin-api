<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Client;
use App\Models\ComerciocityAfipConfig;
use App\Services\Afip\AfipConstanciaInscripcionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * El botón "Obtener datos" de la tarjeta Facturación del modal del cliente:
 * GET admin/client/{clientId}/mensualidad/datos-afip/{cuit}.
 *
 * Lo que estos tests protegen, en orden de importancia:
 *
 *  1. 🔴 Que el mapeo de la respuesta de ARCA a los cuatro campos del receptor
 *     sea el correcto. Es donde vive la lógica real: si el domicilio se arma
 *     mal o la condición IVA sale con un texto que
 *     `CondicionIvaReceptorResolver` no reconoce, la factura se emite con
 *     Consumidor Final por fallback y nadie se entera hasta que sale el PDF.
 *  2. Que un CUIT mal formado se corte ANTES de salir a la red. Un request a
 *     ARCA por cada tecla sería, además de inútil, una forma de que nos corten.
 *  3. Que un error de ARCA vuelva como 200 + `hubo_un_error`, que es el
 *     contrato que espera el front (mismo que el modal de VENDER en
 *     empresa-spa), y no como un 500.
 *  4. Que los datos traídos se puedan guardar y queden persistidos, que es lo
 *     que después lee el PDF de la factura.
 *
 * 🔴 Ningún test sale a la red. El camino que le pega a ARCA de verdad se
 * ejercita reemplazando el servicio en el contenedor; el mapeo se prueba
 * directo sobre el servicio real, sin pasar por SOAP.
 */
class DatosDelContribuyentePorCuitTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Admin logueado por Sanctum: la ruta vive bajo auth:sanctum.
     *
     * @return Admin
     */
    private function admin_logueado(): Admin
    {
        $admin = new Admin();
        $admin->name = 'Admin de facturacion';
        $admin->email = 'facturacion-'.Str::random(8).'@test.local';
        $admin->password = bcrypt('secret');
        $admin->save();

        Sanctum::actingAs($admin);

        return $admin;
    }

    /**
     * Cliente mínimo al que facturarle la mensualidad.
     *
     * @return Client
     */
    private function crear_cliente(): Client
    {
        $client = new Client();
        $client->name = 'Cliente de facturacion';
        $client->slug = 'cliente-facturacion-'.Str::random(8);
        $client->api_url = 'https://ejemplo.test';
        $client->api_key = 'clave-api';
        $client->inbound_api_key = 'clave-inbound';
        $client->is_active = true;
        $client->save();

        return $client;
    }

    /**
     * URL del endpoint para un cliente y un CUIT dados.
     *
     * @param  Client $client
     * @param  string $cuit
     * @return string
     */
    private function url(Client $client, string $cuit): string
    {
        return '/api/admin/client/'.$client->id.'/mensualidad/datos-afip/'.$cuit;
    }

    /**
     * Reemplaza el servicio de ARCA en el contenedor por uno que devuelve lo
     * que se le indique, sin salir a la red.
     *
     * @param  array $respuesta Respuesta que debe devolver `consultar()`.
     * @return void
     */
    private function fingir_arca(array $respuesta): void
    {
        $this->app->instance(AfipConstanciaInscripcionService::class, new class($respuesta) extends AfipConstanciaInscripcionService {
            /** @var array */
            private $respuesta;

            public function __construct(array $respuesta)
            {
                $this->respuesta = $respuesta;
            }

            public function consultar($cuit)
            {
                return $this->respuesta;
            }
        });
    }

    /**
     * Camino feliz: ARCA encontró al contribuyente y el endpoint devuelve los
     * cuatro campos que completan el formulario.
     *
     * @return void
     */
    public function test_devuelve_los_datos_del_contribuyente_cuando_arca_responde()
    {
        $this->admin_logueado();
        $client = $this->crear_cliente();

        $this->fingir_arca([
            'hubo_un_error' => false,
            'error' => null,
            'datos' => [
                'cuit' => '30718519531',
                'razon_social' => 'FERRETERIA COLMAN SRL',
                'condicion_iva' => 'Responsable inscripto',
                'domicilio' => 'AV SIEMPREVIVA 742, ROSARIO, SANTA FE',
            ],
        ]);

        $response = $this->getJson($this->url($client, '30-71851953-1'));

        $response->assertStatus(200);
        $response->assertJson([
            'hubo_un_error' => false,
            'datos' => [
                'cuit' => '30718519531',
                'razon_social' => 'FERRETERIA COLMAN SRL',
                'condicion_iva' => 'Responsable inscripto',
                'domicilio' => 'AV SIEMPREVIVA 742, ROSARIO, SANTA FE',
            ],
        ]);
    }

    /**
     * Un error de ARCA no es un error del request: vuelve 200 con
     * `hubo_un_error` en true y el texto mostrable, que es lo que el front
     * necesita para poder distinguirlo de una caída.
     *
     * @return void
     */
    public function test_un_error_de_arca_vuelve_como_doscientos_con_hubo_un_error()
    {
        $this->admin_logueado();
        $client = $this->crear_cliente();

        $this->fingir_arca([
            'hubo_un_error' => true,
            'error' => 'ARCA no devolvió datos para el CUIT 30718519531.',
            'datos' => null,
        ]);

        $response = $this->getJson($this->url($client, '30718519531'));

        $response->assertStatus(200);
        $response->assertJson([
            'hubo_un_error' => true,
            'error' => 'ARCA no devolvió datos para el CUIT 30718519531.',
        ]);
    }

    /**
     * Un CUIT que no tiene 11 dígitos se corta en el servicio real, antes de
     * pedir el TA y antes de abrir ningún socket.
     *
     * 🔴 Estos dos tests corren contra el servicio REAL a propósito, y por eso
     * las dos barreras importan: si se rompieran las dos, lo que sigue no es un
     * timeout, es un login real contra `wsaa.afip.gov.ar` firmado con el
     * certificado de PRODUCCIÓN, que además pisaría el `TA.xml` del slot (un
     * archivo fuera de la base, que `DatabaseTransactions` no revierte). Por eso
     * el CUIT de ComercioCity se pone explícitamente en null acá abajo, en vez
     * de confiar en que la base de testing esté vacía.
     *
     * @return void
     */
    public function test_un_cuit_incompleto_no_sale_a_la_red()
    {
        $this->admin_logueado();
        $client = $this->crear_cliente();
        $this->sin_cuit_de_comerciocity();

        $response = $this->getJson($this->url($client, '3071851'));

        $response->assertStatus(200);
        $response->assertJson([
            'hubo_un_error' => true,
            'error' => 'El CUIT tiene que tener 11 dígitos.',
        ]);
    }

    /**
     * Segunda barrera antes de la red: sin el CUIT de ComercioCity cargado no se
     * puede firmar nada contra ARCA, y se corta con un mensaje que dice qué
     * configurar en vez de fallar con un error de certificado.
     *
     * @return void
     */
    public function test_sin_cuit_de_comerciocity_no_sale_a_la_red()
    {
        $this->admin_logueado();
        $client = $this->crear_cliente();
        $this->sin_cuit_de_comerciocity();

        $response = $this->getJson($this->url($client, '30718519531'));

        $response->assertStatus(200);
        $response->assertJson([
            'hubo_un_error' => true,
            'error' => 'Falta configurar el CUIT de ComercioCity para poder consultar a ARCA (ver configuración fiscal).',
        ]);
    }

    /**
     * Deja la config fiscal sin CUIT dentro de la transacción del test, para que
     * los tests que corren el servicio real no puedan llegar nunca a la red.
     *
     * @return void
     */
    private function sin_cuit_de_comerciocity(): void
    {
        $config = ComerciocityAfipConfig::current();
        $config->cuit = null;
        $config->save();
    }

    /**
     * La ruta cuelga de un cliente existente: un id que no existe es 404, no
     * una consulta a ARCA por un cliente fantasma.
     *
     * @return void
     */
    public function test_un_cliente_inexistente_da_cuatrocientos_cuatro()
    {
        $this->admin_logueado();

        $this->fingir_arca([
            'hubo_un_error' => false,
            'error' => null,
            'datos' => ['cuit' => '30718519531', 'razon_social' => 'X', 'condicion_iva' => '', 'domicilio' => ''],
        ]);

        $response = $this->getJson('/api/admin/client/999999999/mensualidad/datos-afip/30718519531');

        $response->assertStatus(404);
    }

    /**
     * Cierra el círculo del pedido: lo que trae el botón se puede guardar y
     * queda persistido en el cliente, que es de donde lo lee el PDF de la
     * factura (`MensualidadFacturaPdf::print_client_info()`).
     *
     * @return void
     */
    public function test_los_datos_traidos_se_guardan_en_el_cliente()
    {
        $this->admin_logueado();
        $client = $this->crear_cliente();

        $response = $this->putJson('/api/admin/client/'.$client->id.'/mensualidad', [
            'precio_plan' => 100,
            'precio_por_cuenta' => 10,
            'cantidad_empleados' => 2,
            'afip_cuit' => '30718519531',
            'afip_razon_social' => 'FERRETERIA COLMAN SRL',
            'afip_condicion_iva' => 'Responsable inscripto',
            'afip_domicilio' => 'AV SIEMPREVIVA 742, ROSARIO, SANTA FE',
        ]);

        $response->assertStatus(200);

        $client->refresh();

        $this->assertEquals('30718519531', $client->afip_cuit);
        $this->assertEquals('FERRETERIA COLMAN SRL', $client->afip_razon_social);
        $this->assertEquals('Responsable inscripto', $client->afip_condicion_iva);
        $this->assertEquals('AV SIEMPREVIVA 742, ROSARIO, SANTA FE', $client->afip_domicilio);
    }

    /**
     * Persona jurídica: razón social tal cual, domicilio armado con dirección,
     * localidad y provincia, y Responsable Inscripto deducido de tener IVA
     * entre los impuestos del régimen general.
     *
     * @return void
     */
    public function test_mapea_una_persona_juridica_responsable_inscripto()
    {
        $datos = $this->mapear($this->respuesta_arca([
            'razonSocial' => 'FERRETERIA COLMAN SRL',
            'domicilioFiscal' => (object) [
                'direccion' => 'AV SIEMPREVIVA 742',
                'localidad' => 'ROSARIO',
                'descripcionProvincia' => 'SANTA FE',
            ],
        ], [
            'datosRegimenGeneral' => (object) [
                'impuesto' => [
                    (object) ['descripcionImpuesto' => 'GANANCIAS'],
                    (object) ['descripcionImpuesto' => 'IVA'],
                ],
            ],
        ]));

        $this->assertSame('FERRETERIA COLMAN SRL', $datos['razon_social']);
        $this->assertSame('AV SIEMPREVIVA 742, ROSARIO, SANTA FE', $datos['domicilio']);
        $this->assertSame('Responsable inscripto', $datos['condicion_iva']);
    }

    /**
     * ARCA devuelve un objeto suelto —no un array— cuando el contribuyente
     * tiene un único impuesto. El original de empresa-api solo contempla el
     * array y en ese caso pierde el "Responsable inscripto".
     *
     * @return void
     */
    public function test_mapea_responsable_inscripto_con_un_unico_impuesto()
    {
        $datos = $this->mapear($this->respuesta_arca([
            'razonSocial' => 'UNICO IMPUESTO SA',
        ], [
            'datosRegimenGeneral' => (object) [
                'impuesto' => (object) ['descripcionImpuesto' => 'IVA'],
            ],
        ]));

        $this->assertSame('Responsable inscripto', $datos['condicion_iva']);
    }

    /**
     * 🔴 Exento: ARCA lo nombra "IVA EXENTO" (impuesto 32). Comparando exacto
     * contra 'IVA' —como hace el original de empresa-api— no matcheaba, la
     * condición volvía vacía y al facturar `CondicionIvaReceptorResolver` caía a
     * Consumidor Final (id 5), que es lo que terminaba impreso en el PDF.
     *
     * @return void
     */
    public function test_mapea_un_exento_como_exento_y_no_como_vacio()
    {
        $datos = $this->mapear($this->respuesta_arca([
            'razonSocial' => 'FUNDACION EXENTA',
        ], [
            'datosRegimenGeneral' => (object) [
                'impuesto' => [
                    (object) ['descripcionImpuesto' => 'GANANCIAS'],
                    (object) ['descripcionImpuesto' => 'IVA EXENTO'],
                ],
            ],
        ]));

        $this->assertSame('Exento', $datos['condicion_iva']);
    }

    /**
     * Si el contribuyente tiene los dos, el IVA a secas manda: es responsable
     * inscripto y así hay que facturarle.
     *
     * @return void
     */
    public function test_el_iva_a_secas_gana_sobre_el_exento()
    {
        $datos = $this->mapear($this->respuesta_arca([
            'razonSocial' => 'LOS DOS SA',
        ], [
            'datosRegimenGeneral' => (object) [
                'impuesto' => [
                    (object) ['descripcionImpuesto' => 'IVA EXENTO'],
                    (object) ['descripcionImpuesto' => 'IVA'],
                ],
            ],
        ]));

        $this->assertSame('Responsable inscripto', $datos['condicion_iva']);
    }

    /**
     * CABA devuelve localidad y provincia con el mismo texto. Repetirlo solo
     * alarga un domicilio que después tiene que entrar en una celda de ancho
     * fijo del PDF.
     *
     * @return void
     */
    public function test_no_repite_la_localidad_cuando_la_provincia_es_igual()
    {
        $datos = $this->mapear($this->respuesta_arca([
            'razonSocial' => 'PORTEÑA SA',
            'domicilioFiscal' => (object) [
                'direccion' => 'AV RIVADAVIA 1234',
                'localidad' => 'CIUDAD AUTONOMA BUENOS AIRES',
                'descripcionProvincia' => 'CIUDAD AUTONOMA BUENOS AIRES',
            ],
        ], []));

        $this->assertSame('AV RIVADAVIA 1234, CIUDAD AUTONOMA BUENOS AIRES', $datos['domicilio']);
    }

    /**
     * Para algunos contribuyentes ARCA repite el nodo y el cliente SOAP entrega
     * `domicilioFiscal` como array. Antes se devolvía vacío y era indistinguible
     * de un contribuyente sin domicilio cargado: se perdía en silencio.
     *
     * @return void
     */
    public function test_toma_el_primer_domicilio_cuando_arca_manda_un_array()
    {
        $datos = $this->mapear($this->respuesta_arca([
            'razonSocial' => 'DOS DOMICILIOS SA',
            'domicilioFiscal' => [
                (object) ['direccion' => 'CALLE PRIMERA 100', 'localidad' => 'ROSARIO'],
                (object) ['direccion' => 'CALLE SEGUNDA 200', 'localidad' => 'SANTA FE'],
            ],
        ], []));

        $this->assertSame('CALLE PRIMERA 100, ROSARIO', $datos['domicilio']);
    }

    /**
     * Persona física monotributista: ARCA manda apellido y nombre por separado
     * y hay que unirlos, y el monotributo se deduce del nodo propio.
     *
     * @return void
     */
    public function test_mapea_una_persona_fisica_monotributista()
    {
        $datos = $this->mapear($this->respuesta_arca([
            'apellido' => 'GONZALEZ',
            'nombre' => 'LUCAS',
        ], [
            'datosMonotributo' => (object) ['categoriaMonotributo' => (object) ['descripcionCategoria' => 'C']],
        ]));

        $this->assertSame('GONZALEZ LUCAS', $datos['razon_social']);
        $this->assertSame('Monotributista', $datos['condicion_iva']);
    }

    /**
     * Sin régimen general ni monotributo, la condición IVA vuelve VACÍA (no
     * "NO DETERMINADO"): el front usa el vacío para no pisar lo que el
     * operador haya elegido a mano en el select.
     *
     * Idem el domicilio cuando ARCA no manda `domicilioFiscal`.
     *
     * @return void
     */
    public function test_sin_datos_de_regimen_la_condicion_iva_y_el_domicilio_vuelven_vacios()
    {
        $datos = $this->mapear($this->respuesta_arca(['razonSocial' => 'SIN REGIMEN SA'], []));

        $this->assertSame('', $datos['condicion_iva']);
        $this->assertSame('', $datos['domicilio']);
    }

    /**
     * El domicilio saltea las partes que ARCA no devuelve, sin dejar comas
     * sueltas ni espacios de más.
     *
     * @return void
     */
    public function test_el_domicilio_saltea_las_partes_que_arca_no_manda()
    {
        $datos = $this->mapear($this->respuesta_arca([
            'razonSocial' => 'PARCIAL SA',
            'domicilioFiscal' => (object) [
                'direccion' => 'CALLE FALSA 123',
                'descripcionProvincia' => 'CORDOBA',
            ],
        ], []));

        $this->assertSame('CALLE FALSA 123, CORDOBA', $datos['domicilio']);
    }

    /**
     * Un CUIT que ARCA no reconoce vuelve sin el nodo `datosGenerales`; el
     * mapeo tiene que devolver null y no explotar accediendo a propiedades
     * que no están.
     *
     * @return void
     */
    public function test_una_respuesta_sin_datos_generales_no_mapea_nada()
    {
        $this->assertNull($this->mapear((object) ['personaReturn' => (object) []]));
    }

    /**
     * Arma una respuesta SOAP de ARCA con la forma real: `personaReturn` con
     * un `datosGenerales` y, al mismo nivel, los nodos de régimen.
     *
     * @param  array $generales Campos de `datosGenerales`.
     * @param  array $regimen   Nodos de régimen (`datosMonotributo` / `datosRegimenGeneral`).
     * @return object
     */
    private function respuesta_arca(array $generales, array $regimen)
    {
        $persona_return = array_merge([
            'datosGenerales' => (object) array_merge(['idPersona' => '30718519531'], $generales),
        ], $regimen);

        return (object) ['personaReturn' => (object) $persona_return];
    }

    /**
     * Llama al `mapear()` protegido del servicio REAL, sin pasar por SOAP ni
     * por el WSAA. Es la única forma de probar la lógica que importa sin salir
     * a la red.
     *
     * @param  object $result Respuesta SOAP simulada.
     * @return array|null
     */
    private function mapear($result)
    {
        $servicio = new class extends AfipConstanciaInscripcionService {
            public function mapear_publico($result, $digitos)
            {
                return $this->mapear($result, $digitos);
            }
        };

        return $servicio->mapear_publico($result, '30718519531');
    }
}

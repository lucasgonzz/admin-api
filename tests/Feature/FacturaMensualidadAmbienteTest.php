<?php

namespace Tests\Feature;

use App\Models\ComerciocityAfipConfig;
use App\Models\MensualidadInvoice;
use App\Services\Afip\AfipFacturacionService;
use App\Services\Afip\AfipWsaaService;
use Tests\Feature\Cobranzas\BaseDeCobranzas;

/**
 * Factura C de la mensualidad: el ambiente (producción u homologación) y el CUIT del receptor
 * (misión factura-mensualidad-ambiente, 7/10/2026).
 *
 * El caso que la originó: el admin de producción tenía `afip_produccion = 0`, o sea que TODAS las
 * facturas salían contra la homologación de ARCA. Ahí solo existen unos pocos CUIT de prueba, así
 * que a Arfren, Ananda, San Cayetano y 3dTisk los rechazaba con `[10015] ... no se encuentra
 * registrado en los padrones de AFIP`, mientras que a otros (San Blas) les daba un CAE de prueba
 * sin validez fiscal. Nada en pantalla decía que se estaba en homologación.
 *
 * Nada sale a la red: lo que se prueba son los cortes previos a ARCA, el texto del rechazo y la
 * decisión de si un TA vigente sirve para el ambiente que se necesita.
 */
class FacturaMensualidadAmbienteTest extends BaseDeCobranzas
{
    /** Directorio temporal donde se arma el `TA.xml` de prueba (nunca el `storage/app/afip` real). */
    protected $dir_ta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir_ta = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ta_ambiente_'.uniqid().DIRECTORY_SEPARATOR;
        mkdir($this->dir_ta, 0755, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir_ta.'TA.xml');
        @rmdir($this->dir_ta);

        parent::tearDown();
    }

    /**
     * Deja la config fiscal con CUIT y punto de venta, para que `emitir()` llegue a mirar el CUIT
     * del receptor (antes de eso corta por falta de config).
     *
     * @param  bool $produccion
     * @return ComerciocityAfipConfig
     */
    protected function config_fiscal($produccion)
    {
        $config = ComerciocityAfipConfig::current();
        $config->cuit = '20423548984';
        $config->punto_venta = 1;
        $config->afip_produccion = $produccion;
        $config->save();

        return $config->fresh();
    }

    public function test_el_cuit_se_deja_solo_con_digitos()
    {
        $this->assertSame('30718863623', AfipFacturacionService::cuit_solo_digitos('30-71886362-3'));
        $this->assertSame('30718863623', AfipFacturacionService::cuit_solo_digitos(' 30.718.863.623 '));
        $this->assertSame('30718863623', AfipFacturacionService::cuit_solo_digitos('30718863623'));
        $this->assertSame('', AfipFacturacionService::cuit_solo_digitos(null));
        $this->assertSame('', AfipFacturacionService::cuit_solo_digitos('sin cuit'));
    }

    public function test_un_cuit_de_menos_de_11_digitos_corta_antes_de_llamar_a_arca()
    {
        $this->config_fiscal(true);
        $client = $this->crear_cliente(['afip_cuit' => '30-7188636-3', 'total_mensualidad' => 27000]);

        $resultado = (new AfipFacturacionService())->emitir($client, '2026-10');

        $this->assertFalse($resultado['ok']);
        $this->assertStringContainsString('tiene 10 dígitos', $resultado['error_message']);
        $this->assertSame(0, MensualidadInvoice::where('client_id', $client->id)->count(), 'No se ensucia el historial con un intento que nunca salió.');
    }

    public function test_un_cuit_sin_ningun_digito_se_trata_como_no_cargado()
    {
        $this->config_fiscal(true);
        $client = $this->crear_cliente(['afip_cuit' => 'sin cuit', 'total_mensualidad' => 27000]);

        $resultado = (new AfipFacturacionService())->emitir($client, '2026-10');

        $this->assertFalse($resultado['ok']);
        $this->assertStringContainsString('Cargá el CUIT fiscal', $resultado['error_message']);
    }

    public function test_un_rechazo_en_homologacion_dice_que_es_homologacion()
    {
        $texto = $this->aviso_de_homologacion($this->config_fiscal(false));

        $this->assertStringContainsString('HOMOLOGACIÓN', $texto);
        $this->assertStringContainsString('Producción', $texto);
    }

    public function test_un_rechazo_en_produccion_no_lleva_aviso()
    {
        $this->assertSame('', $this->aviso_de_homologacion($this->config_fiscal(true)));
    }

    /**
     * @param  ComerciocityAfipConfig $config
     * @return string
     */
    protected function aviso_de_homologacion($config)
    {
        $metodo = new \ReflectionMethod(AfipFacturacionService::class, 'aviso_de_homologacion');
        $metodo->setAccessible(true);

        return $metodo->invoke(new AfipFacturacionService(), $config);
    }

    public function test_una_factura_de_homologacion_no_da_el_periodo_por_facturado_en_produccion()
    {
        $client = $this->crear_cliente(['afip_cuit' => '30718863623', 'total_mensualidad' => 27000]);
        $this->factura_autorizada($client, '2026-10', false);
        $servicio = new AfipFacturacionService();

        $this->config_fiscal(true);
        $this->assertFalse($servicio->ya_facturado($client, '2026-10'), 'El CAE de homologación no vale en producción.');

        $this->config_fiscal(false);
        $this->assertTrue($servicio->ya_facturado($client, '2026-10'), 'En homologación sigue valiendo la de homologación.');
    }

    public function test_una_factura_de_produccion_da_el_periodo_por_facturado_en_produccion()
    {
        $client = $this->crear_cliente(['afip_cuit' => '30718863623', 'total_mensualidad' => 27000]);
        $this->factura_autorizada($client, '2026-10', true);

        $this->config_fiscal(true);

        $this->assertTrue((new AfipFacturacionService())->ya_facturado($client, '2026-10'));
    }

    /**
     * Inserta una Factura C autorizada (con CAE) para el cliente y período, en el ambiente dado.
     *
     * @param  \App\Models\Client $client
     * @param  string $periodo
     * @param  bool   $produccion
     * @return MensualidadInvoice
     */
    protected function factura_autorizada($client, $periodo, $produccion)
    {
        return MensualidadInvoice::create([
            'client_id' => $client->id, 'periodo' => $periodo, 'cbte_tipo' => 11, 'cbte_letra' => 'C',
            'cbte_numero' => 1, 'punto_venta' => 1, 'importe_total' => 27000, 'resultado' => 'A',
            'cae' => '70000000000001', 'afip_produccion' => $produccion,
        ]);
    }

    public function test_el_ta_se_reconoce_por_su_source_segun_el_ambiente()
    {
        $homo = 'CN=wsaahomo, O=AFIP, C=AR, SERIALNUMBER=CUIT 33693450239';
        $prod = 'CN=wsaa, O=AFIP, C=AR, SERIALNUMBER=CUIT 33693450239';

        $this->assertTrue(AfipWsaaService::ta_es_del_ambiente($homo, false));
        $this->assertFalse(AfipWsaaService::ta_es_del_ambiente($homo, true));
        $this->assertTrue(AfipWsaaService::ta_es_del_ambiente($prod, true));
        $this->assertFalse(AfipWsaaService::ta_es_del_ambiente($prod, false));
        // Un source vacío o desconocido se da por bueno: es el comportamiento de siempre.
        $this->assertTrue(AfipWsaaService::ta_es_del_ambiente('', true));
        $this->assertTrue(AfipWsaaService::ta_es_del_ambiente('algo raro', false));
    }

    public function test_un_ta_vigente_de_homologacion_se_regenera_al_pasar_a_produccion()
    {
        $this->escribir_ta('CN=wsaahomo, O=AFIP, C=AR, SERIALNUMBER=CUIT 33693450239');

        $servicio = new WsaaDeTest($this->dir_ta, true);
        $servicio->check_wsaa();

        $this->assertTrue($servicio->regenero, 'El TA de homologación no sirve contra producción: hay que pedir uno nuevo.');
    }

    public function test_un_ta_vigente_del_mismo_ambiente_se_reutiliza()
    {
        $this->escribir_ta('CN=wsaa, O=AFIP, C=AR, SERIALNUMBER=CUIT 33693450239');

        $servicio = new WsaaDeTest($this->dir_ta, true);
        $servicio->check_wsaa();

        $this->assertFalse($servicio->regenero, 'Un TA vigente del ambiente correcto no se vuelve a pedir (ARCA contesta alreadyAuthenticated).');
    }

    public function test_un_ta_de_produccion_se_regenera_al_volver_a_homologacion()
    {
        $this->escribir_ta('CN=wsaa, O=AFIP, C=AR, SERIALNUMBER=CUIT 33693450239');

        $servicio = new WsaaDeTest($this->dir_ta, false);
        $servicio->check_wsaa();

        $this->assertTrue($servicio->regenero);
    }

    /**
     * Escribe un `TA.xml` vigente (vence en 10 horas) con el `source` indicado.
     *
     * @param  string $source
     * @return void
     */
    protected function escribir_ta($source)
    {
        $vence = date('c', time() + 36000);

        file_put_contents($this->dir_ta.'TA.xml',
            '<?xml version="1.0" encoding="UTF-8"?><loginTicketResponse version="1"><header>'
            .'<source>'.$source.'</source><destination>SERIALNUMBER=CUIT 20423548984, CN=comerciocity-alias</destination>'
            .'<uniqueId>1</uniqueId><generationTime>'.date('c').'</generationTime><expirationTime>'.$vence.'</expirationTime>'
            .'</header><credentials><token>t</token><sign>s</sign></credentials></loginTicketResponse>');
    }
}

/**
 * `AfipWsaaService` sin tocar disco real ni red: el directorio de trabajo es uno temporal y
 * `wsaa()` solo anota que se lo llamó, en vez de firmar y pegarle a ARCA.
 */
class WsaaDeTest extends AfipWsaaService
{
    /** @var bool Si `check_wsaa()` decidió pedir un TA nuevo. */
    public $regenero = false;

    public function __construct($work_dir, $es_produccion)
    {
        $this->work_dir = $work_dir;
        $this->testing_es_produccion = $es_produccion;
    }

    protected function wsaa()
    {
        $this->regenero = true;
    }
}

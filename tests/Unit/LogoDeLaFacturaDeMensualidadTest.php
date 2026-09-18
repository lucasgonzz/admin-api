<?php

namespace Tests\Unit;

use App\Http\Controllers\Pdf\MensualidadFacturaPdf;
use Tests\TestCase;

/**
 * `MensualidadFacturaPdf` tiene dos logos que hasta esta misión compartían la
 * misma resolución de ruta (`$this->config->logo_path` con fallback a
 * `logo.jpg`): el del encabezado (marca de ComercioCity, configurable desde
 * Configuración fiscal) y el del pie junto al QR ("Comprobante Autorizado",
 * el oficial de AFIP/ARCA, réplica de `AfipQrPdf::logo_afip()` en
 * empresa-api). Que compartieran ruta significaba que subir un logo
 * personalizado también reemplazaba sin querer el logo obligatorio del pie.
 *
 * Estos tests fijan el comportamiento correcto: el encabezado es
 * configurable (con default `logo_comerciocity.png`), el pie es fijo
 * (siempre `logo.jpg`, sin importar `logo_path`).
 */
class LogoDeLaFacturaDeMensualidadTest extends TestCase
{
    /**
     * Ruta pública del logo personalizado de prueba, relativa a `/afip/`.
     * `print_header()`/`print_qr()` chequean `file_exists()` antes de dibujar,
     * así que el fixture tiene que existir de verdad en disco (el contenido no
     * importa: el double de `Image()` de abajo nunca lo abre).
     *
     * @var string
     */
    const LOGO_CUSTOM_RELATIVO = '/afip/logo_test_custom.png';

    protected function setUp(): void
    {
        parent::setUp();

        file_put_contents(public_path(ltrim(self::LOGO_CUSTOM_RELATIVO, '/')), 'fixture-de-test');
    }

    protected function tearDown(): void
    {
        @unlink(public_path(ltrim(self::LOGO_CUSTOM_RELATIVO, '/')));

        parent::tearDown();
    }

    public function test_encabezado_usa_el_logo_de_comerciocity_por_default()
    {
        $pdf = $this->pdf_de_prueba();

        $pdf->render_header();

        $this->assertCount(1, $pdf->imagenes);
        $this->assertStringContainsString('logo_comerciocity.png', $pdf->imagenes[0]);
    }

    public function test_encabezado_respeta_el_logo_personalizado_si_esta_configurado()
    {
        $pdf = $this->pdf_de_prueba(['logo_path' => self::LOGO_CUSTOM_RELATIVO]);

        $pdf->render_header();

        $this->assertCount(1, $pdf->imagenes);
        $this->assertStringContainsString('logo_test_custom.png', $pdf->imagenes[0]);
    }

    public function test_pie_usa_siempre_el_logo_oficial_de_afip_aunque_haya_uno_personalizado()
    {
        $pdf = $this->pdf_de_prueba(['logo_path' => self::LOGO_CUSTOM_RELATIVO]);

        $pdf->render_qr();

        // El QR se saltea (url_exists() devuelve false en el double), así que la
        // única imagen que queda registrada es la del logo del pie.
        $this->assertCount(1, $pdf->imagenes);
        $this->assertStringContainsString('logo.jpg', $pdf->imagenes[0]);
        $this->assertStringNotContainsString('logo_test_custom.png', $pdf->imagenes[0]);
    }

    /**
     * Double de `MensualidadFacturaPdf` que saltea el constructor real (no hay
     * `MensualidadInvoice` ni base de datos en este test), registra las
     * imágenes que se intentan embeber en vez de dibujarlas, y expone
     * `print_header()`/`print_qr()` para poder llamarlos directo.
     *
     * @param  array<string, mixed> $config_overrides
     * @return object
     */
    private function pdf_de_prueba(array $config_overrides = [])
    {
        return new class($config_overrides) extends MensualidadFacturaPdf {
            /** @var array<int, string> Rutas de imagen que se intentaron embeber. */
            public $imagenes = [];

            public function __construct(array $config_overrides)
            {
                \FPDF::__construct();
                $this->AddPage();

                $this->config = (object) array_merge([
                    'razon_social' => 'ComercioCity',
                    'domicilio_comercial' => 'Test 123, CABA',
                    'condicion_iva' => 'Monotributista',
                    'cuit' => '20423548984',
                    'ingresos_brutos' => 'EXENTO',
                    'inicio_actividades' => null,
                    'logo_path' => null,
                ], $config_overrides);

                $this->invoice = (object) [
                    'created_at' => new \DateTime('2026-09-01'),
                    'cuit_negocio' => '20423548984',
                    'punto_venta' => 1,
                    'cbte_tipo' => 11,
                    'cbte_numero' => 1,
                    'importe_total' => 100.0,
                    'cuit_cliente' => '20111111112',
                    'cae' => '75312345678901',
                ];
            }

            public function Image($file, $x = null, $y = null, $w = 0, $h = 0, $type = '', $link = '')
            {
                $this->imagenes[] = $file;
            }

            protected function url_exists($url)
            {
                // Evita el fetch real del QR contra internet en el test.
                return false;
            }

            public function render_header()
            {
                $this->print_header();
            }

            public function render_qr()
            {
                $this->print_qr();
            }
        };
    }
}

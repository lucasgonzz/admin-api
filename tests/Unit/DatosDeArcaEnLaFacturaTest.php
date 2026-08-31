<?php

namespace Tests\Unit;

use App\Helpers\Utf8Normalizer;
use App\Http\Controllers\Pdf\MensualidadFacturaPdf;
use PHPUnit\Framework\TestCase;

/**
 * Los dos pasos silenciosos entre lo que devuelve ARCA y lo que se ve impreso en
 * la Factura C: la conversión de encoding y el ajuste del texto al ancho de la
 * celda del PDF.
 *
 * Los dos fallan de una forma que ningún test de endpoint agarra —el request
 * responde 200, la base guarda, la factura se emite— y recién se ve en el papel:
 * acentos rotos en un caso, texto impreso fuera del recuadro en el otro.
 */
class DatosDeArcaEnLaFacturaTest extends TestCase
{
    /**
     * ARCA contesta en ISO-8859-1 (así se abre el SoapClient). Una razón social
     * con acento tiene que llegar a UTF-8 legible, no como bytes rotos.
     *
     * @return void
     */
    public function test_convierte_a_utf8_lo_que_llega_en_iso_8859_1()
    {
        $en_iso = mb_convert_encoding('CONFITERÍA LA ESPAÑOLA', 'ISO-8859-1', 'UTF-8');

        // Precondición del test: el string de entrada NO es UTF-8 válido.
        $this->assertFalse(mb_check_encoding($en_iso, 'UTF-8'));

        $convertido = Utf8Normalizer::convertir($en_iso);

        $this->assertTrue(mb_check_encoding($convertido, 'UTF-8'));
        $this->assertSame('CONFITERÍA LA ESPAÑOLA', $convertido);
    }

    /**
     * Un texto que ya viene en UTF-8 válido no se toca: convertirlo de nuevo lo
     * rompería.
     *
     * @return void
     */
    public function test_no_toca_lo_que_ya_es_utf8_valido()
    {
        $this->assertSame('ÑOÑO S.A.', Utf8Normalizer::convertir('ÑOÑO S.A.'));
    }

    /**
     * 🔴 El apóstrofo se restaura, no se borra. El original de empresa-api hace
     * `str_replace("\\'", '')` y convierte "D'ANGELO" en "DANGELO", que es lo que
     * después sale impreso en la factura.
     *
     * @return void
     */
    public function test_restaura_el_apostrofo_escapado_en_vez_de_borrarlo()
    {
        $this->assertSame("D'ANGELO S.R.L.", Utf8Normalizer::convertir("D\\'ANGELO S.R.L."));
    }

    /**
     * Limpia caracteres de control y colapsa espacios, que es lo que ensucia el
     * renglón del comprobante.
     *
     * @return void
     */
    public function test_limpia_controles_y_espacios_de_mas()
    {
        $this->assertSame('FERRETERIA COLMAN', Utf8Normalizer::convertir("  FERRETERIA\x00   COLMAN  "));
    }

    /**
     * Recorre arrays anidados sin romper los valores que no son texto.
     *
     * @return void
     */
    public function test_recorre_el_array_sin_tocar_lo_que_no_es_texto()
    {
        $resultado = Utf8Normalizer::convertir([
            'razon_social' => 'ÑANDU SA',
            'cuit' => 30718519531,
            'activo' => true,
            'domicilio' => null,
        ]);

        $this->assertSame('ÑANDU SA', $resultado['razon_social']);
        $this->assertSame(30718519531, $resultado['cuit']);
        $this->assertTrue($resultado['activo']);
        $this->assertNull($resultado['domicilio']);
    }

    /**
     * Un valor corto se imprime tal cual y en el cuerpo normal (8): el ajuste no
     * tiene que degradar lo que ya entraba.
     *
     * @return void
     */
    public function test_un_valor_que_entra_se_imprime_tal_cual_y_en_cuerpo_ocho()
    {
        $pdf = $this->pdf_de_prueba();
        $pdf->ajustar('CHACABUCO 239, GUALEGUAY, ENTRE RIOS', 95);

        $this->assertCount(1, $pdf->celdas);
        $this->assertSame('CHACABUCO 239, GUALEGUAY, ENTRE RIOS', $pdf->celdas[0]['texto']);
        $this->assertSame(8, $pdf->celdas[0]['tamanio']);
    }

    /**
     * 🔴 El caso que motivó el arreglo: un domicilio de CABA no entra a cuerpo 8
     * en los 95mm de la celda. Antes se imprimía igual, pisando el borde del
     * recuadro y saliéndose de la hoja, porque `Cell()` de FPDF no recorta.
     *
     * @return void
     */
    public function test_un_domicilio_largo_entra_en_el_ancho_disponible()
    {
        $largo = 'AV RIVADAVIA 1234 PISO 5 DPTO A, CIUDAD AUTONOMA DE BUENOS AIRES';

        $pdf = $this->pdf_de_prueba();
        $pdf->ajustar($largo, 95);

        $impreso = $pdf->celdas[0];

        // Lo impreso mide, en el cuerpo con el que se imprimió, menos que el ancho.
        $pdf->SetFont('Arial', '', $impreso['tamanio']);
        $this->assertLessThanOrEqual(95, $pdf->GetStringWidth(utf8_decode($impreso['texto'])));

        // Y se achicó el cuerpo antes que recortar: no se perdió ni un carácter.
        $this->assertLessThan(8, $impreso['tamanio']);
        $this->assertSame($largo, $impreso['texto']);
    }

    /**
     * Cuando ni en el cuerpo mínimo entra, se recorta con puntos suspensivos —
     * pero nunca se imprime más ancho que la celda.
     *
     * @return void
     */
    public function test_un_valor_imposible_se_recorta_pero_nunca_se_desborda()
    {
        $pdf = $this->pdf_de_prueba();
        $pdf->ajustar(str_repeat('LARGUISIMO ', 40), 78);

        $impreso = $pdf->celdas[0];

        $pdf->SetFont('Arial', '', $impreso['tamanio']);
        $this->assertLessThanOrEqual(78, $pdf->GetStringWidth(utf8_decode($impreso['texto'])));
        $this->assertStringEndsWith('...', $impreso['texto']);
    }

    /**
     * El recorte no parte un carácter multibyte al medio (usa `mb_substr`): un
     * acento cortado por la mitad sale como basura en el PDF.
     *
     * @return void
     */
    public function test_el_recorte_no_parte_un_acento_al_medio()
    {
        $pdf = $this->pdf_de_prueba();
        $pdf->ajustar(str_repeat('ÑÁÉÍÓÚ', 60), 78);

        $this->assertTrue(mb_check_encoding($pdf->celdas[0]['texto'], 'UTF-8'));
    }

    /**
     * PDF de prueba que registra qué texto y con qué cuerpo se mandó a imprimir.
     *
     * Saltea a propósito el constructor de `MensualidadFacturaPdf` (que renderiza
     * el comprobante entero y necesita un `MensualidadInvoice` y la config fiscal)
     * llamando directo al de FPDF: acá solo interesa el ajuste de texto.
     *
     * @return object
     */
    private function pdf_de_prueba()
    {
        return new class extends MensualidadFacturaPdf {
            /** @var array<int, array<string, mixed>> Celdas impresas: texto y cuerpo usado. */
            public $celdas = [];

            public function __construct()
            {
                \FPDF::__construct();
                $this->AddPage();
            }

            public function Cell($w, $h = 0, $txt = '', $border = 0, $ln = 0, $align = '', $fill = false, $link = '')
            {
                $this->celdas[] = ['texto' => $txt, 'tamanio' => $this->FontSizePt];

                parent::Cell($w, $h, $txt, $border, $ln, $align, $fill, $link);
            }

            /**
             * Expone el helper protegido que se quiere probar.
             *
             * @param  string $texto
             * @param  float  $ancho
             * @return void
             */
            public function ajustar($texto, $ancho)
            {
                $this->print_valor_ajustado($texto, $ancho);
            }
        };
    }
}

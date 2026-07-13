<?php

namespace App\Http\Controllers\Pdf;

use App\Models\ComerciocityAfipConfig;
use App\Models\MensualidadInvoice;
use fpdf;

require_once __DIR__.'/../CommonLaravel/fpdf/fpdf.php';

/**
 * PDF de la Factura C emitida por la mensualidad de un Client (prompt 331).
 *
 * Es un port simplificado, NO acoplado a `Sale`, del layout de
 * `SaleAfipTicketPdf` (empresa-api): misma cabecera de emisor/receptor y
 * mismo pie fiscal (CAE + QR AFIP, replicando `AfipQrPdf`), pero con un
 * layout fijo de un único ítem ("Mantenimiento e infraestructura de
 * plataforma de gestión") en vez del sistema de `PdfColumnProfile` (ese
 * sistema es para ventas con artículos variables; acá el ítem es siempre
 * el mismo). Al ser Factura C (Monotributista), no se discrimina IVA.
 *
 * Se instancia y en el constructor ya arma y devuelve el PDF (mismo patrón
 * que `SaleAfipTicketPdf`): `new MensualidadFacturaPdf($invoice, $config)`
 * renderiza el documento completo.
 */
class MensualidadFacturaPdf extends fpdf
{
    /**
     * Comprobante (Factura C) a imprimir, con su `client` cargado.
     *
     * @var MensualidadInvoice
     */
    protected $invoice;

    /**
     * Cliente (receptor) facturado.
     *
     * @var \App\Models\Client
     */
    protected $client;

    /**
     * Configuración fiscal del emisor (ComercioCity).
     *
     * @var ComerciocityAfipConfig
     */
    protected $config;

    /**
     * Arma el PDF completo de la Factura C.
     *
     * @param MensualidadInvoice    $invoice Comprobante ya autorizado (con `client` cargado).
     * @param ComerciocityAfipConfig $config  Datos fiscales del emisor (ComercioCity).
     */
    function __construct(MensualidadInvoice $invoice, ComerciocityAfipConfig $config)
    {
        parent::__construct();
        $this->SetAutoPageBreak(true, 1);

        $this->invoice = $invoice;
        $this->client = $invoice->client;
        $this->config = $config;

        $this->AddPage();
        $this->print_header();
        $this->print_client_info();
        $this->print_table();
        $this->print_totales();
        $this->print_pie_fiscal();
    }

    /**
     * Devuelve el PDF ya armado como string (destino 'S' de FPDF), en vez
     * de imprimirlo directo con `Output()`+`exit`. Se agregó porque el
     * patrón original (heredado de `SaleAfipTicketPdf`, empresa-api) hace
     * `header()`+echo a mano y salta por completo el kernel de Laravel:
     * al no devolver nunca un `Response`, el middleware de CORS
     * (`HandleCors`) no llega a correr, y desde admin-spa (origen distinto
     * a admin-api) el navegador bloquea la respuesta por faltarle
     * `Access-Control-Allow-Origin`. Devolviendo el contenido como string,
     * el controlador arma una `Response` normal que sí pasa por el
     * pipeline completo de Laravel.
     *
     * @return string
     */
    public function contenido()
    {
        return $this->Output('S');
    }

    /**
     * Cabecera: datos del emisor (ComercioCity) a la izquierda, recuadro con
     * la letra "C" + datos del comprobante (punto de venta, número, fecha) a
     * la derecha. Réplica de `SaleAfipTicketPdf::printTicketCommerceInfo()`
     * pero leyendo el emisor desde `ComerciocityAfipConfig` en vez de
     * `afip_information` (no hay `Sale` ni `Auth()->user()` acá).
     *
     * @return void
     */
    protected function print_header()
    {
        // Réplica de `SaleAfipTicketPdf::__Header()`: sin este reset, el
        // cursor arranca en el margen default de FPDF (10mm) en vez de
        // 5mm, y todo el contenido del header queda corrido respecto de
        // las líneas del recuadro (que usan coordenadas absolutas fijas),
        // superponiéndose con el recuadro de la letra del comprobante.
        $this->SetXY(5, 5);

        $this->SetFont('Arial', 'B', 14);
        $this->Cell(200, 10, 'ORIGINAL', 'T-B', 0, 'C');

        // Recuadro con la letra de comprobante y el código AFIP.
        $this->SetY(15);
        $this->SetX(97);
        $this->SetFont('Arial', 'B', 20);
        $this->Cell(16, 16, 'C', 1, 0, 'C');
        $this->SetY(26);
        $this->SetX(97);
        $this->SetFont('Arial', 'B', 9);
        $this->Cell(16, 5, 'COD. 11', 0, 0, 'C');

        // Datos del emisor (ComercioCity).
        $this->SetY(17);
        $this->SetX(40);
        $this->SetFont('Arial', 'B', 12);
        $this->MultiCell(55, 6, (string) $this->config->razon_social, 0, 'L', false);

        $this->SetX(40);
        $this->SetFont('Arial', '', 9);
        $this->MultiCell(55, 5, (string) $this->config->domicilio_comercial, 0, 'L', false);

        $this->SetX(40);
        $this->SetFont('Arial', 'B', 9);
        $this->Cell(10, 5, 'IVA:', 0, 0, 'L');
        $this->SetFont('Arial', '', 9);
        $this->Cell(50, 5, 'IVA '.$this->config->condicion_iva, 0, 1, 'L');

        $this->SetX(40);
        $this->SetFont('Arial', 'B', 9);
        $this->Cell(20, 4, 'CUIT:', 0, 0, 'L');
        $this->SetFont('Arial', '', 9);
        $this->Cell(50, 4, (string) $this->config->cuit, 0, 1, 'L');

        if (! empty($this->config->inicio_actividades)) {
            $this->SetX(40);
            $this->SetFont('Arial', 'B', 9);
            $this->Cell(20, 4, 'Inicio Act:', 0, 0, 'L');
            $this->SetFont('Arial', '', 9);
            $this->Cell(25, 4, date_format($this->config->inicio_actividades, 'd/m/Y'), 0, 1, 'L');
        }

        // Logo de ComercioCity, si existe en el disco.
        $logo_path = public_path().'/afip/logo.jpg';
        if (@file_exists($logo_path)) {
            $this->Image($logo_path, 5, 15.2, 35, 35);
        }

        // Datos del comprobante: título, punto de venta, número y fecha.
        $this->SetY(17);
        $this->SetX(118);
        $this->SetFont('Arial', 'B', 18);
        $this->Cell(35, 10, 'FACTURA C', 0, 1, 'L');

        $this->SetX(118);
        $this->SetFont('Arial', 'B', 9);
        $this->Cell(27, 5, 'Punto de Venta:', 0, 0, 'L');
        $this->Cell(15, 5, $this->format_punto_venta(), 0, 0, 'L');
        $this->Cell(21, 5, 'Comp. Nro:', 0, 0, 'L');
        $this->Cell(27, 5, $this->format_num_cbte(), 0, 1, 'L');

        $this->SetX(118);
        $this->SetFont('Arial', 'B', 9);
        $this->Cell(32, 5, 'Fecha de Emisión:', 0, 0, 'L');
        $this->Cell(20, 5, date_format($this->invoice->created_at, 'd/m/Y'), 0, 1, 'L');

        $this->SetX(118);
        $this->SetFont('Arial', 'B', 9);
        $this->Cell(30, 5, 'Ingresos Brutos:', 0, 0, 'L');
        $this->Cell(25, 5, (string) $this->config->ingresos_brutos, 0, 1, 'L');

        // Líneas del recuadro de cabecera (igual que `printCommerceLines`).
        $this->SetLineWidth(.3);
        $this->Line(5, 50, 205, 50);
        $this->Line(5, 5, 5, 50);
        $this->Line(205, 5, 205, 50);
        $this->Line(105, 31, 105, 50);

        $this->y = 55;
    }

    /**
     * Datos del receptor (Client): CUIT, condición IVA, razón social y domicilio.
     * Réplica de `SaleAfipTicketPdf::printClientInfo()`.
     *
     * @return void
     */
    protected function print_client_info()
    {
        $this->SetY(53);
        $this->SetX(6);
        $this->SetFont('Arial', 'B', 8);
        $this->Cell(10, 5, 'CUIT:', 0, 0, 'L');
        $this->SetFont('Arial', '', 8);
        $this->Cell(20, 5, (string) $this->client->afip_cuit, 0, 1, 'C');

        $this->SetX(6);
        $this->SetFont('Arial', 'B', 8);
        $this->Cell(37, 5, 'Condición frente al IVA:', 0, 0, 'L');
        $this->SetFont('Arial', '', 8);
        $this->Cell(50, 5, (string) $this->client->afip_condicion_iva, 0, 1, 'L');

        // Ojo con el orden: SetY() resetea X al margen izquierdo por default,
        // por eso va primero acá (si no, pisa el SetX(80) siguiente y este
        // bloque termina renderizando encima de la columna de CUIT).
        $this->SetY(53);
        $this->SetX(80);
        $this->SetFont('Arial', 'B', 8);
        $this->Cell(47, 5, 'Apellido y Nombre / Razón Social:', 0, 0, 'L');
        $this->SetFont('Arial', '', 8);
        $this->Cell(60, 5, (string) $this->client->afip_razon_social, 0, 1, 'L');

        $this->SetX(80);
        $this->SetFont('Arial', 'B', 8);
        $this->Cell(30, 5, 'Domicilio Comercial:', 0, 0, 'L');
        $this->SetFont('Arial', '', 8);
        $this->Cell(60, 5, (string) $this->client->afip_domicilio, 0, 1, 'L');

        // Líneas del recuadro de receptor (igual que `printClientLines`).
        $this->SetLineWidth(.3);
        $this->Line(5, 52, 205, 52);
        $this->Line(5, 68, 205, 68);
        $this->Line(5, 52, 5, 68);
        $this->Line(205, 52, 205, 68);

        $this->y = 75;
    }

    /**
     * Tabla de ítems: layout fijo con un único renglón (sin `PdfColumnProfile`,
     * a diferencia de `SaleAfipTicketPdf`), ya que la Factura C de mensualidad
     * siempre factura el mismo concepto. Sin columna de IVA (Factura C).
     *
     * @return void
     */
    protected function print_table()
    {
        $this->SetX(5);
        $this->SetFont('Arial', 'B', 9);
        $this->Cell(90, 6, 'Descripción', 1, 0, 'L');
        $this->Cell(20, 6, 'Cantidad', 1, 0, 'C');
        $this->Cell(40, 6, 'Precio Unitario', 1, 0, 'C');
        $this->Cell(50, 6, 'Subtotal', 1, 1, 'C');

        $total = (float) $this->invoice->importe_total;
        $this->SetX(5);
        $this->SetFont('Arial', '', 9);
        $this->Cell(90, 6, 'Mantenimiento e infraestructura de plataforma de gestión', 1, 0, 'L');
        $this->Cell(20, 6, '1', 1, 0, 'C');
        $this->Cell(40, 6, '$'.$this->format_price($total), 1, 0, 'R');
        $this->Cell(50, 6, '$'.$this->format_price($total), 1, 1, 'R');

        $this->y += 5;
    }

    /**
     * Totales: al ser Factura C, subtotal e importe total son el mismo valor
     * (no hay IVA que discriminar).
     *
     * @return void
     */
    protected function print_totales()
    {
        $total = (float) $this->invoice->importe_total;

        $this->SetX(120);
        $this->SetFont('Arial', 'B', 10);
        $this->Cell(40, 6, 'Subtotal:', 0, 0, 'L');
        $this->Cell(40, 6, '$'.$this->format_price($total), 0, 1, 'R');

        $this->SetX(120);
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(40, 7, 'Importe Total:', 0, 0, 'L');
        $this->Cell(40, 7, '$'.$this->format_price($total), 0, 1, 'R');

        $this->y += 5;
    }

    /**
     * Pie fiscal: CAE + fecha de vencimiento y el QR de AFIP (obligatorio
     * legalmente). Réplica del armado de `AfipQrPdf::qr()` pero leyendo los
     * datos desde `MensualidadInvoice` en vez de un `afip_ticket` de venta.
     *
     * @return void
     */
    protected function print_pie_fiscal()
    {
        $this->SetX(5);
        $this->SetFont('Arial', 'B', 10);
        $this->Cell(90, 6, 'CAE N°: '.$this->invoice->cae, 0, 1, 'L');

        $this->SetX(5);
        $vencimiento = $this->invoice->cae_expired_at
            ? date_format($this->invoice->cae_expired_at, 'd/m/Y')
            : '';
        $this->Cell(90, 6, 'Fecha de Vto. de CAE: '.$vencimiento, 0, 1, 'L');

        $this->y += 5;
        $this->print_qr();
    }

    /**
     * Arma y embebe el QR de AFIP (mismo `$data` y misma URL de verificación
     * que `AfipQrPdf::qr()`), más el logo "Comprobante Autorizado".
     *
     * @return void
     */
    protected function print_qr()
    {
        $img_width = 40;
        $img_start_x = 5;

        // Datos exigidos por AFIP para el QR del comprobante (mismo formato que `AfipQrPdf`).
        $data = [
            'ver'        => 1,
            'fecha'      => date_format($this->invoice->created_at, 'Y-m-d'),
            'cuit'       => $this->invoice->cuit_negocio,
            'ptoVta'     => $this->invoice->punto_venta,
            'tipoCmp'    => $this->invoice->cbte_tipo,
            'nroCmp'     => $this->invoice->cbte_numero,
            'importe'    => (float) $this->invoice->importe_total,
            'moneda'     => 'PES',
            'ctz'        => 1,
            'tipoDocRec' => 80,
            'nroDocRec'  => $this->invoice->cuit_cliente,
            'codAut'     => $this->invoice->cae,
        ];

        $afip_link = 'https://www.afip.gob.ar/fe/qr/?'.base64_encode(json_encode($data));
        $qr_url = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data='.$afip_link.'&format=jpeg#.jpg';

        if ($this->url_exists($qr_url)) {
            $this->Image($qr_url, $img_start_x, $this->y, $img_width);
        }

        $this->y += $img_width + 2;

        $logo_path = public_path().'/afip/logo.jpg';
        if (@file_exists($logo_path)) {
            $this->Image($logo_path, $img_start_x, $this->y, 30, 15);
            $this->y += 15;
        }

        $this->SetX($img_start_x);
        $this->SetFont('Arial', 'BI', 10);
        $this->Cell(100, 5, 'Comprobante Autorizado', 0, 1, 'L');
    }

    /**
     * Chequea si una URL remota responde antes de intentar embeberla como
     * imagen (equivalente acotado a `GeneralHelper::file_exists_2` de
     * empresa-api, que admin-api no tiene).
     *
     * @param string $url
     * @return bool
     */
    protected function url_exists($url)
    {
        $headers = @get_headers($url);

        return is_array($headers) && isset($headers[0]) && strpos($headers[0], '200') !== false;
    }

    /**
     * Formatea un monto con separador de miles y dos decimales (equivalente
     * acotado a `Numbers::price` de empresa-api, que admin-api no tiene).
     *
     * @param float $amount
     * @return string
     */
    protected function format_price($amount)
    {
        return number_format((float) $amount, 2, ',', '.');
    }

    /**
     * Punto de venta con padding a 4 dígitos (ej. `1` -> `0001`).
     *
     * @return string
     */
    protected function format_punto_venta()
    {
        return str_pad((string) $this->invoice->punto_venta, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Número de comprobante con padding a 8 dígitos (ej. `1` -> `00000001`).
     *
     * @return string
     */
    protected function format_num_cbte()
    {
        return str_pad((string) $this->invoice->cbte_numero, 8, '0', STR_PAD_LEFT);
    }
}

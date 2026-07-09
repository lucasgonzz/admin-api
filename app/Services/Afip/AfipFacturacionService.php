<?php

namespace App\Services\Afip;

use App\Models\Client;
use App\Models\ComerciocityAfipConfig;
use App\Models\MensualidadInvoice;
use Illuminate\Support\Facades\Log;

/**
 * Orquesta la emisión de la Factura C (WSFE) por la mensualidad de un Client
 * (prompt 331). Es la continuación de `ClientMensualidadService` (prompt 329):
 * ese servicio calcula y guarda `total_mensualidad`; este servicio toma ese
 * monto ya calculado y lo factura contra AFIP tal cual, sin recalcularlo.
 *
 * Port simplificado del flujo `AfipWsfeHelper::solicitar_cae()` (empresa-api),
 * pero para un comprobante plano: sin `Sale`, sin artículos, sin cuenta
 * corriente. ComercioCity es Monotributista, así que siempre emite Factura C
 * (comprobante tipo 11): sin IVA discriminado (`ImpNeto = ImpTotal`, `ImpIVA = 0`,
 * sin nodo `Iva`, que en el original solo aplica a Responsable Inscripto).
 */
class AfipFacturacionService
{
    /**
     * Tipo de comprobante AFIP: Factura C. ComercioCity es Monotributista y
     * factura un único ítem conceptual, nunca discrimina IVA.
     *
     * @var int
     */
    const CBTE_TIPO_FACTURA_C = 11;

    /**
     * Tipo de documento AFIP para CUIT (el receptor siempre se identifica por CUIT).
     *
     * @var int
     */
    const DOC_TIPO_CUIT = 80;

    /**
     * Emite (o recupera, si ya está autorizada) la Factura C de la mensualidad
     * de un Client para un período dado.
     *
     * @param  Client $client  Cliente a facturar (receptor).
     * @param  string $periodo Período a facturar, formato 'YYYY-MM'.
     * @return array{ok: bool, ya_facturado: bool, resultado: string|null, cae: string|null,
     *               cbte_numero: int|null, error_message: string|null, invoice_id: int|null}
     */
    public function emitir(Client $client, $periodo)
    {
        // 1. Idempotencia: un período ya autorizado (CAE emitido) nunca se re-emite.
        $invoice_ya_autorizada = MensualidadInvoice::where('client_id', $client->id)
            ->where('periodo', $periodo)
            ->where('resultado', 'A')
            ->whereNotNull('cae')
            ->latest('id')
            ->first();

        if ($invoice_ya_autorizada) {
            return $this->respuesta($invoice_ya_autorizada, true);
        }

        // 2. Config fiscal del emisor (ComercioCity): CUIT y punto de venta son obligatorios.
        $config = ComerciocityAfipConfig::current();

        if (empty($config->cuit) || empty($config->punto_venta)) {
            return $this->respuesta_error('Falta configurar el CUIT o el punto de venta de ComercioCity antes de facturar (ver configuración fiscal).');
        }

        // 3. Datos fiscales del receptor: sin CUIT no se puede armar el comprobante (DocTipo 80).
        if (empty($client->afip_cuit)) {
            return $this->respuesta_error('Cargá el CUIT fiscal del cliente antes de facturar.');
        }

        // 4. Monto a facturar: el total de mensualidad ya calculado en admin (prompts 328/329).
        $total = round((float) $client->total_mensualidad, 2);

        if ($total <= 0) {
            return $this->respuesta_error('El total de la mensualidad debe ser mayor a 0 para poder facturar.');
        }

        // 5. Asegura un TA (Ticket de Acceso) vigente y arma el cliente WSFE autenticado con él.
        try {
            $wsaa = new AfipWsaaService('wsfe');
            $wsaa->check_wsaa();

            $wsfe = new AfipWsfeService(! $config->afip_produccion, $config->cuit);
            $wsfe->set_xml_ta(file_get_contents($wsaa->ta_file_path()));
        } catch (\Throwable $e) {
            Log::error('AfipFacturacionService: error obteniendo TA/WSFE - '.$e->getMessage());

            return $this->respuesta_error('No se pudo autenticar contra AFIP (WSAA): '.$e->getMessage());
        }

        // 6. Número de comprobante: último autorizado + 1 para este punto de venta y tipo.
        $numero_result = $wsfe->obtener_proximo_numero_comprobante($config->punto_venta, self::CBTE_TIPO_FACTURA_C);

        if ($numero_result['hubo_un_error']) {
            return $this->respuesta_error('No se pudo obtener el próximo número de comprobante de AFIP: '.$numero_result['error']);
        }

        $numero = $numero_result['numero_comprobante'];

        // Condición IVA del receptor cacheada en el Client (texto libre → id AFIP).
        $condicion_receptor_id = CondicionIvaReceptorResolver::resolve($client->afip_condicion_iva);

        // 7. Arma el FeCAEReq para Factura C monotributo: sin IVA discriminado, un único ítem conceptual.
        $invoice = [
            'FeCAEReq' => [
                'FeCabReq' => [
                    'CantReg' => 1,
                    'CbteTipo' => self::CBTE_TIPO_FACTURA_C,
                    'PtoVta' => $config->punto_venta,
                ],
                'FeDetReq' => [
                    'FECAEDetRequest' => [
                        // Concepto 1 = productos (igual que empresa-api).
                        'Concepto' => 1,
                        'DocTipo' => self::DOC_TIPO_CUIT,
                        'DocNro' => $client->afip_cuit,
                        'CbteDesde' => $numero,
                        'CbteHasta' => $numero,
                        'CbteFch' => date('Ymd'),
                        'ImpTotal' => $total,
                        'ImpTotConc' => 0,
                        // Factura C: el neto es igual al total (no hay IVA que discriminar).
                        'ImpNeto' => $total,
                        'ImpOpEx' => 0,
                        // Factura C: sin IVA. No se agrega el nodo 'Iva' (solo aplica a Responsable Inscripto).
                        'ImpIVA' => 0,
                        'ImpTrib' => 0,
                        'MonId' => 'PES',
                        'MonCotiz' => 1,
                        'CondicionIVAReceptorId' => $condicion_receptor_id,
                    ],
                ],
            ],
        ];

        // 8. Solicita el CAE contra AFIP.
        $result = $wsfe->FECAESolicitar($invoice);

        // Snapshot base persistido siempre, haya o no error (para poder auditar el intento).
        $datos_invoice = [
            'client_id' => $client->id,
            'periodo' => $periodo,
            'cbte_tipo' => self::CBTE_TIPO_FACTURA_C,
            'cbte_letra' => 'C',
            'cbte_numero' => $numero,
            'punto_venta' => $config->punto_venta,
            'cuit_negocio' => $config->cuit,
            'cuit_cliente' => $client->afip_cuit,
            'doc_tipo' => self::DOC_TIPO_CUIT,
            'doc_nro' => $client->afip_cuit,
            'importe_total' => $total,
            'imp_neto' => $total,
            'imp_iva' => 0,
            'condicion_iva_receptor_id' => $condicion_receptor_id,
            'afip_produccion' => (bool) $config->afip_produccion,
            'request' => $result['request'],
            'response' => $result['response'],
        ];

        if ($result['hubo_un_error']) {
            // Error de red/SOAP: no hubo respuesta interpretable de AFIP.
            $datos_invoice['resultado'] = 'R';
            $datos_invoice['error_message'] = $result['error'];

            $invoice = MensualidadInvoice::create($datos_invoice);

            return $this->respuesta($invoice, false);
        }

        // 9. Interpreta el resultado de AFIP igual que AfipWsfeHelper::update_afip_ticket().
        $afip_result = $result['result'];
        $cab_resp = isset($afip_result->FECAESolicitarResult->FeCabResp) ? $afip_result->FECAESolicitarResult->FeCabResp : null;
        $det_resp = isset($afip_result->FECAESolicitarResult->FeDetResp->FECAEDetResponse) ? $afip_result->FECAESolicitarResult->FeDetResp->FECAEDetResponse : null;

        if ($cab_resp && $cab_resp->Resultado == 'A' && $det_resp) {
            $datos_invoice['resultado'] = 'A';
            $datos_invoice['cae'] = $det_resp->CAE;
            $datos_invoice['cae_expired_at'] = $det_resp->CAEFchVto;
        } else {
            $datos_invoice['resultado'] = 'R';
            $datos_invoice['error_message'] = $this->extraer_error_legible($det_resp);
        }

        $invoice = MensualidadInvoice::create($datos_invoice);

        return $this->respuesta($invoice, false);
    }

    /**
     * Arma un mensaje de error legible a partir de los nodos `Errors`/`Observaciones`
     * que devuelve AFIP cuando rechaza un comprobante.
     *
     * @param  object|null $det_resp Nodo `FECAEDetResponse` de la respuesta de AFIP.
     * @return string
     */
    protected function extraer_error_legible($det_resp)
    {
        if (! $det_resp) {
            return 'AFIP rechazó el comprobante sin detalle de error.';
        }

        $mensajes = [];

        if (isset($det_resp->Observaciones->Obs)) {
            $obs = is_array($det_resp->Observaciones->Obs) ? $det_resp->Observaciones->Obs : [$det_resp->Observaciones->Obs];
            foreach ($obs as $ob) {
                $mensajes[] = '['.$ob->Code.'] '.$ob->Msg;
            }
        }

        if (empty($mensajes)) {
            return 'AFIP rechazó el comprobante sin detalle de error.';
        }

        return implode(' | ', $mensajes);
    }

    /**
     * Arma la respuesta estándar del servicio a partir de un MensualidadInvoice ya persistido.
     *
     * @param  MensualidadInvoice $invoice
     * @param  bool               $ya_facturado Si se abortó por idempotencia (período ya autorizado).
     * @return array
     */
    protected function respuesta(MensualidadInvoice $invoice, $ya_facturado)
    {
        return [
            'ok' => $invoice->resultado === 'A',
            'ya_facturado' => $ya_facturado,
            'resultado' => $invoice->resultado,
            'cae' => $invoice->cae,
            'cbte_numero' => $invoice->cbte_numero,
            'error_message' => $invoice->error_message,
            'invoice_id' => $invoice->id,
        ];
    }

    /**
     * Arma una respuesta de error legible cuando la emisión ni siquiera pudo
     * intentarse contra AFIP (validaciones previas), sin persistir ningún registro.
     *
     * @param  string $mensaje
     * @return array
     */
    protected function respuesta_error($mensaje)
    {
        return [
            'ok' => false,
            'ya_facturado' => false,
            'resultado' => null,
            'cae' => null,
            'cbte_numero' => null,
            'error_message' => $mensaje,
            'invoice_id' => null,
        ];
    }
}

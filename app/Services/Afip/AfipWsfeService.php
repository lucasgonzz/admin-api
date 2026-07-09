<?php

namespace App\Services\Afip;

use Illuminate\Support\Facades\Log;

/**
 * Cliente SOAP del WSFE (Web Service de Facturación Electrónica) de AFIP.
 *
 * Port simplificado de `App\Models\Afip\WSFE` + `WSN` + `WS` (empresa-api),
 * las tres clases que allá arman la jerarquía "WebService de Negocio" genérica
 * (con cache de WSDL en disco, reintentos de red, soporte multi-servicio, etc).
 * Acá se colapsan en una sola clase porque admin-api solo necesita este único
 * web service (`wsfe`) para un único emisor (ComercioCity), sin la complejidad
 * de reutilizar la jerarquía para wsfex/constancia de inscripción como en el
 * original.
 *
 * Mantiene el mismo contrato de retorno que el original en todos los métodos:
 * `['hubo_un_error' => bool, 'result' => mixed, 'request' => string|null,
 * 'response' => string|null, 'error' => string|null]`, para que el orquestador
 * (`AfipFacturacionService`) pueda interpretarlo igual que `AfipWsfeHelper`.
 */
class AfipWsfeService
{
    /**
     * Si el entorno activo es producción (true) u homologación (false).
     *
     * @var bool
     */
    protected $testing_es_produccion;

    /**
     * CUIT del emisor representado (ComercioCity), tal como lo requiere el
     * nodo `Auth.Cuit` de cada request WSFE.
     *
     * @var string
     */
    protected $cuit_representada;

    /**
     * URL del servicio SOAP WSFE (homologación o producción).
     *
     * @var string
     */
    protected $url_wsfe;

    /**
     * Token del TA (Ticket de Acceso) vigente, cargado vía `set_xml_ta()`.
     *
     * @var string|null
     */
    protected $ta_token;

    /**
     * Sign del TA vigente, cargado vía `set_xml_ta()`.
     *
     * @var string|null
     */
    protected $ta_sign;

    /**
     * Instancia de SoapClient ya creada (se reutiliza entre llamadas del mismo request).
     *
     * @var \SoapClient|null
     */
    protected $soap_client;

    /**
     * Constructor: resuelve la URL del web service según el ambiente y guarda
     * el CUIT representado para armar el nodo `Auth` de cada request.
     *
     * @param bool   $testing            true = homologación, false = producción.
     * @param string $cuit_representada  CUIT del emisor (ComercioCity).
     */
    public function __construct($testing, $cuit_representada)
    {
        $this->testing_es_produccion = ! $testing;
        $this->cuit_representada = $cuit_representada;

        // URL WSFE según ambiente (misma que usa el WSFE model de empresa-api).
        $this->url_wsfe = $testing
            ? 'https://wswhomo.afip.gov.ar/wsfev1/service.asmx'
            : 'https://servicios1.afip.gov.ar/wsfev1/service.asmx';
    }

    /**
     * Carga el TA (Token+Sign) vigente desde el XML devuelto por AfipWsaaService,
     * para poder autenticar los requests WSFE. Equivalente a `WSFE::setXmlTa()`.
     *
     * @param  string $ta_xml Contenido del TA.xml vigente.
     * @return $this
     * @throws \Exception Si el XML no tiene los nodos de credenciales esperados.
     */
    public function set_xml_ta($ta_xml)
    {
        $ta = new \SimpleXMLElement($ta_xml);

        if (! isset($ta->credentials->token) || ! isset($ta->credentials->sign)) {
            throw new \Exception('AfipWsfeService: el TA recibido no tiene token/sign validos.');
        }

        $this->ta_token = (string) $ta->credentials->token;
        $this->ta_sign = (string) $ta->credentials->sign;

        return $this;
    }

    /**
     * Solicita el CAE (Código de Autorización Electrónico) de un comprobante.
     * Equivalente a `WSFE::FECAESolicitar()` (heredado de `WSN::__call`).
     *
     * @param  array $invoice Payload `['FeCAEReq' => [...]]` armado por el orquestador.
     * @return array{hubo_un_error: bool, result: mixed, request: string|null, response: string|null, error: string|null}
     */
    public function FECAESolicitar($invoice)
    {
        return $this->call_soap('FECAESolicitar', $invoice);
    }

    /**
     * Consulta el último número de comprobante autorizado para un punto de
     * venta + tipo de comprobante. Equivalente a `WSFE::FECompUltimoAutorizado()`.
     *
     * @param  int $punto_venta
     * @param  int $cbte_tipo
     * @return array{hubo_un_error: bool, result: mixed, request: string|null, response: string|null, error: string|null}
     */
    public function FECompUltimoAutorizado($punto_venta, $cbte_tipo)
    {
        return $this->call_soap('FECompUltimoAutorizado', [
            'PtoVta' => $punto_venta,
            'CbteTipo' => $cbte_tipo,
        ]);
    }

    /**
     * Consulta el último comprobante autorizado y devuelve directamente el
     * próximo número a usar (último + 1). Equivalente a
     * `AfipHelper::getNumeroComprobante()` / `AfipWsfeHelper::get_numero_comprobante()`
     * de empresa-api.
     *
     * @param  int $punto_venta
     * @param  int $cbte_tipo
     * @return array{hubo_un_error: bool, numero_comprobante: int|null, error: string|null}
     */
    public function obtener_proximo_numero_comprobante($punto_venta, $cbte_tipo)
    {
        $result = $this->FECompUltimoAutorizado($punto_venta, $cbte_tipo);

        if (! $result['hubo_un_error']) {
            return [
                'hubo_un_error' => false,
                'numero_comprobante' => $result['result']->FECompUltimoAutorizadoResult->CbteNro + 1,
            ];
        }

        return [
            'hubo_un_error' => true,
            'error' => $result['error'],
        ];
    }

    /**
     * Ejecuta un método SOAP del WSFE inyectando el nodo `Auth` (Token/Sign/Cuit)
     * requerido por AFIP en todos sus web services de negocio, y normaliza la
     * respuesta al mismo formato que devolvía `WS::__call()` en empresa-api.
     *
     * @param  string $method_name Nombre del método SOAP (ej. 'FECAESolicitar').
     * @param  array  $params      Parámetros propios del método (sin el nodo Auth).
     * @return array{hubo_un_error: bool, result: mixed, request: string|null, response: string|null, error: string|null}
     */
    protected function call_soap($method_name, array $params)
    {
        $hubo_un_error = false;
        $result = null;
        $error = null;

        try {
            if (is_null($this->soap_client)) {
                $this->soap_client = new \SoapClient($this->url_wsfe.'?WSDL', [
                    'soap_version' => SOAP_1_1,
                    'location' => $this->url_wsfe,
                    'trace' => 1,
                    'exceptions' => 1,
                    'connection_timeout' => 15,
                ]);
            }

            // Nodo de autenticación requerido por AFIP en cada request WSFE.
            $datos = [
                'Auth' => [
                    'Token' => $this->ta_token,
                    'Sign' => $this->ta_sign,
                    'Cuit' => $this->cuit_representada,
                ],
            ];
            $datos += $params;

            $result = $this->soap_client->$method_name($datos);
        } catch (\SoapFault $e) {
            $hubo_un_error = true;
            $error = $e->getMessage();

            Log::error('AfipWsfeService: SOAP Fault en '.$method_name.' - '.$error);
        } catch (\Throwable $e) {
            $hubo_un_error = true;
            $error = $e->getMessage();

            Log::error('AfipWsfeService: error en '.$method_name.' - '.$error);
        }

        // Request/response crudos para poder auditar la emisión (se guardan en MensualidadInvoice).
        $last_request = null;
        $last_response = null;

        if ($this->soap_client) {
            try {
                $last_request = $this->soap_client->__getLastRequest();
                $last_response = $this->soap_client->__getLastResponse();
            } catch (\Throwable $e) {
                // No romper el flujo por no poder loguear request/response.
            }
        }

        return [
            'hubo_un_error' => $hubo_un_error,
            'result' => $result,
            'request' => $last_request,
            'response' => $last_response,
            'error' => $error,
        ];
    }
}

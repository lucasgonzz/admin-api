<?php

namespace App\Services\Afip;

use Illuminate\Support\Facades\Log;

/**
 * Cliente SOAP del padrón A5 de ARCA/AFIP (servicio `ws_sr_constancia_inscripcion`),
 * el que devuelve la constancia de inscripción de un contribuyente por CUIT.
 *
 * Port simplificado de `App\Models\Afip\WSSRConstanciaInscripcion` + `WSN` + `WS`
 * (empresa-api), colapsado en una sola clase por el mismo criterio con el que
 * `AfipWsfeService` portó la jerarquía de WSFE: esas tres clases existen allá
 * para reutilizarse entre wsfe / wsfex / padrón, y acá hay un solo servicio.
 *
 * 🔴 Dos diferencias con WSFE que NO son cosmética; si se pierden, el servicio
 *    responde error y parece un problema de credenciales:
 *
 *    1. El nodo de autenticación es PLANO: `token`, `sign` y `cuitRepresentada`
 *       viajan al mismo nivel que `idPersona`, no dentro de un nodo `Auth`
 *       anidado como en WSFE. Es la rama `for_constancia_de_inscripcion` de
 *       `WSN::__call()` en el original.
 *    2. El SOAP se abre con `encoding => ISO-8859-1`. Es lo que espera este
 *       servicio; la respuesta se normaliza a UTF-8 después, en el orquestador
 *       (`AfipConstanciaInscripcionService`), con `Utf8Normalizer`.
 *
 * Mantiene el mismo contrato de retorno que `AfipWsfeService`:
 * `['hubo_un_error' => bool, 'result' => mixed, 'error' => string|null]`.
 */
class AfipPadronService
{
    /**
     * URL del padrón A5 en producción. Es la única que se usa: la consulta al
     * padrón es una lectura sin efecto fiscal y la homologación devuelve datos
     * de prueba que no sirven para cargar un cliente real (mismo criterio que
     * `empresa-api`, que arranca con `$testing = false` hardcodeado).
     *
     * @var string
     */
    const URL_PRODUCCION = 'https://aws.afip.gov.ar/sr-padron/webservices/personaServiceA5';

    /**
     * CUIT del titular del certificado con el que se firma el TA, tal como lo
     * exige el parámetro `cuitRepresentada` de cada request.
     *
     * 🔴 NO es el CUIT que se consulta: es el de ComercioCity, dueña del
     * certificado habilitado en ARCA para este servicio.
     *
     * @var string
     */
    protected $cuit_representada;

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
     * @param string $cuit_representada CUIT del titular del certificado (ComercioCity).
     */
    public function __construct($cuit_representada)
    {
        $this->cuit_representada = $cuit_representada;
    }

    /**
     * Carga el TA (Token+Sign) vigente desde el XML que dejó `AfipWsaaService`.
     * Equivalente a `WSN::setXmlTa()`.
     *
     * @param  string $ta_xml Contenido del TA.xml vigente.
     * @return $this
     * @throws \Exception Si el XML no tiene los nodos de credenciales esperados.
     */
    public function set_xml_ta($ta_xml)
    {
        $ta = new \SimpleXMLElement($ta_xml);

        if (! isset($ta->credentials->token) || ! isset($ta->credentials->sign)) {
            throw new \Exception('AfipPadronService: el TA recibido no tiene token/sign validos.');
        }

        $this->ta_token = (string) $ta->credentials->token;
        $this->ta_sign = (string) $ta->credentials->sign;

        return $this;
    }

    /**
     * Consulta la constancia de inscripción de un contribuyente por CUIT.
     * Equivalente a `WSSRConstanciaInscripcion::getPersona_v2()` del original.
     *
     * @param  string $cuit CUIT a consultar, solo dígitos (11).
     * @return array{hubo_un_error: bool, result: mixed, error: string|null}
     */
    public function get_persona_v2($cuit)
    {
        return $this->call_soap('getPersona_v2', ['idPersona' => $cuit]);
    }

    /**
     * Ejecuta un método SOAP del padrón inyectando las credenciales PLANAS que
     * exige este servicio, y normaliza la respuesta al mismo formato que el
     * resto de los servicios AFIP de admin-api.
     *
     * @param  string $method_name Nombre del método SOAP (ej. 'getPersona_v2').
     * @param  array  $params      Parámetros propios del método (sin credenciales).
     * @return array{hubo_un_error: bool, result: mixed, error: string|null}
     */
    protected function call_soap($method_name, array $params)
    {
        $result = null;

        try {
            if (is_null($this->soap_client)) {
                $this->soap_client = new \SoapClient(self::URL_PRODUCCION.'?WSDL', [
                    'soap_version' => SOAP_1_1,
                    'location' => self::URL_PRODUCCION,
                    // Ver el docblock de la clase: lo pide el servicio. La respuesta
                    // se pasa a UTF-8 en el orquestador, no acá.
                    'encoding' => 'ISO-8859-1',
                    'cache_wsdl' => WSDL_CACHE_NONE,
                    'trace' => 1,
                    'exceptions' => 1,
                    'connection_timeout' => 15,
                ]);
            }

            // Credenciales PLANAS (no nodo `Auth`): es lo que distingue a este
            // servicio de WSFE. Ver el punto 1 del docblock de la clase.
            $datos = [
                'token' => $this->ta_token,
                'sign' => $this->ta_sign,
                'cuitRepresentada' => $this->cuit_representada,
            ];
            $datos += $params;

            $result = $this->soap_client->$method_name($datos);
        } catch (\SoapFault $e) {
            Log::error('AfipPadronService: SOAP Fault en '.$method_name.' - '.$e->getMessage());

            return [
                'hubo_un_error' => true,
                'result' => null,
                'error' => $e->getMessage(),
            ];
        } catch (\Throwable $e) {
            Log::error('AfipPadronService: error en '.$method_name.' - '.$e->getMessage());

            return [
                'hubo_un_error' => true,
                'result' => null,
                'error' => $e->getMessage(),
            ];
        }

        return [
            'hubo_un_error' => false,
            'result' => $result,
            'error' => null,
        ];
    }
}

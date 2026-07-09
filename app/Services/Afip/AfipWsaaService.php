<?php

namespace App\Services\Afip;

use App\Models\ComerciocityAfipConfig;
use Illuminate\Support\Facades\Log;

/**
 * Servicio de autenticación WSAA de AFIP para ComercioCity.
 *
 * Port de `App\Http\Controllers\Helpers\Afip\AfipWSAAHelper` (empresa-api) a
 * admin-api: firma un TRA (Ticket de Requerimiento de Acceso) con el
 * certificado + clave privada de ComercioCity, lo manda por SOAP a
 * `LoginCms` de AFIP y cachea en disco el TA (Token+Sign) resultante,
 * válido por ~12hs, para que los servicios WSFE de facturación (prompt 331)
 * puedan usarlo sin volver a autenticarse en cada request.
 *
 * Diferencias respecto del original de empresa-api:
 * - El entorno (homologación/producción) no depende de un `sale`: se lee de
 *   `ComerciocityAfipConfig::current()->afip_produccion`, porque ComercioCity
 *   factura una única cuenta propia, no por cliente.
 * - Los archivos (certificado, clave y TA/TRA de trabajo) viven en
 *   `storage/app/afip/...` en vez de `public/afip/...`, para no exponerlos
 *   nunca por la web.
 * - Ante error de firma o SOAP fault se lanza una `\Exception` en vez de
 *   `exit()`: acá no puede tumbar el proceso, lo debe poder capturar el
 *   flujo de facturación (prompt 331) y mostrarlo como error controlado.
 */
class AfipWsaaService
{
    /**
     * Nombre del web service de AFIP para el que se autentica este TA.
     * ComercioCity solo emite Factura C por WSFE (no exporta), por eso
     * siempre es 'wsfe'; se deja como parámetro por si a futuro hiciera
     * falta otro servicio (ej. wsfex), igual que en el original.
     *
     * @var string
     */
    protected $ws_name;

    /**
     * Si el entorno activo es producción (true) u homologación (false).
     * Se resuelve una sola vez en el constructor a partir de la config
     * fiscal global de ComercioCity.
     *
     * @var bool
     */
    protected $testing_es_produccion;

    /**
     * Ruta 'file://...' al certificado (.crt) usado para firmar el TRA.
     *
     * @var string
     */
    protected $cert;

    /**
     * Ruta 'file://...' a la clave privada (.key) usada para firmar el TRA.
     *
     * @var string
     */
    protected $private_key;

    /**
     * URL del servicio SOAP LoginCms de AFIP (homologación o producción).
     *
     * @var string
     */
    protected $url_wsaa;

    /**
     * Directorio de trabajo donde se leen/escriben TRA.xml, TRA.tmp,
     * TA.xml, CMS.txt, request.xml y response.xml para este ws_name.
     *
     * @var string
     */
    protected $work_dir;

    /**
     * Constructor: resuelve entorno (prod/homologación) desde la config
     * fiscal de ComercioCity y arma todas las rutas de archivos necesarias.
     *
     * @param  string $ws_name Nombre del web service AFIP (default 'wsfe').
     */
    public function __construct($ws_name = 'wsfe')
    {
        $this->ws_name = $ws_name;

        $this->define();
    }

    /**
     * Resuelve entorno y rutas de certificado/clave/trabajo según
     * `ComerciocityAfipConfig::current()->afip_produccion`.
     *
     * @return void
     */
    protected function define()
    {
        // Config fiscal única de ComercioCity: define si se opera en
        // producción o en homologación contra los web services de AFIP.
        $afip_config = ComerciocityAfipConfig::current();
        $this->testing_es_produccion = (bool) $afip_config->afip_produccion;

        // Directorio de trabajo (TRA/TA/CMS) para este ws_name, dentro de storage.
        $this->work_dir = storage_path('app/afip/wsaa/'.$this->ws_name.'/');

        // Crea el directorio de trabajo si todavía no existe (recursivo).
        if (! is_dir($this->work_dir)) {
            mkdir($this->work_dir, 0755, true);
        }

        if ($this->testing_es_produccion) {
            // Entorno de producción: certificado real de ComercioCity y URL productiva de AFIP.
            $this->cert = 'file://'.realpath(storage_path('app/afip/production/comerciocity.crt'));
            $this->private_key = 'file://'.realpath(storage_path('app/afip/production/comerciocity.key'));
            $this->url_wsaa = 'https://wsaa.afip.gov.ar/ws/services/LoginCms';
        } else {
            // Entorno de homologación: certificado de pruebas y URL de test de AFIP.
            $this->cert = 'file://'.realpath(storage_path('app/afip/testing/comerciocity.crt'));
            $this->private_key = 'file://'.realpath(storage_path('app/afip/testing/comerciocity.key'));
            $this->url_wsaa = 'https://wsaahomo.afip.gov.ar/ws/services/LoginCms';
        }
    }

    /**
     * Ruta absoluta al archivo TA.xml (Token+Sign vigente) de este ws_name.
     * Pensado para que el servicio WSFE de facturación lo lea directamente.
     *
     * @return string
     */
    public function ta_file_path()
    {
        return $this->work_dir.'TA.xml';
    }

    /**
     * Garantiza que exista un TA (Token+Sign) vigente en `TA.xml`: si no
     * existe, está incompleto o venció, vuelve a autenticarse contra WSAA.
     * Equivalente a `checkWsaa()` del helper original de empresa-api.
     *
     * @return void
     * @throws \Exception Si falla la firma del TRA o AFIP responde un SOAP fault.
     */
    public function check_wsaa()
    {
        $ta_file = $this->ta_file_path();

        if (file_exists($ta_file)) {
            Log::info('AfipWsaaService: TA.xml existe, se valida vigencia');
            $ta = new \SimpleXMLElement(file_get_contents($ta_file));

            if (! isset($ta->header->expirationTime) || ! isset($ta->credentials->token) || ! isset($ta->credentials->sign)) {
                Log::info('AfipWsaaService: el TA no tiene los datos necesarios, se regenera');
                $this->wsaa();
            } else if (strtotime($ta->header->expirationTime) < time()) {
                Log::info('AfipWsaaService: el TA estaba vencido, se regenera');
                $this->wsaa();
            } else {
                Log::info('AfipWsaaService: el TA vigente esta OK');
            }
        } else {
            Log::info('AfipWsaaService: el TA no estaba creado, se genera');
            $this->wsaa();
        }
    }

    /**
     * Orquesta el ciclo completo de autenticación WSAA: crea el TRA, lo
     * firma (CMS) y lo envía a AFIP para obtener y persistir el TA.
     *
     * @return void
     * @throws \Exception Si falla la firma del TRA o AFIP responde un SOAP fault.
     */
    protected function wsaa()
    {
        $this->create_tra();

        $cms = $this->sign_tra();
        $ta = $this->call_wsaa($cms);

        file_put_contents($this->ta_file_path(), $ta);
    }

    /**
     * Genera el TRA (Ticket de Requerimiento de Acceso): un XML con un id
     * único, ventana de validez de 60 segundos y el nombre del ws_name a
     * autenticar, y lo persiste en TRA.xml para poder firmarlo.
     *
     * @return void
     */
    protected function create_tra()
    {
        $tra = new \SimpleXMLElement(
            '<?xml version="1.0" encoding="UTF-8"?>'.
            '<loginTicketRequest version="1.0">'.
            '</loginTicketRequest>'
        );

        $tra->addChild('header');
        $tra->header->addChild('uniqueId', date('U'));
        $tra->header->addChild('generationTime', date('c', date('U') - 60));
        $tra->header->addChild('expirationTime', date('c', date('U') + 60));
        $tra->addChild('service', $this->ws_name);
        $tra->asXML($this->work_dir.'TRA.xml');
    }

    /**
     * Firma el TRA con el certificado y clave privada de ComercioCity
     * (PKCS#7) y extrae el CMS resultante (sin las cabeceras MIME que
     * agrega `openssl_pkcs7_sign`), guardándolo también en CMS.txt.
     *
     * @return string CMS (mensaje firmado) listo para enviar a WSAA.
     * @throws \Exception Si `openssl_pkcs7_sign` no pudo firmar el TRA
     *                     (certificado/clave inválidos o inaccesibles).
     */
    protected function sign_tra()
    {
        $tra_xml = $this->work_dir.'TRA.xml';
        $tra_tmp = $this->work_dir.'TRA.tmp';
        $cms_file = $this->work_dir.'CMS.txt';

        $status = openssl_pkcs7_sign(
            $tra_xml,
            $tra_tmp,
            $this->cert,
            $this->private_key,
            [],
            ! PKCS7_DETACHED
        );

        if (! $status) {
            // No se pudo firmar: certificado o clave privada inválidos, vencidos o
            // con ruta incorrecta. Se corta acá con una excepción legible en vez
            // de exit(), para que el flujo de facturación la capture como error.
            throw new \Exception('AfipWsaaService: error generando la firma PKCS#7 del TRA (revisar certificado/clave en storage/app/afip).');
        }

        // openssl_pkcs7_sign agrega 4 líneas de cabecera MIME antes del CMS real;
        // se descartan para quedarnos solo con el cuerpo firmado.
        $inf = fopen($tra_tmp, 'r');
        $i = 0;
        $cms = '';
        while (! feof($inf)) {
            $buffer = fgets($inf);
            if ($i++ >= 4) {
                $cms .= $buffer;
            }
        }
        fclose($inf);
        unlink($tra_tmp);

        file_put_contents($cms_file, $cms);

        return $cms;
    }

    /**
     * Envía el CMS firmado al servicio SOAP `LoginCms` de WSAA y devuelve
     * el TA (Token+Sign) recibido. Persiste el request/response SOAP en
     * disco para poder auditar fallos.
     *
     * @param  string $cms CMS firmado devuelto por `sign_tra()`.
     * @return string TA (Token+Sign) en XML tal como lo devuelve AFIP.
     * @throws \Exception Si AFIP responde un SOAP fault (ej. TRA duplicado, vencido, etc.).
     */
    protected function call_wsaa($cms)
    {
        Log::info('AfipWsaaService: llamando a LoginCms de WSAA ('.$this->url_wsaa.')');

        $client = new \SoapClient($this->url_wsaa.'?WSDL', [
            'location' => $this->url_wsaa,
            'trace' => 1,
            'exceptions' => 0,
        ]);

        $results = $client->loginCms(['in0' => $cms]);

        file_put_contents($this->work_dir.'request.xml', $client->__getLastRequest());
        file_put_contents($this->work_dir.'response.xml', $client->__getLastResponse());

        if (is_soap_fault($results)) {
            Log::info('AfipWsaaService: SOAP Fault '.$results->faultcode."\n".$results->faultstring);

            // Error de AFIP (ej. TRA repetido, certificado no habilitado, etc.):
            // se propaga como excepción legible en vez de exit() para no tumbar el request.
            throw new \Exception('AfipWsaaService: SOAP Fault de AFIP - '.$results->faultcode.': '.$results->faultstring);
        }

        return $results->loginCmsReturn;
    }
}

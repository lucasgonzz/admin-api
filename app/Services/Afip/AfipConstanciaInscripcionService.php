<?php

namespace App\Services\Afip;

use App\Helpers\Utf8Normalizer;
use App\Models\ComerciocityAfipConfig;
use Illuminate\Support\Facades\Log;

/**
 * Trae de ARCA los datos de un contribuyente a partir de su CUIT, para completar
 * los datos fiscales del receptor antes de facturarle la mensualidad.
 *
 * Port de `AfipConstanciaInscripcionController::get_constancia_inscripcion()`
 * (empresa-api), donde el mismo dato se usa para dar de alta un cliente desde
 * VENDER. Diferencias con el original, todas por el destino del dato:
 *
 * - Devuelve el domicilio **armado en un solo string** (dirección, localidad,
 *   provincia), porque acá el receptor tiene una única columna
 *   `clients.afip_domicilio`, mientras que en empresa hay `address`,
 *   `location_id` y `provincia_id` separados.
 * - La condición IVA se devuelve con los valores del select del modal
 *   ("Monotributista" / "Responsable inscripto"), que son los que
 *   `CondicionIvaReceptorResolver` sabe traducir al id de AFIP, en vez del
 *   "MONOTRIBUTO" / "RESPONSABLE INSCRIPTO" crudo de ARCA.
 * - No se resuelve el camino de DNI (padrón A13): a un cliente de ComercioCity
 *   se le factura siempre con `DocTipo 80` (CUIT), un DNI no sirve.
 *
 * 🔴 Va SIEMPRE contra producción, sin mirar `ComerciocityAfipConfig::afip_produccion`.
 *    Es una lectura sin efecto fiscal, y la homologación del padrón devuelve
 *    datos de prueba que no sirven para cargar un cliente real. Es exactamente
 *    lo que hace el original de empresa-api (`$testing = false` hardcodeado).
 */
class AfipConstanciaInscripcionService
{
    /**
     * Nombre del web service de ARCA al que se le pide el TA. El certificado de
     * ComercioCity ya está habilitado para este servicio (es el mismo que usan
     * las empresa-api de todos los clientes).
     *
     * @var string
     */
    const WS_NAME = 'ws_sr_constancia_inscripcion';

    /**
     * Consulta ARCA y devuelve los datos del contribuyente listos para
     * completar el formulario de facturación del cliente.
     *
     * Nunca lanza: cualquier problema (CUIT mal formado, ARCA caída, respuesta
     * incompleta o inconvertible a UTF-8) vuelve como `hubo_un_error` con un
     * texto mostrable al usuario. Por eso el `try` cubre el método entero y no
     * solo la autenticación: el mapeo y la normalización también pueden tirar.
     *
     * @param  string $cuit CUIT a consultar; puede venir con guiones o puntos.
     * @return array{hubo_un_error: bool, error: string|null, datos: array|null}
     */
    public function consultar($cuit)
    {
        $digitos = preg_replace('/\D/', '', (string) $cuit);

        if (strlen($digitos) !== 11) {
            return $this->error('El CUIT tiene que tener 11 dígitos.');
        }

        try {
            // CUIT del titular del certificado con el que se firma el TA. Es el mismo
            // valor que usa la emisión como CUIT del emisor: el certificado de
            // ComercioCity está emitido a este CUIT, así que si acá fuera otro, ARCA
            // rechazaría el request por no coincidir con la firma.
            $config = ComerciocityAfipConfig::current();

            if (empty($config->cuit)) {
                return $this->error('Falta configurar el CUIT de ComercioCity para poder consultar a ARCA (ver configuración fiscal).');
            }

            // `true` = forzar producción, ver el docblock de la clase.
            $wsaa = new AfipWsaaService(self::WS_NAME, true);
            $wsaa->check_wsaa();

            $padron = new AfipPadronService($config->cuit);
            $padron->set_xml_ta(file_get_contents($wsaa->ta_file_path()));

            $respuesta = $padron->get_persona_v2($digitos);

            if ($respuesta['hubo_un_error']) {
                return $this->error('ARCA respondió con un error: '.$respuesta['error']);
            }

            $datos = $this->mapear($respuesta['result'], $digitos);

            if (is_null($datos)) {
                Log::info('AfipConstanciaInscripcionService: respuesta sin datosGenerales para el CUIT '.$digitos);

                return $this->error('ARCA no devolvió datos para el CUIT '.$digitos.'. Verificá que el número sea correcto y que el contribuyente esté activo.');
            }

            return [
                'hubo_un_error' => false,
                'error' => null,
                'datos' => Utf8Normalizer::convertir($datos),
            ];
        } catch (\Throwable $e) {
            Log::error('AfipConstanciaInscripcionService: '.$e->getMessage());

            return $this->error('No se pudo consultar a ARCA: '.$e->getMessage());
        }
    }

    /**
     * Traduce la respuesta SOAP de ARCA a los cuatro campos que guarda el
     * receptor. Devuelve `null` si la respuesta no trae `datosGenerales`, que
     * es como ARCA contesta un CUIT inexistente o dado de baja.
     *
     * @param  mixed  $result  Objeto devuelto por el cliente SOAP.
     * @param  string $digitos CUIT consultado, solo dígitos.
     * @return array|null
     */
    protected function mapear($result, $digitos)
    {
        if (! is_object($result) || ! isset($result->personaReturn) || ! isset($result->personaReturn->datosGenerales)) {
            return null;
        }

        $persona_return = $result->personaReturn;
        $generales = $persona_return->datosGenerales;

        return [
            'cuit' => isset($generales->idPersona) ? (string) $generales->idPersona : $digitos,
            'razon_social' => $this->razon_social($generales),
            'condicion_iva' => $this->condicion_iva($persona_return),
            'domicilio' => $this->domicilio($generales),
        ];
    }

    /**
     * Razón social de una persona jurídica, o "Apellido Nombre" si es física.
     * ARCA devuelve `razonSocial` en un caso y `apellido`/`nombre` en el otro,
     * nunca los dos.
     *
     * @param  object $generales Nodo `datosGenerales` de la respuesta.
     * @return string
     */
    protected function razon_social($generales)
    {
        if (isset($generales->razonSocial) && (string) $generales->razonSocial !== '') {
            return (string) $generales->razonSocial;
        }

        $apellido = isset($generales->apellido) ? (string) $generales->apellido : '';
        $nombre = isset($generales->nombre) ? (string) $generales->nombre : '';

        return trim($apellido.' '.$nombre);
    }

    /**
     * Arma el domicilio fiscal en un solo renglón: dirección, localidad y
     * provincia, salteando las partes que ARCA no haya devuelto.
     *
     * Es el campo que el PDF imprime como "Domicilio Comercial"
     * (`MensualidadFacturaPdf::print_client_info()`).
     *
     * @param  object $generales Nodo `datosGenerales` de la respuesta.
     * @return string
     */
    protected function domicilio($generales)
    {
        if (! property_exists($generales, 'domicilioFiscal')) {
            return '';
        }

        $domicilio_fiscal = $generales->domicilioFiscal;

        // Para algunos contribuyentes ARCA repite el nodo y el cliente SOAP lo
        // entrega como array. Se usa el primero, igual que hace el camino del
        // padrón A13 en empresa-api; sin esto el domicilio se perdía en silencio
        // y era indistinguible de un contribuyente que no tiene domicilio cargado.
        if (is_array($domicilio_fiscal)) {
            $domicilio_fiscal = count($domicilio_fiscal) > 0 ? $domicilio_fiscal[0] : null;
        }

        if (! is_object($domicilio_fiscal)) {
            return '';
        }

        $partes = [];
        $partes_normalizadas = [];

        foreach (['direccion', 'localidad', 'descripcionProvincia'] as $campo) {
            if (! property_exists($domicilio_fiscal, $campo)) {
                continue;
            }

            $valor = trim((string) $domicilio_fiscal->$campo);

            if ($valor === '') {
                continue;
            }

            // CABA viene con localidad y provincia idénticas ("CIUDAD AUTONOMA
            // BUENOS AIRES" las dos). Repetirlo solo alarga un texto que después
            // tiene que entrar en una celda de ancho fijo del PDF de la factura.
            $normalizado = mb_strtoupper($valor);

            if (in_array($normalizado, $partes_normalizadas, true)) {
                continue;
            }

            $partes[] = $valor;
            $partes_normalizadas[] = $normalizado;
        }

        return implode(', ', $partes);
    }

    /**
     * Determina la condición frente al IVA, devuelta con el mismo texto que usa
     * el select del modal (y que `CondicionIvaReceptorResolver` sabe traducir al
     * id de AFIP al facturar).
     *
     * Devuelve string vacío cuando ARCA no permite determinarla: el front no
     * pisa con vacío lo que ya estuviera elegido a mano.
     *
     * @param  object $persona_return Nodo `personaReturn` de la respuesta.
     * @return string
     */
    protected function condicion_iva($persona_return)
    {
        if (isset($persona_return->datosMonotributo)) {
            return 'Monotributista';
        }

        if (isset($persona_return->datosRegimenGeneral->impuesto)) {
            $impuestos = $persona_return->datosRegimenGeneral->impuesto;

            // ARCA devuelve un objeto suelto cuando hay un solo impuesto y un
            // array cuando hay varios; el original de empresa-api solo
            // contempla el array y pierde el caso de un único impuesto.
            if (! is_array($impuestos)) {
                $impuestos = [$impuestos];
            }

            $es_exento = false;

            foreach ($impuestos as $impuesto) {
                if (! isset($impuesto->descripcionImpuesto)) {
                    continue;
                }

                $descripcion = mb_strtoupper(trim((string) $impuesto->descripcionImpuesto));

                // El IVA "a secas" es el del responsable inscripto.
                if ($descripcion === 'IVA') {
                    return 'Responsable inscripto';
                }

                /* Al exento ARCA lo nombra "IVA EXENTO" (impuesto 32). Comparando
                   exacto contra 'IVA' —como hace el original de empresa-api— no
                   matcheaba, la condición volvía vacía, y al facturar
                   CondicionIvaReceptorResolver caía a Consumidor Final (id 5) e
                   imprimía eso en el comprobante. */
                if (strpos($descripcion, 'EXENTO') !== false) {
                    $es_exento = true;
                }
            }

            if ($es_exento) {
                return 'Exento';
            }
        }

        return '';
    }

    /**
     * Arma la respuesta de error estándar del servicio.
     *
     * @param  string $mensaje
     * @return array{hubo_un_error: bool, error: string, datos: null}
     */
    protected function error($mensaje)
    {
        return [
            'hubo_un_error' => true,
            'error' => $mensaje,
            'datos' => null,
        ];
    }
}

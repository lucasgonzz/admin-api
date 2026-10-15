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
     * Tipo de documento AFIP para "Consumidor Final / Otro" (receptor sin identificar). Va siempre
     * con `DocNro = 0`. Es lo que se usa cuando el cliente tiene el tilde `facturar_consumidor_final`
     * (misión mensualidad-consumidor-final, 13/10/2026).
     *
     * @var int
     */
    const DOC_TIPO_CONSUMIDOR_FINAL = 99;

    /**
     * `CondicionIVAReceptorId` de AFIP para Consumidor Final. Es el mismo 5 que
     * `CondicionIvaReceptorResolver::FALLBACK_ID`, pero acá es un dato fijo del comprobante a
     * Consumidor Final y no un fallback: no debe cambiar si alguien toca el fallback del resolver.
     *
     * @var int
     */
    const CONDICION_IVA_CONSUMIDOR_FINAL = 5;

    /**
     * Emite (o recupera, si ya está autorizada) la Factura C de la mensualidad
     * de un Client para un período dado.
     *
     * @param  Client $client  Cliente a facturar (receptor).
     * @param  string $periodo Período a facturar, formato 'YYYY-MM'.
     * @return array{ok: bool, ya_facturado: bool, resultado: string|null, cae: string|null,
     *               cbte_numero: int|null, error_message: string|null, invoice_id: int|null,
     *               importe_total: float|null, consumidor_final: bool|null}
     */
    public function emitir(Client $client, $periodo)
    {
        // 1. Idempotencia: un período ya autorizado (CAE emitido) nunca se re-emite.
        $invoice_ya_autorizada = $this->invoice_autorizada($client, $periodo);

        if ($invoice_ya_autorizada) {
            return $this->respuesta($invoice_ya_autorizada, true);
        }

        // 2. Config fiscal del emisor (ComercioCity): CUIT y punto de venta son obligatorios.
        $config = ComerciocityAfipConfig::current();

        if (empty($config->cuit) || empty($config->punto_venta)) {
            return $this->respuesta_error('Falta configurar el CUIT o el punto de venta de ComercioCity antes de facturar (ver configuración fiscal).');
        }

        // 3. Datos fiscales del receptor: con CUIT (DocTipo 80) o, si el cliente tiene el tilde
        // `facturar_consumidor_final`, a Consumidor Final sin identificar (DocTipo 99). Toda esa
        // decisión vive en `resolver_receptor()`; acá solo se corta si devolvió un error.
        $receptor = $this->resolver_receptor($client);

        if ($receptor['error'] !== null) {
            return $this->respuesta_error($receptor['error']);
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
                        'DocTipo' => $receptor['doc_tipo'],
                        'DocNro' => $receptor['doc_nro'],
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
                        'CondicionIVAReceptorId' => $receptor['condicion_iva_id'],
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
            // A Consumidor Final `cuit_cliente` queda en null: no hay CUIT que guardar, y el PDF y el
            // QR deciden por `doc_tipo`/`doc_nro`.
            'cuit_cliente' => $receptor['cuit_cliente'],
            'doc_tipo' => $receptor['doc_tipo'],
            'doc_nro' => $receptor['doc_nro'],
            'importe_total' => $total,
            'imp_neto' => $total,
            'imp_iva' => 0,
            'condicion_iva_receptor_id' => $receptor['condicion_iva_id'],
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
            $datos_invoice['error_message'] = $this->extraer_error_legible($det_resp).$this->aviso_de_homologacion($config);
        }

        $invoice = MensualidadInvoice::create($datos_invoice);

        return $this->respuesta($invoice, false);
    }

    /**
     * Decide cómo se identifica al receptor en el comprobante y valida lo que haga falta.
     *
     * Dos caminos, y el que se elige lo decide SOLO el tilde del cliente:
     *
     * - `facturar_consumidor_final` en 1: Consumidor Final sin identificar. `DocTipo 99`,
     *   `DocNro '0'`, `CondicionIVAReceptorId 5` y `cuit_cliente` null. 🔴 NO se mira `afip_cuit` ni
     *   `afip_condicion_iva` a propósito: el tilde es una decisión explícita de Lucas, y un CUIT
     *   cargado de antes (o una condición "Responsable inscripto" traída de ARCA) no debe volver a
     *   convertir la factura en una con CUIT ni mandar un `CondicionIVAReceptorId` incoherente con
     *   un `DocTipo 99`. El dato queda guardado por si el tilde se destilda.
     * - Sin el tilde: el camino de siempre, idéntico (mismos mensajes, mismo orden de cortes). El
     *   CUIT viaja a AFIP solo con dígitos, porque el placeholder del modal invita a escribirlo con
     *   guiones (`20-12345678-9`) y así AFIP lo rechaza (hallazgo del 31/8/2026), y la condición IVA
     *   se resuelve desde el texto libre cacheado en el cliente.
     *
     * Público para poder probarse sin llegar a ARCA.
     *
     * @param  Client $client Cliente a facturar (receptor).
     * @return array{error: string|null, doc_tipo: int|null, doc_nro: string|null,
     *               cuit_cliente: string|null, condicion_iva_id: int|null, consumidor_final: bool}
     *         `error` es el mensaje de corte (o null si el receptor está listo para emitir).
     */
    public function resolver_receptor(Client $client)
    {
        // Consumidor Final: no hay nada que validar del cliente (el tilde ya es la decisión).
        if ($client->facturar_consumidor_final) {
            return [
                'error' => null,
                'doc_tipo' => self::DOC_TIPO_CONSUMIDOR_FINAL,
                'doc_nro' => '0',
                'cuit_cliente' => null,
                'condicion_iva_id' => self::CONDICION_IVA_CONSUMIDOR_FINAL,
                'consumidor_final' => true,
            ];
        }

        // Camino con CUIT (DocTipo 80): sin CUIT válido no se puede armar el comprobante.
        $cuit_receptor = self::cuit_solo_digitos($client->afip_cuit);

        if ($cuit_receptor === '') {
            return $this->receptor_con_error('Cargá el CUIT fiscal del cliente antes de facturar.');
        }

        if (strlen($cuit_receptor) !== 11) {
            return $this->receptor_con_error('El CUIT fiscal del cliente tiene '.strlen($cuit_receptor).' dígitos y un CUIT tiene 11. Revisalo antes de facturar.');
        }

        return [
            'error' => null,
            'doc_tipo' => self::DOC_TIPO_CUIT,
            'doc_nro' => $cuit_receptor,
            'cuit_cliente' => $cuit_receptor,
            // Condición IVA del receptor cacheada en el Client (texto libre → id AFIP).
            'condicion_iva_id' => CondicionIvaReceptorResolver::resolve($client->afip_condicion_iva),
            'consumidor_final' => false,
        ];
    }

    /**
     * Resultado de `resolver_receptor()` cuando el receptor no se puede armar: solo trae el
     * mensaje, con el resto de las claves en null para que quien lo lea no tenga que chequear
     * `isset()`.
     *
     * @param  string $mensaje
     * @return array
     */
    protected function receptor_con_error($mensaje)
    {
        return [
            'error' => $mensaje,
            'doc_tipo' => null,
            'doc_nro' => null,
            'cuit_cliente' => null,
            'condicion_iva_id' => null,
            'consumidor_final' => false,
        ];
    }

    /**
     * Deja un CUIT solo con sus dígitos: saca guiones, puntos y espacios, que es como lo escribe la
     * gente y como lo rechaza AFIP en `DocNro`.
     *
     * @param  string|null $valor CUIT tal como está guardado en el cliente.
     * @return string Solo dígitos (cadena vacía si no había ninguno).
     */
    public static function cuit_solo_digitos($valor)
    {
        return preg_replace('/\D/', '', (string) $valor);
    }

    /**
     * ¿Ya hay una Factura C autorizada (resultado 'A', con CAE) para este cliente y período?
     *
     * Público para que el controller pueda consultarlo ANTES de decidir si vale la pena hacer un
     * llamado de red al cliente (hallazgo del chequeo independiente de la misión
     * cobranzas-mejoras, 18/9/2026): sincronizar empleados es una llamada HTTP al empresa-api del
     * cliente que puede tardar varios segundos (timeout + reintentos) y además pisa
     * `cantidad_empleados`/`total_mensualidad` con los datos de HOY — no tiene sentido pagar ese
     * costo, ni arriesgar ese pisado, en un mes que `emitir()` va a cortar de todos modos por esta
     * misma idempotencia sin llegar a leer esos campos.
     *
     * @param  Client $client
     * @param  string $periodo Período a consultar, formato 'YYYY-MM'.
     * @return bool
     */
    public function ya_facturado(Client $client, $periodo)
    {
        return $this->invoice_autorizada($client, $periodo) !== null;
    }

    /**
     * La Factura C autorizada (resultado 'A', con CAE) de este cliente y período, si existe. Si
     * por algún motivo hubiera más de una, la más reciente. Extraído para que `emitir()` y
     * `ya_facturado()` compartan exactamente la misma consulta de idempotencia, en vez de tener
     * dos definiciones de "ya está facturado" que se puedan desincronizar.
     *
     * Solo cuenta la autorizada en el MISMO ambiente que está activo (7/10/2026): una factura con
     * CAE de HOMOLOGACIÓN no tiene validez fiscal, así que al pasar a producción no puede dar el
     * período por facturado ni impedir que se emita la real. En homologación sigue valiendo la
     * de homologación, igual que siempre.
     *
     * @param  Client $client
     * @param  string $periodo
     * @return MensualidadInvoice|null
     */
    protected function invoice_autorizada(Client $client, $periodo)
    {
        return MensualidadInvoice::where('client_id', $client->id)
            ->where('periodo', $periodo)
            ->where('afip_produccion', (bool) ComerciocityAfipConfig::current()->afip_produccion)
            ->where('resultado', 'A')
            ->whereNotNull('cae')
            ->latest('id')
            ->first();
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
     * Texto que se suma a un rechazo de AFIP cuando la emisión fue contra HOMOLOGACIÓN.
     *
     * En homologación ARCA solo conoce unos pocos CUIT de prueba, así que a casi cualquier
     * cliente real lo rechaza con `[10015] ... no se encuentra registrado en los padrones de
     * AFIP`. Ese mensaje no dice que el problema es el ambiente y se lo toma por un dato mal
     * cargado del cliente (7/10/2026: Arfren, Ananda, San Cayetano y 3dTisk). En producción
     * devuelve cadena vacía: el rechazo se muestra tal cual lo manda AFIP.
     *
     * @param  ComerciocityAfipConfig $config
     * @return string
     */
    protected function aviso_de_homologacion(ComerciocityAfipConfig $config)
    {
        if ($config->afip_produccion) {
            return '';
        }

        return ' — OJO: esta emisión fue contra HOMOLOGACIÓN (el ambiente de pruebas de ARCA, sin validez fiscal), donde solo existen unos pocos CUIT de prueba. Si el CUIT del cliente es real, activá Producción en Configuración fiscal.';
    }

    /**
     * Arma la respuesta estándar del servicio a partir de un MensualidadInvoice ya persistido.
     *
     * `importe_total` viaja acá (hallazgo del chequeo independiente, 18/9/2026) para que el
     * frontend pueda mostrar el monto REAL que quedó facturado, en vez de prometer de antemano el
     * que tenía cargado antes de que `emitir_factura_json()` sincronice empleados: ese sync puede
     * cambiar el total entre que Lucas confirma el `window.confirm()` y que AFIP autoriza el
     * comprobante, y una Factura C no se puede corregir después.
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
            'importe_total' => $invoice->importe_total !== null ? (float) $invoice->importe_total : null,
            // Ambiente de la emisión (campo nuevo, opcional para quien consume): false = homologación.
            'afip_produccion' => (bool) $invoice->afip_produccion,
            // La factura salió a Consumidor Final sin identificar (DocTipo 99). Campo nuevo, opcional
            // para quien consume: el confirm/aviso del front puede mostrarlo, y un front anterior
            // lo ignora.
            'consumidor_final' => (int) $invoice->doc_tipo === self::DOC_TIPO_CONSUMIDOR_FINAL,
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

<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ComerciocityAfipConfig;
use App\Models\MensualidadActualizacion;
use App\Models\MensualidadInvoice;
use App\Models\MensualidadInvoicePdfAccessToken;
use App\Models\MensualidadPago;
use App\Models\MensualidadPeriodo;
use App\Http\Controllers\Pdf\MensualidadFacturaPdf;
use App\Services\Afip\AfipConstanciaInscripcionService;
use App\Services\Afip\AfipFacturacionService;
use App\Services\ClientMensualidadService;
use App\Services\ClientMensualidadSyncService;
use App\Services\CobranzasMensualidadService;
use Illuminate\Http\Request;

/**
 * API JSON para consultar y actualizar la mensualidad de un Client desde admin
 * (prompt 329), para emitir su Factura C contra AFIP/WSFE (prompt 331), y para
 * la capa OPCIONAL de sincronización con la empresa-api del cliente (prompt
 * 335: traer conteos vivos / empujar fecha de pago). El cálculo del total
 * sigue siendo autónomo (no depende de esta sincronización). Toda la lógica
 * de cálculo vive en ClientMensualidadService, la de facturación en
 * AfipFacturacionService y la de sync en ClientMensualidadSyncService; este
 * controller solo valida el request y arma la respuesta.
 */
class ClientMensualidadController extends Controller
{
    /**
     * Devuelve el snapshot de mensualidad de un cliente (inputs + total
     * calculado + datos fiscales + desglose por línea).
     *
     * @param  int|string               $clientId
     * @param  ClientMensualidadService $service   Inyectado por el IoC de Laravel.
     * @return \Illuminate\Http\JsonResponse
     */
    public function show_json($clientId, ClientMensualidadService $service)
    {
        $client = Client::findOrFail($clientId);

        return response()->json($service->estado($client));
    }

    /**
     * Actualiza los inputs de mensualidad de un cliente, recalcula el total
     * con la misma fórmula que empresa-api y devuelve el snapshot actualizado.
     *
     * @param  Request                 $request
     * @param  int|string               $clientId
     * @param  ClientMensualidadService $service   Inyectado por el IoC de Laravel.
     * @return \Illuminate\Http\JsonResponse
     */
    public function update_json(Request $request, $clientId, ClientMensualidadService $service)
    {
        $client = Client::findOrFail($clientId);

        // Validación acotada de los inputs de mensualidad y datos fiscales del receptor.
        $validated = $request->validate([
            'precio_plan' => ['required', 'numeric', 'min:0'],
            'precio_por_cuenta' => ['required', 'numeric', 'min:0'],
            'cantidad_empleados' => ['required', 'integer', 'min:0'],
            'tiene_ecommerce' => ['boolean'],
            'tiene_mercado_libre' => ['boolean'],
            'tiene_tienda_nube' => ['boolean'],
            'precio_ecommerce' => ['nullable', 'numeric', 'min:0'],
            'precio_mercado_libre' => ['nullable', 'numeric', 'min:0'],
            'precio_tienda_nube' => ['nullable', 'numeric', 'min:0'],
            'payment_expired_at' => ['nullable', 'date'],
            // Primer mes que se cobra (misión modulo-cobranzas, 18/9/2026). Se guarda como día 1.
            'mensualidad_inicio' => ['nullable', 'date'],
            /* Los `max` son los anchos reales de las columnas en `clients` (migración
               2026_07_08_100100). Sin ellos, un valor más largo —que ahora puede
               llegar solo, traído de ARCA por el botón "Obtener datos"— explota como
               500 genérico contra el modo estricto de MySQL en vez de volver un 422
               que la pantalla pueda mostrar. */
            'afip_cuit' => ['nullable', 'string', 'max:50'],
            'afip_razon_social' => ['nullable', 'string', 'max:120'],
            'afip_condicion_iva' => ['nullable', 'string', 'max:60'],
            'afip_domicilio' => ['nullable', 'string', 'max:200'],
        ]);

        $service->guardar($client, $validated);

        return response()->json($service->estado($client));
    }

    /**
     * Trae de ARCA los datos del contribuyente de un CUIT (razón social,
     * domicilio y condición IVA) para completar los datos fiscales del receptor
     * antes de facturarle. Es el botón "Obtener datos" de la tarjeta
     * Facturación del modal del cliente.
     *
     * NO guarda nada: devuelve los datos para que el front complete el
     * formulario y sea Lucas quien confirme con "Guardar". El `clientId` va en
     * la ruta por consistencia con el resto del grupo (y para que la consulta
     * quede atada a un cliente existente), aunque la consulta a ARCA dependa
     * solo del CUIT.
     *
     * Responde 200 siempre: un CUIT que ARCA no reconoce no es un error del
     * request, es un resultado. El front distingue por `hubo_un_error`, igual
     * que el modal de VENDER en empresa-spa.
     *
     * @param  int|string                        $clientId
     * @param  string                            $cuit     CUIT a consultar (puede traer guiones).
     * @param  AfipConstanciaInscripcionService  $service  Inyectado por el IoC de Laravel.
     * @return \Illuminate\Http\JsonResponse
     */
    public function datos_afip_por_cuit_json($clientId, $cuit, AfipConstanciaInscripcionService $service)
    {
        Client::findOrFail($clientId);

        return response()->json($service->consultar($cuit));
    }

    /**
     * Emite la Factura C de la mensualidad de un cliente contra AFIP (WSFE)
     * para el período indicado (prompt 331). Si el período ya fue autorizado
     * anteriormente, no vuelve a emitir: devuelve el registro existente con
     * `ya_facturado = true`.
     *
     * @param  Request                $request
     * @param  int|string             $clientId
     * @param  AfipFacturacionService $service   Inyectado por el IoC de Laravel.
     * @return \Illuminate\Http\JsonResponse
     */
    public function emitir_factura_json(Request $request, $clientId, AfipFacturacionService $service)
    {
        $client = Client::findOrFail($clientId);

        // Período a facturar: por default, el mes/año actual ('YYYY-MM').
        $periodo = $request->input('periodo', date('Y-m'));

        $resultado = $service->emitir($client, $periodo);

        return response()->json($resultado);
    }

    /**
     * Devuelve el historial completo de Facturas C (intentos de emisión)
     * de un cliente, más reciente primero (prompt 364). Incluye también los
     * intentos rechazados ('R') con su `error_message`, para que Lucas pueda
     * ver qué pasó en cada intento, no solo el último emitido en la sesión
     * actual del modal (que hoy vive únicamente en memoria del frontend).
     *
     * No se seleccionan las columnas `request`/`response` (SOAP crudo de
     * AFIP): son pesadas y no le sirven a la UI. El botón "Ver PDF" de cada
     * fila (prompt 365) reutiliza tal cual `factura_pdf_access_token_json()`
     * y `factura_pdf_view()` (prompt 362), que ya validan por `invoiceId`.
     *
     * @param  int|string $clientId
     * @return \Illuminate\Http\JsonResponse
     */
    public function facturas_json($clientId)
    {
        // Todas las filas de mensualidad_invoices de este cliente (cada una
        // es un intento de emisión, autorizado o rechazado), sin los campos
        // SOAP crudos, ordenadas de la más reciente a la más antigua.
        $facturas = MensualidadInvoice::query()
            ->where('client_id', $clientId)
            ->orderByDesc('created_at')
            ->get([
                'id',
                'periodo',
                'cbte_tipo',
                'cbte_letra',
                'cbte_numero',
                'punto_venta',
                'importe_total',
                'cae',
                'cae_expired_at',
                'resultado',
                'error_message',
                'afip_produccion',
                'created_at',
            ]);

        return response()->json(['facturas' => $facturas], 200);
    }

    /**
     * Devuelve el PDF de una Factura C ya emitida (prompt 331), replicando el
     * layout fiscal de `SaleAfipTicketPdf`/`AfipQrPdf` de empresa-api pero
     * simplificado a un único ítem (`MensualidadFacturaPdf`, prompt 332).
     *
     * Solo se permite generar el PDF de comprobantes autorizados: si la
     * emisión fue rechazada o no tiene CAE, no hay nada fiscalmente válido
     * para imprimir.
     *
     * @param  int|string $clientId
     * @param  int|string $invoiceId
     * @return \Illuminate\Http\Response
     */
    public function factura_pdf($clientId, $invoiceId)
    {
        $invoice = MensualidadInvoice::with('client')
            ->where('client_id', $clientId)
            ->findOrFail($invoiceId);

        // Solo se imprime lo que AFIP autorizó: sin CAE no hay comprobante fiscal válido.
        if ($invoice->resultado !== 'A' || empty($invoice->cae)) {
            return response()->json([
                'error' => 'Esta factura no está autorizada por AFIP (sin CAE), no se puede generar el PDF.',
            ], 422);
        }

        return $this->build_factura_pdf_response($invoice);
    }

    /**
     * Emite un token de un solo uso (vida corta: 2 minutos) que autoriza la
     * vista en vivo del PDF de una Factura C sin pasar por `auth:sanctum`
     * (prompt 362). Ruta autenticada por Bearer normal: admin-spa la pide
     * para armar el `window.open` a `factura_pdf_view` inmediatamente.
     *
     * Aplica la misma validación de autorización AFIP que `factura_pdf()`:
     * no tiene sentido emitir un token de acceso para un PDF que ni siquiera
     * se puede generar.
     *
     * @param  int|string $clientId
     * @param  int|string $invoiceId
     * @return \Illuminate\Http\JsonResponse
     */
    public function factura_pdf_access_token_json($clientId, $invoiceId)
    {
        $invoice = MensualidadInvoice::with('client')
            ->where('client_id', $clientId)
            ->findOrFail($invoiceId);

        // Misma validación de `factura_pdf()`: sin CAE no hay nada que ver.
        if ($invoice->resultado !== 'A' || empty($invoice->cae)) {
            return response()->json([
                'error' => 'Esta factura no está autorizada por AFIP (sin CAE), no se puede generar el PDF.',
            ], 422);
        }

        // Token opaco de un solo uso (mismo mecanismo que `SalePdfAccessToken` de empresa-api).
        $token = bin2hex(random_bytes(32));

        MensualidadInvoicePdfAccessToken::create([
            'token' => $token,
            'mensualidad_invoice_id' => $invoice->id,
            // 2 minutos alcanzan de sobra: admin-spa lo consume al toque para
            // abrir la pestaña, no es un link para compartir ni guardar.
            'expires_at' => now()->addMinutes(2),
        ]);

        return response()->json(['token' => $token], 200);
    }

    /**
     * Emite (o reusa) el link público y durable del PDF de una Factura C, para el botón "Enviar
     * por WhatsApp" (pedido 10, misión cobranzas-mejoras, 18/9/2026). A diferencia de
     * `factura_pdf_access_token_json()` —token de un solo uso, vive 2 minutos, pensado para que
     * admin-spa abra el PDF al toque con `window.open()`— este token NO vence ni se consume:
     * Lucas lo manda por WhatsApp y el cliente tiene que poder volver a abrirlo desde cualquier
     * dispositivo, en cualquier momento.
     *
     * Idempotente: si la factura ya tiene un `public_token` generado, lo reusa tal cual (apretar
     * el botón dos veces manda el mismo link).
     *
     * @param  int|string $clientId
     * @param  int|string $invoiceId
     * @return \Illuminate\Http\JsonResponse
     */
    public function factura_link_whatsapp_json($clientId, $invoiceId)
    {
        $invoice = MensualidadInvoice::where('client_id', $clientId)->findOrFail($invoiceId);

        // Misma validación que el resto de los endpoints de PDF: sin CAE no hay nada que enviar.
        if ($invoice->resultado !== 'A' || empty($invoice->cae)) {
            return response()->json([
                'error' => 'Esta factura no está autorizada por AFIP (sin CAE), no se puede generar el PDF.',
            ], 422);
        }

        if (empty($invoice->public_token)) {
            $invoice->public_token = bin2hex(random_bytes(32));
            $invoice->save();
        }

        return response()->json(['token' => $invoice->public_token], 200);
    }

    /**
     * Vista en vivo del PDF de una Factura C (prompt 362): ruta pública
     * (fuera del grupo `auth:sanctum`) gateada por un token de un solo uso
     * emitido por `factura_pdf_access_token_json()`. Existe porque una
     * navegación directa del navegador (`window.open`) no puede mandar el
     * header `Authorization` que sí agrega el interceptor de axios.
     *
     * @param  int|string $clientId
     * @param  int|string $invoiceId
     * @param  string     $token
     * @return \Illuminate\Http\Response
     */
    public function factura_pdf_view($clientId, $invoiceId, $token)
    {
        // Token válido = existe, corresponde a esta factura, no fue usado
        // todavía y no venció (2 minutos desde su emisión).
        $access = MensualidadInvoicePdfAccessToken::where('token', $token)
            ->where('mensualidad_invoice_id', $invoiceId)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();

        if (! $access) {
            abort(403);
        }

        // De un solo uso: se marca consumido antes de servir el PDF (igual
        // que hace tienda-api con `SalePdfAccessToken`), así una segunda
        // request con el mismo token ya no encuentra un `$access` válido.
        $access->used_at = now();
        $access->save();

        $invoice = MensualidadInvoice::with('client')
            ->where('client_id', $clientId)
            ->findOrFail($invoiceId);

        // Misma validación de autorización AFIP que el resto de los endpoints de PDF.
        if ($invoice->resultado !== 'A' || empty($invoice->cae)) {
            return response()->json([
                'error' => 'Esta factura no está autorizada por AFIP (sin CAE), no se puede generar el PDF.',
            ], 422);
        }

        return $this->build_factura_pdf_response($invoice);
    }

    /**
     * Sirve el PDF de una Factura C por su link público y durable (pedido 10, misión
     * cobranzas-mejoras, 18/9/2026): a diferencia de `factura_pdf_view()` (de un solo uso, vence a
     * los 2 minutos), este NO marca nada como usado ni chequea vencimiento — a propósito, porque
     * es el link que Lucas manda por WhatsApp y tiene que poder volver a abrirse en cualquier
     * momento, desde cualquier dispositivo.
     *
     * Un token que no matchea (factura inexistente, de otro cliente, o de otra factura) da 404
     * liso: nunca 403 ni un mensaje que confirme "el token existe pero...", para no darle
     * información a quien esté probando tokens al azar.
     *
     * @param  int|string $clientId
     * @param  int|string $invoiceId
     * @param  string     $token
     * @return \Illuminate\Http\Response
     */
    public function factura_pdf_publico($clientId, $invoiceId, $token)
    {
        $invoice = MensualidadInvoice::with('client')
            ->where('client_id', $clientId)
            ->where('id', $invoiceId)
            ->where('public_token', $token)
            ->first();

        if (! $invoice || $invoice->resultado !== 'A' || empty($invoice->cae)) {
            abort(404);
        }

        return $this->build_factura_pdf_response($invoice);
    }

    /**
     * Arma la respuesta HTTP con el PDF de una Factura C ya validada como
     * autorizada por AFIP. Extraído de `factura_pdf()` (prompt 332) para que
     * tanto la ruta autenticada como la vista pública gateada por token
     * (prompt 362) compartan la misma lógica de armado de la respuesta.
     *
     * @param  MensualidadInvoice $invoice Comprobante ya validado (con `client` cargado).
     * @return \Illuminate\Http\Response
     */
    private function build_factura_pdf_response(MensualidadInvoice $invoice)
    {
        $config = ComerciocityAfipConfig::current();

        // `MensualidadFacturaPdf` arma el documento en su constructor y expone
        // el contenido vía `contenido()` (destino 'S' de FPDF). A diferencia
        // del patrón original de `SaleAfipTicketPdf` (Output()+exit directo),
        // acá se envuelve en una Response real de Laravel para que pase por
        // el middleware de CORS: admin-spa consume esta API desde otro
        // origen, y una respuesta que se salta el kernel de Laravel nunca
        // lleva el header Access-Control-Allow-Origin.
        $pdf = new MensualidadFacturaPdf($invoice, $config);

        return response($pdf->contenido(), 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="factura_'.$invoice->cbte_numero.'.pdf"');
    }

    /**
     * Trae del empresa-api del cliente los conteos vivos (empleados,
     * ecommerce, mercado libre, tienda nube) y datos fiscales, para que el
     * front precargue el formulario de mensualidad sin cargarlos a mano
     * (prompt 335, capa opcional). No persiste nada por sí solo: Lucas
     * revisa y confirma con el botón "Guardar" habitual.
     *
     * @param  int|string                  $clientId
     * @param  ClientMensualidadSyncService $sync_service Inyectado por el IoC de Laravel.
     * @return \Illuminate\Http\JsonResponse
     */
    public function traer_del_cliente_json($clientId, ClientMensualidadSyncService $sync_service)
    {
        $client = Client::findOrFail($clientId);

        return response()->json($sync_service->traer_del_cliente($client));
    }

    /**
     * Empuja al empresa-api del cliente la fecha de próximo pago y los
     * precios actuales guardados en admin, para que el cliente no tenga que
     * cargarlos a mano en su propio sistema (prompt 335, capa opcional).
     *
     * @param  int|string                  $clientId
     * @param  ClientMensualidadSyncService $sync_service Inyectado por el IoC de Laravel.
     * @return \Illuminate\Http\JsonResponse
     */
    public function actualizar_en_cliente_json($clientId, ClientMensualidadSyncService $sync_service)
    {
        $client = Client::findOrFail($clientId);

        return response()->json($sync_service->actualizar_en_cliente($client));
    }

    /* ------------------------------------------------------------------ *
     |  Cobranzas: meses, pagos y actualizaciones de precio (misión modulo-cobranzas, 18/9/2026)
     * ------------------------------------------------------------------ */

    /**
     * Regla de validación de un mes 'YYYY-MM' (la misma en todos los endpoints de cobranzas).
     */
    const REGLA_PERIODO = 'regex:/^\\d{4}-(0[1-9]|1[0-2])$/';

    /**
     * Los meses de mensualidad del cliente con su estado calculado, mes a mes (incluidos los que
     * no tienen fila). Es la tarjeta "Pagos de la mensualidad" del modal del cliente.
     *
     * @param  Request                     $request  ?desde=YYYY-MM&hasta=YYYY-MM (opcionales).
     * @param  int|string                  $clientId
     * @param  CobranzasMensualidadService $service
     * @return \Illuminate\Http\JsonResponse
     */
    public function periodos_json(Request $request, $clientId, CobranzasMensualidadService $service)
    {
        $client = Client::findOrFail($clientId);

        $validated = $request->validate([
            'desde' => ['nullable', 'string', self::REGLA_PERIODO],
            'hasta' => ['nullable', 'string', self::REGLA_PERIODO],
        ]);

        return response()->json([
            'periodos'           => $service->periodos($client, $validated['desde'] ?? null, $validated['hasta'] ?? null),
            'mensualidad_inicio' => $client->mensualidad_inicio ? $client->mensualidad_inicio->toDateString() : null,
            'mes_corriente'      => $service->mes_corriente(),
        ]);
    }

    /**
     * Registra un pago de la mensualidad de un mes y devuelve el mes recalculado.
     *
     * @param  Request                     $request  {periodo, monto?, fecha_pago?, medio?, observacion?, cerrar_periodo?}
     * @param  int|string                  $clientId
     * @param  CobranzasMensualidadService $service
     * @return \Illuminate\Http\JsonResponse
     */
    public function registrar_pago_json(Request $request, $clientId, CobranzasMensualidadService $service)
    {
        $client = Client::findOrFail($clientId);

        $validated = $request->validate([
            'periodo'        => ['required', 'string', self::REGLA_PERIODO],
            'monto'          => ['nullable', 'numeric', 'min:0'],
            'fecha_pago'     => ['nullable', 'date'],
            'medio'          => ['nullable', 'string', 'max:40'],
            'observacion'    => ['nullable', 'string'],
            'cerrar_periodo' => ['nullable', 'boolean'],
        ]);

        return response()->json([
            'periodo' => $service->registrar_pago($client, $validated, $request->user()),
        ]);
    }

    /**
     * Borra un pago de la mensualidad y devuelve el mes recalculado. El pago tiene que ser de
     * este cliente: un id de otro cliente es un 404, no un borrado.
     *
     * @param  int|string                  $clientId
     * @param  int|string                  $pagoId
     * @param  CobranzasMensualidadService $service
     * @return \Illuminate\Http\JsonResponse
     */
    public function eliminar_pago_json($clientId, $pagoId, CobranzasMensualidadService $service)
    {
        $client = Client::findOrFail($clientId);

        $pago = MensualidadPago::where('client_id', $client->id)->findOrFail($pagoId);

        return response()->json([
            'periodo' => $service->eliminar_pago($client, $pago),
        ]);
    }

    /**
     * Marca a mano el estado de un mes: `sin_cargo`, `pendiente` (reabrir) o `pagado` sin pago.
     * `parcial` no se marca a mano: sale solo de registrar un pago menor al esperado.
     *
     * @param  Request                     $request  {estado, observacion?, monto_esperado?}
     * @param  int|string                  $clientId
     * @param  string                      $periodo  'YYYY-MM' (viene en la ruta).
     * @param  CobranzasMensualidadService $service
     * @return \Illuminate\Http\JsonResponse
     */
    public function marcar_periodo_json(Request $request, $clientId, $periodo, CobranzasMensualidadService $service)
    {
        $client = Client::findOrFail($clientId);

        if (! CobranzasMensualidadService::es_periodo_valido($periodo)) {
            return response()->json(['message' => 'El período tiene que ser YYYY-MM.'], 422);
        }

        $validated = $request->validate([
            'estado'         => ['required', 'string', 'in:' . MensualidadPeriodo::ESTADO_PENDIENTE . ',' . MensualidadPeriodo::ESTADO_PAGADO . ',' . MensualidadPeriodo::ESTADO_SIN_CARGO],
            'observacion'    => ['nullable', 'string'],
            'monto_esperado' => ['nullable', 'numeric', 'min:0'],
        ]);

        return response()->json([
            'periodo' => $service->marcar_periodo(
                $client,
                $periodo,
                $validated['estado'],
                // Null o vacío = no tocar la nota (el servicio lo documenta así); para borrarla no hay caso de uso.
                isset($validated['observacion']) && trim((string) $validated['observacion']) !== '' ? (string) $validated['observacion'] : null,
                isset($validated['monto_esperado']) ? (float) $validated['monto_esperado'] : null
            ),
        ]);
    }

    /**
     * El historial de actualizaciones de precio del cliente (más reciente primero) y el resumen
     * de la última oficial.
     *
     * @param  int|string                  $clientId
     * @param  CobranzasMensualidadService $service
     * @return \Illuminate\Http\JsonResponse
     */
    public function actualizaciones_json($clientId, CobranzasMensualidadService $service)
    {
        $client = Client::findOrFail($clientId);

        return response()->json([
            'actualizaciones' => $service->actualizaciones($client),
            'resumen'         => $service->resumen_actualizacion($client),
        ]);
    }

    /**
     * Registra una actualización de precios y la aplica al cliente (los cinco precios; empleados,
     * toggles y fecha de pago quedan como estaban).
     *
     * @param  Request                     $request  {fecha?, precio_plan, precio_por_cuenta, precio_ecommerce?, precio_mercado_libre?, precio_tienda_nube?, es_oficial?, observacion?}
     * @param  int|string                  $clientId
     * @param  CobranzasMensualidadService $service
     * @return \Illuminate\Http\JsonResponse
     */
    public function registrar_actualizacion_json(Request $request, $clientId, CobranzasMensualidadService $service)
    {
        $client = Client::findOrFail($clientId);

        $validated = $request->validate([
            'fecha'                => ['nullable', 'date'],
            'precio_plan'          => ['required', 'numeric', 'min:0'],
            'precio_por_cuenta'    => ['required', 'numeric', 'min:0'],
            'precio_ecommerce'     => ['nullable', 'numeric', 'min:0'],
            'precio_mercado_libre' => ['nullable', 'numeric', 'min:0'],
            'precio_tienda_nube'   => ['nullable', 'numeric', 'min:0'],
            'es_oficial'           => ['nullable', 'boolean'],
            'observacion'          => ['nullable', 'string'],
        ]);

        return response()->json($service->registrar_actualizacion($client, $validated, $request->user()));
    }

    /**
     * Edita la marca de oficial y/o la nota de una actualización ya registrada. Los precios no se
     * editan: una actualización con otros precios es otra actualización.
     *
     * @param  Request                     $request  {es_oficial?, observacion?}
     * @param  int|string                  $clientId
     * @param  int|string                  $id
     * @param  CobranzasMensualidadService $service
     * @return \Illuminate\Http\JsonResponse
     */
    public function editar_actualizacion_json(Request $request, $clientId, $id, CobranzasMensualidadService $service)
    {
        $client = Client::findOrFail($clientId);

        $actualizacion = MensualidadActualizacion::where('client_id', $client->id)->findOrFail($id);

        $validated = $request->validate([
            'es_oficial'  => ['nullable', 'boolean'],
            'observacion' => ['nullable', 'string'],
        ]);

        if (array_key_exists('es_oficial', $validated) && $validated['es_oficial'] !== null) {
            $actualizacion->es_oficial = (bool) $validated['es_oficial'];
        }

        if (array_key_exists('observacion', $validated)) {
            $observacion = trim((string) $validated['observacion']);
            $actualizacion->observacion = $observacion === '' ? null : $observacion;
        }

        $actualizacion->save();

        return response()->json([
            'actualizaciones' => $service->actualizaciones($client),
            'resumen'         => $service->resumen_actualizacion($client),
        ]);
    }

    /**
     * Borra una actualización del historial. NO revierte los precios del cliente: los precios
     * vigentes son los que están en `clients`, y borrar una fila del historial es corregir el
     * historial, no volver atrás un cambio.
     *
     * @param  int|string                  $clientId
     * @param  int|string                  $id
     * @param  CobranzasMensualidadService $service
     * @return \Illuminate\Http\JsonResponse
     */
    public function eliminar_actualizacion_json($clientId, $id, CobranzasMensualidadService $service)
    {
        $client = Client::findOrFail($clientId);

        MensualidadActualizacion::where('client_id', $client->id)->findOrFail($id)->delete();

        return response()->json([
            'actualizaciones' => $service->actualizaciones($client),
            'resumen'         => $service->resumen_actualizacion($client),
        ]);
    }

    /**
     * Botón "Traer empleados": consulta el conteo vivo en el empresa-api del cliente y lo guarda
     * (solo `cantidad_empleados`; recalcula el total). Responde 200 siempre: un cliente sin
     * sincronización (versión vieja, sin api_key, sin sistema) vuelve con `soportado` false y el
     * motivo, que es un resultado, no un error del request.
     *
     * @param  int|string                  $clientId
     * @param  CobranzasMensualidadService $service
     * @return \Illuminate\Http\JsonResponse
     */
    public function sincronizar_empleados_json($clientId, CobranzasMensualidadService $service)
    {
        $client = Client::findOrFail($clientId);

        return response()->json($service->sincronizar_empleados($client));
    }
}

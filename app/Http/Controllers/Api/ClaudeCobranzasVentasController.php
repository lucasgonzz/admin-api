<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespuestasParaClaude;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\LicenciaCuota;
use App\Models\Venta;
use App\Services\ComisionService;
use App\Services\LicenciaCuotaService;
use App\Services\VentaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Los pagos y ajustes de las ventas desde Claude/ChatGPT (misión cobro-dudoso-y-pagos-ventas,
 * 30/10/2026). Hasta ahora `claude/*` solo daba de alta ventas (`ClaudeVentasController`); lo demás
 * —cobrar una cuota, borrar una cuota mal cargada, pagarle al vendedor, marcar un cliente de dudoso
 * cobro— solo se podía hacer en el panel. Estos cuatro endpoints lo cierran:
 *
 *  - `POST claude/clients/{id}/cuotas/{cuotaId}/pago` — registra el pago (entero o parcial) de una
 *    cuota del cliente. Sin `monto`, paga LO QUE FALTA de esa cuota (queda `pagada`).
 *  - `POST claude/clients/{id}/cuotas/{cuotaId}/eliminar` — borra una cuota; el total de la venta y
 *    su comisión bajan en lo que valía. Se RECHAZA (422) una cuota con plata cobrada: borrar un
 *    cobro real no es un «ajuste».
 *  - `POST claude/ventas/{ventaId}/comision/pagos` — registra un pago al vendedor a cuenta de su
 *    comisión. Sin `monto`, paga TODO LO QUE ESTÁ A PAGAR hoy (`comision_de().a_pagar`). El cliente
 *    se saca de la venta.
 *  - `POST claude/clients/{id}/cobro-dudoso` — enciende o apaga la marca de «cliente de dudoso
 *    cobro» (su deuda de ventas pasa a «En stand-by»). Es la misma lógica que el panel.
 *
 * Los frenos son los del bloque `claude/*` (el patrón de `ClaudeVentasController`):
 *  1. `dry_run` por defecto true: se corre EL MISMO servicio que después escribe —adentro de una
 *     transacción que se deshace siempre— y se devuelve cómo quedaría todo. Así un dry_run que pasa
 *     predice una escritura que pasa, y no hay una segunda copia de la cuenta de plata.
 *  2. Con `dry_run=false`, `confirm_client_name` tiene que coincidir con el nombre del cliente
 *     (recorte y minúsculas); el error no revela el nombre correcto. En el pago de comisión, el
 *     cliente es el de la venta.
 *
 * 🔴 Ninguna cuenta de plata está escrita acá: se reusan `LicenciaCuotaService::registrar_pago()` y
 * `eliminar()`, `ComisionService::registrar_pago()` y `VentaService::comision_de()` /
 * `cambiar_cobro_dudoso()`. Escribe filas del admin (cuotas, pagos de comisión, la marca del
 * cliente) pero no toca el sistema de ningún negocio ni le manda nada a nadie: peligrosidad `baja`
 * en el catálogo.
 *
 * Los errores salen con la forma única del bloque: `{"error": "..."}` con 404 o 422.
 */
class ClaudeCobranzasVentasController extends Controller
{
    use RespuestasParaClaude;

    /**
     * Mensajes de validación en español para las reglas que no cubre el trait (`numeric`).
     *
     * @return array<string, string>
     */
    protected function mensajes_de_validacion()
    {
        return [
            'required' => 'El parámetro :attribute es obligatorio.',
            'date'     => 'El parámetro :attribute tiene que ser una fecha válida.',
            'numeric'  => 'El parámetro :attribute tiene que ser un número.',
            'boolean'  => 'El parámetro :attribute tiene que ser booleano (1, 0, true o false).',
            'string'   => 'El parámetro :attribute tiene que ser texto.',
            'min'      => 'El parámetro :attribute está por debajo del mínimo permitido.',
            'max'      => 'El parámetro :attribute supera el máximo permitido.',
        ];
    }

    /**
     * Registra el pago de una cuota del cliente.
     *
     * Reglas (valen igual en el dry_run y en el real):
     *  - la cuota tiene que ser DEL cliente de la ruta (404 si no);
     *  - una cuota que no tiene nada pendiente (ya `pagada`) no recibe otro pago (422): sería
     *    duplicar un cobro, y un reintento de Claude lo haría sin querer;
     *  - sin `monto`, se paga lo que falta; `completa=true` da la cuota por pagada aunque lo
     *    pagado no llegue al monto (un descuento, una diferencia de cambio).
     *
     * @param Request              $request {monto?, fecha_pago?, observacion?, completa?, dry_run?, confirm_client_name?}
     * @param int|string           $id      Cliente.
     * @param int|string           $cuotaId Cuota.
     * @param LicenciaCuotaService $cuotas
     * @param VentaService         $ventas
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function pago_de_cuota_json(Request $request, $id, $cuotaId, LicenciaCuotaService $cuotas, VentaService $ventas)
    {
        $client = Client::find((int) $id);
        if ($client === null) {
            return $this->error_404('no existe el cliente ' . $id);
        }

        /* La cuota se busca DENTRO del cliente: una cuota de otro cliente es 404, igual que una que
           no existe. Es lo que evita cobrarle a un cliente una cuota ajena por equivocar un id. */
        $cuota = LicenciaCuota::where('client_id', $client->id)->find((int) $cuotaId);
        if ($cuota === null) {
            return $this->error_404('no existe la cuota ' . $cuotaId . ' en el cliente ' . $client->id);
        }

        $invalido = $this->validar_o_422($request, [
            'monto'               => ['nullable', 'numeric', 'min:0.01', 'max:999999999'],
            'fecha_pago'          => ['nullable', 'date'],
            'observacion'         => ['nullable', 'string', 'max:1000'],
            'completa'            => ['nullable', 'boolean'],
            'dry_run'             => ['nullable', 'boolean'],
            'confirm_client_name' => ['nullable', 'string', 'max:190'],
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        /* Un `monto` que viene en el request pero vacío NO significa «pagá todo lo que falta»: es casi
           seguro un error del que llama (el middleware de strings vacíos lo convierte en null y
           `filled()` lo tomaría por ausente, cobrando la cuota entera). Para pagar lo que falta se
           omite la clave. */
        if ($request->exists('monto') && ! $request->filled('monto')) {
            return $this->error_422('El parámetro monto vino vacío. Omitilo para pagar lo que falta de la cuota, o mandá el importe. No se escribió nada.', [
                'client_id' => (int) $client->id,
            ]);
        }

        $pendiente = $cuota->monto_pendiente();
        if ($pendiente <= 0) {
            return $this->error_422('La cuota ya está pagada: no se le registra otro pago. No se escribió nada.', [
                'client_id' => (int) $client->id,
                'cuota'     => $cuotas->cuota_para_json($cuota),
                'ayuda'     => 'Si el pago registrado está mal, corregilo desde el panel (pestaña Ventas del cliente).',
            ]);
        }

        /* Sin `monto`: lo que falta de esa cuota. Con `monto`: lo que entró AHORA (se suma a lo ya pagado). */
        $monto = $request->filled('monto') ? round((float) $request->input('monto'), 2) : $pendiente;

        /* Tope: un pago no puede superar lo que falta de la cuota. Sin este freno, un importe en la
           moneda equivocada (pesos sobre una cuota en dólares) o un cero de más queda como «cobrado»
           y infla Cobranzas. Si el cliente pagó de más de verdad, se corrige desde el panel. */
        if ($monto - $pendiente > 0.009) {
            return $this->error_422('El monto supera lo que falta de la cuota. No se escribió nada.', [
                'client_id' => (int) $client->id,
                'monto'     => $monto,
                'pendiente' => $pendiente,
                'moneda'    => (string) $cuota->moneda,
                'ayuda'     => 'Revisá el importe y la moneda de la cuota. Para pagar lo que falta, omití monto.',
            ]);
        }

        $fecha_pago = $this->texto_o_null($request->input('fecha_pago'));
        $observacion = $this->texto_o_null($request->input('observacion'));
        $completa = $request->boolean('completa');

        $cuota_antes = $cuotas->cuota_para_json($cuota);

        /* Freno 1: sin `dry_run=false` explícito esto no escribe nada. */
        $dry_run = $request->filled('dry_run') ? $request->boolean('dry_run') : true;
        if ($dry_run) {
            $simulado = $this->simular(function () use ($cuotas, $ventas, $cuota, $monto, $fecha_pago, $observacion, $completa) {
                $cuota = $cuotas->registrar_pago($cuota, $monto, $fecha_pago, $observacion, $completa);

                return [
                    'cuota'  => $cuotas->cuota_para_json($cuota),
                    'venta'  => $this->venta_json($ventas, $cuota->venta_id),
                ];
            });

            return response()->json([
                'dry_run'      => true,
                'escribio'     => false,
                'client'       => $this->client_json($client),
                'monto'        => $monto,
                'cuota_antes'  => $cuota_antes,
                'cuota_despues' => $simulado['cuota'],
                'venta'        => $simulado['venta'],
                'nota'         => 'No se escribió NADA. Así quedaría la cuota (y la venta, con su comisión liberada) con este pago. '
                    . 'Para registrarlo de verdad, repetí la misma llamada con dry_run=false y confirm_client_name con el '
                    . 'nombre exacto del cliente.',
            ], 200);
        }

        /* Freno 2: el nombre. Con `dry_run=false` ya no es opcional. */
        $rechazo = $this->rechazar_si_el_nombre_del_cliente_no_confirma($request, $client, 'No se escribió nada.');
        if ($rechazo !== null) {
            return $rechazo;
        }

        $cuota = $cuotas->registrar_pago($cuota, $monto, $fecha_pago, $observacion, $completa);

        return response()->json(array_merge([
            'dry_run'  => false,
            'escribio' => true,
            'cuota'    => $cuotas->cuota_para_json($cuota),
            'venta'    => $this->venta_json($ventas, $cuota->venta_id),
        ], $ventas->lista_del_cliente($client)), 200);
    }

    /**
     * Elimina una cuota del cliente. El total de la venta de la cuota y su comisión bajan en lo que
     * valía (los recalcula `LicenciaCuotaService::eliminar()`, el mismo que usa el panel).
     *
     * 🔴 Se rechaza (422) una cuota con plata cobrada (`monto_cobrado() > 0`): borrarla borraría un
     * cobro real y dejaría la cuenta del cliente mintiendo. Esto es para cuotas mal cargadas o que ya
     * no corresponden, no para deshacer cobros.
     *
     * @param Request              $request {dry_run?, confirm_client_name?}
     * @param int|string           $id      Cliente.
     * @param int|string           $cuotaId Cuota.
     * @param LicenciaCuotaService $cuotas
     * @param VentaService         $ventas
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function eliminar_cuota_json(Request $request, $id, $cuotaId, LicenciaCuotaService $cuotas, VentaService $ventas)
    {
        $client = Client::find((int) $id);
        if ($client === null) {
            return $this->error_404('no existe el cliente ' . $id);
        }

        $cuota = LicenciaCuota::where('client_id', $client->id)->find((int) $cuotaId);
        if ($cuota === null) {
            return $this->error_404('no existe la cuota ' . $cuotaId . ' en el cliente ' . $client->id);
        }

        $invalido = $this->validar_o_422($request, [
            'dry_run'             => ['nullable', 'boolean'],
            'confirm_client_name' => ['nullable', 'string', 'max:190'],
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        if ($cuota->monto_cobrado() > 0) {
            return $this->error_422('La cuota tiene plata cobrada: no se elimina, porque se borraría un cobro real. No se escribió nada.', [
                'client_id'     => (int) $client->id,
                'cuota'         => $cuotas->cuota_para_json($cuota),
                'monto_cobrado' => $cuota->monto_cobrado(),
                'ayuda'         => 'Eliminar sirve para una cuota mal cargada y todavía sin cobrar. Si lo cobrado está mal, corregilo desde el panel.',
            ]);
        }

        $cuota_json = $cuotas->cuota_para_json($cuota);
        $venta_antes = $this->venta_json($ventas, $cuota->venta_id);

        /* Freno 1: sin `dry_run=false` explícito esto no escribe nada. */
        $dry_run = $request->filled('dry_run') ? $request->boolean('dry_run') : true;
        if ($dry_run) {
            $venta_despues = $this->simular(function () use ($cuotas, $ventas, $cuota) {
                $cuotas->eliminar($cuota);

                return $this->venta_json($ventas, $cuota->venta_id);
            });

            return response()->json([
                'dry_run'       => true,
                'escribio'      => false,
                'client'        => $this->client_json($client),
                'cuota'         => $cuota_json,
                'venta_antes'   => $venta_antes,
                'venta_despues' => $venta_despues,
                'nota'          => 'No se escribió NADA. Así quedaría la venta (total y comisión) sin esta cuota. Para eliminarla de '
                    . 'verdad, repetí la misma llamada con dry_run=false y confirm_client_name con el nombre exacto del cliente.',
            ], 200);
        }

        /* Freno 2: el nombre. */
        $rechazo = $this->rechazar_si_el_nombre_del_cliente_no_confirma($request, $client, 'No se escribió nada.');
        if ($rechazo !== null) {
            return $rechazo;
        }

        $cuotas->eliminar($cuota);

        return response()->json(array_merge([
            'dry_run'         => false,
            'escribio'        => true,
            'cuota_eliminada' => $cuota_json,
            'venta'           => $this->venta_json($ventas, $cuota->venta_id),
        ], $ventas->lista_del_cliente($client)), 200);
    }

    /**
     * Registra un pago al vendedor de la venta, a cuenta de su comisión.
     *
     * El cliente se saca de la venta: `confirm_client_name` es el nombre del cliente de la venta.
     * Sin `monto`, se paga TODO lo que está a pagar hoy (`VentaService::comision_de().a_pagar`, lo
     * liberado por lo que el cliente pagó menos lo ya pagado al vendedor); si no hay nada a pagar,
     * 422 (no se registran pagos de cero). Una venta sin vendedor es 422, lo dice el servicio.
     * Con `monto` explícito se puede pagar más de lo liberado (un adelanto): se registra igual y la
     * respuesta lo marca con `excede: true`, como en el panel.
     *
     * @param Request         $request {monto?, fecha?, nota?, dry_run?, confirm_client_name?}
     * @param int|string      $ventaId Venta.
     * @param ComisionService $comisiones
     * @param VentaService    $ventas
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function pago_de_comision_json(Request $request, $ventaId, ComisionService $comisiones, VentaService $ventas)
    {
        $venta = Venta::with(['cuotas', 'comision_pagos', 'vendedor', 'client'])->find((int) $ventaId);
        if ($venta === null) {
            return $this->error_404('no existe la venta ' . $ventaId);
        }

        $client = $venta->client;
        if ($client === null) {
            return $this->error_404('la venta ' . $ventaId . ' no tiene un cliente cargado');
        }

        $invalido = $this->validar_o_422($request, [
            'monto'               => ['nullable', 'numeric', 'min:0.01', 'max:999999999'],
            'fecha'               => ['nullable', 'date'],
            'nota'                => ['nullable', 'string', 'max:255'],
            'dry_run'             => ['nullable', 'boolean'],
            'confirm_client_name' => ['nullable', 'string', 'max:190'],
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        $comision_antes = $ventas->comision_de($venta);

        /* Sin `monto`: todo lo que está a pagar. Si no hay nada, no se registra un pago de cero. Una
           venta SIN vendedor no entra a este chequeo: la rechaza el servicio con su mensaje. */
        $monto_pedido = $request->filled('monto') ? round((float) $request->input('monto'), 2) : null;
        if ($monto_pedido === null && $venta->vendedor_admin_id !== null && $comision_antes['a_pagar'] <= 0) {
            return $this->error_422('No hay nada a pagar de la comisión de esta venta: lo liberado ya está pagado. No se escribió nada.', [
                'client_id' => (int) $client->id,
                'venta_id'  => (int) $venta->id,
                'comision'  => $comision_antes,
                'ayuda'     => 'La comisión se libera a medida que el cliente paga. Para un adelanto, mandá `monto` explícito.',
            ]);
        }
        $monto = $monto_pedido !== null ? $monto_pedido : $comision_antes['a_pagar'];

        $fecha = $this->texto_o_null($request->input('fecha'));
        $nota = $this->texto_o_null($request->input('nota'));

        /* Freno 1: sin `dry_run=false` explícito esto no escribe nada. */
        $dry_run = $request->filled('dry_run') ? $request->boolean('dry_run') : true;
        if ($dry_run) {
            try {
                $simulado = $this->simular(function () use ($comisiones, $ventas, $venta, $monto, $fecha, $nota) {
                    $resultado = $comisiones->registrar_pago($venta, $monto, $fecha, $nota, null);

                    return [
                        'pago'     => $comisiones->pago_para_json($resultado['pago']),
                        'excede'   => $resultado['excede'],
                        'comision' => $ventas->comision_de($this->venta_fresca($venta->id)),
                    ];
                });
            } catch (ValidationException $e) {
                return $this->error_422('El pago no se puede registrar. No se escribió nada.', [
                    'client_id' => (int) $client->id,
                    'venta_id'  => (int) $venta->id,
                    'detalles'  => $e->validator->errors()->all(),
                ]);
            }

            return response()->json([
                'dry_run'         => true,
                'escribio'        => false,
                'client'          => $this->client_json($client),
                'venta_id'        => (int) $venta->id,
                'pago'            => $simulado['pago'],
                'excede'          => $simulado['excede'],
                'comision_antes'  => $comision_antes,
                'comision_despues' => $simulado['comision'],
                'nota'            => 'No se escribió NADA. Este es el pago al vendedor que se registraría y cómo quedaría su comisión. '
                    . 'Para registrarlo de verdad, repetí la misma llamada con dry_run=false y confirm_client_name con el '
                    . 'nombre exacto del cliente de la venta.',
            ], 200);
        }

        /* Freno 2: el nombre del cliente DE LA VENTA. */
        $rechazo = $this->rechazar_si_el_nombre_del_cliente_no_confirma($request, $client, 'No se escribió nada.');
        if ($rechazo !== null) {
            return $rechazo;
        }

        try {
            $resultado = $comisiones->registrar_pago($venta, $monto, $fecha, $nota, null);
        } catch (ValidationException $e) {
            return $this->error_422('El pago no se puede registrar. No se escribió nada.', [
                'client_id' => (int) $client->id,
                'venta_id'  => (int) $venta->id,
                'detalles'  => $e->validator->errors()->all(),
            ]);
        }

        return response()->json(array_merge([
            'dry_run'  => false,
            'escribio' => true,
            'pago'     => $comisiones->pago_para_json($resultado['pago']),
            'excede'   => $resultado['excede'],
            'venta'    => $ventas->venta_para_json($this->venta_fresca($venta->id)),
        ], $ventas->lista_del_cliente($client)), 201);
    }

    /**
     * Enciende o apaga la marca de «cliente de dudoso cobro» (stand-by): su deuda de ventas deja de
     * contar como plata a cobrar sin darse por perdida. Misma lógica que el panel
     * (`VentaService::cambiar_cobro_dudoso()`). No toca ventas, cuotas ni comisiones.
     *
     * El dry_run muestra la marca y el resumen por moneda (con `en_espera` / `por_cobrar`) antes y
     * después.
     *
     * @param Request      $request {cobro_dudoso (requerido), motivo?, dry_run?, confirm_client_name?}
     * @param int|string   $id      Cliente.
     * @param VentaService $ventas
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function cobro_dudoso_json(Request $request, $id, VentaService $ventas)
    {
        $client = Client::find((int) $id);
        if ($client === null) {
            return $this->error_404('no existe el cliente ' . $id);
        }

        $invalido = $this->validar_o_422($request, [
            'cobro_dudoso'        => ['required', 'boolean'],
            'motivo'              => ['nullable', 'string', 'max:255'],
            'dry_run'             => ['nullable', 'boolean'],
            'confirm_client_name' => ['nullable', 'string', 'max:190'],
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        $encendido = $request->boolean('cobro_dudoso');
        $motivo = $this->texto_o_null($request->input('motivo'));

        /* Freno 1: sin `dry_run=false` explícito esto no escribe nada. */
        $dry_run = $request->filled('dry_run') ? $request->boolean('dry_run') : true;
        if ($dry_run) {
            $marca_antes = $this->marca_json($client);
            $resumen_antes = $this->resumen_del_cliente($ventas, $client);

            $simulado = $this->simular(function () use ($ventas, $client, $encendido, $motivo) {
                $ventas->cambiar_cobro_dudoso($client, $encendido, $motivo);

                return [
                    'marca'   => $this->marca_json($client),
                    'resumen' => $this->resumen_del_cliente($ventas, $client),
                ];
            });

            return response()->json([
                'dry_run'                    => true,
                'escribio'                   => false,
                'client'                     => $this->client_json($client),
                'marca_antes'                => $marca_antes,
                'marca_despues'              => $simulado['marca'],
                'resumen_por_moneda_antes'   => $resumen_antes,
                'resumen_por_moneda_despues' => $simulado['resumen'],
                'nota'                       => 'No se escribió NADA. Así quedaría la marca y la deuda pendiente (en_espera / por_cobrar). '
                    . 'Para aplicarla de verdad, repetí la misma llamada con dry_run=false y confirm_client_name con el nombre '
                    . 'exacto del cliente.',
            ], 200);
        }

        /* Freno 2: el nombre. */
        $rechazo = $this->rechazar_si_el_nombre_del_cliente_no_confirma($request, $client, 'No se escribió nada.');
        if ($rechazo !== null) {
            return $rechazo;
        }

        $ventas->cambiar_cobro_dudoso($client, $encendido, $motivo);

        return response()->json(array_merge([
            'dry_run'  => false,
            'escribio' => true,
        ], $ventas->lista_del_cliente($client->fresh())), 200);
    }

    /**
     * Corre una acción de escritura adentro de una transacción que se DESHACE siempre, y devuelve lo
     * que la acción devolvió. Es el dry_run: se ejecuta el mismo servicio que después escribe (así lo
     * que el dry_run predice es lo que la escritura hace), pero no queda ni una fila. Si la acción
     * lanza (por ejemplo una `ValidationException`), la transacción se deshace igual y la excepción
     * sigue su camino.
     *
     * @param callable $accion Lo que se simula.
     *
     * @return mixed Lo que devolvió `$accion`.
     */
    protected function simular(callable $accion)
    {
        DB::beginTransaction();

        try {
            return $accion();
        } finally {
            DB::rollBack();
        }
    }

    /**
     * La venta con todo lo que necesita `venta_para_json()`, leída de la base en este momento.
     *
     * @param int $venta_id
     *
     * @return Venta
     */
    protected function venta_fresca($venta_id): Venta
    {
        return Venta::with(['cuotas', 'comision_pagos', 'vendedor', 'client'])->findOrFail($venta_id);
    }

    /**
     * La venta como viaja al front (`venta_para_json`), o null si la cuota no pertenece a una venta
     * (cuota suelta) o la venta ya no existe.
     *
     * @param VentaService $ventas
     * @param int|null     $venta_id
     *
     * @return array<string, mixed>|null
     */
    protected function venta_json(VentaService $ventas, $venta_id)
    {
        if ($venta_id === null) {
            return null;
        }

        $venta = Venta::with(['cuotas', 'comision_pagos', 'vendedor', 'client'])->find($venta_id);

        return $venta !== null ? $ventas->venta_para_json($venta) : null;
    }

    /**
     * El resumen por moneda de las ventas del cliente (con `en_espera` y `por_cobrar`), según el
     * estado actual de la marca del objeto `$client`.
     *
     * @param VentaService $ventas
     * @param Client       $client
     *
     * @return array<string, array<string, float>>
     */
    protected function resumen_del_cliente(VentaService $ventas, Client $client): array
    {
        $de_ventas = Venta::where('client_id', $client->id)
            ->with(['cuotas', 'comision_pagos', 'vendedor'])
            ->get();

        /* Se le da el objeto en memoria: así el resumen refleja la marca tal como está en `$client`. */
        foreach ($de_ventas as $venta) {
            $venta->setRelation('client', $client);
        }

        return $ventas->resumen_por_moneda($de_ventas);
    }

    /**
     * La marca de dudoso cobro del cliente, tal como está en el objeto.
     *
     * @param Client $client
     *
     * @return array{cobro_dudoso: bool, cobro_dudoso_desde: string|null, cobro_dudoso_motivo: string|null}
     */
    protected function marca_json(Client $client): array
    {
        return [
            'cobro_dudoso'        => (bool) $client->cobro_dudoso,
            'cobro_dudoso_desde'  => $client->cobro_dudoso_desde ? $client->cobro_dudoso_desde->toDateString() : null,
            'cobro_dudoso_motivo' => $client->cobro_dudoso_motivo !== null ? (string) $client->cobro_dudoso_motivo : null,
        ];
    }

    /**
     * Los datos mínimos del cliente para el dry_run (id, uuid y nombre), como en el alta de ventas.
     *
     * @param Client $client
     *
     * @return array{id: int, uuid: string, name: string}
     */
    protected function client_json(Client $client): array
    {
        return [
            'id'   => (int) $client->id,
            'uuid' => (string) $client->uuid,
            'name' => (string) $client->name,
        ];
    }
}

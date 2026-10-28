<?php

namespace App\Http\Controllers;

use App\Models\ComisionPago;
use App\Models\Venta;
use App\Services\ComisionService;
use App\Services\VentaService;
use Illuminate\Http\Request;

/**
 * Las comisiones de los vendedores (misión ventas-y-comisiones, 28/10/2026):
 *
 *  - `GET comisiones` — Cobranzas → Comisiones, la tabla de Lucas: todas las ventas con vendedor
 *    (o las de uno, con `?vendedor_admin_id=`), con su comisión y sus pagos, y el resumen por
 *    vendedor.
 *  - `GET comisiones/mias` — "Mis comisiones": lo mismo filtrado al admin autenticado, más su
 *    resumen (para cobrar, ya cobrado, por liberar) y los pagos que recibió.
 *  - `POST ventas/{ventaId}/comision/pagos` y `DELETE comision-pagos/{pagoId}` — registrar y borrar
 *    un pago al vendedor.
 *
 * La lógica vive en `ComisionService` y `VentaService::comision_de()`.
 */
class ComisionController extends Controller
{
    /**
     * La tabla de comisiones.
     *
     * @param  Request         $request  ?vendedor_admin_id=
     * @param  ComisionService $service
     * @param  VentaService    $ventas
     * @return \Illuminate\Http\JsonResponse
     */
    public function index_json(Request $request, ComisionService $service, VentaService $ventas)
    {
        $validated = $request->validate([
            'vendedor_admin_id' => ['nullable', 'integer'],
        ]);

        $vendedor_admin_id = isset($validated['vendedor_admin_id']) ? (int) $validated['vendedor_admin_id'] : null;

        $filas = $service->tabla($vendedor_admin_id);

        return response()->json([
            'comisiones'           => $filas,
            'resumen_por_vendedor' => $service->resumen_por_vendedor($filas),
            // Clave agregada (no está en el contrato mínimo): el selector de vendedor de la pantalla.
            'vendedores'           => $ventas->vendedores(),
        ]);
    }

    /**
     * "Mis comisiones": las del admin autenticado.
     *
     * @param  Request         $request
     * @param  ComisionService $service
     * @return \Illuminate\Http\JsonResponse
     */
    public function mias_json(Request $request, ComisionService $service)
    {
        $admin_id = (int) $request->user()->id;

        $filas = $service->tabla($admin_id);

        return response()->json([
            'comisiones'           => $filas,
            'resumen_por_vendedor' => $service->resumen_por_vendedor($filas),
            'resumen'              => $service->resumen_vendedor($admin_id),
        ]);
    }

    /**
     * Registra un pago al vendedor de la venta. Se permite pagar más de lo liberado (un adelanto):
     * la respuesta lo marca con `excede: true`.
     *
     * @param  Request         $request  {monto, fecha?, nota?}
     * @param  int|string      $ventaId
     * @param  ComisionService $service
     * @return \Illuminate\Http\JsonResponse
     */
    public function registrar_pago_json(Request $request, $ventaId, ComisionService $service)
    {
        $venta = Venta::findOrFail($ventaId);

        $validated = $request->validate([
            'monto' => ['required', 'numeric', 'min:0.01', 'max:999999999'],
            'fecha' => ['nullable', 'date'],
            'nota'  => ['nullable', 'string', 'max:255'],
        ]);

        $resultado = $service->registrar_pago(
            $venta,
            (float) $validated['monto'],
            $validated['fecha'] ?? null,
            $validated['nota'] ?? null,
            $request->user()
        );

        return response()->json([
            'venta'  => $service->fila($this->recargar($venta)),
            'pago'   => $service->pago_para_json($resultado['pago']),
            'excede' => $resultado['excede'],
        ], 201);
    }

    /**
     * Borra un pago al vendedor. Devuelve la venta con su comisión recalculada.
     *
     * @param  int|string      $pagoId
     * @param  ComisionService $service
     * @return \Illuminate\Http\JsonResponse
     */
    public function eliminar_pago_json($pagoId, ComisionService $service)
    {
        $pago = ComisionPago::findOrFail($pagoId);
        $venta = Venta::find($pago->venta_id);

        $service->eliminar_pago($pago);

        return response()->json([
            'venta' => $venta ? $service->fila($this->recargar($venta)) : null,
        ]);
    }

    /**
     * La venta con todo lo que necesita una fila de la tabla.
     *
     * @param Venta $venta
     *
     * @return Venta
     */
    private function recargar(Venta $venta): Venta
    {
        return $venta->fresh(['cuotas', 'comision_pagos', 'vendedor', 'client']);
    }
}

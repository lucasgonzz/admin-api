<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\Sena;
use App\Models\Venta;
use App\Services\SenaService;
use App\Services\VentaService;
use Illuminate\Http\Request;

/**
 * Las señas desde el panel del admin (misión senas-en-ventas, 30/10/2026).
 *
 * Una seña es un pago a cuenta que se aplica sola a las cuotas pendientes más próximas de la venta
 * (ver `SenaService`). Tres rutas, todas bajo `auth:sanctum`:
 *
 *  - `POST ventas/{ventaId}/senas`  — registra una seña en una venta y la aplica a sus cuotas.
 *    201 con `{sena, venta}` más la lista entera del cliente (`lista_del_cliente`): la pestaña
 *    Ventas se redibuja completa, como con el resto de las acciones de ventas.
 *  - `POST leads/{leadId}/senas`    — registra una seña en un lead que todavía no se promovió. Queda
 *    pendiente y se aplica sola cuando el lead se promueve. 201 con `{sena}` y el estado de las
 *    señas del lead (`senas`, `total`).
 *  - `GET  leads/{leadId}/senas`    — las señas del lead: `{senas, total, total_aplicado}`.
 *
 * Toda la lógica y las reglas (monto > 0, no pasar lo pendiente, lead ya promovido) viven en
 * `SenaService`; acá solo se valida la FORMA del request. Los errores de negocio salen como el 422
 * estándar de Laravel, `{message, errors}`, igual que el resto de las rutas del panel.
 */
class SenaController extends Controller
{
    /**
     * Reglas de forma compartidas por las dos altas.
     *
     * @return array<string, array<int, string>>
     */
    protected function reglas(): array
    {
        return [
            'monto' => ['required', 'numeric', 'min:0.01', 'max:999999999'],
            'fecha' => ['nullable', 'date'],
            'nota'  => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Registra una seña en una venta y la aplica a sus cuotas.
     *
     * @param Request      $request  {monto, fecha?, nota?}
     * @param int|string   $ventaId
     * @param SenaService  $senas
     * @param VentaService $ventas
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function store_en_venta_json(Request $request, $ventaId, SenaService $senas, VentaService $ventas)
    {
        $venta = Venta::with('client')->findOrFail((int) $ventaId);

        $validated = $request->validate($this->reglas());

        $sena = $senas->registrar_en_venta(
            $venta,
            (float) $validated['monto'],
            $validated['fecha'] ?? null,
            $validated['nota'] ?? null,
            $request->user(),
            Sena::ORIGEN_MANUAL
        );

        $venta_fresca = Venta::with(['cuotas', 'comision_pagos', 'vendedor', 'client', 'senas'])->findOrFail($venta->id);

        return response()->json(array_merge(
            [
                'sena'  => $senas->sena_para_json($sena),
                'venta' => $ventas->venta_para_json($venta_fresca),
            ],
            $ventas->lista_del_cliente($venta_fresca->client)
        ), 201);
    }

    /**
     * Registra una seña en un lead que todavía no se promovió (queda pendiente).
     *
     * @param Request     $request {monto, fecha?, nota?}
     * @param int|string  $leadId
     * @param SenaService $senas
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function store_en_lead_json(Request $request, $leadId, SenaService $senas)
    {
        $lead = Lead::findOrFail((int) $leadId);

        $validated = $request->validate($this->reglas());

        $sena = $senas->registrar_en_lead(
            $lead,
            (float) $validated['monto'],
            $validated['fecha'] ?? null,
            $validated['nota'] ?? null,
            $request->user(),
            Sena::ORIGEN_MANUAL
        );

        return response()->json(array_merge(
            ['sena' => $senas->sena_para_json($sena)],
            $senas->senas_del_lead_para_json($lead)
        ), 201);
    }

    /**
     * Las señas registradas en un lead: la lista (con `aplicada` para distinguir las pendientes de
     * las ya aplicadas a una venta), el total pendiente y el total ya aplicado.
     *
     * @param int|string  $leadId
     * @param SenaService $senas
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index_del_lead_json($leadId, SenaService $senas)
    {
        $lead = Lead::findOrFail((int) $leadId);

        return response()->json($senas->senas_del_lead_para_json($lead));
    }
}

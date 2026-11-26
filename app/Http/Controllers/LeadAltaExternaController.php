<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Services\LeadAltaExternaService;
use Illuminate\Http\Request;

/**
 * Alta de un lead que llegó por otro medio, desde el panel (misión leads-referidos-compromisos, etapa E1).
 *
 *  - `POST admin/lead/externo`: 201 `{model, primer_contacto}` si crea el lead; 200 con lo mismo si
 *    `completar_existente=true` y el teléfono ya tenía lead (se completa ESE lead); 409
 *    `{code: lead_existente, lead_id}` si el teléfono ya tiene lead y no se pidió completar; 422 con
 *    `errors.<campo>` si el pedido es inválido.
 *
 * Sin lógica de negocio: todo está en {@see LeadAltaExternaService}. Sin `$request->validate()` a propósito: el
 * servicio responde el 422 con la misma forma que el resto del CRM de pipelines (`PipelineRuleException`), que el
 * wrapper de axios de la SPA ya muestra campo por campo. Extiende `Controller` como el resto de los controladores
 * de leads del admin.
 */
class LeadAltaExternaController extends Controller
{
    /**
     * Da de alta (o completa) un lead de otro medio.
     *
     * @param Request                $request
     * @param LeadAltaExternaService $servicio
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function store_json(Request $request, LeadAltaExternaService $servicio)
    {
        $resultado = $servicio->crear($request->all(), $request->user(), false);

        if ($resultado['conflicto']) {
            return response()->json([
                'message' => 'Ya hay un lead con ese teléfono.',
                'code'    => 'lead_existente',
                'lead_id' => (int) $resultado['lead_id'],
            ], 409);
        }

        $lead = $resultado['lead'];

        return response()->json([
            'model'           => $this->fullModel('lead', (int) $lead->id),
            'primer_contacto' => $resultado['primer_contacto'],
        ], $resultado['creado'] ? 201 : 200);
    }
}

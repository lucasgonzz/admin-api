<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadLlamada;
use App\Services\LeadLlamadaService;
use Illuminate\Http\Request;

/**
 * «Registrar llamada» del tablero de Juan (misión embudo-juan-pipeline, E1 y E1b).
 *
 * Sin lógica de negocio: la validación (422 con `errors.<campo>`), el candado del lead, el movimiento de la tarjeta y los
 * efectos están en {@see LeadLlamadaService}. Sin `$request->validate()` a propósito: el servicio responde el 422 con la
 * misma forma que el resto del CRM de pipelines.
 *
 *  - `POST lead/{id}/llamadas`: 201 `{llamada, oportunidad}`; 200 `{llamada, oportunidad, duplicada: true}` si es un audio
 *    de Juan que ya estaba cargado.
 *  - `GET lead/{id}/llamadas`: 200 `{models: [...]}`, las últimas 20.
 */
class LeadLlamadaController extends Controller
{
    /** Cuántas llamadas devuelve el listado. */
    const LIMITE_DEL_LISTADO = 20;

    /**
     * Registra una llamada del lead.
     *
     * @param Request            $request
     * @param int|string         $id       Id del lead.
     * @param LeadLlamadaService $servicio
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function store_json(Request $request, $id, LeadLlamadaService $servicio)
    {
        $lead = Lead::query()->findOrFail((int) $id);

        $resultado = $servicio->registrar($lead, $request->user(), $request->all());

        $cuerpo = [
            'llamada'     => $servicio->a_json($resultado['llamada']),
            'oportunidad' => $servicio->tarjeta_a_json($resultado['tarjeta']),
        ];

        if ($resultado['duplicada']) {
            $cuerpo['duplicada'] = true;

            return response()->json($cuerpo, 200);
        }

        return response()->json($cuerpo, 201);
    }

    /**
     * Las últimas llamadas del lead, de la más nueva a la más vieja.
     *
     * @param int|string         $id
     * @param LeadLlamadaService $servicio
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index_json($id, LeadLlamadaService $servicio)
    {
        $lead = Lead::query()->findOrFail((int) $id);

        $llamadas = LeadLlamada::query()
            ->with(['admin', 'cargada_por'])
            ->where('lead_id', (int) $lead->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::LIMITE_DEL_LISTADO)
            ->get();

        $modelos = [];
        foreach ($llamadas as $llamada) {
            $modelos[] = $servicio->a_json($llamada);
        }

        return response()->json(['models' => $modelos], 200);
    }
}

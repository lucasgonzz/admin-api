<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespuestasParaClaude;
use App\Http\Controllers\Controller;
use App\Services\ImplementationFormSteps;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * `GET claude/support-codex/implementation-form-steps/pending` (misión cruzada `monitor-pasos-implementar-auto`,
 * 16/11/2026): las implementaciones de sistema con el formulario completo y el user setup sin correr, cuyo cliente
 * tiene un ticket abierto. Solo lectura y aditivo: un monitor viejo no llama esta ruta, y un admin viejo contesta
 * 404 y el monitor nuevo apaga ese ciclo. La lógica vive en `ImplementationFormSteps`.
 */
class ClaudeImplementationFormStepsController extends Controller
{
    use RespuestasParaClaude;

    /**
     * Lista los pasos de formulario completo pendientes de disparar.
     *
     * Parámetros: `since` (fecha ISO 8601, opcional) y `limit` (entero 1..20, por defecto 10). Un parámetro mal
     * formado es 422 con la forma única del bloque `claude/*`.
     *
     * @param Request                 $request  Request entrante.
     * @param ImplementationFormSteps $servicio Servicio que arma la lista.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function pending(Request $request, ImplementationFormSteps $servicio)
    {
        $error = $this->validar_o_422($request, [
            'since' => 'sometimes|nullable|date',
            'limit' => 'sometimes|nullable|integer|min:1|max:' . ImplementationFormSteps::LIMITE_MAXIMO,
        ]);

        if ($error !== null) {
            return $error;
        }

        // `since` ya validado como fecha; se interpreta con su propio offset si lo trae.
        $since = $request->filled('since') ? Carbon::parse($request->input('since')) : null;
        $limit = $request->filled('limit') ? (int) $request->input('limit') : ImplementationFormSteps::LIMITE_POR_DEFECTO;

        return response()->json($servicio->pending($since, $limit));
    }
}

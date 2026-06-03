<?php

namespace App\Http\Controllers;

use App\Models\FollowupRule;
use Illuminate\Http\Request;

/**
 * API JSON de reglas de seguimiento automático (scheduler + IA).
 */
class FollowupRuleController extends Controller
{
    /**
     * Lista todas las reglas ordenadas por id.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index_json()
    {
        $models = FollowupRule::query()->orderBy('id')->get();

        return response()->json(['models' => $models], 200);
    }

    /**
     * Actualiza parámetros editables de una regla.
     *
     * @param Request $request
     * @param int|string $id
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function update_json(Request $request, $id)
    {
        $rule = FollowupRule::findOrFail($id);

        if ($request->has('horas_espera')) {
            $rule->horas_espera = max(1, min(8760, (int) $request->input('horas_espera')));
        }
        if ($request->has('max_followups')) {
            $rule->max_followups = max(0, min(100, (int) $request->input('max_followups')));
        }
        if ($request->has('activa')) {
            $rule->activa = $request->boolean('activa');
        }
        if ($request->has('descripcion')) {
            $rule->descripcion = $request->input('descripcion');
        }

        $rule->save();

        return response()->json(['model' => $this->fullModel('followup_rule', $rule->id)], 200);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\FollowupTemplate;
use Illuminate\Http\Request;

/**
 * API JSON de plantillas Meta para seguimientos automáticos directos.
 */
class FollowupTemplateController extends Controller
{
    /**
     * Lista todas las plantillas ordenadas por estado y número de día.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index_json()
    {
        $models = FollowupTemplate::query()
            ->orderBy('estado')
            ->orderBy('dia_numero')
            ->get();

        return response()->json(['models' => $models], 200);
    }

    /**
     * Actualiza los campos editables de una plantilla (nombre, idioma, activa, texto).
     *
     * @param Request    $request
     * @param int|string $id
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function update_json(Request $request, $id)
    {
        $template = FollowupTemplate::findOrFail($id);

        if ($request->has('template_name')) {
            $template->template_name = trim((string) $request->input('template_name'));
        }
        if ($request->has('language_code')) {
            $template->language_code = trim((string) $request->input('language_code'));
        }
        if ($request->has('activa')) {
            $template->activa = $request->boolean('activa');
        }
        if ($request->has('body_template')) {
            /* Permite guardar null o string vacío para limpiar el body de la plantilla. */
            $raw = $request->input('body_template');
            $template->body_template = ($raw === null || trim((string) $raw) === '')
                ? null
                : trim((string) $raw);
        }

        $template->save();

        return response()->json(['model' => $template], 200);
    }
}

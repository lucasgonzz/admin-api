<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadCompromiso;
use App\Services\LeadCompromisoService;
use Illuminate\Http\Request;

/**
 * Los compromisos de contacto de un lead, desde el panel (misión leads-referidos-compromisos, etapa E2).
 *
 * Sin lógica de negocio: crear, mover, cancelar y validar viven en {@see LeadCompromisoService}, que responde el 422 con
 * `errors.<campo>` (por eso no hay `$request->validate()`, igual que en {@see LeadLlamadaController}).
 *
 *  - `GET admin/lead/{id}/compromisos`: 200 `{compromisos: [...], motor_activo}` con el abierto primero y después el historial.
 *  - `POST admin/lead/{id}/compromisos`: 201 `{model}`; reemplaza al vigente. Con el motor apagado, 409 y no se guarda nada.
 *  - `PUT admin/lead-compromisos/{id}`: 200 `{model}`; cambia fecha, acción o resumen de un compromiso vigente.
 *  - `POST admin/lead-compromisos/{id}/cancelar`: 200 `{model}`.
 *
 * 🔴 Ninguna de estas rutas le escribe al lead: lo que el motor le mande sale por su camino de siempre (sugerencia con
 * aprobación). Por eso las puede usar el perfil «Sólo Leads» (Juan).
 */
class LeadCompromisoController extends Controller
{
    /**
     * Los compromisos del lead: el abierto primero y después el historial.
     *
     * @param int|string            $id       Id del lead.
     * @param LeadCompromisoService $servicio
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index_json($id, LeadCompromisoService $servicio)
    {
        $lead = Lead::query()->findOrFail((int) $id);

        $modelos = [];
        foreach ($servicio->listar($lead) as $compromiso) {
            $modelos[] = $servicio->a_json($compromiso);
        }

        /* `motor_activo`: con el motor apagado el compromiso no se va a ejecutar; la pantalla no tiene que mostrarlo como si sí. */
        return response()->json(['compromisos' => $modelos, 'motor_activo' => LeadCompromisoService::activo()], 200);
    }

    /**
     * Crea un compromiso a mano (Juan anota «llamame el viernes»).
     *
     * @param Request               $request  `vence_at`, `accion`, `resumen`, `cita_del_lead`.
     * @param int|string            $id       Id del lead.
     * @param LeadCompromisoService $servicio
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function store_json(Request $request, $id, LeadCompromisoService $servicio)
    {
        $lead = Lead::query()->findOrFail((int) $id);

        /* Con el motor apagado `crear()` no hace nada: se dice claro, para que nadie crea que quedó anotado. */
        if (! LeadCompromisoService::activo()) {
            return response()->json([
                'message' => 'El motor de compromisos está apagado: no se guardó nada. Se prende desde Cuenta.',
                'code'    => 'motor_apagado',
            ], 409);
        }

        $compromiso = $servicio->crear($lead, array_merge($request->all(), ['origen' => LeadCompromiso::ORIGEN_MANUAL]), $request->user() !== null ? (int) $request->user()->id : null);

        return response()->json(['model' => $servicio->a_json($compromiso)], 201);
    }

    /**
     * Cambia la fecha, la acción o el resumen de un compromiso vigente.
     *
     * @param Request               $request
     * @param int|string            $id       Id del compromiso.
     * @param LeadCompromisoService $servicio
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function update_json(Request $request, $id, LeadCompromisoService $servicio)
    {
        $compromiso = LeadCompromiso::query()->findOrFail((int) $id);

        $compromiso = $servicio->actualizar($compromiso, $request->only(['vence_at', 'accion', 'resumen', 'cita_del_lead']), $request->user() !== null ? (int) $request->user()->id : null);

        return response()->json(['model' => $servicio->a_json($compromiso)], 200);
    }

    /**
     * Cancela un compromiso (el lead ya no lo necesita, se arregló por otro lado).
     *
     * @param Request               $request  `motivo` (opcional).
     * @param int|string            $id       Id del compromiso.
     * @param LeadCompromisoService $servicio
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function cancelar_json(Request $request, $id, LeadCompromisoService $servicio)
    {
        $compromiso = LeadCompromiso::query()->findOrFail((int) $id);

        $compromiso = $servicio->cancelar($compromiso, $request->input('motivo'), $request->user() !== null ? (int) $request->user()->id : null);

        return response()->json(['model' => $servicio->a_json($compromiso)], 200);
    }
}

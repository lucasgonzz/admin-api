<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Services\LeadLlamadaGrabacionService;
use Illuminate\Http\Request;

/**
 * La grabación de una llamada de Juan, desde el panel (misión leads-referidos-compromisos, etapa E3).
 *
 * Sin lógica de negocio: la validación (422 con `errors.<campo>`), la unión de las partes y el encolado de la transcripción están en
 * {@see LeadLlamadaGrabacionService}. Sin `$request->validate()` a propósito: el servicio responde el 422 con la misma forma que
 * «Registrar llamada». La llamada tiene que ser del lead de la URL: la de otro lead da 404 en todas las puertas.
 *
 *  - `POST admin/lead/{id}/llamadas/{llamada_id}/grabacion` `{nombre, bytes, mime}`: 201 `{grabacion}` en `subiendo`; 409 con la
 *    grabación si la llamada ya tiene una en cola, transcribiéndose o lista.
 *  - `POST …/grabacion/partes` multipart `{indice, parte, grabacion_id?}`: 200 `{grabacion}`; repetir una parte no duplica nada.
 *  - `POST …/grabacion/completar` `{sha256?, grabacion_id?}`: 200 `{grabacion}` en `pendiente`; 422 con `faltantes`; 409 si el mismo archivo ya está
 *    en otra llamada del lead.
 *  - `GET …/grabacion`: 200 `{grabacion}` con la transcripción completa; 404 si la llamada no tiene.
 *  - `POST …/grabacion/resumir`: 200 `{grabacion}`; vuelve a resumir una grabación transcripta sin resumen (422 si no corresponde).
 *  - `POST …/grabacion/reintentar`: 200 `{grabacion}` en `pendiente`; 422 si no se puede desde su estado.
 *
 * 🔴 Ninguna de estas rutas le escribe al lead (sólo sube un audio y lo transcribe), así que las puede usar el perfil «Sólo Leads».
 */
class LeadLlamadaGrabacionController extends Controller
{
    /**
     * Arranca la subida de la grabación de una llamada.
     *
     * @param Request                    $request    `nombre`, `bytes` y, opcional, `mime`.
     * @param int|string                 $id         Id del lead.
     * @param int|string                 $llamada_id Id de la llamada.
     * @param LeadLlamadaGrabacionService $servicio
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function iniciar_json(Request $request, $id, $llamada_id, LeadLlamadaGrabacionService $servicio)
    {
        $lead    = Lead::query()->findOrFail((int) $id);
        $llamada = $servicio->llamada_del_lead($lead, $llamada_id);

        $resultado = $servicio->iniciar($lead, $llamada, $request->user(), $request->only(['nombre', 'bytes', 'mime']));

        if ($resultado['conflicto']) {
            return response()->json([
                'message'   => 'Esta llamada ya tiene una grabación (está «' . $resultado['grabacion']->status . '»).',
                'code'      => 'llamada_con_grabacion',
                'grabacion' => $servicio->a_json($resultado['grabacion']),
            ], 409);
        }

        return response()->json(['grabacion' => $servicio->a_json($resultado['grabacion'])], 201);
    }

    /**
     * Recibe una parte del archivo.
     *
     * @param Request                    $request    Multipart: `indice` y `parte`.
     * @param int|string                 $id         Id del lead.
     * @param int|string                 $llamada_id Id de la llamada.
     * @param LeadLlamadaGrabacionService $servicio
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function parte_json(Request $request, $id, $llamada_id, LeadLlamadaGrabacionService $servicio)
    {
        $lead      = Lead::query()->findOrFail((int) $id);
        $grabacion = $servicio->de_la_llamada($servicio->llamada_del_lead($lead, $llamada_id), $request->input('grabacion_id'));

        $grabacion = $servicio->recibir_parte($grabacion, $request->input('indice'), $request->file('parte'));

        return response()->json(['grabacion' => $servicio->a_json($grabacion)], 200);
    }

    /**
     * Cierra la subida: une las partes y encola la transcripción.
     *
     * @param Request                    $request    `sha256` (opcional).
     * @param int|string                 $id         Id del lead.
     * @param int|string                 $llamada_id Id de la llamada.
     * @param LeadLlamadaGrabacionService $servicio
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function completar_json(Request $request, $id, $llamada_id, LeadLlamadaGrabacionService $servicio)
    {
        $lead      = Lead::query()->findOrFail((int) $id);
        $grabacion = $servicio->de_la_llamada($servicio->llamada_del_lead($lead, $llamada_id), $request->input('grabacion_id'));

        $resultado = $servicio->completar($grabacion, $request->input('sha256'));

        return response()->json($resultado['cuerpo'], $resultado['http']);
    }

    /**
     * El estado de la grabación, con el resumen y la transcripción completa.
     *
     * @param int|string                 $id         Id del lead.
     * @param int|string                 $llamada_id Id de la llamada.
     * @param LeadLlamadaGrabacionService $servicio
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function mostrar_json($id, $llamada_id, LeadLlamadaGrabacionService $servicio)
    {
        $lead      = Lead::query()->findOrFail((int) $id);
        $grabacion = $servicio->de_la_llamada($servicio->llamada_del_lead($lead, $llamada_id));

        return response()->json(['grabacion' => $servicio->a_json($grabacion)], 200);
    }

    /**
     * Vuelve a resumir una grabación transcripta cuya IA de resumen había fallado.
     *
     * @param int|string                 $id         Id del lead.
     * @param int|string                 $llamada_id Id de la llamada.
     * @param LeadLlamadaGrabacionService $servicio
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function resumir_json($id, $llamada_id, LeadLlamadaGrabacionService $servicio)
    {
        $lead      = Lead::query()->findOrFail((int) $id);
        $grabacion = $servicio->de_la_llamada($servicio->llamada_del_lead($lead, $llamada_id));

        return response()->json(['grabacion' => $servicio->a_json($servicio->volver_a_resumir($grabacion))], 200);
    }

    /**
     * Vuelve a poner en cola una grabación que falló o que quedó colgada.
     *
     * @param int|string                 $id         Id del lead.
     * @param int|string                 $llamada_id Id de la llamada.
     * @param LeadLlamadaGrabacionService $servicio
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function reintentar_json($id, $llamada_id, LeadLlamadaGrabacionService $servicio)
    {
        $lead      = Lead::query()->findOrFail((int) $id);
        $grabacion = $servicio->de_la_llamada($servicio->llamada_del_lead($lead, $llamada_id));

        return response()->json(['grabacion' => $servicio->a_json($servicio->reintentar($grabacion))], 200);
    }
}

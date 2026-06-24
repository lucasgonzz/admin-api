<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessImplementationFormSubmit;
use App\Models\Implementation;
use App\Models\ImplementationStage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Endpoints públicos del formulario de configuración de implementación.
 *
 * El cliente accede mediante un link único con form_token (UUID v4) sin necesitar
 * autenticación. Los tres endpoints permiten: leer el estado actual del formulario,
 * guardar parcialmente los datos (autoguardado) y enviar definitivamente el formulario.
 */
class ImplementationFormController extends Controller
{
    /**
     * Devuelve el estado actual del formulario para el token dado.
     *
     * Si el token no existe → 404.
     * Si el formulario ya fue enviado → { submitted: true }.
     * Si aún no fue enviado → { submitted: false, client_name: string, form_data: object }.
     *
     * @param string $token Token UUID v4 del formulario (form_token en implementations).
     *
     * @return JsonResponse
     */
    public function show(string $token): JsonResponse
    {
        // Buscar la implementación por su token público.
        $implementation = Implementation::byFormToken($token)
            ->with(['client', 'stages'])
            ->first();

        if ($implementation === null) {
            return response()->json(['message' => 'Formulario no encontrado.'], 404);
        }

        // Si el formulario ya fue enviado, responder con flag simple (no exponer datos).
        if ($implementation->form_submitted_at !== null) {
            return response()->json(['submitted' => true], 200);
        }

        // Nombre del cliente para personalizar el formulario en el frontend.
        $client_name = $implementation->client
            ? $implementation->client->resolve_display_name()
            : '';

        // Datos actuales del stage 1 (etapa de recolección de información de la empresa).
        $stage = $implementation->stages->first(function ($s) {
            return (int) $s->stage_number === 1;
        });

        // El form_data puede estar parcialmente completado (autoguardados previos) o vacío.
        $form_data = ($stage && is_array($stage->data)) ? $stage->data : (object) [];

        return response()->json([
            'submitted'   => false,
            'client_name' => $client_name,
            'form_data'   => $form_data,
        ], 200);
    }

    /**
     * Autoguardado parcial: mergea los campos enviados sobre el data actual del stage 1.
     *
     * No reemplaza todo el data, solo mergea los campos recibidos en `fields`.
     * Rechaza el guardado si el formulario ya fue enviado definitivamente.
     *
     * @param Request $request Petición con clave `fields` (objeto con campos del formulario).
     * @param string  $token   Token UUID v4 del formulario.
     *
     * @return JsonResponse { saved_at: string } o error 422.
     */
    public function save(Request $request, string $token): JsonResponse
    {
        // Buscar la implementación por su token público.
        $implementation = Implementation::byFormToken($token)->first();

        if ($implementation === null) {
            return response()->json(['message' => 'Formulario no encontrado.'], 404);
        }

        // No permitir modificaciones si el formulario ya fue enviado.
        if ($implementation->form_submitted_at !== null) {
            return response()->json(['message' => 'El formulario ya fue enviado y no puede modificarse.'], 422);
        }

        // Campos a mergear recibidos en el body.
        $fields = $request->input('fields', []);

        if (! is_array($fields)) {
            return response()->json(['message' => 'El campo fields debe ser un objeto.'], 422);
        }

        // Obtener el stage 1 para leer/actualizar su data.
        $stage = ImplementationStage::where('implementation_id', $implementation->id)
            ->where('stage_number', 1)
            ->first();

        if ($stage === null) {
            return response()->json(['message' => 'Etapa del formulario no encontrada.'], 404);
        }

        // Mergeamos los campos nuevos sobre los existentes para no perder autoguardados previos.
        $current_data = is_array($stage->data) ? $stage->data : [];
        $current_data = array_merge($current_data, $fields);

        $stage->data = $current_data;
        $stage->save();

        // Devolver timestamp de guardado para que el frontend muestre "Guardado hace X segundos".
        return response()->json([
            'saved_at' => now()->toIso8601String(),
        ], 200);
    }

    /**
     * Envío definitivo del formulario de configuración.
     *
     * Flujo:
     * 1. Mergea los campos finales al data del stage 1.
     * 2. Marca form_submitted_at en la implementación.
     * 3. Despacha el job ProcessImplementationFormSubmit con el delay configurado.
     *
     * @param Request $request Petición con clave `fields` (objeto con campos del formulario).
     * @param string  $token   Token UUID v4 del formulario.
     *
     * @return JsonResponse { success: true } o error 422.
     */
    public function submit(Request $request, string $token): JsonResponse
    {
        // Buscar la implementación por su token público.
        $implementation = Implementation::byFormToken($token)->first();

        if ($implementation === null) {
            return response()->json(['message' => 'Formulario no encontrado.'], 404);
        }

        // Rechazar reenvíos: el formulario solo puede enviarse una vez.
        if ($implementation->form_submitted_at !== null) {
            return response()->json(['message' => 'El formulario ya fue enviado.'], 422);
        }

        // Campos finales recibidos en el envío.
        $fields = $request->input('fields', []);

        if (! is_array($fields)) {
            return response()->json(['message' => 'El campo fields debe ser un objeto.'], 422);
        }

        // Obtener el stage 1 para mergear los datos finales.
        $stage = ImplementationStage::where('implementation_id', $implementation->id)
            ->where('stage_number', 1)
            ->first();

        if ($stage === null) {
            Log::channel('daily')->error('ImplementationFormController@submit: stage 1 no encontrado.', [
                'implementation_id' => $implementation->id,
            ]);

            return response()->json(['message' => 'Etapa del formulario no encontrada.'], 404);
        }

        // Mergear los campos finales sobre los datos ya guardados en autoguardados previos.
        $current_data = is_array($stage->data) ? $stage->data : [];
        $current_data = array_merge($current_data, $fields);

        $stage->data = $current_data;
        $stage->save();

        // Registrar el timestamp de envío definitivo para bloquear futuros intentos.
        $implementation->form_submitted_at = now();
        $implementation->save();

        // Despachar el job que procesará el formulario con el delay configurado en settings.
        ProcessImplementationFormSubmit::dispatch($implementation->id);

        Log::channel('daily')->info('ImplementationFormController@submit: formulario enviado.', [
            'implementation_id'  => $implementation->id,
            'form_submitted_at'  => $implementation->form_submitted_at->toIso8601String(),
        ]);

        return response()->json(['success' => true], 200);
    }
}

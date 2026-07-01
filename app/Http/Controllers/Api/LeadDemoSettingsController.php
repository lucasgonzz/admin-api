<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LeadDemoSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Expone GET y PUT para gestionar la configuración de demos desde el admin.
 *
 * Delega toda la lógica de persistencia y defaults a LeadDemoSettings.
 */
class LeadDemoSettingsController extends Controller
{
    /**
     * Devuelve la configuración actual de demos (duración, márgenes, automatizaciones).
     *
     * @return JsonResponse
     */
    public function show(): JsonResponse
    {
        return response()->json(LeadDemoSettings::to_array(), 200);
    }

    /**
     * Persiste los parámetros configurables de demos.
     *
     * Los valores en minutos son enteros entre 0 y 240; la hora de mañana es string H:i.
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function update(Request $request): JsonResponse
    {
        /* Validar todos los campos que consume persist_from_request (solo pasan las claves listadas). */
        $validated = $request->validate([
            'duracion_minutos'                => 'required|integer|min:'.LeadDemoSettings::MIN_MINUTOS.'|max:'.LeadDemoSettings::MAX_MINUTOS,
            'setup_minutos_antes'             => 'required|integer|min:'.LeadDemoSettings::MIN_MINUTOS.'|max:'.LeadDemoSettings::MAX_MINUTOS,
            'gracia_minutos_post'             => 'required|integer|min:'.LeadDemoSettings::MIN_MINUTOS.'|max:'.LeadDemoSettings::MAX_MINUTOS,
            'recordatorio_minutos_antes'      => 'required|integer|min:'.LeadDemoSettings::MIN_MINUTOS.'|max:'.LeadDemoSettings::MAX_MINUTOS,
            'recordatorio_manana_hora'        => 'required|date_format:H:i',
            'check_ingreso_minutos_post'      => 'required|integer|min:'.LeadDemoSettings::MIN_MINUTOS.'|max:'.LeadDemoSettings::MAX_MINUTOS,
            'resumen_minutos_antes_fin'       => 'required|integer|min:'.LeadDemoSettings::MIN_MINUTOS.'|max:'.LeadDemoSettings::MAX_MINUTOS,
            'duracion_llamada_closer_minutos' => 'required|integer|min:'.LeadDemoSettings::MIN_MINUTOS.'|max:'.LeadDemoSettings::MAX_MINUTOS,
            /* Horarios del closer: string H:i-H:i o vacío (día sin trabajo). */
            'closer_horario_lunes_viernes'    => 'nullable|string|max:20',
            'closer_horario_sabado'           => 'nullable|string|max:20',
            'closer_horario_domingo'          => 'nullable|string|max:20',
            'frecuencia_slots_minutos'        => 'required|integer|in:'.implode(',', LeadDemoSettings::VALID_FRECUENCIA_SLOTS),
            'llamada_debe_terminar_en_horario' => 'required|boolean',
            // Settings del ciclo de vida automatizado de la demo.
            'ingreso_timeout_minutos'         => 'required|integer|min:'.LeadDemoSettings::MIN_MINUTOS.'|max:'.LeadDemoSettings::MAX_MINUTOS,
            'fin_seguimiento_minutos'         => 'required|integer|min:'.LeadDemoSettings::MIN_MINUTOS.'|max:'.LeadDemoSettings::MAX_MINUTOS,
            'fin_timeout_minutos'             => 'required|integer|min:'.LeadDemoSettings::MIN_MINUTOS.'|max:'.LeadDemoSettings::MAX_MINUTOS,
            'pendiente_ingreso_horas_timeout' => 'required|integer|min:1|max:720',
            'pendiente_terminar_timeout_minutos' => 'required|integer|min:'.LeadDemoSettings::MIN_MINUTOS.'|max:'.LeadDemoSettings::MAX_MINUTOS,
        ]);

        /* Persistir todos los valores validados. */
        LeadDemoSettings::persist_from_request($validated);

        return response()->json(LeadDemoSettings::to_array(), 200);
    }
}

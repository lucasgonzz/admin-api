<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\Implementation;
use App\Models\ImplementationStage;
use App\Services\ImplementationConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Acciones y listado del flujo de implementación guiada de clientes.
 */
class ImplementationController extends Controller
{
    /**
     * Lista todas las implementaciones ordenadas por updated_at descendente.
     *
     * Carga relaciones: client, stages y stages.config para mostrar el nombre de etapa
     * desde la tabla de configuración maestra.
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        // Listado completo con relaciones necesarias para el panel izquierdo.
        $implementations = Implementation::query()
            ->with(['client', 'stages', 'stages.config'])
            ->orderBy('updated_at', 'desc')
            ->get();

        return response()->json(['models' => $implementations], 200);
    }

    /**
     * Detalle completo de una implementación, incluyendo mensajes ordenados por sent_at.
     *
     * @param Implementation $implementation Implementación cargada por route model binding.
     *
     * @return JsonResponse
     */
    public function show(Implementation $implementation): JsonResponse
    {
        // Cargar todas las relaciones requeridas para el panel de detalle.
        $implementation->load(['client', 'stages', 'stages.config', 'messages']);

        return response()->json(['model' => $implementation], 200);
    }

    /**
     * Devuelve el JSON `data` del stage 4 de una implementación (archivos, análisis, import_status).
     *
     * @param Implementation $implementation Implementación cargada por route model binding.
     *
     * @return JsonResponse { data: object|null }
     */
    public function get_stage4_data(Implementation $implementation): JsonResponse
    {
        $stage = ImplementationStage::where('implementation_id', $implementation->id)
            ->where('stage_number', 4)
            ->first();

        if ($stage === null) {
            return response()->json(['data' => null], 200);
        }

        $stage_data = is_array($stage->data) ? $stage->data : null;

        return response()->json(['data' => $stage_data], 200);
    }

    /**
     * Avanza manualmente a la siguiente etapa de la implementación.
     *
     * Flujo:
     *  1. Marca la etapa actual como 'completed' con completed_at = now().
     *  2. Incrementa current_stage en 1.
     *  3. Si current_stage > 7 → marca implementación como 'completed'.
     *  4. Si no → marca nueva etapa como 'in_progress' con started_at = now().
     *
     * @param Implementation $implementation Implementación a avanzar (route model binding).
     *
     * @return JsonResponse
     */
    public function advance_stage(Implementation $implementation): JsonResponse
    {
        // Registro de la etapa actual que se va a cerrar.
        $current_stage_record = ImplementationStage::where('implementation_id', $implementation->id)
            ->where('stage_number', $implementation->current_stage)
            ->first();

        // Completar la etapa activa si existe.
        if ($current_stage_record) {
            $current_stage_record->status       = 'completed';
            $current_stage_record->completed_at = now();
            $current_stage_record->save();
        }

        // Número de la etapa siguiente.
        $next_stage = $implementation->current_stage + 1;
        $implementation->current_stage = $next_stage;

        if ($next_stage > 7) {
            // La implementación finalizó: todas las etapas cubiertas.
            $implementation->status       = 'completed';
            $implementation->completed_at = now();
        } else {
            // Activar la etapa siguiente poniendo en marcha su cronómetro.
            $next_stage_record = ImplementationStage::where('implementation_id', $implementation->id)
                ->where('stage_number', $next_stage)
                ->first();

            if ($next_stage_record) {
                $next_stage_record->status     = 'in_progress';
                $next_stage_record->started_at = now();
                $next_stage_record->save();
            }
        }

        $implementation->save();

        // Acciones automáticas al activar etapas con lógica de conversación (2 a 7).
        if ($next_stage >= 2 && $next_stage <= 7) {
            $conversation_service = new ImplementationConversationService();
            $conversation_service->handle_stage_advance($implementation, $next_stage);
        }

        // Devolver modelo fresco con todas las relaciones del panel de detalle.
        return response()->json([
            'model' => $implementation->fresh()->load(['stages', 'stages.config', 'client', 'messages']),
        ], 200);
    }

    /**
     * Inicia la implementación de un cliente: crea el registro y las 7 etapas.
     *
     * @param Client $client Cliente destino (route model binding).
     *
     * @return JsonResponse
     */
    public function start(Client $client): JsonResponse
    {
        // Un cliente solo puede tener una implementación activa en el sistema.
        if ($client->implementation()->exists()) {
            return response()->json([
                'message' => 'Este cliente ya tiene una implementación iniciada.',
            ], 422);
        }

        /**
         * Admin asignado por defecto leído del setting global.
         * Se convierte a entero; si es 0 o no existe se guarda como null.
         */
        $assigned_admin_id = (int) AdminSetting::get('implementation_assigned_admin_id', 0) ?: null;

        /** Implementación creada con etapa 1 en curso. */
        $implementation = DB::transaction(function () use ($client, $assigned_admin_id) {
            $implementation = Implementation::create([
                'client_id'          => $client->id,
                'status'             => 'in_progress',
                'current_stage'      => 1,
                'started_at'         => now(),
                'assigned_admin_id'  => $assigned_admin_id,
            ]);

            // Crear las siete etapas en estado pendiente.
            for ($stage_number = 1; $stage_number <= 7; $stage_number++) {
                ImplementationStage::create([
                    'implementation_id' => $implementation->id,
                    'stage_number'        => $stage_number,
                    'status'              => 'pending',
                ]);
            }

            // Activar la etapa 1.
            ImplementationStage::where('implementation_id', $implementation->id)
                ->where('stage_number', 1)
                ->update([
                    'status'     => 'in_progress',
                    'started_at' => now(),
                ]);

            return $implementation;
        });

        return response()->json([
            'model' => $implementation->load(['stages', 'client']),
        ], 201);
    }
}

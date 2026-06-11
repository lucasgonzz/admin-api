<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\ClientEcommerce;
use App\Models\EcommerceImplementation;
use App\Models\EcommerceImplementationStage;
use App\Services\EcommerceImplementationConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Acciones y listado del flujo de implementación de la tienda online (ecommerce).
 *
 * Sigue el mismo estilo que ImplementationController pero sobre el modelo separado
 * de ecommerce (5 etapas).
 */
class EcommerceImplementationController extends Controller
{
    /**
     * Cantidad total de etapas del flujo de ecommerce.
     */
    private const TOTAL_STAGES = 5;

    /**
     * Lista todas las implementaciones de ecommerce ordenadas por updated_at descendente.
     *
     * Carga relaciones: client, stages, stages.config y client_ecommerce. Agrega el campo
     * virtual `ready_to_advance` (true cuando la etapa activa está completada y aún hay etapas).
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        $implementations = EcommerceImplementation::query()
            ->with(['client', 'stages', 'stages.config', 'client_ecommerce'])
            ->orderBy('updated_at', 'desc')
            ->get();

        $implementations->each(function ($impl) {
            $impl->ready_to_advance = $this->is_ready_to_advance($impl);
        });

        return response()->json(['models' => $implementations], 200);
    }

    /**
     * Devuelve la cantidad de implementaciones de ecommerce listas para avanzar.
     *
     * @return JsonResponse { count: int }
     */
    public function ready_to_advance_count(): JsonResponse
    {
        $implementations = EcommerceImplementation::query()
            ->where('status', 'in_progress')
            ->where('current_stage', '<', self::TOTAL_STAGES)
            ->with('stages')
            ->get();

        $count = 0;

        $implementations->each(function ($impl) use (&$count) {
            if ($this->is_ready_to_advance($impl)) {
                $count++;
            }
        });

        return response()->json(['count' => $count], 200);
    }

    /**
     * Detalle completo de una implementación de ecommerce, incluyendo mensajes.
     *
     * @param EcommerceImplementation $ecommerce_implementation Cargada por route model binding.
     *
     * @return JsonResponse
     */
    public function show(EcommerceImplementation $ecommerce_implementation): JsonResponse
    {
        $ecommerce_implementation->load(['client', 'stages', 'stages.config', 'client_ecommerce', 'messages']);

        return response()->json(['model' => $ecommerce_implementation], 200);
    }

    /**
     * Inicia la implementación de la tienda online de un cliente.
     *
     * Crea el ClientEcommerce (si no existe), la EcommerceImplementation con sus 5 etapas,
     * activa la Etapa 1 y dispara su apertura (sugerencia de dominio por WhatsApp).
     *
     * @param Client $client Cliente destino (route model binding).
     *
     * @return JsonResponse
     */
    public function start(Client $client): JsonResponse
    {
        // Un cliente solo puede tener una implementación de ecommerce activa.
        if ($client->ecommerce_implementation()->exists()) {
            return response()->json([
                'message' => 'Este cliente ya tiene una implementación de ecommerce iniciada.',
            ], 422);
        }

        // Admin asignado por defecto (mismo setting global que la implementación de sistema).
        $assigned_admin_id = (int) AdminSetting::get('implementation_assigned_admin_id', 0) ?: null;

        $implementation = DB::transaction(function () use ($client, $assigned_admin_id) {
            // Crear (o reutilizar) la tienda online del cliente.
            $client_ecommerce = ClientEcommerce::firstOrCreate(
                ['client_id' => $client->id],
                ['status' => 'pending']
            );

            $implementation = EcommerceImplementation::create([
                'client_id'           => $client->id,
                'client_ecommerce_id' => $client_ecommerce->id,
                'status'              => 'in_progress',
                'current_stage'       => 1,
                'started_at'          => now(),
                'assigned_admin_id'   => $assigned_admin_id,
            ]);

            // Crear las 5 etapas en estado pendiente.
            for ($stage_number = 1; $stage_number <= self::TOTAL_STAGES; $stage_number++) {
                EcommerceImplementationStage::create([
                    'ecommerce_implementation_id' => $implementation->id,
                    'stage_number'                => $stage_number,
                    'status'                      => 'pending',
                ]);
            }

            // Activar la Etapa 1.
            EcommerceImplementationStage::where('ecommerce_implementation_id', $implementation->id)
                ->where('stage_number', 1)
                ->update([
                    'status'     => 'in_progress',
                    'started_at' => now(),
                ]);

            return $implementation;
        });

        // Disparar la apertura de la Etapa 1 (sugerencia de dominio por WhatsApp).
        (new EcommerceImplementationConversationService())->send_stage_opening_message($implementation, 1);

        return response()->json([
            'model' => $implementation->fresh()->load(['stages', 'client', 'client_ecommerce']),
        ], 201);
    }

    /**
     * Avanza manualmente a la siguiente etapa de la implementación de ecommerce.
     *
     * @param EcommerceImplementation $ecommerce_implementation Route model binding.
     *
     * @return JsonResponse
     */
    public function advance_stage(EcommerceImplementation $ecommerce_implementation): JsonResponse
    {
        // Completar la etapa activa si existe.
        $current_stage_record = EcommerceImplementationStage::where('ecommerce_implementation_id', $ecommerce_implementation->id)
            ->where('stage_number', $ecommerce_implementation->current_stage)
            ->first();

        if ($current_stage_record) {
            $current_stage_record->status       = 'completed';
            $current_stage_record->completed_at = now();
            $current_stage_record->save();
        }

        // Número de la etapa siguiente.
        $next_stage = $ecommerce_implementation->current_stage + 1;
        $ecommerce_implementation->current_stage = $next_stage;

        if ($next_stage > self::TOTAL_STAGES) {
            // Todas las etapas cubiertas: implementación completada.
            $ecommerce_implementation->status       = 'completed';
            $ecommerce_implementation->completed_at = now();
        } else {
            $next_stage_record = EcommerceImplementationStage::where('ecommerce_implementation_id', $ecommerce_implementation->id)
                ->where('stage_number', $next_stage)
                ->first();

            if ($next_stage_record) {
                $next_stage_record->status     = 'in_progress';
                $next_stage_record->started_at = now();
                $next_stage_record->save();
            }
        }

        $ecommerce_implementation->save();

        // Acciones automáticas al activar la nueva etapa (2 a 5).
        if ($next_stage >= 2 && $next_stage <= self::TOTAL_STAGES) {
            $conversation_service = new EcommerceImplementationConversationService();
            $conversation_service->handle_stage_advance($ecommerce_implementation, $next_stage);
        }

        return response()->json([
            'model' => $ecommerce_implementation->fresh()->load(['stages', 'stages.config', 'client', 'client_ecommerce', 'messages']),
        ], 200);
    }

    /**
     * Elimina una implementación de ecommerce y su información asociada.
     *
     * @param EcommerceImplementation $ecommerce_implementation Route model binding.
     *
     * @return JsonResponse
     */
    public function destroy(EcommerceImplementation $ecommerce_implementation): JsonResponse
    {
        DB::transaction(function () use ($ecommerce_implementation) {
            $ecommerce_implementation->delete();
        });

        return response()->json(['message' => 'Implementación de ecommerce eliminada.'], 200);
    }

    /**
     * Determina si una implementación está lista para avanzar de etapa.
     *
     * @param EcommerceImplementation $impl Implementación con stages cargados.
     *
     * @return bool
     */
    private function is_ready_to_advance(EcommerceImplementation $impl): bool
    {
        if ($impl->current_stage >= self::TOTAL_STAGES) {
            return false;
        }

        $current_stage_record = $impl->stages->first(function ($stage) use ($impl) {
            return $stage->stage_number == $impl->current_stage;
        });

        return $current_stage_record && $current_stage_record->status === 'completed';
    }
}

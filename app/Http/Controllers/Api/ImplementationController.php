<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Implementation;
use App\Models\ImplementationStage;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Acciones del flujo de implementación guiada de clientes.
 */
class ImplementationController extends Controller
{
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

        /** Implementación creada con etapa 1 en curso. */
        $implementation = DB::transaction(function () use ($client) {
            $implementation = Implementation::create([
                'client_id'     => $client->id,
                'status'        => 'in_progress',
                'current_stage' => 1,
                'started_at'    => now(),
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

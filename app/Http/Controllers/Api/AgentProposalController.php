<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgentProposal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controlador para gestionar las propuestas del agente analizador.
 * Lucas puede ingresar propuestas manualmente después de su análisis con Claude,
 * y aprobarlas o rechazarlas desde el panel.
 */
class AgentProposalController extends Controller
{
    /**
     * Devuelve propuestas pendientes primero, luego aprobadas/rechazadas de los últimos 30 días.
     *
     * @return JsonResponse
     */
    public function index_json(): JsonResponse
    {
        /* Fecha de corte para propuestas ya resueltas: últimos 30 días. */
        $cutoff = now()->subDays(30);

        /* Propuestas pendientes sin límite de fecha (todas las que esperan resolución). */
        $pending = AgentProposal::where('estado', 'pendiente')
            ->with('report:id,report_date,report_type')
            ->orderByDesc('created_at')
            ->get();

        /* Propuestas resueltas (aprobadas o rechazadas) de los últimos 30 días. */
        $resolved = AgentProposal::whereIn('estado', ['aprobada', 'rechazada'])
            ->where('created_at', '>=', $cutoff)
            ->with('report:id,report_date,report_type')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'pending'  => $pending,
            'resolved' => $resolved,
        ]);
    }

    /**
     * Crea una propuesta manualmente.
     * Lucas la ingresa después de su análisis con Claude para aplicar cambios controlados.
     *
     * @param Request $request Campos: tipo, descripcion, razonamiento, datos_de_soporte (JSON), accion_payload (JSON).
     *
     * @return JsonResponse
     */
    public function store_json(Request $request): JsonResponse
    {
        /* Validar campos obligatorios de la propuesta. */
        $validated = $request->validate([
            'tipo'              => 'required|string|max:40',
            'descripcion'       => 'required|string|max:255',
            'razonamiento'      => 'required|string',
            'datos_de_soporte'  => 'nullable|string',
            'accion_payload'    => 'nullable|string',
            'report_id'         => 'nullable|integer',
        ]);

        /* Decodificar campos JSON opcionales si vienen como strings. */
        $datos_de_soporte = null;
        if (!empty($validated['datos_de_soporte'])) {
            $datos_de_soporte = json_decode($validated['datos_de_soporte'], true);
        }

        $accion_payload = null;
        if (!empty($validated['accion_payload'])) {
            $accion_payload = json_decode($validated['accion_payload'], true);
        }

        /* Crear la propuesta en estado pendiente. */
        $proposal = AgentProposal::create([
            'report_id'        => $validated['report_id'] ?? null,
            'tipo'             => $validated['tipo'],
            'descripcion'      => $validated['descripcion'],
            'razonamiento'     => $validated['razonamiento'],
            'datos_de_soporte' => $datos_de_soporte,
            'accion_payload'   => $accion_payload,
            'estado'           => 'pendiente',
        ]);

        return response()->json($proposal, 201);
    }

    /**
     * Aprueba una propuesta y ejecuta su acción de payload.
     *
     * @param int $id ID de la propuesta.
     *
     * @return JsonResponse
     */
    public function approve_json(int $id): JsonResponse
    {
        /* Buscar o retornar 404. */
        $proposal = AgentProposal::findOrFail($id);

        /* Verificar que la propuesta sigue pendiente. */
        if ($proposal->estado !== 'pendiente') {
            return response()->json([
                'error' => "La propuesta ya está en estado '{$proposal->estado}' y no puede aprobarse.",
            ], 422);
        }

        /* Ejecutar la acción del payload y marcar como aprobada. */
        $proposal->apply();

        return response()->json($proposal->fresh());
    }

    /**
     * Rechaza una propuesta sin ejecutar ninguna acción.
     *
     * @param int $id ID de la propuesta.
     *
     * @return JsonResponse
     */
    public function reject_json(int $id): JsonResponse
    {
        /* Buscar o retornar 404. */
        $proposal = AgentProposal::findOrFail($id);

        /* Verificar que la propuesta sigue pendiente. */
        if ($proposal->estado !== 'pendiente') {
            return response()->json([
                'error' => "La propuesta ya está en estado '{$proposal->estado}' y no puede rechazarse.",
            ], 422);
        }

        /* Marcar como rechazada con timestamp. */
        $proposal->update([
            'estado'       => 'rechazada',
            'rechazada_at' => now(),
        ]);

        return response()->json($proposal->fresh());
    }
}

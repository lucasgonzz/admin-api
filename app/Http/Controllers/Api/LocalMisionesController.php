<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Services\Local\MisionesDelLocal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LocalMisionesController extends Controller
{
    public function iniciar(Request $request, $id, MisionesDelLocal $misiones): JsonResponse
    {
        abort_unless($request->user() instanceof Admin, 403);

        $input = $request->validate(['mission_sha256' => 'sometimes|string|regex:/^[a-f0-9]{64}$/']);

        return response()->json($misiones->solicitar((int) $id, $input['mission_sha256'] ?? null), 202);
    }

    public function pendientes(MisionesDelLocal $misiones): JsonResponse
    {
        return response()->json(['items' => $misiones->solicitudes()]);
    }

    public function reclamar(Request $request, $id, MisionesDelLocal $misiones): JsonResponse
    {
        $input = $request->validate(['solicitud_id' => 'required|uuid', 'claim_token' => 'required|uuid']);

        return response()->json(['claimed' => $misiones->reclamar((int) $id, $input['solicitud_id'], $input['claim_token'])]);
    }
}

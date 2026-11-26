<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SupportMissionDeliveryService;
use Illuminate\Http\Request;

class SupportMissionDeliveryController extends Controller
{
    public function verify(Request $request, $id, SupportMissionDeliveryService $service)
    {
        // `schedule_id` es opcional desde el contrato C5 (25/11/2026): la misión instalada por la actualización del
        // cliente no tiene programación. Con acuerdo, SupportMissionDeliveryService::verify() lo sigue exigiendo.
        $data = $request->validate(['mission_id' => 'required|integer|min:1', 'schedule_id' => 'sometimes|nullable|integer|min:1',
            'evidencia' => 'required|string|min:20|max:5000']);
        return response()->json($service->verify((int) $id, $data));
    }
}

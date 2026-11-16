<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SupportMissionDeliveryService;
use Illuminate\Http\Request;

class SupportMissionDeliveryController extends Controller
{
    public function verify(Request $request, $id, SupportMissionDeliveryService $service)
    {
        $data = $request->validate(['mission_id' => 'required|integer|min:1', 'schedule_id' => 'required|integer|min:1',
            'evidencia' => 'required|string|min:20|max:5000']);
        return response()->json($service->verify((int) $id, $data));
    }
}

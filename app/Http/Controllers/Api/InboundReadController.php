<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RegisterNotificationReadService;
use Illuminate\Http\Request;

class InboundReadController extends Controller
{
    public function store(Request $request, RegisterNotificationReadService $service)
    {
        $client = $request->attributes->get('sync_client');
        if (is_null($client)) {
            return response()->json(['error' => 'client not resolved'], 401);
        }

        $read = $service->register($client, $request->all());

        if (is_null($read)) {
            return response()->json(['error' => 'invalid payload'], 422);
        }

        return response()->json(['ok' => true, 'uuid' => $read->uuid], 200);
    }
}

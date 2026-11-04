<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ImplementationTimelineService;
use Illuminate\Http\JsonResponse;

class ImplementationTimelineController extends Controller
{
    public function index(ImplementationTimelineService $timeline): JsonResponse
    {
        return response()->json(['data' => $timeline->businesses()]);
    }
}

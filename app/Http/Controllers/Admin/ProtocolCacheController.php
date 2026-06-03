<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\WhatsappProtocolService;
use Illuminate\Http\JsonResponse;

/**
 * Endpoints del admin para invalidar la caché del protocolo de WhatsApp en GitHub.
 */
class ProtocolCacheController extends Controller
{
    /**
     * @var WhatsappProtocolService Servicio que gestiona lectura y caché del protocolo.
     */
    protected $whatsapp_protocol_service;

    /**
     * @param WhatsappProtocolService $whatsapp_protocol_service
     */
    public function __construct(WhatsappProtocolService $whatsapp_protocol_service)
    {
        $this->whatsapp_protocol_service = $whatsapp_protocol_service;
    }

    /**
     * POST /api/admin/protocol/refresh-cache
     *
     * Borra la caché local para que la próxima sugerencia de Claude lea GitHub de nuevo.
     *
     * @return JsonResponse
     */
    public function refresh(): JsonResponse
    {
        $this->whatsapp_protocol_service->refreshCache();

        return response()->json([
            'success' => true,
            'message' => 'Caché del protocolo invalidada correctamente',
        ]);
    }
}

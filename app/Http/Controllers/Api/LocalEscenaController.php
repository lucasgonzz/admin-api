<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Local\EscenaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * «El local» del admin (misión el-local, 3/11/2026): la escena que dibuja la vista `/local` de la SPA
 * (contrato B, `GET api/admin/local/escena`, grupo `admin/*` con `auth:sanctum`).
 *
 * Delgado a propósito: todo el cálculo está en {@see EscenaService}. Lo único propio de acá es el cache de
 * 20 s, para que dos admins mirando la vista a la vez (o la misma vista abierta en el teléfono y en la compu)
 * no dupliquen el cálculo.
 */
class LocalEscenaController extends Controller
{
    /** @var EscenaService */
    private $escena;

    /**
     * @param EscenaService $escena Arma la escena.
     */
    public function __construct(EscenaService $escena)
    {
        $this->escena = $escena;
    }

    /**
     * `GET admin/local/escena`: el estado del local (negocios, agentes, bandeja, oficina y eventos).
     *
     * @return JsonResponse `200 {calculado_en, resumen, negocios, agentes, bandeja, oficina, eventos}`.
     */
    public function escena_json(): JsonResponse
    {
        $escena = $this->escena;

        $payload = Cache::remember(EscenaService::CACHE_KEY, EscenaService::CACHE_SEGUNDOS, function () use ($escena) {
            return $escena->armar();
        });

        return response()->json($payload, 200);
    }
}

<?php

namespace App\Http\Controllers;

use App\Services\Local\ActividadClientesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Actividad que el sistema de un cliente le avisa al admin para «El local» (misión el-local, 3/11/2026,
 * contrato C: `POST api/inbound/local/actividad`). Su emisor es el `empresa-api` en la Fase 2.
 *
 * Va en el mismo grupo que `inbound/serper-usage` (middleware `admin.inbound.strict` + limitador
 * `shared-catalog-inbound`): la clave `X-Admin-Api-Key` es OBLIGATORIA siempre —aunque la integración legacy
 * esté sin exigirla— y es lo único que identifica al cliente (`sync_client`), igual que en
 * `SerperUsageController::store()`. Un `client_uuid` en el body no cuenta para nada.
 *
 * Delgado: valida y guarda con {@see ActividadClientesService}.
 */
class LocalActividadInboundController extends Controller
{
    /** @var ActividadClientesService */
    private $actividad;

    /**
     * @param ActividadClientesService $actividad Valida y guarda los eventos.
     */
    public function __construct(ActividadClientesService $actividad)
    {
        $this->actividad = $actividad;
    }

    /**
     * `POST inbound/local/actividad`.
     *
     * @param Request $request `{eventos: [{tipo, ocurrido_at, usuario?, detalle?}]}` (hasta 100).
     *
     * @return JsonResponse `200 {ok: true, guardados}` o `422 {error, errors}`.
     */
    public function store(Request $request): JsonResponse
    {
        $datos = $request->all();

        $errores = $this->actividad->validar($datos);
        if ($errores !== null) {
            return response()->json([
                'error'  => 'Los eventos de actividad no son válidos. No se guardó ninguno.',
                'errors' => $errores,
            ], 422);
        }

        $client_id = (int) $request->attributes->get('sync_client')->id;
        $guardados = $this->actividad->guardar($client_id, $datos['eventos']);

        return response()->json(['ok' => true, 'guardados' => $guardados], 200);
    }
}

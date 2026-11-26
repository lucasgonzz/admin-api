<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Mcp\McpOauthService;
use App\Services\Mcp\McpServidor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Endpoint MCP de sólo lectura `POST /api/mcp` (misión dots-openclaw, 25/11/2026): JSON-RPC por HTTP, sin sesión.
 *
 * Este controlador sólo resuelve lo HTTP, en este orden: conector prendido, `Origin`, tamaño del cuerpo, bearer
 * (401 con `WWW-Authenticate` que apunta a los metadatos) y `MCP-Protocol-Version`. El protocolo lo atiende
 * {@see McpServidor} y la autenticación {@see McpOauthService}. `GET` y `DELETE` dan 405: no hay stream SSE ni
 * sesión que cerrar.
 */
class McpController extends Controller
{
    /** @var McpOauthService */
    private $oauth;

    /** @var McpServidor */
    private $servidor;

    /**
     * @param McpOauthService $oauth    Autentica el bearer.
     * @param McpServidor     $servidor Atiende el JSON-RPC.
     */
    public function __construct(McpOauthService $oauth, McpServidor $servidor)
    {
        $this->oauth    = $oauth;
        $this->servidor = $servidor;
    }

    /**
     * `POST /api/mcp`: un mensaje JSON-RPC.
     *
     * @param Request $request Pedido.
     *
     * @return JsonResponse|Response
     */
    public function mensaje(Request $request)
    {
        if (! $this->oauth->habilitado()) {
            return response()->json(['message' => 'No encontrado.'], 404);
        }

        // Origin: si un navegador lo manda y no es uno de los permitidos se corta (protección contra DNS rebinding).
        $origen = $request->headers->get('Origin');
        if ($origen !== null && ! in_array($origen, (array) config('mcp.allowed_origins', []), true)) {
            return response()->json(['message' => 'Origen no permitido.'], 403);
        }

        $cuerpo = (string) $request->getContent();
        if (strlen($cuerpo) > (int) config('mcp.max_body_bytes', 262144)) {
            return response()->json(['message' => 'El pedido es demasiado grande.'], 413);
        }

        $autenticado = $this->autenticar($request);
        if ($autenticado === null) {
            return $this->no_autorizado($request->bearerToken() !== null);
        }

        $version = $request->headers->get('MCP-Protocol-Version');
        if ($version !== null && ! $this->servidor->version_soportada($version)) {
            return response()->json(['message' => 'Versión de protocolo no soportada.'], 400);
        }

        $respuesta = $this->servidor->manejar(json_decode($cuerpo, true), $autenticado['admin']);

        if ($respuesta['body'] === null) {
            return response('', $respuesta['status']);
        }

        return response()->json($respuesta['body'], $respuesta['status'], [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * `GET` y `DELETE /api/mcp`: no hay stream ni sesión, así que no se admiten.
     *
     * @return JsonResponse
     */
    public function no_permitido(): JsonResponse
    {
        if (! $this->oauth->habilitado()) {
            return response()->json(['message' => 'No encontrado.'], 404);
        }

        return response()->json(['message' => 'Método no permitido: usá POST.'], 405, ['Allow' => 'POST']);
    }

    /**
     * Resuelve el bearer del pedido.
     *
     * @param Request $request Pedido.
     *
     * @return array{token: \App\Models\McpOauthToken, admin: \App\Models\Admin}|null
     */
    private function autenticar(Request $request)
    {
        $bearer = $request->bearerToken();

        return $bearer === null || $bearer === '' ? null : $this->oauth->autenticar($bearer);
    }

    /**
     * 401 con `WWW-Authenticate`: apunta a los metadatos del recurso y pide el scope de lectura (más
     * `error="invalid_token"` si vino un token que no sirve).
     *
     * @param bool $vino_token Si el pedido traía un bearer (inválido).
     *
     * @return JsonResponse
     */
    private function no_autorizado($vino_token): JsonResponse
    {
        $encabezado = 'Bearer resource_metadata="' . $this->oauth->url_metadatos_del_recurso() . '", scope="' . McpOauthService::SCOPE_LECTURA . '"';
        if ($vino_token) {
            $encabezado .= ', error="invalid_token"';
        }

        return response()->json(['message' => 'Hace falta autorizar el conector.'], 401, ['WWW-Authenticate' => $encabezado]);
    }
}

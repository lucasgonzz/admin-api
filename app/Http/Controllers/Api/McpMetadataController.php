<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Mcp\McpOauthService;
use Illuminate\Http\JsonResponse;

/**
 * Metadatos de descubrimiento OAuth del conector MCP (misión dots-openclaw, 25/11/2026): los documentos
 * `/.well-known/…` que ChatGPT lee antes de conectarse. Se sirven desde `routes/web.php` (la raíz del dominio
 * llega a Laravel) y son públicos por diseño: no llevan ningún dato privado, sólo las direcciones del servidor.
 *
 * Delgado a propósito: el contenido lo arma {@see McpOauthService}. Con `mcp.enabled = false` dan 404.
 */
class McpMetadataController extends Controller
{
    /** @var McpOauthService */
    private $oauth;

    /**
     * @param McpOauthService $oauth Arma los documentos.
     */
    public function __construct(McpOauthService $oauth)
    {
        $this->oauth = $oauth;
    }

    /**
     * `GET /.well-known/oauth-protected-resource[/api/mcp]`: metadatos del recurso protegido.
     *
     * @return JsonResponse
     */
    public function recurso_json(): JsonResponse
    {
        if (! $this->oauth->habilitado()) {
            return response()->json(['message' => 'No encontrado.'], 404);
        }

        return $this->publico($this->oauth->metadatos_del_recurso());
    }

    /**
     * `GET /.well-known/oauth-authorization-server` y `/.well-known/openid-configuration`: metadatos del
     * servidor de autorización.
     *
     * @return JsonResponse
     */
    public function servidor_json(): JsonResponse
    {
        if (! $this->oauth->habilitado()) {
            return response()->json(['message' => 'No encontrado.'], 404);
        }

        return $this->publico($this->oauth->metadatos_del_servidor());
    }

    /**
     * Una respuesta JSON de metadatos, sin escapar las barras de las URLs.
     *
     * @param array<string, mixed> $documento Documento de metadatos.
     *
     * @return JsonResponse
     */
    private function publico(array $documento): JsonResponse
    {
        return response()->json($documento, 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

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
     * `GET /.well-known/oauth-protected-resource/api/mcp/whatsapp`: metadatos del recurso de lectura de WhatsApp
     * (404 con el interruptor apagado).
     *
     * @return JsonResponse
     */
    public function recurso_whatsapp_json(): JsonResponse
    {
        return $this->recurso_de_perfil(McpOauthService::PERFIL_WHATSAPP_LECTURA);
    }

    /**
     * `GET /.well-known/oauth-protected-resource/api/mcp/whatsapp-envio`: metadatos del recurso de envío de WhatsApp
     * (404 con el interruptor apagado).
     *
     * @return JsonResponse
     */
    public function recurso_whatsapp_envio_json(): JsonResponse
    {
        return $this->recurso_de_perfil(McpOauthService::PERFIL_WHATSAPP_ENVIO);
    }

    /**
     * `GET /.well-known/oauth-protected-resource/api/mcp/decisiones`: metadatos del recurso de decisiones de misiones
     * (404 con el interruptor apagado).
     *
     * @return JsonResponse
     */
    public function recurso_decisiones_json(): JsonResponse
    {
        return $this->recurso_de_perfil(McpOauthService::PERFIL_DECISIONES);
    }

    /**
     * Metadatos del recurso de un perfil con interruptor (WhatsApp o decisiones), o 404 si el conector o el interruptor de WhatsApp están apagados.
     *
     * @param string $perfil Perfil (uno de los PERFIL_* de {@see McpOauthService}).
     *
     * @return JsonResponse
     */
    private function recurso_de_perfil($perfil): JsonResponse
    {
        if (! $this->oauth->habilitado() || ! $this->oauth->perfil_habilitado($perfil)) {
            return response()->json(['message' => 'No encontrado.'], 404);
        }

        return $this->publico($this->oauth->metadatos_del_recurso($perfil));
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

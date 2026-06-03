<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Obtiene el protocolo de WhatsApp desde GitHub con caché configurable.
 *
 * Si falla la lectura (token ausente, error HTTP, timeout), devuelve string vacío
 * para no interrumpir el flujo de sugerencias de Claude.
 */
class WhatsappProtocolService
{
    /** Clave única en el driver de caché de Laravel. */
    const CACHE_KEY = 'whatsapp_protocol';

    /** URL del archivo de protocolo en el repositorio privado. */
    const GITHUB_CONTENTS_URL = 'https://api.github.com/repos/lucasgonzz/comerciocity-protocolo-whatsapp/contents/protocolo_whatsapp.md';

    /**
     * Devuelve el texto del protocolo, usando caché si está vigente.
     *
     * @return string Contenido del markdown o string vacío si no se pudo leer.
     */
    public function getProtocol(): string
    {
        // TTL en minutos desde configuración de entorno (por defecto 10).
        $ttl_minutes = (int) env('PROTOCOL_CACHE_TTL_MINUTES', 10);
        if ($ttl_minutes < 1) {
            $ttl_minutes = 10;
        }

        // Segundos para Cache::remember (Laravel usa segundos en versiones recientes).
        $ttl_seconds = $ttl_minutes * 60;

        return Cache::remember(self::CACHE_KEY, $ttl_seconds, function () {
            return $this->fetch_from_github();
        });
    }

    /**
     * Invalida la caché para forzar una nueva lectura en la próxima llamada a getProtocol().
     *
     * @return void
     */
    public function refreshCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Descarga y decodifica el archivo desde la GitHub Contents API.
     *
     * @return string Texto del protocolo o string vacío ante cualquier fallo.
     */
    protected function fetch_from_github(): string
    {
        // Token de acceso personal o fine-grained con permiso de lectura al repo.
        $token = trim((string) env('GITHUB_PROTOCOL_TOKEN', ''));
        if ($token === '') {
            Log::warning('WhatsappProtocolService: GITHUB_PROTOCOL_TOKEN no está configurado; protocolo omitido.');

            return '';
        }

        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'Authorization' => 'Bearer '.$token,
                    'Accept'        => 'application/vnd.github+json',
                    'User-Agent'    => 'ComercioCity-Admin-API',
                ])
                ->get(self::GITHUB_CONTENTS_URL);

            if (! $response->successful()) {
                Log::error('WhatsappProtocolService: error al leer protocolo desde GitHub.', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);

                return '';
            }

            $payload = $response->json();
            if (! is_array($payload) || empty($payload['content'])) {
                Log::error('WhatsappProtocolService: respuesta de GitHub sin campo content.', [
                    'payload_keys' => is_array($payload) ? array_keys($payload) : null,
                ]);

                return '';
            }

            // GitHub devuelve el contenido en base64, a veces con saltos de línea en el JSON.
            $encoded = str_replace(["\n", "\r"], '', (string) $payload['content']);
            $decoded = base64_decode($encoded, true);
            if ($decoded === false) {
                Log::error('WhatsappProtocolService: no se pudo decodificar el contenido base64.');

                return '';
            }

            return trim($decoded);
        } catch (\Throwable $e) {
            Log::error('WhatsappProtocolService: excepción al consultar GitHub.', [
                'message' => $e->getMessage(),
            ]);

            return '';
        }
    }
}

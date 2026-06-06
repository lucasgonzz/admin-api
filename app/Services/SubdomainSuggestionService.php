<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Sugiere un subdominio corto para un cliente usando Anthropic Claude Haiku.
 *
 * La lógica: nombre lo más corto posible, identificatorio, sin palabras
 * genéricas (distribuciones/mayorista/comercio/servicios). Solo letras,
 * números y guiones, máximo 20 caracteres.
 *
 * Si la API de Anthropic falla o no está configurada, devuelve un fallback
 * basado en Str::slug del nombre de empresa.
 */
class SubdomainSuggestionService
{
    /**
     * Modelo Claude Haiku a usar (rápido y económico para esta tarea simple).
     */
    private const MODEL = 'claude-haiku-4-5-20251001';

    /**
     * System prompt que le indica a Claude cómo generar el subdominio.
     */
    private const SYSTEM_PROMPT = <<<PROMPT
Sos un asistente que sugiere nombres de subdominio cortos para clientes de ComercioCity.
Respondé SOLO con el nombre del subdominio, sin puntos, sin espacios, en minúsculas, sin acentos, sin caracteres especiales.
Solo letras, números y guiones. Máximo 20 caracteres.
Ejemplos: "HB Distribuciones" → "hb", "La Cava de Don Juan" → "lacava", "La Martina" → "lamartina", "Masquito" → "masquito", "Galván Mayorista" → "galvan", "Ferretería El Tornillo" → "eltornillo".
La lógica es: lo más corto posible, identificatorio, sin palabras genéricas como distribuciones/mayorista/comercio/servicios.
PROMPT;

    /**
     * Sugiere un subdominio para el nombre de empresa dado.
     *
     * Llama a Claude Haiku con el system prompt definido. Si la llamada
     * falla (API key ausente, timeout, respuesta vacía), devuelve un
     * fallback generado con Str::slug, truncado a 20 caracteres.
     *
     * @param  string $company_name Nombre de empresa del cliente.
     * @return string               Subdominio sugerido (solo [a-z0-9\-], máx 20 chars).
     */
    public function suggest(string $company_name): string
    {
        /* Validación mínima: si el nombre está vacío, usar fallback directo. */
        $company_name = trim($company_name);
        if ($company_name === '') {
            return 'cliente';
        }

        try {
            /* API key de Anthropic: si no está configurada, usar fallback. */
            $api_key = (string) config('services.anthropic.api_key');
            if ($api_key === '') {
                Log::debug('SubdomainSuggestionService: ANTHROPIC_API_KEY no configurada, usando fallback.');
                return $this->build_fallback($company_name);
            }

            /* Llamada a Claude Haiku: simple single-turn, sin tools. */
            $response = $this->build_http_client()->post('https://api.anthropic.com/v1/messages', [
                'model'      => self::MODEL,
                'max_tokens' => 30,
                'system'     => self::SYSTEM_PROMPT,
                'messages'   => [
                    ['role' => 'user', 'content' => $company_name],
                ],
            ]);

            if ($response->failed()) {
                Log::warning('SubdomainSuggestionService: error Anthropic HTTP.', [
                    'status' => $response->status(),
                    'body'   => substr($response->body(), 0, 300),
                ]);
                return $this->build_fallback($company_name);
            }

            /* Extraer texto del primer bloque content de tipo text. */
            $content_blocks = $response->json('content') ?? [];
            $raw_text       = '';
            foreach ($content_blocks as $block) {
                if (is_array($block) && ($block['type'] ?? '') === 'text') {
                    $raw_text .= (string) $block['text'];
                }
            }

            /* Limpiar respuesta: minúsculas, solo [a-z0-9\-], máx 20 chars. */
            $slug = trim(strtolower($raw_text));
            $slug = (string) preg_replace('/[^a-z0-9\-]/', '', $slug);
            $slug = substr($slug, 0, 20);

            if ($slug === '') {
                return $this->build_fallback($company_name);
            }

            return $slug;

        } catch (\Throwable $exception) {
            Log::error('SubdomainSuggestionService: excepción inesperada.', [
                'company_name' => $company_name,
                'error'        => $exception->getMessage(),
            ]);
            return $this->build_fallback($company_name);
        }
    }

    /**
     * Genera un subdominio de fallback usando Str::slug, truncado a 20 chars.
     *
     * Se usa cuando la API de Anthropic no está disponible o devuelve error.
     *
     * @param  string $company_name Nombre de empresa.
     * @return string               Slug truncado a 20 caracteres.
     */
    private function build_fallback(string $company_name): string
    {
        /* Str::slug genera slug seguro para URL desde el nombre libre. */
        return substr(Str::slug($company_name ?: 'cliente'), 0, 20);
    }

    /**
     * Construye el cliente HTTP hacia Anthropic respetando la configuración
     * de SSL del proyecto (igual que SupportAiSuggestionService).
     *
     * @return PendingRequest
     */
    private function build_http_client(): PendingRequest
    {
        /* Cabeceras requeridas por la API de Anthropic. */
        $api_key = (string) config('services.anthropic.api_key');
        $http    = Http::withHeaders([
            'x-api-key'         => $api_key,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ])->timeout(30);

        /* Configuración TLS: WAMP/Windows puede requerir ca_bundle o verify=false. */
        $verify_ssl = (bool) config('services.anthropic.verify_ssl', true);
        $ca_bundle  = config('services.anthropic.ca_bundle');

        if (! $verify_ssl) {
            $http = $http->withoutVerifying();
        } elseif (is_string($ca_bundle) && $ca_bundle !== '' && is_file($ca_bundle)) {
            $http = $http->withOptions(['verify' => $ca_bundle]);
        }

        return $http;
    }
}

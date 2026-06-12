<?php

namespace App\Services;

use App\Models\AgentIdentity;
use App\Models\AiSystemPrompt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Descarga los archivos de prompt de agentes desde GitHub y actualiza la BD.
 *
 * Archivos sincronizados:
 *   - prompts_agentes/setter_identidad.md  → AgentIdentity (activo)
 *   - prompts_agentes/setter_system_prompt.md → AiSystemPrompt (activo)
 */
class AgentPromptSyncService
{
    /** URL base de la GitHub Contents API para la carpeta de prompts de agentes. */
    const GITHUB_BASE_URL = 'https://api.github.com/repos/lucasgonzz/claude-comerciocity/contents/prompts_agentes';

    /**
     * Mapa clave interna → nombre de archivo en el repositorio.
     *
     * @var array<string, string>
     */
    const FILES = [
        'setter_identidad'     => 'setter_identidad.md',
        'setter_system_prompt' => 'setter_system_prompt.md',
    ];

    /**
     * Descarga todos los archivos y actualiza la BD.
     * Devuelve array con resultado por archivo: ['file' => string, 'ok' => bool, 'error' => string|null]
     *
     * @return array<int, array{file: string, ok: bool, error: string|null}>
     */
    public function sync(): array
    {
        // Acumulador de resultados por archivo sincronizado.
        $results = [];

        foreach (self::FILES as $key => $filename) {
            try {
                // Descarga y persiste cada archivo de forma independiente.
                $content = $this->fetch_file($filename);
                $this->persist($key, $content);
                $results[] = ['file' => $filename, 'ok' => true, 'error' => null];
            } catch (\Throwable $e) {
                Log::error("AgentPromptSyncService: error al sincronizar {$filename}", ['error' => $e->getMessage()]);
                $results[] = ['file' => $filename, 'ok' => false, 'error' => $e->getMessage()];
            }
        }

        return $results;
    }

    /**
     * Descarga un archivo desde GitHub y devuelve su contenido decodificado.
     *
     * @param string $filename Nombre del archivo en prompts_agentes/
     * @return string Contenido del markdown
     */
    protected function fetch_file(string $filename): string
    {
        // Token con permiso de lectura al repositorio privado.
        $token = trim((string) env('GITHUB_PROTOCOL_TOKEN', ''));
        if ($token === '') {
            throw new \RuntimeException('GITHUB_PROTOCOL_TOKEN no está configurado.');
        }

        // Opciones SSL alineadas con el resto de integraciones HTTP del proyecto.
        $verify_ssl = (bool) config('services.anthropic.verify_ssl', true);
        $ca_bundle  = config('services.anthropic.ca_bundle');

        $http = Http::timeout(15)->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept'        => 'application/vnd.github+json',
            'User-Agent'    => 'ComercioCity-Admin-API',
        ]);

        if (! $verify_ssl) {
            $http = $http->withoutVerifying();
        } elseif (is_string($ca_bundle) && $ca_bundle !== '' && is_file($ca_bundle)) {
            $http = $http->withOptions(['verify' => $ca_bundle]);
        }

        $response = $http->get(self::GITHUB_BASE_URL.'/'.$filename);

        if (! $response->successful()) {
            throw new \RuntimeException("GitHub HTTP {$response->status()}: {$response->body()}");
        }

        $payload = $response->json();
        if (! is_array($payload) || empty($payload['content'])) {
            throw new \RuntimeException("Respuesta de GitHub sin campo content para {$filename}.");
        }

        // GitHub devuelve el contenido en base64, a veces con saltos de línea.
        $encoded = str_replace(["\n", "\r"], '', (string) $payload['content']);
        $decoded = base64_decode($encoded, true);
        if ($decoded === false) {
            throw new \RuntimeException("No se pudo decodificar base64 para {$filename}.");
        }

        return trim($decoded);
    }

    /**
     * Persiste el contenido descargado en el modelo correspondiente.
     *
     * @param string $key Clave interna del mapa FILES
     * @param string $content Texto del markdown descargado
     * @return void
     */
    protected function persist(string $key, string $content): void
    {
        if ($key === 'setter_identidad') {
            $identity = AgentIdentity::obtener_activo();
            if (! $identity) {
                $identity = new AgentIdentity();
                $identity->name   = 'Martín';
                $identity->activa = true;
            }
            $identity->description = $content;
            $identity->save();

            return;
        }

        if ($key === 'setter_system_prompt') {
            $prompt = AiSystemPrompt::obtener_activo();
            if (! $prompt) {
                $prompt = new AiSystemPrompt();
                $prompt->descripcion = 'System prompt principal';
                $prompt->activa      = true;
            }
            $prompt->contenido = $content;
            $prompt->save();

            return;
        }
    }
}

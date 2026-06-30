<?php

namespace App\Services;

use App\Models\AgentIdentity;
use App\Models\AiSystemPrompt;
use App\Models\SyncedGithubFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Descarga TODOS los archivos sincronizables del repositorio lucasgonzz/claude-comerciocity
 * desde la GitHub Contents API y los persiste en base de datos.
 *
 * Único punto de contacto con GitHub para contenido editable: tanto el botón
 * "Sincronizar desde GitHub" del admin como el scheduled job cada 10 minutos
 * ejecutan este mismo `sync()`. Ningún servicio de runtime debe volver a pegarle
 * a la GitHub API al generar una sugerencia de Claude — todos leen de BD.
 *
 * Para agregar un archivo nuevo, basta con sumar una entrada al mapa self::FILES
 * (cambio de una sola línea), indicando su ruta en el repo y a qué destino persiste.
 */
class AgentPromptSyncService
{
    /** URL base de la GitHub Contents API apuntando a la raíz del repositorio. */
    const GITHUB_CONTENTS_BASE_URL = 'https://api.github.com/repos/lucasgonzz/claude-comerciocity/contents/';

    /** Destino: descripción del agente activo (modelo AgentIdentity). */
    const TARGET_AGENT_IDENTITY = 'agent_identity';

    /** Destino: contenido del system prompt activo (modelo AiSystemPrompt). */
    const TARGET_AI_SYSTEM_PROMPT = 'ai_system_prompt';

    /** Destino: archivo genérico sin modelo de dominio propio (modelo SyncedGithubFile). */
    const TARGET_SYNCED_FILE = 'synced_github_file';

    /**
     * Mapa de todos los archivos sincronizables del repositorio.
     *
     * Cada entrada define:
     *   - key:       clave interna estable (para SyncedGithubFile y logs)
     *   - repo_path: ruta del archivo dentro del repositorio
     *   - target:    a qué modelo/clave persiste (ver constantes TARGET_*)
     *
     * Agregar un archivo nuevo = sumar una entrada acá, sin tocar el resto del flujo.
     *
     * @var array<int, array{key: string, repo_path: string, target: string}>
     */
    const FILES = [
        [
            'key'       => 'setter_identidad',
            'repo_path' => 'prompts_agentes/setter_identidad.md',
            'target'    => self::TARGET_AGENT_IDENTITY,
        ],
        [
            'key'       => 'setter_system_prompt',
            'repo_path' => 'prompts_agentes/setter_system_prompt.md',
            'target'    => self::TARGET_AI_SYSTEM_PROMPT,
        ],
        [
            'key'       => 'leads_protocolo_whatsapp',
            'repo_path' => 'comercial/leads_protocolo_whatsapp.md',
            'target'    => self::TARGET_SYNCED_FILE,
        ],
        // Archivos del protocolo modular (tool use)
        [
            'key'       => 'whatsapp_system_base',
            'repo_path' => 'agente/protocolo_whatsapp/system_base.md',
            'target'    => self::TARGET_SYNCED_FILE,
        ],
        [
            'key'       => 'whatsapp_recurso_calificacion',
            'repo_path' => 'agente/protocolo_whatsapp/recursos/calificacion.md',
            'target'    => self::TARGET_SYNCED_FILE,
        ],
        [
            'key'       => 'whatsapp_recurso_posicionamiento',
            'repo_path' => 'agente/protocolo_whatsapp/recursos/posicionamiento.md',
            'target'    => self::TARGET_SYNCED_FILE,
        ],
        [
            'key'       => 'whatsapp_recurso_precios',
            'repo_path' => 'agente/protocolo_whatsapp/recursos/precios.md',
            'target'    => self::TARGET_SYNCED_FILE,
        ],
        [
            'key'       => 'whatsapp_recurso_demo_agenda',
            'repo_path' => 'agente/protocolo_whatsapp/recursos/demo_agenda.md',
            'target'    => self::TARGET_SYNCED_FILE,
        ],
        [
            'key'       => 'whatsapp_recurso_demo_ciclo',
            'repo_path' => 'agente/protocolo_whatsapp/recursos/demo_ciclo.md',
            'target'    => self::TARGET_SYNCED_FILE,
        ],
        [
            'key'       => 'whatsapp_recurso_post_demo',
            'repo_path' => 'agente/protocolo_whatsapp/recursos/post_demo.md',
            'target'    => self::TARGET_SYNCED_FILE,
        ],
        [
            'key'       => 'whatsapp_recurso_reglas',
            'repo_path' => 'agente/protocolo_whatsapp/recursos/reglas.md',
            'target'    => self::TARGET_SYNCED_FILE,
        ],
        [
            'key'       => 'whatsapp_recurso_referidos',
            'repo_path' => 'agente/protocolo_whatsapp/recursos/referidos.md',
            'target'    => self::TARGET_SYNCED_FILE,
        ],
    ];

    /**
     * Descarga todos los archivos y actualiza la BD.
     * Devuelve array con resultado por archivo: ['file' => string, 'ok' => bool, 'error' => string|null]
     *
     * Cada archivo se sincroniza de forma independiente: el fallo de uno no
     * interrumpe la sincronización de los demás.
     *
     * @return array<int, array{file: string, ok: bool, error: string|null}>
     */
    public function sync(): array
    {
        // Acumulador de resultados por archivo sincronizado.
        $results = [];

        foreach (self::FILES as $file) {
            try {
                // Descarga y persiste cada archivo de forma independiente.
                $content = $this->fetch_file($file['repo_path']);
                $this->persist($file, $content);
                $results[] = ['file' => $file['repo_path'], 'ok' => true, 'error' => null];
            } catch (\Throwable $e) {
                Log::error("AgentPromptSyncService: error al sincronizar {$file['repo_path']}", ['error' => $e->getMessage()]);
                $results[] = ['file' => $file['repo_path'], 'ok' => false, 'error' => $e->getMessage()];
            }
        }

        return $results;
    }

    /**
     * Descarga un archivo desde GitHub y devuelve su contenido decodificado.
     *
     * @param string $repo_path Ruta del archivo dentro del repositorio (ej: 'comercial/leads_protocolo_whatsapp.md')
     * @return string Contenido del markdown
     */
    protected function fetch_file(string $repo_path): string
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

        $response = $http->get(self::GITHUB_CONTENTS_BASE_URL.$repo_path);

        if (! $response->successful()) {
            throw new \RuntimeException("GitHub HTTP {$response->status()}: {$response->body()}");
        }

        $payload = $response->json();
        if (! is_array($payload) || empty($payload['content'])) {
            throw new \RuntimeException("Respuesta de GitHub sin campo content para {$repo_path}.");
        }

        // GitHub devuelve el contenido en base64, a veces con saltos de línea.
        $encoded = str_replace(["\n", "\r"], '', (string) $payload['content']);
        $decoded = base64_decode($encoded, true);
        if ($decoded === false) {
            throw new \RuntimeException("No se pudo decodificar base64 para {$repo_path}.");
        }

        return trim($decoded);
    }

    /**
     * Persiste el contenido descargado en el destino correspondiente.
     *
     * @param array{key: string, repo_path: string, target: string} $file Entrada del mapa FILES
     * @param string $content Texto del markdown descargado
     * @return void
     */
    protected function persist(array $file, string $content): void
    {
        if ($file['target'] === self::TARGET_AGENT_IDENTITY) {
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

        if ($file['target'] === self::TARGET_AI_SYSTEM_PROMPT) {
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

        if ($file['target'] === self::TARGET_SYNCED_FILE) {
            // Archivo genérico sin modelo de dominio propio: upsert por clave interna.
            $synced = SyncedGithubFile::obtener_por_key($file['key']);
            if (! $synced) {
                $synced = new SyncedGithubFile();
                $synced->key = $file['key'];
            }
            $synced->repo_path = $file['repo_path'];
            $synced->content   = $content;
            $synced->synced_at = now();
            $synced->save();

            return;
        }
    }
}

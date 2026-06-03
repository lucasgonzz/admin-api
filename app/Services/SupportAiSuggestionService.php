<?php

namespace App\Services;

use App\Models\SupportMessage;
use App\Models\SupportTicket;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Genera sugerencias de respuesta de soporte vía Anthropic (Claude) con tool use.
 *
 * Claude puede consultar el repositorio de documentación de ComercioCity
 * (lucasgonzz/comerciocity-manual-sistema) de forma autónoma usando las tools
 * `list_manual_files` y `get_manual_file` antes de formular su respuesta.
 */
class SupportAiSuggestionService
{
    /**
     * URL base de la GitHub API (REST v3).
     */
    private const GITHUB_API_BASE = 'https://api.github.com';

    /**
     * Repositorio de documentación de ComercioCity.
     */
    private const GITHUB_REPO = 'lucasgonzz/comerciocity-manual-sistema';

    /**
     * Rama del repositorio a consultar.
     */
    private const GITHUB_BRANCH = 'main';

    /**
     * Máximo de iteraciones del agentic loop para evitar bucles infinitos.
     */
    private const MAX_TOOL_ITERATIONS = 5;

    /**
     * Solicita a Claude una respuesta sugerida para el operador usando tool use para
     * consultar el manual de ComercioCity. Si el ticket no tiene nombre, puede incluir
     * suggested_title en la respuesta.
     *
     * @param SupportTicket $ticket Ticket abierto con relación client cargada si es posible.
     *
     * @return array{suggested_message: string, reasoning: string, suggested_title?: string}
     */
    public function generate(SupportTicket $ticket): array
    {
        // Ticket sin título: Claude debe proponer un nombre corto además del mensaje.
        $ticket_needs_title = trim((string) ($ticket->name ?? '')) === '';

        try {
            $api_key = (string) config('services.anthropic.api_key');
            if ($api_key === '') {
                return [
                    'suggested_message' => '',
                    'reasoning'         => 'ANTHROPIC_API_KEY no está configurada.',
                ];
            }

            $system_prompt = $this->build_system_prompt();
            $user_content  = $this->build_user_content($ticket);

            if ($ticket_needs_title) {
                $user_content = $this->append_title_suggestion_to_user_content($user_content);
            }

            $model   = (string) config('services.anthropic.model', 'claude-sonnet-4-20250514');
            $http    = $this->build_http_client();
            $tools   = $this->build_github_tools();
            $messages = [
                ['role' => 'user', 'content' => $user_content],
            ];

            // Agentic loop: repite hasta end_turn o hasta el límite de iteraciones.
            $iterations = 0;
            $final_text = '';

            while ($iterations < self::MAX_TOOL_ITERATIONS) {
                $iterations++;

                $response = $http->post('https://api.anthropic.com/v1/messages', [
                    'model'      => $model,
                    'max_tokens' => 2000,
                    'system'     => $system_prompt,
                    'tools'      => $tools,
                    'messages'   => $messages,
                ]);

                if ($response->failed()) {
                    $error_message = $this->extract_anthropic_error_message($response->json(), $response->status());

                    Log::error('SupportAiSuggestionService Anthropic error', [
                        'ticket_id'  => $ticket->id,
                        'iteration'  => $iterations,
                        'status'     => $response->status(),
                        'body'       => substr($response->body(), 0, 500),
                    ]);

                    return [
                        'suggested_message' => '',
                        'reasoning'         => $error_message,
                    ];
                }

                $response_body = $response->json();
                $stop_reason   = (string) ($response_body['stop_reason'] ?? '');
                $content_blocks = $response_body['content'] ?? [];

                if ($stop_reason === 'end_turn') {
                    $final_text = $this->extract_response_text($response_body);
                    break;
                }

                if ($stop_reason === 'tool_use') {
                    // Normalizar bloques antes de reenviarlos: PHP decodifica input:{} como [] y Anthropic exige object.
                    $normalized_content = $this->normalize_assistant_content_for_api($content_blocks);

                    // Agregar el mensaje del asistente (con los bloques tool_use) al historial.
                    $messages[] = [
                        'role'    => 'assistant',
                        'content' => $normalized_content,
                    ];

                    // Procesar cada tool_use y construir los tool_result.
                    $tool_results = $this->execute_tool_calls($content_blocks, $ticket->id);

                    // Agregar los resultados como mensaje de user.
                    $messages[] = [
                        'role'    => 'user',
                        'content' => $tool_results,
                    ];

                    continue;
                }

                // stop_reason desconocido: extraer texto si existe y salir.
                $final_text = $this->extract_response_text($response_body);
                break;
            }

            if ($final_text === '') {
                Log::warning('SupportAiSuggestionService: loop terminó sin texto final.', [
                    'ticket_id'  => $ticket->id,
                    'iterations' => $iterations,
                ]);

                return [
                    'suggested_message' => '',
                    'reasoning'         => 'Claude no generó respuesta después de '.$iterations.' iteraciones.',
                ];
            }

            $parsed = $this->parse_json_response($final_text);

            $result = [
                'suggested_message' => trim((string) ($parsed['suggested_message'] ?? '')),
                'reasoning'         => trim((string) ($parsed['reasoning'] ?? '')),
            ];

            if ($ticket_needs_title) {
                $suggested_title = trim((string) ($parsed['suggested_title'] ?? ''));
                if ($suggested_title !== '') {
                    $result['suggested_title'] = $suggested_title;
                }
            }

            return $result;
        } catch (\Throwable $exception) {
            Log::error('SupportAiSuggestionService error', [
                'ticket_id' => $ticket->id,
                'error'     => $exception->getMessage(),
            ]);

            return [
                'suggested_message' => '',
                'reasoning'         => $exception->getMessage(),
            ];
        }
    }

    /**
     * Ajusta el prompt user para pedir suggested_title cuando el ticket aún no tiene nombre.
     * Solo se invoca desde generate(); no altera build_user_content ni otros flujos.
     *
     * @param string $user_content Texto base de build_user_content.
     *
     * @return string
     */
    protected function append_title_suggestion_to_user_content(string $user_content): string
    {
        $standard_json_block = 'Generá una respuesta sugerida para el operador y explicá brevemente tu razonamiento. Respondé SOLO en JSON con este formato exacto:
{
  "suggested_message": "...",
  "reasoning": "..."
}';

        $title_json_block = 'Generá una respuesta sugerida para el operador y explicá brevemente tu razonamiento.

Este es el primer mensaje del ticket y aún no tiene título. Además de la respuesta sugerida, generá un título corto y descriptivo (máximo 6 palabras) que resuma el problema o consulta del cliente.

Respondé SOLO en JSON con este formato exacto:
{
  "suggested_message": "...",
  "suggested_title": "...",
  "reasoning": "..."
}';

        return str_replace($standard_json_block, $title_json_block, $user_content);
    }

    /**
     * Arma el system prompt indicando a Claude cómo usar las tools del repositorio.
     *
     * @return string
     */
    protected function build_system_prompt(): string
    {
        return <<<SYSTEM
Sos un asistente de soporte técnico de ComercioCity, una plataforma de operación comercial para distribuidoras y comercios argentinos.
Tu tarea es sugerir respuestas para que el operador las revise y apruebe antes de enviar. Respondé siempre en español rioplatense, de forma clara, cordial y concisa.

Tenés acceso a herramientas para consultar el repositorio de documentación de ComercioCity. Antes de responder cualquier duda técnica o funcional del cliente, usá list_manual_files para ver qué archivos están disponibles y luego get_manual_file para leer el contenido relevante. El repositorio está organizado por módulo y funcionalidad. Cada archivo tiene un frontmatter con modulo, tema y keywords que te ayudan a inferir cuál leer.
SYSTEM;
    }

    /**
     * Construye el mensaje user con datos del cliente e historial completo del ticket.
     *
     * @param SupportTicket $ticket
     *
     * @return string
     */
    protected function build_user_content(SupportTicket $ticket): string
    {
        $client_name = $ticket->resolve_contact_display_name();

        $client_email = trim((string) ($ticket->client_user_email ?? ''));
        if ($client_email === '') {
            $client_email = 'sin email';
        }

        $historial = $this->format_conversation_history($ticket->id);

        return <<<USER
Cliente: {$client_name} ({$client_email})

Historial de la conversación:
{$historial}

Generá una respuesta sugerida para el operador y explicá brevemente tu razonamiento. Respondé SOLO en JSON con este formato exacto:
{
  "suggested_message": "...",
  "reasoning": "..."
}
USER;
    }

    /**
     * Formatea todos los mensajes del ticket para el prompt en orden cronológico.
     *
     * @param int $ticket_id
     *
     * @return string
     */
    protected function format_conversation_history(int $ticket_id): string
    {
        $messages = SupportMessage::where('support_ticket_id', $ticket_id)
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        if ($messages->isEmpty()) {
            return '(Sin mensajes todavía.)';
        }

        $lines = [];
        foreach ($messages as $message) {
            // Mensajes de voz: el body ya es la transcripción; el prefijo aclara el contexto a Claude.
            if ($message->sender_type === 'admin') {
                $label = 'Operador';
            } elseif ($message->kind === 'audio') {
                $label = 'Cliente (audio transcripto)';
            } else {
                $label = 'Cliente';
            }

            $body = trim((string) ($message->body ?? ''));
            if ($body === '') {
                $body = '['.strtoupper((string) $message->kind).']';
            }
            $lines[] = $label.': '.$body;
        }

        return implode("\n", $lines);
    }

    /**
     * Devuelve el array de tools para la API de Anthropic (tool use / function calling).
     * Las dos tools permiten a Claude explorar el repositorio del manual antes de responder.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function build_github_tools(): array
    {
        return [
            [
                'name'        => 'get_manual_file',
                'description' => 'Lee el contenido de un archivo del repositorio de documentación de ComercioCity. Usá esta herramienta para consultar el manual antes de responder dudas del cliente. El repositorio está organizado en carpetas por módulo y cada archivo tiene un frontmatter con modulo, tema y keywords para que puedas inferir cuál leer.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'path' => [
                            'type'        => 'string',
                            'description' => 'Ruta del archivo dentro del repo. Ejemplo: "listado/precios.md" o "general/interfaz-tablas-y-formularios.md".',
                        ],
                    ],
                    'required' => ['path'],
                ],
            ],
            [
                'name'        => 'list_manual_files',
                'description' => 'Lista todos los archivos disponibles en el repositorio de documentación con sus rutas. Usá esta herramienta primero si no sabés en qué archivo buscar la respuesta.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => new \stdClass(),
                    'required'   => [],
                ],
            ],
        ];
    }

    /**
     * Ejecuta las tool calls de un bloque de contenido del asistente y retorna los tool_result.
     *
     * @param array<int, mixed> $content_blocks Bloques content devueltos por Claude.
     * @param int|string        $ticket_id      Para logging.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function execute_tool_calls(array $content_blocks, $ticket_id): array
    {
        $tool_results = [];

        foreach ($content_blocks as $block) {
            if (! is_array($block) || ($block['type'] ?? '') !== 'tool_use') {
                continue;
            }

            $tool_id   = (string) ($block['id'] ?? '');
            $tool_name = (string) ($block['name'] ?? '');
            $tool_input = $block['input'] ?? [];

            try {
                if ($tool_name === 'list_manual_files') {
                    $content = $this->github_list_files();
                } elseif ($tool_name === 'get_manual_file') {
                    $path = (string) ($tool_input['path'] ?? '');
                    $content = $this->github_get_file($path);
                } else {
                    $content = 'Tool desconocida: '.$tool_name;
                }

                $tool_results[] = [
                    'type'       => 'tool_result',
                    'tool_use_id' => $tool_id,
                    'content'    => $content,
                ];
            } catch (\Throwable $exception) {
                Log::warning('SupportAiSuggestionService: error en tool call.', [
                    'ticket_id' => $ticket_id,
                    'tool'      => $tool_name,
                    'error'     => $exception->getMessage(),
                ]);

                // Devuelve error a Claude para que pueda continuar sin romper el flujo.
                $tool_results[] = [
                    'type'        => 'tool_result',
                    'tool_use_id' => $tool_id,
                    'is_error'    => true,
                    'content'     => 'Error al ejecutar '.$tool_name.': '.$exception->getMessage(),
                ];
            }
        }

        return $tool_results;
    }

    /**
     * Normaliza bloques content del asistente para reenviarlos a Anthropic en el agentic loop.
     * json_decode convierte input:{} en array vacío []; la API exige object en tool_use.input.
     * También elimina campos de respuesta (p. ej. caller) que no acepta el request.
     *
     * @param array<int, mixed> $content_blocks Bloques devueltos por Claude.
     *
     * @return array<int, mixed>
     */
    protected function normalize_assistant_content_for_api(array $content_blocks): array
    {
        $normalized = [];

        foreach ($content_blocks as $block) {
            if (! is_array($block)) {
                $normalized[] = $block;
                continue;
            }

            if (($block['type'] ?? '') === 'tool_use') {
                $input = $block['input'] ?? [];
                // json_decode convierte {} en array vacío; Anthropic exige object en tool_use.input.
                if (! is_array($input) || $input === []) {
                    $block['input'] = new \stdClass();
                } else {
                    $block['input'] = (object) $input;
                }
                unset($block['caller']);
            }

            $normalized[] = $block;
        }

        return $normalized;
    }

    /**
     * Extrae mensaje legible del cuerpo de error de Anthropic.
     *
     * @param array<string, mixed>|null $body   JSON de error.
     * @param int                       $status Código HTTP.
     *
     * @return string
     */
    protected function extract_anthropic_error_message($body, int $status): string
    {
        if (is_array($body) && isset($body['error']) && is_array($body['error'])) {
            $message = trim((string) ($body['error']['message'] ?? ''));
            if ($message !== '') {
                return 'Error Anthropic HTTP '.$status.': '.$message;
            }
        }

        return 'Error Anthropic HTTP '.$status.'.';
    }

    /**
     * Llama a la GitHub API y devuelve la lista de rutas .md del repositorio.
     *
     * @return string Lista de rutas separadas por salto de línea.
     *
     * @throws \RuntimeException Si la API responde con error.
     */
    protected function github_list_files(): string
    {
        $url      = self::GITHUB_API_BASE.'/repos/'.self::GITHUB_REPO.'/git/trees/'.self::GITHUB_BRANCH.'?recursive=1';
        $response = $this->build_github_http_client()->get($url);

        if ($response->failed()) {
            throw new \RuntimeException('GitHub API error '.$response->status().' al listar archivos.');
        }

        $tree = $response->json('tree') ?? [];
        $paths = [];

        foreach ($tree as $node) {
            if (! is_array($node)) {
                continue;
            }
            // Solo blobs (archivos) con extensión .md.
            if (($node['type'] ?? '') === 'blob' && str_ends_with((string) ($node['path'] ?? ''), '.md')) {
                $paths[] = (string) $node['path'];
            }
        }

        if (empty($paths)) {
            return '(No se encontraron archivos .md en el repositorio.)';
        }

        return implode("\n", $paths);
    }

    /**
     * Descarga y decodifica el contenido de un archivo del repositorio.
     *
     * @param string $path Ruta relativa dentro del repo.
     *
     * @return string Contenido del archivo en texto plano.
     *
     * @throws \RuntimeException Si la ruta está vacía o la API responde con error.
     */
    protected function github_get_file(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            throw new \RuntimeException('La ruta del archivo no puede estar vacía.');
        }

        $encoded_path = implode('/', array_map('rawurlencode', explode('/', $path)));
        $url          = self::GITHUB_API_BASE.'/repos/'.self::GITHUB_REPO.'/contents/'.$encoded_path.'?ref='.self::GITHUB_BRANCH;

        $response = $this->build_github_http_client()->get($url);

        if ($response->failed()) {
            throw new \RuntimeException('GitHub API error '.$response->status().' al leer '.$path.'.');
        }

        $encoding = (string) ($response->json('encoding') ?? '');
        $content  = (string) ($response->json('content') ?? '');

        if ($encoding === 'base64') {
            return base64_decode(str_replace("\n", '', $content));
        }

        // Fallback: la API puede devolver el texto directo en repos pequeños.
        return $content;
    }

    /**
     * Cliente HTTP para GitHub API con token de autenticación si está configurado.
     *
     * @return PendingRequest
     */
    protected function build_github_http_client(): PendingRequest
    {
        $token = (string) config('services.github.token', '');

        $headers = [
            'Accept'     => 'application/vnd.github+json',
            'User-Agent' => 'ComercioCity-Admin/1.0',
        ];

        if ($token !== '') {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        $http = Http::withHeaders($headers)->timeout(15);

        // Misma configuración TLS que Anthropic (WAMP/Windows suele requerir ca_bundle o verify_ssl=false).
        $verify_ssl = (bool) config('services.anthropic.verify_ssl', true);
        $ca_bundle  = config('services.anthropic.ca_bundle');

        if (! $verify_ssl) {
            $http = $http->withoutVerifying();
        } elseif (is_string($ca_bundle) && $ca_bundle !== '' && is_file($ca_bundle)) {
            $http = $http->withOptions(['verify' => $ca_bundle]);
        }

        return $http;
    }

    /**
     * Cliente HTTP hacia Anthropic con la misma configuración TLS que leads.
     *
     * @return PendingRequest
     */
    protected function build_http_client(): PendingRequest
    {
        $api_key = (string) config('services.anthropic.api_key');

        $http = Http::withHeaders([
            'x-api-key'         => $api_key,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ])->timeout(120);

        $verify_ssl = (bool) config('services.anthropic.verify_ssl', true);
        $ca_bundle  = config('services.anthropic.ca_bundle');

        if (! $verify_ssl) {
            $http = $http->withoutVerifying();
        } elseif (is_string($ca_bundle) && $ca_bundle !== '' && is_file($ca_bundle)) {
            $http = $http->withOptions(['verify' => $ca_bundle]);
        }

        return $http;
    }

    /**
     * Extrae el texto de los bloques text del último mensaje del asistente.
     *
     * @param array<string, mixed> $body Respuesta JSON de Anthropic.
     *
     * @return string
     */
    protected function extract_response_text(array $body): string
    {
        $text = '';

        if (isset($body['content']) && is_array($body['content'])) {
            foreach ($body['content'] as $block) {
                if (is_array($block) && isset($block['text']) && ($block['type'] ?? '') === 'text') {
                    $text .= (string) $block['text'];
                }
            }
        }

        return $text;
    }

    /**
     * Decodifica el JSON embebido en la respuesta de Claude.
     *
     * @param string $raw
     *
     * @return array<string, mixed>
     */
    protected function parse_json_response(string $raw): array
    {
        $start = strpos($raw, '{');
        $end   = strrpos($raw, '}');

        if ($start === false || $end === false || $end <= $start) {
            throw new \RuntimeException('Claude no devolvió JSON válido.');
        }

        $json = substr($raw, $start, $end - $start + 1);
        $data = json_decode($json, true);

        if (! is_array($data)) {
            throw new \RuntimeException('JSON inválido: '.json_last_error_msg());
        }

        return $data;
    }
}

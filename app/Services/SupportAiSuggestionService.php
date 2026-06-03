<?php

namespace App\Services;

use App\Models\SupportKnowledgeBase;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Genera sugerencias de respuesta de soporte vía Anthropic (Claude).
 */
class SupportAiSuggestionService
{
    /**
     * Solicita a Claude una respuesta sugerida para el operador según historial y KB.
     * Si el ticket no tiene nombre, puede incluir suggested_title en la respuesta.
     *
     * @param SupportTicket $ticket Ticket abierto con relación client cargada si es posible.
     *
     * @return array{suggested_message: string, reasoning: string, suggested_title?: string}
     */
    public function generate(SupportTicket $ticket): array
    {
        // Ticket sin título: Claude debe proponer un nombre corto además del mensaje.
        $ticket_needs_title = trim((string) ($ticket->name ?? '')) === '';

        $empty_result = [
            'suggested_message' => '',
            'reasoning'         => '',
        ];

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

            $model         = (string) config('services.anthropic.model', 'claude-sonnet-4-20250514');
            $http          = $this->build_http_client();

            $response = $http->post('https://api.anthropic.com/v1/messages', [
                'model'      => $model,
                'max_tokens' => 1000,
                'system'     => $system_prompt,
                'messages'   => [
                    ['role' => 'user', 'content' => $user_content],
                ],
            ]);

            if ($response->failed()) {
                Log::error('SupportAiSuggestionService Anthropic error', [
                    'ticket_id' => $ticket->id,
                    'status'    => $response->status(),
                    'body'      => substr($response->body(), 0, 500),
                ]);

                return [
                    'suggested_message' => '',
                    'reasoning'         => 'Error Anthropic HTTP '.$response->status().'.',
                ];
            }

            $text   = $this->extract_response_text($response->json());
            $parsed = $this->parse_json_response($text);

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
     * @param string $user_content Texto base de build_user_content
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
     * Arma el system prompt con la base de conocimiento activa.
     *
     * @return string
     */
    protected function build_system_prompt(): string
    {
        $entries = SupportKnowledgeBase::where('is_active', true)
            ->orderBy('id')
            ->get();

        $knowledge_blocks = [];
        foreach ($entries as $entry) {
            $knowledge_blocks[] = trim((string) $entry->title)."\n".trim((string) $entry->content);
        }

        $knowledge_text = implode("\n---\n", $knowledge_blocks);
        if ($knowledge_text === '') {
            $knowledge_text = '(Sin entradas activas en la base de conocimiento.)';
        }

        return <<<SYSTEM
Sos un asistente de soporte técnico de ComercioCity, una plataforma de operación comercial para distribuidoras y comercios argentinos.
Tu tarea es sugerir respuestas para que el operador las revise y apruebe antes de enviar. Respondé siempre en español rioplatense, de forma clara, cordial y concisa.

BASE DE CONOCIMIENTO:
{$knowledge_text}
SYSTEM;
    }

    /**
     * Construye el mensaje user con datos del cliente e historial reciente.
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
     * Formatea los últimos 20 mensajes del ticket para el prompt.
     *
     * @param int $ticket_id
     *
     * @return string
     */
    protected function format_conversation_history(int $ticket_id): string
    {
        $recent_ids = SupportMessage::where('support_ticket_id', $ticket_id)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->limit(20)
            ->pluck('id');

        if ($recent_ids->isEmpty()) {
            return '(Sin mensajes todavía.)';
        }

        $messages = SupportMessage::whereIn('id', $recent_ids)
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc')
            ->get();

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
        ])->timeout(90);

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
     * Extrae texto plano de la respuesta JSON de Claude.
     *
     * @param array<string, mixed> $body
     *
     * @return string
     */
    protected function extract_response_text(array $body): string
    {
        $text = '';

        if (isset($body['content']) && is_array($body['content'])) {
            foreach ($body['content'] as $block) {
                if (is_array($block) && isset($block['text'])) {
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

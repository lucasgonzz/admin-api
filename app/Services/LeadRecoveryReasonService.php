<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadMessage;
use Carbon\Carbon;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Redacta el motivo de la demora ({{2}} de la plantilla Meta `cc_recuperacion_motivo`)
 * leyendo la conversación real del lead, usando Anthropic Claude Haiku.
 *
 * Mismo patrón de llamada que SubdomainSuggestionService: mismo armado de headers,
 * mismo POST a /v1/messages, misma extracción de bloques `content` de tipo `text`,
 * mismo try/catch con Log y fallback. Nunca lanza excepción hacia afuera: si algo
 * falla (API key ausente, HTTP failed, respuesta vacía, excepción), devuelve un
 * motivo genérico y honesto.
 */
class LeadRecoveryReasonService
{
    /**
     * Modelo Claude Haiku a usar (tarea corta y barata).
     */
    private const MODEL = 'claude-haiku-4-5-20251001';

    /**
     * Motivo genérico devuelto cuando la IA no está disponible o falla.
     */
    private const FALLBACK_REASON = 'estos días tuvimos la agenda llena de demos';

    /**
     * System prompt literal (prompt 390): le pide a Claude redactar solo el
     * fragmento {MOTIVO} que encaja en la plantilla aprobada por Meta, sin
     * inventar hechos que no estén en la conversación real del lead.
     */
    private const SYSTEM_PROMPT = <<<PROMPT
Sos Martín, el setter de ComercioCity. Le vamos a escribir a un lead al que dejamos sin respuesta y se nos cerró la ventana de 24hs de WhatsApp. La plantilla dice: "Hola {nombre}! Perdoná la demora en responderte, {MOTIVO}. ¿Retomamos por donde habíamos quedado?"

Tu única tarea es escribir el {MOTIVO}: un fragmento corto que encaje en esa oración, en minúscula, sin punto final, sin comillas, máximo 12 palabras.

Reglas:
- Tiene que sonar humano, honesto y sin dramatizar. Nada de excusas rebuscadas ni tecnicismos.
- Nunca inventes hechos que no estén en la conversación (no digas que hablamos por teléfono, que mandamos un mail o que pasó algo que no pasó).
- Si la conversación no da ninguna pista, usá algo genérico y honesto.
- Escribí en español rioplatense, tuteo con "vos".

Ejemplos de buenas respuestas:
"estos días tuvimos la agenda llena de demos"
"se nos traspapeló tu mensaje entre las consultas de la semana"
"venimos con varias implementaciones encima y se nos pasó"

Respondé SOLO con el fragmento. Nada más.
PROMPT;

    /**
     * Sugiere el motivo de la demora para el lead dado, leyendo su conversación real.
     *
     * Toma los últimos 10 LeadMessage del lead (por id ascendente), excluyendo los
     * eventos de sistema (is_status_event) y los registros de error (is_error), y
     * arma con ellos un historial "Lead: ..." / "Nosotros: ..." según quién habló
     * (campo `sender` de LeadMessage). Suma cuántos días pasaron desde el último
     * mensaje del lead para darle contexto temporal a Claude.
     *
     * @param  Lead   $lead Lead cuya conversación se va a leer.
     * @return string       Motivo redactado, listo para {{2}} de cc_recuperacion_motivo.
     */
    public function suggest(Lead $lead): string
    {
        try {
            /* API key de Anthropic: si no está configurada, usar fallback directo. */
            $api_key = (string) config('services.anthropic.api_key');
            if ($api_key === '') {
                Log::debug('LeadRecoveryReasonService: ANTHROPIC_API_KEY no configurada, usando fallback.', [
                    'lead_id' => $lead->id,
                ]);
                return self::FALLBACK_REASON;
            }

            /* Últimos 10 mensajes reales de la conversación (sin eventos de sistema ni errores). */
            $messages = LeadMessage::query()
                ->where('lead_id', $lead->id)
                ->where('is_status_event', false)
                ->where('is_error', false)
                ->orderByDesc('id')
                ->limit(10)
                ->get()
                ->sortBy('id')
                ->values();

            /* User prompt: historial formateado + días desde el último mensaje del lead. */
            $user_prompt = $this->build_user_prompt($messages);

            /* Llamada a Claude Haiku: simple single-turn, sin tools. */
            $response = $this->build_http_client()->post('https://api.anthropic.com/v1/messages', [
                'model'      => self::MODEL,
                'max_tokens' => 60,
                'system'     => self::SYSTEM_PROMPT,
                'messages'   => [
                    ['role' => 'user', 'content' => $user_prompt],
                ],
            ]);

            if ($response->failed()) {
                Log::warning('LeadRecoveryReasonService: error Anthropic HTTP.', [
                    'lead_id' => $lead->id,
                    'status'  => $response->status(),
                    'body'    => substr($response->body(), 0, 300),
                ]);
                return self::FALLBACK_REASON;
            }

            /* Extraer texto del/los bloque(s) content de tipo text. */
            $content_blocks = $response->json('content') ?? [];
            $raw_text       = '';
            foreach ($content_blocks as $block) {
                if (is_array($block) && ($block['type'] ?? '') === 'text') {
                    $raw_text .= (string) $block['text'];
                }
            }

            $reason = $this->clean_reason($raw_text);

            if ($reason === '') {
                return self::FALLBACK_REASON;
            }

            return $reason;

        } catch (\Throwable $exception) {
            Log::error('LeadRecoveryReasonService: excepción inesperada.', [
                'lead_id' => $lead->id,
                'error'   => $exception->getMessage(),
            ]);
            return self::FALLBACK_REASON;
        }
    }

    /**
     * Arma el texto de usuario que se le manda a Claude: historial de la conversación
     * formateado como "Lead: ..." / "Nosotros: ..." según el sender de cada mensaje,
     * más la cantidad de días desde el último mensaje del lead.
     *
     * @param  \Illuminate\Support\Collection $messages Últimos mensajes reales, ya ordenados por id ascendente.
     * @return string
     */
    private function build_user_prompt($messages): string
    {
        /* Líneas del historial: "Lead: ..." si sender=lead, "Nosotros: ..." en cualquier otro caso. */
        $lines = [];
        /* Timestamp del último mensaje enviado por el lead (para calcular los días de demora). */
        $last_lead_message_at = null;

        foreach ($messages as $msg) {
            $content = trim((string) ($msg->content ?? ''));
            if ($content === '') {
                continue;
            }

            $is_lead = $msg->sender === 'lead';
            $label   = $is_lead ? 'Lead' : 'Nosotros';
            $lines[] = "{$label}: {$content}";

            if ($is_lead) {
                $last_lead_message_at = $msg->sent_at ?? $msg->created_at;
            }
        }

        $history_text = implode("\n", $lines);
        if ($history_text === '') {
            $history_text = '(sin mensajes previos disponibles)';
        }

        /* Días transcurridos desde el último mensaje del lead, si se pudo determinar. */
        $days_text = '';
        if ($last_lead_message_at !== null) {
            try {
                $days = Carbon::parse($last_lead_message_at)->diffInDays(now());
                $days_text = "Pasaron {$days} día(s) desde el último mensaje del lead.\n\n";
            } catch (\Throwable $e) {
                $days_text = '';
            }
        }

        return $days_text . "Conversación:\n{$history_text}";
    }

    /**
     * Limpia la respuesta cruda de Claude: recorta espacios, saca comillas de
     * apertura/cierre y el punto final si viene, y trunca a 120 caracteres.
     *
     * @param  string $raw_text Texto crudo devuelto por la API.
     * @return string           Motivo limpio, listo para insertar en la plantilla.
     */
    private function clean_reason(string $raw_text): string
    {
        $reason = trim($raw_text);

        /* Sacar comillas simples/dobles ASCII de apertura y cierre si vienen. */
        $reason = trim($reason, "\"'");
        $reason = trim($reason);

        /* Sacar el punto final si viene. */
        if (substr($reason, -1) === '.') {
            $reason = substr($reason, 0, -1);
        }

        return substr($reason, 0, 120);
    }

    /**
     * Construye el cliente HTTP hacia Anthropic respetando la configuración
     * de SSL del proyecto (igual que SubdomainSuggestionService).
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

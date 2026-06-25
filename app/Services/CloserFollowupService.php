<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Genera un mensaje de seguimiento sugerido para el closer basado en el call_summary del lead.
 *
 * El mensaje resultante se guarda como LeadMessage (sender=sistema, status=sugerido, is_followup=true)
 * y aparece en la conversación del lead con el estilo wa-bubble--followup (amarillo).
 * El closer puede editarlo y enviarlo desde el panel de conversación.
 */
class CloserFollowupService
{
    /**
     * Modelo Claude usado para la generación del mensaje de seguimiento.
     * Haiku es suficiente para este tipo de texto corto y es el más económico.
     */
    private const MODEL = 'claude-haiku-4-5-20251001';

    /** Endpoint de la API de Anthropic para mensajes. */
    private const ANTHROPIC_API_URL = 'https://api.anthropic.com/v1/messages';

    /**
     * Genera un mensaje de seguimiento sugerido para el closer basado en call_summary.
     *
     * Crea un LeadMessage con sender=sistema, status=sugerido, is_followup=true y
     * system_message_kind=closer_followup_suggestion. Marca el lead con
     * tiene_sugerencia_pendiente=true y emite un evento de conversación actualizada
     * para refrescar el panel en tiempo real.
     *
     * @param Lead $lead Lead para el cual generar el seguimiento.
     *
     * @return void
     */
    public function generate_followup_from_summary(Lead $lead): void
    {
        /* Necesitamos call_summary para poder generar el mensaje. */
        $summary = $lead->call_summary;
        if (empty($summary)) {
            Log::channel('daily')->info('[CLOSER_FOLLOWUP] Sin call_summary, no se genera seguimiento.', [
                'lead_id' => $lead->id,
            ]);
            return;
        }

        /* Datos del resumen usados para construir el prompt. */
        $escenario    = $summary['escenario_cierre'] ?? null;
        $proximo_paso = (string) ($summary['proximo_paso'] ?? '');
        $resumen      = (string) ($summary['resumen_general'] ?? '');
        $nombre       = (string) ($lead->contact_name ?? '');

        /* Escenario D: lead descartado, no tiene sentido generar seguimiento. */
        if ($escenario === 'D') {
            Log::channel('daily')->info('[CLOSER_FOLLOWUP] Escenario D: no se genera seguimiento.', [
                'lead_id' => $lead->id,
            ]);
            return;
        }

        /* Construir el prompt y llamar a Claude. */
        $prompt  = $this->build_prompt($nombre, $escenario, $proximo_paso, $resumen);
        $mensaje = $this->call_claude($prompt);

        if (!$mensaje) {
            Log::channel('daily')->warning('[CLOSER_FOLLOWUP] Claude no devolvió mensaje de seguimiento.', [
                'lead_id' => $lead->id,
            ]);
            return;
        }

        /* Persistir el mensaje sugerido en la conversación del lead. */
        LeadMessage::create([
            'lead_id'             => $lead->id,
            'sender'              => 'sistema',
            'content'             => $mensaje,
            'status'              => 'sugerido',
            'is_followup'         => true,
            'system_message_kind' => 'closer_followup_suggestion',
            'sent_at'             => null,
        ]);

        /* Marcar el lead para que el badge de sugerencia pendiente aparezca en el panel. */
        $lead->tiene_sugerencia_pendiente = true;
        $lead->save();

        /* Emitir evento para refrescar la conversación en tiempo real. */
        LeadBroadcastService::emit_conversation_updated((int) $lead->id, 0);

        Log::channel('daily')->info('[CLOSER_FOLLOWUP] Mensaje de seguimiento generado.', [
            'lead_id'   => $lead->id,
            'escenario' => $escenario,
        ]);
    }

    /**
     * Construye el prompt para Claude con el contexto del resumen de la llamada.
     *
     * @param string      $nombre       Nombre del lead.
     * @param string|null $escenario    Código de escenario de cierre (A, B, C o null).
     * @param string      $proximo_paso Próximo paso acordado en la llamada.
     * @param string      $resumen      Resumen general de la llamada.
     *
     * @return string Prompt listo para enviar a Claude.
     */
    private function build_prompt(string $nombre, ?string $escenario, string $proximo_paso, string $resumen): string
    {
        /* Descripción legible del escenario para contextualizar a Claude. */
        $escenario_desc = match ($escenario) {
            'A' => 'El lead cerró o acordó términos. Necesita un mensaje de confirmación y próximos pasos.',
            'B' => 'El lead quiere hablar con Lucas (el dueño) antes de decidir.',
            'C' => 'El lead está interesado pero no decidió. Quedó en pensar/consultar.',
            default => 'Resultado no determinado de la llamada.',
        };

        return <<<PROMPT
Sos el asistente del closer de ComercioCity (Tommy). Tu tarea es escribir UN mensaje de WhatsApp
de seguimiento que Tommy le va a enviar al lead después de la llamada de venta.

Información de la llamada:
- Nombre del lead: {$nombre}
- Resultado: {$escenario_desc}
- Próximo paso acordado: {$proximo_paso}
- Resumen de la llamada: {$resumen}

Reglas para el mensaje:
- Escribilo como lo escribiría Tommy: directo, cálido, sin ser pesado
- Mencioná el próximo paso concreto que quedó acordado (si hay)
- Si el escenario es C (seguimiento): generar certeza y seguridad sobre la decisión, no presionar
- Si el escenario es B: coordinar la reunión con Lucas de forma natural
- Si el escenario es A: confirmar la decisión y explicar cómo siguen los pasos de implementación
- Máximo 3-4 líneas. Un solo mensaje, no múltiples separados por ---
- No usar emojis salvo uno al final si queda natural
- Devolvé SOLO el texto del mensaje, sin comillas, sin explicaciones

PROMPT;
    }

    /**
     * Llama a la API de Anthropic con el prompt dado y devuelve el texto generado.
     *
     * Aplica la misma configuración TLS que el resto del proyecto (sin verificación en WAMP/Windows
     * si la variable ANTHROPIC_VERIFY_SSL está en false).
     *
     * @param string $prompt Prompt a enviar a Claude.
     *
     * @return string|null Texto generado o null si la llamada falla.
     */
    private function call_claude(string $prompt): ?string
    {
        /* Validar que la API key esté configurada. */
        $api_key = (string) config('services.anthropic.api_key');
        if ($api_key === '') {
            Log::channel('daily')->error('[CLOSER_FOLLOWUP] ANTHROPIC_API_KEY no está configurada.');
            return null;
        }

        /* Construir cliente HTTP con los mismos headers que el resto del proyecto. */
        $http = Http::withHeaders([
            'x-api-key'         => $api_key,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ])->timeout(30);

        /* Configuración TLS según entorno (WAMP en Windows puede requerir cacert explícito). */
        $verify_ssl = (bool) config('services.anthropic.verify_ssl', true);
        $ca_bundle  = config('services.anthropic.ca_bundle');
        if (!$verify_ssl) {
            $http = $http->withoutVerifying();
        } elseif (is_string($ca_bundle) && $ca_bundle !== '' && is_file($ca_bundle)) {
            $http = $http->withOptions(['verify' => $ca_bundle]);
        }

        try {
            $response = $http->post(self::ANTHROPIC_API_URL, [
                'model'      => self::MODEL,
                'max_tokens' => 300,
                'messages'   => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);

            if ($response->failed()) {
                Log::channel('daily')->warning('[CLOSER_FOLLOWUP] Error en la API de Claude.', [
                    'status' => $response->status(),
                    'body'   => substr($response->body(), 0, 500),
                ]);
                return null;
            }

            /* Concatenar todos los bloques de texto de la respuesta (misma lógica que otros servicios). */
            $body = $response->json();
            $text = '';
            if (isset($body['content']) && is_array($body['content'])) {
                foreach ($body['content'] as $block) {
                    if (is_array($block) && isset($block['text'])) {
                        $text .= (string) $block['text'];
                    }
                }
            }

            return trim($text) ?: null;
        } catch (\Throwable $e) {
            Log::channel('daily')->error('[CLOSER_FOLLOWUP] Excepción al llamar a Claude.', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}

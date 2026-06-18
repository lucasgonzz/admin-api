<?php

namespace App\Services;

use App\Helpers\WhatsappNormalizer;
use App\Models\Lead;
use App\Models\LeadMessage;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Interpreta reacciones entrantes de WhatsApp (Kapso) y las aplica al mensaje original
 * de la conversación del lead, sin crear filas nuevas ni disparar sugerencias IA.
 */
class LeadWhatsappReactionService
{
    /**
     * Detecta si el payload es una reacción y devuelve datos normalizados para aplicarla.
     *
     * @param array<string, mixed> $payload Body JSON del webhook Kapso.
     * @param array<string, mixed> $parsed  Resultado de parse_inbound_message.
     *
     * @return array<string, mixed>|null null si no es reacción.
     */
    public function extract_reaction(array $payload, array $parsed): ?array
    {
        $message = $payload['message'] ?? null;
        if (! is_array($message)) {
            return null;
        }

        $raw_type = strtolower(trim((string) ($message['type'] ?? '')));

        // Formato nativo Kapso / Meta: message.type = reaction.
        if ($raw_type === 'reaction' && isset($message['reaction']) && is_array($message['reaction'])) {
            $target_id = trim((string) ($message['reaction']['message_id'] ?? ''));
            if ($target_id !== '') {
                $emoji = (string) ($message['reaction']['emoji'] ?? '');

                return $this->build_reaction_data($parsed, $target_id, $emoji);
            }
        }

        // Fallback: texto legado en kapso.content o body ("Reacted with 👍 to message wamid.…").
        $kapso_content = $parsed['kapso_content'] ?? null;
        if (($kapso_content === null || $kapso_content === '') && isset($message['kapso']['content'])) {
            $kapso_content = trim((string) $message['kapso']['content']);
        }

        $legacy = $this->parse_legacy_kapso_reaction_text((string) ($kapso_content ?? ''));
        if ($legacy === null) {
            $legacy = $this->parse_legacy_kapso_reaction_text((string) ($parsed['body'] ?? ''));
        }

        if ($legacy !== null) {
            return $this->build_reaction_data($parsed, $legacy['target_message_id'], $legacy['emoji']);
        }

        return null;
    }

    /**
     * Aplica la reacción del lead al mensaje objetivo de la conversación.
     *
     * @param array<string, mixed> $reaction_data Datos de extract_reaction.
     * @param array<string, mixed> $payload       Payload completo (contacto, etc.).
     *
     * @return bool true si se aplicó o ya estaba procesada; false si no se encontró el mensaje objetivo.
     */
    public function handle_lead_inbound_reaction(array $reaction_data, array $payload): bool
    {
        $reaction_message_id = (string) ($reaction_data['reaction_message_id'] ?? '');
        if ($reaction_message_id === '') {
            return false;
        }

        // Idempotencia: el mismo evento de reacción no debe procesarse dos veces.
        if (LeadMessage::query()
            ->where('lead_reaction_whatsapp_message_id', $reaction_message_id)
            ->exists()) {
            return true;
        }

        $target_whatsapp_id = (string) ($reaction_data['target_whatsapp_message_id'] ?? '');
        if ($target_whatsapp_id === '') {
            return false;
        }

        $target_message = LeadMessage::query()
            ->where('whatsapp_message_id', $target_whatsapp_id)
            ->first();

        if ($target_message === null) {
            Log::channel('daily')->warning('WhatsApp webhook: reacción sin mensaje objetivo en lead_messages.', [
                'from'                    => $reaction_data['from'] ?? null,
                'target_whatsapp_message_id' => $target_whatsapp_id,
                'reaction_message_id'     => $reaction_message_id,
                'emoji'                   => $reaction_data['emoji'] ?? null,
            ]);

            return false;
        }

        $lead = Lead::query()->find($target_message->lead_id);
        if ($lead === null) {
            return false;
        }

        $from_phone = (string) ($reaction_data['from'] ?? '');
        if ($from_phone === '' || ! WhatsappNormalizer::phones_match((string) $lead->phone, $from_phone)) {
            Log::channel('daily')->warning('WhatsApp webhook: reacción con teléfono que no coincide con el lead del mensaje.', [
                'lead_id'                 => $lead->id,
                'from'                    => $from_phone,
                'target_whatsapp_message_id' => $target_whatsapp_id,
            ]);

            return false;
        }

        $emoji = trim((string) ($reaction_data['emoji'] ?? ''));
        $reaction_at = $this->resolve_reaction_datetime($reaction_data['timestamp'] ?? null);

        if ($emoji !== '') {
            $target_message->lead_reaction_emoji = $emoji;
            $target_message->lead_reaction_at = $reaction_at;
        } else {
            // WhatsApp envía emoji vacío cuando el usuario quita la reacción.
            $target_message->lead_reaction_emoji = null;
            $target_message->lead_reaction_at = null;
        }

        $target_message->lead_reaction_whatsapp_message_id = $reaction_message_id;
        $target_message->save();

        LeadBroadcastService::emit_conversation_updated((int) $lead->id, (int) $target_message->id);

        Log::channel('daily')->info('WhatsApp webhook: reacción aplicada a mensaje de lead.', [
            'lead_id'                 => $lead->id,
            'target_message_id'       => $target_message->id,
            'target_whatsapp_message_id' => $target_whatsapp_id,
            'emoji'                   => $emoji !== '' ? $emoji : '(removida)',
        ]);

        return true;
    }

    /**
     * Indica si un content de lead_messages es el texto espurio de una reacción mal persistida.
     *
     * @param string $content
     *
     * @return bool
     */
    public static function is_legacy_reaction_content(string $content): bool
    {
        $content = trim($content);
        if ($content === '') {
            return false;
        }

        return (bool) preg_match('/^Reacted(?: with)? .+ to message wamid\./iu', $content)
            || (bool) preg_match('/^Removed reaction from message wamid\./iu', $content);
    }

    /**
     * Parsea el texto legado que Kapso usa como representación de una reacción.
     *
     * @param string $text Contenido de kapso.content o body.
     *
     * @return array{emoji: string, target_message_id: string}|null
     */
    protected function parse_legacy_kapso_reaction_text(string $text): ?array
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        // "Reacted with 👍 to message wamid.…"
        if (preg_match('/^Reacted with (.+) to message (wamid\.\S+)$/iu', $text, $matches)) {
            return [
                'emoji'              => trim($matches[1]),
                'target_message_id'  => trim($matches[2]),
            ];
        }

        // Variante sin "with": "Reacted 👍 to message wamid.…"
        if (preg_match('/^Reacted (.+) to message (wamid\.\S+)$/iu', $text, $matches)) {
            return [
                'emoji'              => trim($matches[1]),
                'target_message_id'  => trim($matches[2]),
            ];
        }

        // Kapso a veces manda solo el emoji en content cuando type=reaction; no aplica acá sin target.

        // "Removed reaction from message wamid.…"
        if (preg_match('/^Removed reaction from message (wamid\.\S+)$/iu', $text, $matches)) {
            return [
                'emoji'              => '',
                'target_message_id'  => trim($matches[1]),
            ];
        }

        return null;
    }

    /**
     * Arma el array normalizado de reacción a partir del mensaje parseado del webhook.
     *
     * @param array<string, mixed> $parsed
     * @param string               $target_whatsapp_message_id wamid del mensaje reaccionado.
     * @param string               $emoji                      Emoji Unicode o vacío si se quitó.
     *
     * @return array<string, mixed>
     */
    protected function build_reaction_data(array $parsed, string $target_whatsapp_message_id, string $emoji): array
    {
        return [
            'from'                         => (string) ($parsed['from'] ?? ''),
            'reaction_message_id'          => (string) ($parsed['message_id'] ?? ''),
            'target_whatsapp_message_id'   => $target_whatsapp_message_id,
            'emoji'                        => $emoji,
            'timestamp'                    => $parsed['timestamp'] ?? null,
            'contact_name'                 => $parsed['contact_name'] ?? null,
        ];
    }

    /**
     * Convierte timestamp unix de Meta a Carbon; fallback a now().
     *
     * @param mixed $timestamp Valor crudo del payload.
     *
     * @return Carbon
     */
    protected function resolve_reaction_datetime($timestamp): Carbon
    {
        if ($timestamp !== null && $timestamp !== '' && is_numeric($timestamp)) {
            return Carbon::createFromTimestamp((int) $timestamp);
        }

        return now();
    }
}

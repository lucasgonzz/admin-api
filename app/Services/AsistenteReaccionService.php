<?php

namespace App\Services;

use App\Helpers\WhatsappNormalizer;
use App\Models\ClientAssistantMessage;

/**
 * Decide si una reacción de WhatsApp es la del dueño a un mensaje de SU asistente y arma el cuerpo
 * que se le reenvía al `empresa-api` de su sistema (contrato `POST api/admin-sync/asistente/reacciones`).
 *
 * Vive en el admin porque el `wamid` de cada respuesta del asistente se guarda sólo acá
 * (`client_assistant_messages`): el `empresa-api` no lo conoce. Este servicio NO manda nada ni toca la
 * base: sólo resuelve y arma. El envío lo hace `ReenviarReaccionAlAsistenteJob`.
 *
 * 🔴 Las dos guardas de `saliente_reaccionado()` no son decoración. Sin la del teléfono, cualquiera que
 * conociera un `wamid` valoraría la respuesta de otro dueño; sin la de demos, la reacción de un lead
 * a una respuesta de la demo viajaría a un cliente que no tiene nada que ver.
 */
class AsistenteReaccionService
{
    /**
     * Ruta del endpoint del asistente en el `empresa-api` del cliente.
     */
    const RUTA_REACCIONES = 'api/admin-sync/asistente/reacciones';

    /**
     * Devuelve el saliente del asistente al que apunta la reacción, o null si no es de uno.
     *
     * Una reacción es "del asistente" cuando el `wamid` reaccionado es de una fila SALIENTE de un
     * dueño real (`client_id` > 0, sin instancia de demo) y el teléfono que reacciona es el de esa fila.
     *
     * @param array<string, mixed> $reaction_data Salida de `LeadWhatsappReactionService::extract_reaction()`
     *                                            (usa `target_whatsapp_message_id` y `from`).
     *
     * @return ClientAssistantMessage|null La fila saliente; null si no corresponde reenviar.
     */
    public function saliente_reaccionado(array $reaction_data): ?ClientAssistantMessage
    {
        // wamid del mensaje reaccionado y teléfono de quien reacciona.
        $target_whatsapp_message_id = trim((string) ($reaction_data['target_whatsapp_message_id'] ?? ''));
        $from_phone                 = trim((string) ($reaction_data['from'] ?? ''));

        if ($target_whatsapp_message_id === '' || $from_phone === '') {
            return null;
        }

        // El wamid es único en la práctica; si hubiera más de una fila saliente se prueba cada una.
        $candidatas = ClientAssistantMessage::query()
            ->where('whatsapp_message_id', $target_whatsapp_message_id)
            ->where('direccion', ClientAssistantMessage::DIRECCION_SALIENTE)
            ->where('client_id', '>', 0)
            ->get();

        foreach ($candidatas as $fila) {
            // Hilo de demo de un lead: nunca se reenvía.
            if ($fila->es_de_demo()) {
                continue;
            }

            // Quien reacciona tiene que ser el dueño de ese hilo.
            if (! WhatsappNormalizer::phones_match((string) $fila->telefono, $from_phone)) {
                continue;
            }

            return $fila;
        }

        return null;
    }

    /**
     * Arma el cuerpo JSON del contrato (§2.5).
     *
     * `emoji` viaja siempre, incluso vacío (`""` = el dueño quitó la reacción). `ai_message_id` y
     * `reaction_message_id` son opcionales: se omiten si no hay valor.
     *
     * @param ClientAssistantMessage $fila          Saliente reaccionado.
     * @param array<string, mixed>   $reaction_data Datos de la reacción (`emoji`, `reaction_message_id`,
     *                                              `target_whatsapp_message_id`).
     *
     * @return array<string, mixed>
     */
    public function cuerpo_del_contrato(ClientAssistantMessage $fila, array $reaction_data): array
    {
        return self::armar_cuerpo(
            (string) $fila->whatsapp_message_id,
            (string) ($reaction_data['emoji'] ?? ''),
            $fila->ai_message_id !== null ? (int) $fila->ai_message_id : null,
            (string) ($reaction_data['reaction_message_id'] ?? '')
        );
    }

    /**
     * Forma única del cuerpo: la usan el webhook (vía `cuerpo_del_contrato`) y el job.
     *
     * @param string      $whatsapp_message_id  wamid del mensaje reaccionado.
     * @param string      $emoji                Emoji tal cual ('' = quitó la reacción).
     * @param int|null    $ai_message_id        Mensaje del asistente en el empresa-api, si la fila lo tiene.
     * @param string|null $reaction_message_id  wamid del evento de reacción (sólo para log del otro lado).
     *
     * @return array<string, mixed>
     */
    public static function armar_cuerpo(string $whatsapp_message_id, string $emoji, ?int $ai_message_id, ?string $reaction_message_id): array
    {
        $cuerpo = [
            'whatsapp_message_id' => $whatsapp_message_id,
            'emoji'               => $emoji,
        ];

        // Sólo si la fila lo tiene (> 0): un 0 no identifica ningún mensaje.
        if ($ai_message_id !== null && $ai_message_id > 0) {
            $cuerpo['ai_message_id'] = $ai_message_id;
        }

        if ($reaction_message_id !== null && $reaction_message_id !== '') {
            $cuerpo['reaction_message_id'] = $reaction_message_id;
        }

        return $cuerpo;
    }
}

<?php

namespace App\Services\WhatsappPersonal;

use App\Helpers\WhatsappNormalizer;
use App\Services\WhatsappInboundMediaService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Procesa los eventos de Kapso que llegan por el número PERSONAL (misión wa-personal-coexistencia).
 *
 * Es sólo guardar: deja el mensaje en `whatsapp_personal_messages`, mantiene `whatsapp_personal_chats` y
 * actualiza los estados de entrega. 🔴 No crea leads, tickets ni nada de implementación, no llama al asistente,
 * no manda notificaciones, no encola jobs y no sale a la red: el número personal es de Lucas, no un canal de
 * atención. Un test cuenta las filas de TODAS las tablas para asegurarlo.
 *
 * Reglas de lectura del payload (forma documentada de Kapso):
 *   - La dirección sale de `message.kapso.direction` (`inbound`/`outbound`); sin ese dato, `received` es entrante
 *     y `sent` es saliente. El eco de lo que Lucas escribe desde la app es saliente: el cliente va en
 *     `message.to`, no en `message.from`.
 *   - El origen sale de `message.kapso.origin` (`cloud_api`, `business_app`, `history_sync`…).
 *   - Lo que no se entiende se ignora con seguridad: grupos, números cortos, payloads sin id ni contraparte y
 *     eventos `whatsapp.thread.*`.
 *   - No se guardan binarios ni el payload crudo.
 */
class WhatsappPersonalWebhookService
{
    /** Eventos de mensaje que traen un mensaje (entrante, o eco/saliente). */
    const EVENTOS_DE_MENSAJE = ['whatsapp.message.received', 'whatsapp.message.sent'];

    /** Eventos de estado de entrega y el estado al que llevan. */
    const EVENTOS_DE_ESTADO = [
        'whatsapp.message.delivered' => 'entregado',
        'whatsapp.message.read'      => 'leido',
        'whatsapp.message.failed'    => 'fallido',
    ];

    /** Tipos de mensaje que se guardan con su nombre; el resto se guarda como `otro`. */
    const TIPOS_CONOCIDOS = ['text', 'image', 'audio', 'video', 'document', 'sticker', 'reaction', 'location', 'contacts'];

    /** Tipos con archivo adjunto. */
    const TIPOS_CON_MEDIA = ['image', 'audio', 'video', 'document', 'sticker'];

    /** Máximo de caracteres del cuerpo que se guardan. */
    const MAX_CUERPO = 4096;

    /** Cuánto pesa cada estado: uno sólo sube, nunca baja. */
    const RANGO_DE_ESTADO = ['fallido' => 0, 'pendiente' => 0, 'incierto' => 0, 'simulado' => 1, 'enviado' => 1, 'entregado' => 2, 'leido' => 3];

    /**
     * Procesa un evento del número personal. Nunca lanza: devuelve qué hizo.
     *
     * @param string               $evento  Nombre del evento de Kapso.
     * @param array<string, mixed> $payload Cuerpo del webhook decodificado.
     * @param string               $phone_number_id `phone_number_id` de la fila personal.
     *
     * @return string guardado|estado|ignorado
     */
    public function procesar(string $evento, array $payload, string $phone_number_id): string
    {
        try {
            if (in_array($evento, self::EVENTOS_DE_MENSAJE, true)) {
                return $this->guardar_mensaje($evento, $payload, $phone_number_id);
            }

            if (isset(self::EVENTOS_DE_ESTADO[$evento])) {
                return $this->aplicar_estado(self::EVENTOS_DE_ESTADO[$evento], $payload);
            }
        } catch (\Throwable $exception) {
            // Sin cuerpo ni teléfono en el log: es un chat privado.
            Log::channel('daily')->error('WhatsappPersonal: error al procesar un evento.', [
                'evento' => $evento,
                'error'  => get_class($exception),
            ]);
        }

        return 'ignorado';
    }

    /**
     * Guarda un mensaje (entrante, saliente o eco) y deja el chat al día.
     *
     * @param string               $evento          Evento de Kapso.
     * @param array<string, mixed> $payload         Cuerpo del webhook.
     * @param string               $phone_number_id Número personal.
     *
     * @return string guardado|ignorado
     */
    private function guardar_mensaje(string $evento, array $payload, string $phone_number_id): string
    {
        $mensaje = isset($payload['message']) && is_array($payload['message']) ? $payload['message'] : null;
        $wamid   = $mensaje !== null && isset($mensaje['id']) && is_scalar($mensaje['id']) ? trim((string) $mensaje['id']) : '';
        if ($mensaje === null || $wamid === '') {
            return 'ignorado';
        }

        $kapso     = isset($mensaje['kapso']) && is_array($mensaje['kapso']) ? $mensaje['kapso'] : [];
        $direccion = $this->direccion_de($evento, $kapso);

        $telefono = $this->contraparte($payload, $mensaje, $direccion);
        if ($telefono === null) {
            return 'ignorado';
        }

        $origen = $this->origen_de($kapso);

        // Si ya lo tenemos (el eco de un envío nuestro, o un webhook repetido) sólo se confirma el envío.
        $existente = DB::table('whatsapp_personal_messages')->where('whatsapp_message_id', $wamid)->first();
        if ($existente !== null) {
            if ($direccion === 'out') {
                $this->subir_estado($existente, 'enviado');
            }

            return 'guardado';
        }

        $tipo     = $this->tipo_de($mensaje);
        $sent_at  = $this->momento_de($mensaje);
        $media    = $this->media_de($mensaje, $tipo);
        $nombre   = $this->texto_corto(isset($payload['conversation']['contact_name']) ? $payload['conversation']['contact_name'] : null, 120);
        $reply_to = isset($mensaje['context']['id']) && is_scalar($mensaje['context']['id']) ? $this->texto_corto($mensaje['context']['id'], 128) : null;
        $ahora    = Carbon::now()->toDateTimeString();

        $creadas = DB::table('whatsapp_personal_messages')->insertOrIgnore([
            'phone_number_id'     => $this->texto_corto($phone_number_id, 40),
            'chat_telefono'       => $telefono,
            'direccion'           => $direccion,
            'origen'              => $origen,
            'whatsapp_message_id' => $this->texto_corto($wamid, 128),
            'tipo'                => $tipo,
            'cuerpo'              => $this->cuerpo_de($mensaje, $tipo),
            'media_id'            => $media['id'],
            'media_mime'          => $media['mime'],
            'media_filename'      => $media['filename'],
            'contact_name'        => $nombre,
            'reply_to_wamid'      => $reply_to,
            'estado'              => $direccion === 'in' ? 'recibido' : 'enviado',
            'sent_at'             => $sent_at->toDateTimeString(),
            'created_at'          => $ahora,
            'updated_at'          => $ahora,
        ]);

        if ($creadas > 0) {
            $this->actualizar_chat($telefono, $nombre, $sent_at, $direccion === 'in' && $origen !== 'history_sync');
            Log::channel('daily')->info('WhatsappPersonal: mensaje guardado.', [
                'message_id' => $wamid,
                'chat'       => '…' . substr($telefono, -4),
                'tipo'       => $tipo,
            ]);
        }

        return 'guardado';
    }

    /**
     * Aplica un evento de estado de entrega al saliente con ese wamid. Uno desconocido se ignora.
     *
     * @param string               $estado  entregado|leido|fallido.
     * @param array<string, mixed> $payload Cuerpo del webhook.
     *
     * @return string estado|ignorado
     */
    private function aplicar_estado(string $estado, array $payload): string
    {
        $wamid = isset($payload['message']['id']) && is_scalar($payload['message']['id']) ? trim((string) $payload['message']['id']) : '';
        if ($wamid === '') {
            return 'ignorado';
        }

        $fila = DB::table('whatsapp_personal_messages')->where('whatsapp_message_id', $wamid)->where('direccion', 'out')->first();
        if ($fila === null) {
            return 'ignorado';
        }

        $this->subir_estado($fila, $estado, $estado === 'fallido' ? $this->motivo_del_fallo($payload) : null);

        return 'estado';
    }

    /**
     * Sube el estado de un saliente sin bajarlo nunca (enviado < entregado < leído). Un `fallido` sólo entra si
     * todavía no fue entregado ni leído.
     *
     * @param object      $fila   Fila del mensaje.
     * @param string      $nuevo  Estado nuevo.
     * @param string|null $motivo Motivo del fallo, si lo hay.
     *
     * @return void
     */
    private function subir_estado($fila, string $nuevo, ?string $motivo = null): void
    {
        $actual       = (string) $fila->estado;
        $rango_actual = isset(self::RANGO_DE_ESTADO[$actual]) ? self::RANGO_DE_ESTADO[$actual] : 0;
        $rango_nuevo  = self::RANGO_DE_ESTADO[$nuevo];

        if ($nuevo === 'fallido') {
            // Un fallo no pisa un enviado/entregado/leído: Kapso reporta el fallo antes de cualquier entrega.
            if ($actual === 'entregado' || $actual === 'leido' || $actual === 'fallido') {
                return;
            }
        } elseif ($rango_nuevo <= $rango_actual) {
            return;
        }

        $cambios = ['estado' => $nuevo, 'updated_at' => Carbon::now()->toDateTimeString()];
        if ($nuevo === 'fallido') {
            $cambios['estado_error'] = $motivo !== null ? $this->texto_corto($motivo, 255) : null;
        } else {
            $cambios['estado_error'] = null;
        }

        DB::table('whatsapp_personal_messages')->where('id', $fila->id)->update($cambios);
    }

    /**
     * Crea o actualiza la fila del chat (último mensaje, último entrante y nombre del contacto).
     *
     * @param string      $telefono    Chat en E.164.
     * @param string|null $nombre      Nombre del contacto, si Kapso lo mandó.
     * @param Carbon      $sent_at     Hora real del mensaje.
     * @param bool        $es_entrante Si cuenta como entrante «de verdad» (no historial).
     *
     * @return void
     */
    private function actualizar_chat(string $telefono, ?string $nombre, Carbon $sent_at, bool $es_entrante): void
    {
        $ahora = Carbon::now()->toDateTimeString();

        DB::table('whatsapp_personal_chats')->insertOrIgnore([
            'chat_telefono' => $telefono,
            'created_at'    => $ahora,
            'updated_at'    => $ahora,
        ]);

        $chat = DB::table('whatsapp_personal_chats')->where('chat_telefono', $telefono)->first();
        if ($chat === null) {
            return;
        }

        $cambios = ['updated_at' => $ahora];
        if ($chat->last_message_at === null || Carbon::parse($chat->last_message_at)->lt($sent_at)) {
            $cambios['last_message_at'] = $sent_at->toDateTimeString();
        }
        if ($es_entrante && ($chat->last_inbound_at === null || Carbon::parse($chat->last_inbound_at)->lt($sent_at))) {
            $cambios['last_inbound_at'] = $sent_at->toDateTimeString();
        }
        if ($nombre !== null && $nombre !== '') {
            $cambios['contact_name'] = $nombre;
        }

        DB::table('whatsapp_personal_chats')->where('id', $chat->id)->update($cambios);
    }

    /**
     * Dirección del mensaje: `in` o `out`.
     *
     * @param string               $evento Evento de Kapso.
     * @param array<string, mixed> $kapso  Nodo `message.kapso`.
     *
     * @return string
     */
    private function direccion_de(string $evento, array $kapso): string
    {
        $direccion = isset($kapso['direction']) && is_string($kapso['direction']) ? strtolower($kapso['direction']) : '';

        if ($direccion === 'outbound') {
            return 'out';
        }
        if ($direccion === 'inbound') {
            return 'in';
        }

        return $evento === 'whatsapp.message.sent' ? 'out' : 'in';
    }

    /**
     * Origen del mensaje según Kapso (`cloud_api`, `business_app`, `history_sync`); otro valor es `otro`.
     *
     * @param array<string, mixed> $kapso Nodo `message.kapso`.
     *
     * @return string
     */
    private function origen_de(array $kapso): string
    {
        $origen = isset($kapso['origin']) && is_string($kapso['origin']) ? strtolower(trim($kapso['origin'])) : '';

        if ($origen === '') {
            return 'cloud_api';
        }

        return in_array($origen, ['cloud_api', 'business_app', 'history_sync'], true) ? $origen : 'otro';
    }

    /**
     * Teléfono del cliente en E.164: `from` si es entrante, `to` si es saliente, y como respaldo
     * `conversation.phone_number`. Null si no hay o no es un teléfono (grupo con `@`, menos de 8 dígitos).
     *
     * @param array<string, mixed> $payload   Cuerpo del webhook.
     * @param array<string, mixed> $mensaje   Nodo `message`.
     * @param string               $direccion in|out.
     *
     * @return string|null
     */
    private function contraparte(array $payload, array $mensaje, string $direccion): ?string
    {
        $clave     = $direccion === 'in' ? 'from' : 'to';
        $candidato = isset($mensaje[$clave]) && is_scalar($mensaje[$clave]) ? trim((string) $mensaje[$clave]) : '';

        if ($candidato === '' && isset($payload['conversation']['phone_number']) && is_scalar($payload['conversation']['phone_number'])) {
            $candidato = trim((string) $payload['conversation']['phone_number']);
        }

        if ($candidato === '' || strpos($candidato, '@') !== false) {
            return null;
        }

        $digitos = preg_replace('/\D+/', '', $candidato);
        if (strlen((string) $digitos) < 8) {
            return null;
        }

        $normalizado = WhatsappNormalizer::normalize($candidato);

        return $normalizado === '' ? null : $normalizado;
    }

    /**
     * Tipo guardado: el de Meta si lo conocemos (`ptt` y `voice` son audio), `otro` si no.
     *
     * @param array<string, mixed> $mensaje Nodo `message`.
     *
     * @return string
     */
    private function tipo_de(array $mensaje): string
    {
        $tipo = isset($mensaje['type']) && is_string($mensaje['type']) ? strtolower(trim($mensaje['type'])) : 'text';

        if ($tipo === 'ptt' || $tipo === 'voice') {
            $tipo = 'audio';
        }

        return in_array($tipo, self::TIPOS_CONOCIDOS, true) ? $tipo : 'otro';
    }

    /**
     * Texto del mensaje: el cuerpo, el pie de una imagen/video/documento, la transcripción de un audio o el
     * emoji de una reacción. Un tipo desconocido no guarda cuerpo.
     *
     * @param array<string, mixed> $mensaje Nodo `message`.
     * @param string               $tipo    Tipo ya normalizado.
     *
     * @return string|null
     */
    private function cuerpo_de(array $mensaje, string $tipo): ?string
    {
        $texto = null;

        if ($tipo === 'text' && isset($mensaje['text']['body'])) {
            $texto = $mensaje['text']['body'];
        } elseif (in_array($tipo, ['image', 'video', 'document'], true) && isset($mensaje[$tipo]['caption'])) {
            $texto = $mensaje[$tipo]['caption'];
        } elseif ($tipo === 'audio' && isset($mensaje['kapso']['transcript']['text'])) {
            $texto = $mensaje['kapso']['transcript']['text'];
        } elseif ($tipo === 'reaction' && isset($mensaje['reaction']['emoji'])) {
            $texto = $mensaje['reaction']['emoji'];
        }

        if (! is_scalar($texto)) {
            return null;
        }

        $texto = trim((string) $texto);

        return $texto === '' ? null : mb_substr($texto, 0, self::MAX_CUERPO);
    }

    /**
     * Metadatos del adjunto (id de Meta, mime y nombre). El binario no se baja ni se guarda.
     *
     * @param array<string, mixed> $mensaje Nodo `message`.
     * @param string               $tipo    Tipo ya normalizado.
     *
     * @return array{id: string|null, mime: string|null, filename: string|null}
     */
    private function media_de(array $mensaje, string $tipo): array
    {
        $vacio = ['id' => null, 'mime' => null, 'filename' => null];
        if (! in_array($tipo, self::TIPOS_CON_MEDIA, true)) {
            return $vacio;
        }

        $media = (new WhatsappInboundMediaService())->extract_inbound_media($mensaje, $tipo);
        if ($media === null) {
            return $vacio;
        }

        // El nombre que manda Meta en el documento gana sobre el que se adivina.
        $nombre = isset($mensaje[$tipo]['filename']) && is_scalar($mensaje[$tipo]['filename'])
            ? trim((string) $mensaje[$tipo]['filename'])
            : (string) $media['filename'];

        return [
            'id'       => $media['whatsapp_media_id'] !== null ? $this->texto_corto($media['whatsapp_media_id'], 128) : null,
            'mime'     => $media['mime'] !== null && $media['mime'] !== '' ? $this->texto_corto($media['mime'], 80) : null,
            'filename' => $nombre !== '' ? $this->texto_corto($nombre, 190) : null,
        ];
    }

    /**
     * Hora real del mensaje (`message.timestamp`, epoch en segundos); sin dato, ahora.
     *
     * @param array<string, mixed> $mensaje Nodo `message`.
     *
     * @return Carbon
     */
    private function momento_de(array $mensaje): Carbon
    {
        if (isset($mensaje['timestamp']) && is_numeric($mensaje['timestamp']) && (int) $mensaje['timestamp'] > 0) {
            return Carbon::createFromTimestamp((int) $mensaje['timestamp']);
        }

        return Carbon::now();
    }

    /**
     * Motivo legible de un fallo de entrega, si Kapso lo manda en alguno de los lugares conocidos.
     *
     * @param array<string, mixed> $payload Cuerpo del webhook.
     *
     * @return string
     */
    private function motivo_del_fallo(array $payload): string
    {
        foreach ([isset($payload['message']['errors']) ? $payload['message']['errors'] : null, isset($payload['errors']) ? $payload['errors'] : null] as $errores) {
            if (! is_array($errores) || $errores === [] || ! is_array(reset($errores))) {
                continue;
            }

            $primero = reset($errores);
            $partes  = [];
            foreach (['code', 'title', 'message'] as $clave) {
                if (isset($primero[$clave]) && is_scalar($primero[$clave]) && trim((string) $primero[$clave]) !== '') {
                    $partes[] = trim((string) $primero[$clave]);
                }
            }

            if ($partes !== []) {
                return implode(' — ', $partes);
            }
        }

        return 'Fallo de entrega informado por Kapso';
    }

    /**
     * Recorta un valor a un largo máximo; null o vacío queda null.
     *
     * @param mixed $valor Valor escalar.
     * @param int   $max   Largo máximo en caracteres.
     *
     * @return string|null
     */
    private function texto_corto($valor, int $max): ?string
    {
        if (! is_scalar($valor)) {
            return null;
        }

        $texto = trim((string) $valor);

        return $texto === '' ? null : mb_substr($texto, 0, $max);
    }
}

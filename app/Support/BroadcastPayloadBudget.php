<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Presupuesto de tamaño para los payloads que viajan por Pusher Channels.
 *
 * El problema que resuelve, medido en producción el 2/9/2026: un evento que serializa un
 * modelo entero crece con el modelo. `LeadSuggestionCreated` mandaba el `Lead` completo
 * (144 columnas) más cinco relaciones y, cuando el lead venía cargado, el broadcast reventaba
 * con «The data content of this event exceeds the allowed maximum (10240 bytes)».
 *
 * La respuesta acá es de clase, no de caso: en vez de podar relaciones de a una cada vez que
 * un evento explota, se mide el payload antes de emitirlo y, si no entra, se emite **sin la
 * clave pesada**. El evento siempre lleva su id, así que el consumidor puede refrescar por API.
 */
class BroadcastPayloadBudget
{
    /**
     * Presupuesto en bytes para el payload de un evento.
     *
     * 🔴 POR QUÉ 9000 Y NO 10240 PELADO. El límite de 10240 bytes que impone Pusher Channels
     * se aplica al **body del evento HTTP**, no al array que devuelve `broadcastWith()`.
     * Encima de nuestro payload, Laravel arma un sobre: el nombre del evento, el o los canales,
     * el `socket_id` y —esto es lo caro— el payload va **serializado a JSON adentro de un
     * string JSON**, o sea con cada comilla escapada (`"` → `\"`). Medir nuestro array pelado
     * contra 10240 sería medir con la regla equivocada y volver a chocar justo arriba del
     * límite, que es exactamente el escenario que ya nos pasó.
     *
     * 1240 bytes de margen cubren el sobre y el escapado con holgura para los tres eventos que
     * usan este presupuesto hoy. Si algún día un evento legítimo queda entre 9000 y 10240 y se
     * recorta de más, el log de abajo lo va a mostrar con el tamaño exacto: se sube el número
     * con un dato en la mano, no a ojo.
     */
    const PRESUPUESTO_BYTES = 9000;

    /**
     * Devuelve el payload completo si entra en el presupuesto; si no, sin la clave pesada.
     *
     * @param array<string, mixed> $payload      Payload tal como lo armó `broadcastWith()`.
     * @param string               $clave_pesada Clave que se saca cuando no entra (el modelo).
     * @param string               $evento       Nombre del evento, solo para que el log sirva.
     *
     * @return array<string, mixed>
     */
    public static function ajustar(array $payload, string $clave_pesada, string $evento): array
    {
        $tamanio = self::medir($payload);

        if ($tamanio <= self::PRESUPUESTO_BYTES) {
            return $payload;
        }

        // Se loguea siempre que se recorta: un payload recortado degrada la experiencia
        // (el consumidor tiene que ir a buscar el modelo por API) y eso tiene que ser
        // visible. Un recorte silencioso es una regresión que nadie encuentra.
        Log::warning('BroadcastPayloadBudget: payload recortado por exceder el presupuesto.', [
            'evento'            => $evento,
            'clave_removida'    => $clave_pesada,
            'bytes_medidos'     => $tamanio,
            'presupuesto_bytes' => self::PRESUPUESTO_BYTES,
        ]);

        unset($payload[$clave_pesada]);

        return $payload;
    }

    /**
     * Tamaño en bytes del payload serializado a JSON.
     *
     * Si el payload no se puede serializar, se devuelve el máximo entero a propósito: un
     * payload que no serializa tampoco lo va a poder mandar Pusher, así que el llamador lo
     * trata como «no entra» y lo recorta en vez de arrastrar el problema hasta la red.
     *
     * @param array<string, mixed> $payload
     *
     * @return int
     */
    public static function medir(array $payload): int
    {
        $json = json_encode($payload);

        if ($json === false) {
            return PHP_INT_MAX;
        }

        return strlen($json);
    }
}

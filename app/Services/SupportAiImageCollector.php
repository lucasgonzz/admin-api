<?php

namespace App\Services;

use App\Models\SupportMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Junta las imágenes que el cliente mandó, listas para pasárselas al agente.
 *
 * El caso que esto resuelve es concreto: el cliente saca una foto de la pantalla con el error
 * y la manda por WhatsApp. Hasta ahora al agente le llegaba solamente `[IMAGE]` en el historial
 * —o sea, sabía que había una imagen y nada más— y contestaba a ciegas.
 *
 * Se manda un conjunto ACOTADO a propósito. Tres razones, en orden de importancia:
 *   1. El agentic loop reenvía el primer mensaje en cada iteración (hasta cinco), así que cada
 *      imagen se paga hasta cinco veces por consulta.
 *   2. Un request con más de veinte imágenes activa un límite de dimensión más estricto de Meta
 *      para TODAS las imágenes del request, no solo las que sobran.
 *   3. Las fotos viejas de un ticket largo casi nunca son de lo que el cliente está preguntando
 *      ahora; meterlas encima confunde al agente en vez de ayudarlo.
 */
class SupportAiImageCollector
{
    /**
     * Cuántas imágenes se le mandan al agente como mucho.
     */
    const MAX_IMAGES = 3;

    /**
     * Tope por imagen, en bytes del archivo original.
     *
     * La API rechaza a partir de 10 MB de base64, y base64 infla cerca de un 33%: 7 MB de
     * archivo quedan holgados por debajo, y cualquier captura de pantalla real pesa mucho menos.
     */
    const MAX_BYTES_PER_IMAGE = 7340032;

    /**
     * Formatos que la API acepta. No hay otros: un BMP o un HEIC se descartan.
     *
     * @var array<int, string>
     */
    const SUPPORTED_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /**
     * Devuelve las últimas imágenes que mandó el cliente en este ticket.
     *
     * Solo mira mensajes del cliente: una imagen que mandó el operador se la mandó él mismo y
     * ya sabe qué es. Y solo desde la última respuesta del operador, que es el recorte natural
     * de "lo que el cliente está preguntando ahora".
     *
     * @param int $ticket_id Ticket del que se juntan las imágenes.
     *
     * @return array<int, array<string, string>> Filas con media_type y data (base64), en el
     *                                           orden en que las mandó el cliente.
     */
    public function collect(int $ticket_id): array
    {
        $desde_id = $this->last_admin_message_id($ticket_id);

        $mensajes = SupportMessage::query()
            ->where('support_ticket_id', $ticket_id)
            ->where('sender_type', 'user')
            ->where('id', '>', $desde_id)
            ->with('attachments')
            ->orderBy('id', 'desc')
            ->get();

        $imagenes = [];

        foreach ($mensajes as $mensaje) {
            foreach ($mensaje->attachments as $adjunto) {
                if (count($imagenes) >= self::MAX_IMAGES) {
                    break 2;
                }

                $imagen = $this->read_attachment($adjunto);
                if ($imagen !== null) {
                    $imagenes[] = $imagen;
                }
            }
        }

        // Se recorrió de la más nueva a la más vieja para quedarse con las últimas; se devuelven
        // en el orden en que el cliente las mandó, que es como se entienden.
        return array_reverse($imagenes);
    }

    /**
     * Id del último mensaje del operador en el ticket, o 0 si todavía no contestó ninguno.
     *
     * Los borradores del agente no cuentan: mientras esperan aprobación no son una respuesta,
     * y tomarlos como corte dejaría al agente sin ver la imagen que motivó su propio borrador.
     *
     * @param int $ticket_id Ticket.
     *
     * @return int
     */
    private function last_admin_message_id(int $ticket_id): int
    {
        $ultimo = SupportMessage::query()
            ->where('support_ticket_id', $ticket_id)
            ->where('sender_type', 'admin')
            ->where('is_ai_suggestion_draft', false)
            ->orderBy('id', 'desc')
            ->first(['id']);

        return $ultimo !== null ? (int) $ultimo->id : 0;
    }

    /**
     * Lee un adjunto del disco y lo devuelve en base64, o null si no sirve.
     *
     * @param \App\Models\SupportMessageAttachment $adjunto Adjunto a leer.
     *
     * @return array<string, string>|null
     */
    private function read_attachment($adjunto)
    {
        $mime = strtolower(trim((string) ($adjunto->mime ?? '')));
        if (! in_array($mime, self::SUPPORTED_MIMES, true)) {
            return null;
        }

        $size = (int) ($adjunto->size ?? 0);
        if ($size > self::MAX_BYTES_PER_IMAGE) {
            Log::channel('daily')->info('SupportAiImageCollector: imagen descartada por tamaño.', [
                'attachment_id' => $adjunto->id,
                'size'          => $size,
            ]);

            return null;
        }

        $disk = trim((string) ($adjunto->disk ?? 'public'));
        $path = trim((string) ($adjunto->path ?? ''));
        if ($path === '') {
            return null;
        }

        try {
            // Los adjuntos viejos pueden no estar más en disco: el registro sobrevive al archivo.
            if (! Storage::disk($disk)->exists($path)) {
                return null;
            }

            $binario = Storage::disk($disk)->get($path);
        } catch (\Throwable $exception) {
            Log::channel('daily')->warning('SupportAiImageCollector: no se pudo leer el adjunto.', [
                'attachment_id' => $adjunto->id,
                'path'          => $path,
                'error'         => $exception->getMessage(),
            ]);

            return null;
        }

        if ($binario === null || $binario === '') {
            return null;
        }

        // El tope real es el del base64, no el del archivo: se re-chequea con el tamaño de
        // verdad y no con la columna `size`, que un adjunto viejo puede tener mal cargada.
        if (strlen($binario) > self::MAX_BYTES_PER_IMAGE) {
            return null;
        }

        return [
            'media_type' => $mime,
            'data'       => base64_encode($binario),
        ];
    }
}

<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Las fotos que el dueño le manda al asistente por WhatsApp.
 *
 * El caso que las trajo lo dictó Lucas con un ejemplo: el dueño le saca una foto a la factura de un
 * proveedor y escribe *"esto es la compra de tal proveedor"*. Del otro lado, el asistente da de alta
 * la compra y cuelga la foto como factura para que la IA la procese. Este servicio es el tramo del
 * medio: baja los bytes de Kapso y los deja listos para viajar en el multipart del mensaje.
 *
 * 🔴 **Acá no se guarda nada en disco.** La foto es del cliente y la procesa el sistema del cliente;
 * una copia en el admin no le sirve a nadie y sería una factura de un tercero viviendo en un storage
 * que no le corresponde. Los bytes pasan de largo: se bajan, se mandan y se olvidan.
 *
 * 🔴 **Y si no se pueden bajar, el mensaje sale igual.** Perder el mensaje entero porque no se pudo
 * bajar un adjunto es peor que perder la foto: el dueño escribió algo y espera respuesta. Lo que sí
 * pasa es que el asistente se entera —`nota_para_el_asistente()` se le pega al texto— así puede
 * decir que la foto no le llegó y pedirla de nuevo, en vez de contestar como si no hubiera habido
 * ninguna.
 */
class AsistenteImagenesService
{
    /**
     * Cuántas fotos como máximo viajan en un mensaje.
     *
     * Es el tope del `empresa-api` (`POST admin-sync/asistente/mensajes` acepta hasta 3) y se valida
     * de ESTE lado a propósito: mandarle cuatro y que las rechace convierte un mensaje con una foto
     * de más en un mensaje perdido. Hoy el webhook trae como mucho una por mensaje —WhatsApp manda
     * un adjunto por vez—, así que este tope es la red para el día que eso cambie o para una corrida
     * a mano del job.
     */
    const MAXIMO_DE_IMAGENES = 3;

    /**
     * Tipos que acepta el escaneo de facturas del `empresa-api`. Cualquier otro se descarta acá.
     */
    const MIMES_ACEPTADOS = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * Tope de tamaño por foto, en bytes: los mismos 12 MB del escaneo de factura del cliente.
     */
    const MAXIMO_DE_BYTES = 12582912;

    /**
     * Descarga de la media entrante de Kapso.
     *
     * @var WhatsappInboundMediaService
     */
    private $media;

    /**
     * @param WhatsappInboundMediaService $media Descarga de la media entrante de Kapso.
     */
    public function __construct(WhatsappInboundMediaService $media)
    {
        $this->media = $media;
    }

    /**
     * Baja las fotos de un mensaje y devuelve las que están en condiciones de viajar.
     *
     * @param array<int, array<string, mixed>> $medias        Metadata de `extract_inbound_media()`, una por foto.
     * @param int                              $mensaje_id    Fila de `client_assistant_messages`, solo para el log.
     *
     * @return array{partes: array<int, array<string, mixed>>, descartes: array<int, string>}
     *         `partes` ya tiene la forma que espera `Http::asMultipart()`; `descartes` son los
     *         motivos legibles de lo que quedó afuera, para dejarlos escritos en la fila.
     */
    public function preparar(array $medias, int $mensaje_id): array
    {
        $partes    = [];
        $descartes = [];

        $total = count($medias);
        if ($total > self::MAXIMO_DE_IMAGENES) {
            $sobrantes = $total - self::MAXIMO_DE_IMAGENES;
            $descartes[] = 'Se descartaron ' . $sobrantes . ' foto(s): el mensaje traía ' . $total
                . ' y el máximo es ' . self::MAXIMO_DE_IMAGENES . '.';

            /* Se quedan las PRIMERAS y no las últimas: en un envío múltiple de WhatsApp el orden es
             * el de captura, y la primera foto de una factura es la que trae el encabezado con el
             * proveedor y el número — que es justamente lo que el asistente necesita leer. */
            $medias = array_slice($medias, 0, self::MAXIMO_DE_IMAGENES);
        }

        $orden = 0;
        foreach ($medias as $media) {
            if (! is_array($media)) {
                continue;
            }

            $orden++;

            $mime_declarado = strtolower(trim((string) ($media['mime'] ?? '')));

            /* El mime declarado se mira ANTES de bajar nada: si el dueño mandó un PDF o un video, no
             * tiene sentido gastar la descarga para descartarlo después. Vacío sí pasa: hay payloads
             * de Kapso que no lo informan, y ahí manda lo que digan los bytes. */
            if ($mime_declarado !== '' && ! $this->es_mime_aceptado($mime_declarado)) {
                $descartes[] = 'Se descartó un adjunto de tipo ' . $mime_declarado . ': el asistente solo recibe fotos.';
                continue;
            }

            $binario = $this->media->descargar_binario($media);

            if ($binario === null) {
                /* 🔴 Sin la URL ni el id: la URL de Kapso viene firmada y el id identifica un
                 * adjunto de un cliente. Con la fila y el orden alcanza para encontrarlo. */
                Log::channel('daily')->warning('AsistenteImagenes: no se pudo bajar una foto del dueño.', [
                    'assistant_message_id' => $mensaje_id,
                    'orden'                => $orden,
                ]);

                $descartes[] = 'No se pudo descargar una de las fotos.';
                continue;
            }

            $bytes = strlen($binario);
            if ($bytes > self::MAXIMO_DE_BYTES) {
                $descartes[] = 'Se descartó una foto de ' . $this->en_megas($bytes)
                    . ' MB: el máximo es ' . $this->en_megas(self::MAXIMO_DE_BYTES) . ' MB.';
                continue;
            }

            /* 🔴 El tipo real sale de los BYTES y no de lo que declaró el payload. Es la misma regla
             * que ya sigue el recolector de imágenes del agente de soporte, y existe porque un
             * `mime` mentido —o ausente— termina en un `Content-Type` que no coincide con el
             * contenido, y del otro lado eso es una imagen que el modelo no puede leer. */
            $mime_real = $this->mime_de_los_bytes($binario);
            if ($mime_real === null) {
                $descartes[] = 'Se descartó un adjunto que no es una imagen válida.';
                continue;
            }

            $partes[] = [
                'name'     => 'imagenes[]',
                'contents' => $binario,
                'filename' => 'foto-' . $orden . '.' . $this->extension_de($mime_real),
                'headers'  => ['Content-Type' => $mime_real],
            ];
        }

        return ['partes' => $partes, 'descartes' => $descartes];
    }

    /**
     * La línea que se le pega al texto para que el asistente sepa que faltó una foto.
     *
     * Sin esto, el asistente contesta como si el dueño no hubiera mandado nada y el dueño se queda
     * sin entender por qué le preguntan de vuelta. Va entre corchetes, igual que el
     * `[Audio sin transcripción]` que ya usa el webhook para lo mismo: marca que es una nota del
     * sistema y no algo que escribió la persona.
     *
     * @param int $cantidad Cuántas fotos no llegaron.
     *
     * @return string
     */
    public function nota_para_el_asistente(int $cantidad): string
    {
        if ($cantidad < 1) {
            return '';
        }

        return $cantidad === 1
            ? '[El dueño mandó una foto que no se pudo recibir. Pedile que la mande de nuevo.]'
            : '[El dueño mandó ' . $cantidad . ' fotos que no se pudieron recibir. Pedile que las mande de nuevo.]';
    }

    /**
     * Indica si un mime es uno de los que acepta el escaneo del cliente.
     *
     * `image/jpg` no es un tipo real pero aparece en payloads viejos, así que se normaliza en vez de
     * descartarse: rechazar una factura por eso sería rechazarla por un error de otro.
     *
     * @param string $mime Mime en minúsculas.
     *
     * @return bool
     */
    private function es_mime_aceptado(string $mime): bool
    {
        if ($mime === 'image/jpg') {
            return true;
        }

        return in_array($mime, self::MIMES_ACEPTADOS, true);
    }

    /**
     * Tipo real de una imagen, leído de sus bytes.
     *
     * @param string $binario Bytes del archivo.
     *
     * @return string|null Mime aceptado, o null si no es una imagen o es de un tipo que no va.
     */
    private function mime_de_los_bytes(string $binario): ?string
    {
        $info = @getimagesizefromstring($binario);
        if ($info === false || empty($info['mime'])) {
            return null;
        }

        $mime = strtolower(trim((string) $info['mime']));
        if ($mime === 'image/jpg') {
            $mime = 'image/jpeg';
        }

        return in_array($mime, self::MIMES_ACEPTADOS, true) ? $mime : null;
    }

    /**
     * Extensión que le corresponde a un mime aceptado.
     *
     * @param string $mime Mime ya validado.
     *
     * @return string
     */
    private function extension_de(string $mime): string
    {
        switch ($mime) {
            case 'image/png':
                return 'png';
            case 'image/webp':
                return 'webp';
            default:
                return 'jpg';
        }
    }

    /**
     * Megabytes con un decimal, para los mensajes de descarte.
     *
     * @param int $bytes
     *
     * @return string
     */
    private function en_megas(int $bytes): string
    {
        return number_format($bytes / 1048576, 1, ',', '');
    }
}

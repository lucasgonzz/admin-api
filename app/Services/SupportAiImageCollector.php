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
 *   2. Un request con más de veinte imágenes activa un límite de dimensión más estricto de la
 *      API de Anthropic para TODAS las imágenes del request, no solo las que sobran. (Es de
 *      Anthropic, no de Meta: acá el request va a api.anthropic.com, WhatsApp no participa.)
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
     * 2,5 MB de archivo son unos 3,3 MB de base64. Es holgado para cualquier captura de
     * pantalla real y deja las tres imagenes bien lejos del limite del request (ver
     * MAX_BASE64_TOTAL). El tope anterior de 7 MB pasaba el limite POR IMAGEN por dos por
     * ciento y no miraba el acumulado en absoluto.
     */
    const MAX_BYTES_PER_IMAGE = 2621440;

    /**
     * Tope del acumulado de las tres imagenes, en bytes de base64.
     *
     * El limite del request entero de la API es 32 MB, y adentro de ese request tambien viajan
     * el system prompt, el historial y -en las iteraciones 2 a 5 del agentic loop- los archivos
     * del manual que el agente fue leyendo, sin truncar. Toparlo solo por imagen no alcanzaba:
     * tres imagenes en el tope viejo daban 29,4 MB de base64 y dejaban 2,6 MB para todo lo
     * demas, asi que a la tercera o cuarta iteracion el request se pasaba y la API devolvia un
     * error que dejaba al operador sin ninguna sugerencia.
     */
    const MAX_BASE64_TOTAL = 10485760;

    /**
     * Lado maximo, en pixeles. Arriba de esto la API rechaza el request entero.
     */
    const MAX_DIMENSION = 8000;

    /**
     * Formatos que la API acepta. No hay otros: un BMP o un HEIC se descartan.
     *
     * @var array<int, string>
     */
    const SUPPORTED_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /**
     * Cuántas imágenes se descartaron en la última corrida de collect().
     *
     * Sirve para avisarle al agente. Sin ese aviso, el historial le muestra que hubo una imagen
     * (la línea `[IMAGE]`), la imagen no le llega, y nadie le dice que no le llegó: es la receta
     * para que invente qué decía el cartel.
     *
     * @var int
     */
    private $descartadas = 0;

    /**
     * Cuántas imágenes quedaron afuera en la última corrida.
     *
     * @return int
     */
    public function descartadas(): int
    {
        return $this->descartadas;
    }

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
        $acumulado = 0;
        $this->descartadas = 0;

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
                    // Las que sobran tambien cuentan como descartadas: si no, el agente ve
                    // cinco [IMAGE] en el historial, recibe tres, y nadie le dice que faltan dos.
                    $this->descartadas++;

                    continue;
                }

                $imagen = $this->read_attachment($adjunto);
                if ($imagen === null) {
                    $this->descartadas++;

                    continue;
                }

                // Tope del acumulado: la que no entra se descarta, no se manda un request que
                // la API va a rechazar entero y que dejaria al operador sin sugerencia.
                $peso = strlen($imagen['data']);
                if ($acumulado + $peso > self::MAX_BASE64_TOTAL) {
                    $this->descartadas++;
                    Log::channel('daily')->info('SupportAiImageCollector: imagen descartada por el tope acumulado.', [
                        'attachment_id' => $adjunto->id,
                        'acumulado'     => $acumulado,
                    ]);

                    continue;
                }

                $acumulado += $peso;
                $imagenes[] = $imagen;
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
        // La columna se usa solo como filtro barato de primera pasada. Se normaliza porque el
        // mime lo escribe Kapso tal como venga: este mismo repo ya sabe que Meta manda
        // `image/jpg` (ver el mapa de WhatsappInboundMediaService::resolve_extension), y
        // tambien llegan mimes con parametros del tipo `image/jpeg; charset=binary`.
        $mime = $this->normalizar_mime((string) ($adjunto->mime ?? ''));
        if ($mime !== '' && ! in_array($mime, self::SUPPORTED_MIMES, true)) {
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

        // Re-chequeo con el tamaño de verdad y no con la columna `size`, que en los adjuntos
        // que vienen del ERP del cliente se copia sin validar.
        if (strlen($binario) > self::MAX_BYTES_PER_IMAGE) {
            return null;
        }

        // 🔴 El media_type que viaja sale de los BYTES, nunca de la columna. Si la columna dice
        // `image/png` y los bytes son JPEG, la API devuelve 400 y `generate()` sale sin ninguna
        // sugerencia: una columna mal cargada apagaria al agente para toda esa conversación.
        // De paso se leen las dimensiones, porque arriba de 8000px la API rechaza igual.
        $info = @getimagesizefromstring($binario);
        if ($info === false || empty($info['mime'])) {
            return null;
        }

        $mime_real = $this->normalizar_mime((string) $info['mime']);
        if (! in_array($mime_real, self::SUPPORTED_MIMES, true)) {
            return null;
        }

        if ((int) $info[0] > self::MAX_DIMENSION || (int) $info[1] > self::MAX_DIMENSION) {
            Log::channel('daily')->info('SupportAiImageCollector: imagen descartada por dimensiones.', [
                'attachment_id' => $adjunto->id,
                'ancho'         => $info[0],
                'alto'          => $info[1],
            ]);

            return null;
        }

        return [
            'media_type' => $mime_real,
            'data'       => base64_encode($binario),
        ];
    }

    /**
     * Normaliza un mime para poder compararlo.
     *
     * Baja a minusculas, corta los parametros despues del `;` y unifica `image/jpg`, que es
     * como lo reporta Meta a veces, con el `image/jpeg` que espera la API.
     *
     * @param string $mime Mime crudo.
     *
     * @return string
     */
    private function normalizar_mime(string $mime): string
    {
        $limpio = strtolower(trim($mime));

        $punto_y_coma = strpos($limpio, ';');
        if ($punto_y_coma !== false) {
            $limpio = trim(substr($limpio, 0, $punto_y_coma));
        }

        return $limpio === 'image/jpg' ? 'image/jpeg' : $limpio;
    }
}

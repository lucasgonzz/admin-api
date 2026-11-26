<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Parte la grabación de una llamada en tramos que OpenAI acepta (misión leads-referidos-compromisos, etapa E3).
 *
 * OpenAI recibe archivos de hasta 25 MB y sólo en algunos formatos; una llamada de 40 minutos pesa entre 19 y 400 MB según cómo la
 * grabó el celular. Con ffmpeg el audio se recomprime a mp3 mono de 16 kHz y 48 kbps (unos 3,6 MB cada 10 minutos, muy por debajo
 * del tope) y se corta en tramos de {@see tramo_segundos()}. Los cortes son en duro, sin solapamiento: puede partir una palabra, un
 * costo bajo para un resumen (mejora posible: cortar en los silencios con `silencedetect`; deuda técnica baja).
 *
 * 🔴 SIN CONSTRUCTOR a propósito: los tests lo reemplazan en el contenedor por una subclase que no corre nada, y dependen de que se
 * pueda instanciar sin colaboradores. El ejecutable y la duración se piden a {@see FfmpegLocator} (también del contenedor) en cada
 * llamada.
 *
 * Quien lo usa sólo lo invoca si `FfmpegLocator::path()` devuelve una ruta: sin ffmpeg no hay nada que cortar.
 */
class CortadorDeAudioEnTramos
{
    /** Segundos máximos para recomprimir UN tramo antes de matar a ffmpeg (cortar 10 minutos de audio tarda segundos). */
    const TIMEOUT_POR_TRAMO_SEGUNDOS = 300;

    /** Tope de tramos de una grabación (a 10 minutos son 20 horas de llamada): evita un bucle por una duración mal leída. */
    const MAX_TRAMOS = 120;

    /** Prefijo de la carpeta temporal donde quedan los tramos; {@see limpiar()} sólo borra carpetas con este prefijo. */
    const PREFIJO_DE_CARPETA = 'cc_tramos_';

    /** Argumentos de ffmpeg que comprimen cada tramo: mono, 16 kHz, mp3 de 48 kbps. */
    const CODEC = '-ac 1 -ar 16000 -c:a libmp3lame -b:a 48k';

    /**
     * Duración, en segundos, del último audio que se cortó (null si todavía no cortó ninguno): quien lo usa la guarda en la grabación.
     *
     * @var int|null
     */
    public $ultima_duracion = null;

    /**
     * Parte el audio en tramos y devuelve las rutas de los archivos, en orden.
     *
     * @param string $ruta_absoluta Archivo de audio original (no se modifica).
     * @param string $extension     Su extensión, en minúsculas (informativa: ffmpeg detecta el formato por el contenido).
     *
     * @return array<int, string> Rutas absolutas de los tramos mp3, en el orden de la llamada.
     *
     * @throws \RuntimeException Si ffmpeg no está, no se pudo leer la duración o falló al recomprimir algún tramo (con un texto
     *                           que se le muestra a Juan; sin datos de la llamada).
     */
    public function cortar(string $ruta_absoluta, string $extension): array
    {
        // Localizador de ffmpeg del contenedor (los tests lo reemplazan).
        $localizador = app(FfmpegLocator::class);
        // Ejecutable de ffmpeg, o null si el servidor no lo tiene.
        $ffmpeg = $localizador->path();
        if ($ffmpeg === null) {
            throw new \RuntimeException('El servidor no tiene ffmpeg para partir el audio.');
        }

        // Duración total del audio, en segundos.
        $duracion = $localizador->duracion_en_segundos($ffmpeg, $ruta_absoluta);
        if ($duracion === null) {
            throw new \RuntimeException('No se pudo leer la duración del audio: el archivo puede estar dañado o en un formato que ffmpeg no abre.');
        }

        $this->ultima_duracion = $duracion;

        // Segundos de cada tramo y cantidad de tramos que salen.
        $largo  = $this->tramo_segundos();
        $tramos = (int) ceil($duracion / $largo);
        if ($tramos < 1 || $tramos > self::MAX_TRAMOS) {
            throw new \RuntimeException('El audio dura demasiado para transcribirlo (más de ' . self::MAX_TRAMOS . ' tramos).');
        }

        // Carpeta temporal propia de esta grabación.
        $carpeta = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . self::PREFIJO_DE_CARPETA . bin2hex(random_bytes(6));
        if (! @mkdir($carpeta, 0700, true) && ! is_dir($carpeta)) {
            throw new \RuntimeException('No se pudo crear la carpeta temporal para partir el audio.');
        }

        // Rutas de los tramos ya hechos (si uno falla se borran todos).
        $rutas = [];

        for ($posicion = 0; $posicion < $tramos; $posicion++) {
            $salida = $carpeta . DIRECTORY_SEPARATOR . sprintf('tramo_%03d.mp3', $posicion + 1);
            // `-ss`/`-t` como opciones de SALIDA: ffmpeg decodifica hasta el punto de corte (preciso con cualquier formato).
            $motivo = $localizador->convert_audio(
                $ffmpeg,
                $ruta_absoluta,
                $salida,
                '-ss ' . ($posicion * $largo) . ' -t ' . $largo . ' ' . self::CODEC,
                self::TIMEOUT_POR_TRAMO_SEGUNDOS
            );

            if ($motivo !== null) {
                $this->limpiar($rutas);
                @rmdir($carpeta);
                Log::channel('daily')->warning('CortadorDeAudioEnTramos: ffmpeg no pudo armar un tramo.', ['tramo' => $posicion + 1, 'de' => $tramos]);

                throw new \RuntimeException('Falló al partir el audio en el tramo ' . ($posicion + 1) . ' de ' . $tramos . ': ' . $motivo);
            }

            $rutas[] = $salida;
        }

        return $rutas;
    }

    /**
     * Borra los tramos que dejó {@see cortar()} y su carpeta temporal (sólo si es una carpeta de este cortador y quedó vacía).
     * Nunca lanza: limpiar no puede romper una transcripción que ya salió.
     *
     * @param array<int, string> $rutas Rutas devueltas por `cortar()`.
     *
     * @return void
     */
    public function limpiar(array $rutas): void
    {
        $carpetas = [];
        foreach ($rutas as $ruta) {
            if (is_string($ruta) && is_file($ruta)) {
                @unlink($ruta);
            }
            $carpetas[dirname((string) $ruta)] = true;
        }

        foreach (array_keys($carpetas) as $carpeta) {
            if (strpos(basename($carpeta), self::PREFIJO_DE_CARPETA) === 0 && is_dir($carpeta)) {
                @rmdir($carpeta);
            }
        }
    }

    /**
     * Duración de cada tramo en segundos, de la configuración (mínimo 60 para que un valor mal puesto no genere cientos de tramos).
     *
     * @return int
     */
    public function tramo_segundos(): int
    {
        return max(60, (int) config('services.openai_call_transcription.tramo_segundos', 600));
    }
}

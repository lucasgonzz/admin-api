<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Encuentra ffmpeg en el servidor y convierte audio con él, degradando sin romper cuando no está.
 *
 * ffmpeg NO se puede dar por instalado: en el shared hosting casi nunca está y en el VPS depende de
 * cómo se armó cada máquina. Por eso todo lo que lo necesita (convertir un audio saliente a Ogg/Opus,
 * transcodificar un audio entrante para que lo reproduzca Safari) pregunta primero {@see path()} y
 * tiene un plan B cuando devuelve null.
 *
 * La detección vive en UN solo lugar (antes estaba escrita dentro de WhatsappSendService) y es
 * sobrescribible: los tests registran en el contenedor una subclase que devuelve null para probar el
 * camino "sin ffmpeg" aunque la máquina de desarrollo sí lo tenga.
 */
class FfmpegLocator
{
    /**
     * Ruta absoluta del ejecutable ffmpeg, o null si no está o no se puede ejecutar.
     *
     * @return string|null
     */
    public function path(): ?string
    {
        // exec() y shell_exec() pueden estar deshabilitadas (disable_functions): sin ellas no hay forma de correrlo.
        if (! function_exists('exec') || ! function_exists('shell_exec')) {
            return null;
        }

        // En Windows el buscador es `where`; en Linux/macOS, `which`.
        $command = DIRECTORY_SEPARATOR === '\\' ? 'where ffmpeg 2>NUL' : 'which ffmpeg 2>/dev/null';

        // Salida del buscador: una ruta por línea; se toma la primera.
        $output = trim((string) @shell_exec($command));
        if ($output === '') {
            return null;
        }

        $lines = preg_split('/\r\n|\r|\n/', $output);
        $first = trim((string) $lines[0]);

        return $first !== '' ? $first : null;
    }

    /**
     * Convierte un archivo de audio con ffmpeg.
     *
     * Si ffmpeg termina mal, el archivo de salida parcial se borra acá mismo. Cuando devuelve null
     * (éxito) el llamador es dueño del archivo de salida y tiene que borrarlo cuando termine de usarlo.
     *
     * @param string $ffmpeg_path     Ruta del ejecutable (de {@see path()}).
     * @param string $input_path      Archivo de origen.
     * @param string $output_path     Archivo de destino (la extensión elige el contenedor).
     * @param string $codec_arguments Argumentos de códec ya armados (p. ej. `-c:a libopus -b:a 32k`).
     *
     * @return string|null null si convirtió bien; texto del motivo si no.
     */
    public function convert_audio(string $ffmpeg_path, string $input_path, string $output_path, string $codec_arguments): ?string
    {
        // Línea de comando: -y pisa el destino, -vn descarta el video, -loglevel error deja solo lo crítico.
        $command = escapeshellarg($ffmpeg_path)
            . ' -y -i ' . escapeshellarg($input_path)
            . ' -vn ' . $codec_arguments
            . ' -loglevel error '
            . escapeshellarg($output_path)
            . ' 2>&1';

        // Líneas de salida de ffmpeg y código de salida.
        $output_lines = [];
        $exit_code = 1;

        try {
            exec($command, $output_lines, $exit_code);
        } catch (\Throwable $exception) {
            Log::channel('daily')->warning('FfmpegLocator: excepción al correr ffmpeg.', [
                'error' => $exception->getMessage(),
            ]);

            return 'ffmpeg no se pudo ejecutar: ' . $exception->getMessage();
        }

        if ($exit_code !== 0 || ! is_file($output_path) || filesize($output_path) === 0) {
            Log::channel('daily')->warning('FfmpegLocator: ffmpeg no pudo convertir el audio.', [
                'input'     => $input_path,
                'exit_code' => $exit_code,
                'output'    => implode(' ', $output_lines),
            ]);

            if (is_file($output_path)) {
                @unlink($output_path);
            }

            return 'ffmpeg no pudo convertir el audio (código ' . $exit_code . ').';
        }

        return null;
    }
}

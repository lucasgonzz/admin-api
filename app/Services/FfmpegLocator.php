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
     * Segundos máximos que se le dan a una conversión antes de matar a ffmpeg.
     *
     * @var int
     */
    const DEFAULT_TIMEOUT_SECONDS = 30;

    /**
     * Resultado cacheado del buscador (ruta o null) durante la vida del proceso PHP.
     *
     * Cada `which`/`where` es un proceso hijo: sin caché se pagaba uno por cada audio servido o enviado.
     *
     * @var string|null
     */
    protected static $cached_path = null;

    /**
     * Indica si {@see $cached_path} ya tiene un resultado (distingue "no buscado" de "buscado y no está").
     *
     * @var bool
     */
    protected static $path_was_looked_up = false;

    /**
     * Ruta absoluta del ejecutable ffmpeg, o null si no está o no se puede ejecutar.
     *
     * El resultado se cachea en memoria del proceso; una subclase de test que sobrescribe este método (o
     * {@see lookup_path()}) no depende de la caché.
     *
     * @return string|null
     */
    public function path(): ?string
    {
        if (! self::$path_was_looked_up) {
            self::$cached_path = $this->lookup_path();
            self::$path_was_looked_up = true;
        }

        return self::$cached_path;
    }

    /**
     * Olvida el resultado cacheado de {@see path()} (para tests y para un proceso de larga vida).
     *
     * @return void
     */
    public static function forget_cached_path(): void
    {
        self::$cached_path = null;
        self::$path_was_looked_up = false;
    }

    /**
     * Busca ffmpeg en el sistema (sin caché).
     *
     * @return string|null
     */
    protected function lookup_path(): ?string
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
     * Convierte un archivo de audio con ffmpeg, con tope de tiempo.
     *
     * Si ffmpeg termina mal, no termina a tiempo (se lo mata) o no deja el archivo de salida, el parcial se
     * borra acá mismo y se devuelve el motivo. Cuando devuelve null (éxito) el llamador es dueño del archivo
     * de salida y tiene que borrarlo (o moverlo) cuando termine de usarlo.
     *
     * @param string $ffmpeg_path     Ruta del ejecutable (de {@see path()}).
     * @param string $input_path      Archivo de origen.
     * @param string $output_path     Archivo de destino (la extensión elige el contenedor).
     * @param string $codec_arguments Argumentos de códec ya armados, separados por espacios (p. ej. `-c:a libopus -b:a 32k`).
     * @param int    $timeout_seconds Segundos máximos de ejecución; al vencer se mata a ffmpeg.
     *
     * @return string|null null si convirtió bien; texto del motivo si no.
     */
    public function convert_audio(
        string $ffmpeg_path,
        string $input_path,
        string $output_path,
        string $codec_arguments,
        int $timeout_seconds = self::DEFAULT_TIMEOUT_SECONDS
    ): ?string {
        // Argumentos del proceso, uno por elemento (sin shell: no hay nada que escapar ni inyectar).
        $arguments = $this->build_arguments($ffmpeg_path, $input_path, $output_path, $codec_arguments);

        // Resultado de la ejecución: código de salida, texto de salida y si venció el tope.
        $result = $this->run_process($arguments, $timeout_seconds);

        if ($result['timed_out']) {
            Log::channel('daily')->warning('FfmpegLocator: ffmpeg excedió el tope de tiempo y se lo mató.', [
                'input'   => $input_path,
                'timeout' => $timeout_seconds,
            ]);

            if (is_file($output_path)) {
                @unlink($output_path);
            }

            return 'ffmpeg tardó más de ' . $timeout_seconds . ' segundos y se canceló.';
        }

        if ($result['exit_code'] !== 0 || ! is_file($output_path) || filesize($output_path) === 0) {
            Log::channel('daily')->warning('FfmpegLocator: ffmpeg no pudo convertir el audio.', [
                'input'     => $input_path,
                'exit_code' => $result['exit_code'],
                'output'    => $result['output'],
            ]);

            if (is_file($output_path)) {
                @unlink($output_path);
            }

            return 'ffmpeg no pudo convertir el audio (código ' . $result['exit_code'] . ').';
        }

        return null;
    }

    /**
     * Arma el comando de ffmpeg como lista de argumentos.
     *
     * Es un método aparte (protegido) para poder sustituir el proceso por uno de prueba.
     *
     * @param string $ffmpeg_path     Ejecutable.
     * @param string $input_path      Archivo de origen.
     * @param string $output_path     Archivo de destino.
     * @param string $codec_arguments Argumentos de códec separados por espacios.
     *
     * @return array<int, string>
     */
    protected function build_arguments(string $ffmpeg_path, string $input_path, string $output_path, string $codec_arguments): array
    {
        // -y pisa el destino, -vn descarta el video, -loglevel error deja solo lo crítico.
        $arguments = [$ffmpeg_path, '-y', '-i', $input_path, '-vn'];

        $codec_parts = preg_split('/\s+/', trim($codec_arguments), -1, PREG_SPLIT_NO_EMPTY);
        foreach ($codec_parts as $part) {
            $arguments[] = $part;
        }

        $arguments[] = '-loglevel';
        $arguments[] = 'error';
        $arguments[] = $output_path;

        return $arguments;
    }

    /**
     * Ejecuta un proceso con tope de tiempo y lo mata si lo supera.
     *
     * Usa proc_open con la lista de argumentos (sin shell, así el kill alcanza a ffmpeg y no a un `sh`
     * intermedio, tanto en Linux como en Windows) y la salida a un archivo temporal. Si proc_open está deshabilitada, cae a exec() sin tope.
     *
     * @param array<int, string> $arguments       Ejecutable y argumentos.
     * @param int                $timeout_seconds Segundos máximos.
     *
     * @return array{exit_code: int, output: string, timed_out: bool}
     */
    protected function run_process(array $arguments, int $timeout_seconds): array
    {
        // Sin proc_open no hay forma de poner tope: se ejecuta como antes, por exec().
        if (! function_exists('proc_open')) {
            $command = implode(' ', array_map('escapeshellarg', $arguments)) . ' 2>&1';
            $output_lines = [];
            $exit_code = 1;
            try {
                exec($command, $output_lines, $exit_code);
            } catch (\Throwable $exception) {
                return ['exit_code' => 1, 'output' => $exception->getMessage(), 'timed_out' => false];
            }

            return ['exit_code' => (int) $exit_code, 'output' => implode(' ', $output_lines), 'timed_out' => false];
        }

        // stdout y stderr van a un archivo temporal (no a pipes): en Windows los pipes de proc_open no pueden
        // ser no bloqueantes y leerlos colgaba al proceso padre hasta que ffmpeg terminara, anulando el tope.
        $output_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ffmpeg_out_' . uniqid('', true) . '.log';
        $descriptors = [0 => ['pipe', 'r'], 1 => ['file', $output_file, 'a'], 2 => ['file', $output_file, 'a']];
        $pipes = [];

        try {
            $process = @proc_open($arguments, $descriptors, $pipes);
        } catch (\Throwable $exception) {
            @unlink($output_file);

            return ['exit_code' => 1, 'output' => $exception->getMessage(), 'timed_out' => false];
        }

        if (! is_resource($process)) {
            @unlink($output_file);

            return ['exit_code' => 1, 'output' => 'no se pudo iniciar el proceso', 'timed_out' => false];
        }

        fclose($pipes[0]);

        // Momento límite, código de salida y si venció el tope.
        $deadline = microtime(true) + max(1, $timeout_seconds);
        $exit_code = -1;
        $timed_out = false;

        while (true) {
            $status = proc_get_status($process);
            if (! $status['running']) {
                // El código de salida solo es válido en la PRIMERA lectura posterior al fin.
                $exit_code = (int) $status['exitcode'];
                break;
            }

            if (microtime(true) >= $deadline) {
                $timed_out = true;
                proc_terminate($process);
                break;
            }

            usleep(50000);
        }

        proc_close($process);

        // Salida acumulada (recortada) y limpieza del archivo.
        $output = is_file($output_file) ? (string) @file_get_contents($output_file) : '';
        @unlink($output_file);

        return ['exit_code' => $exit_code, 'output' => trim(substr($output, 0, 500)), 'timed_out' => $timed_out];
    }
}

<?php

namespace Tests\Fakes;

use App\Services\Prueba\PruebaVpsService;

/**
 * Reemplazo de `PruebaVpsService` para los tests: sobreescribe la ÚNICA costura que abre SSH
 * (`ejecutar()`) y contesta lo que cada script contestaría, sin tocar ningún servidor.
 *
 * Todo lo demás es código real: la validación de argumentos, el armado del comando, la lectura del JSON
 * de la última línea, el base64 de los archivos y el `sed` de las variables. Lo que un test afirma sobre
 * `$comandos`, `$archivos` y `$variables` es lo que de verdad viajaría al VPS.
 *
 * PHP 7.4.
 */
class PruebaVpsServiceFake extends PruebaVpsService
{
    /** Comandos crudos, en orden. */
    public $comandos = [];

    /** Archivos escritos con `escribir_archivo()`: ruta => contenido (decodificado del base64). */
    public $archivos = [];

    /** Variables escritas con `escribir_variables_env()`: ruta => [KEY => línea escrita]. */
    public $variables = [];

    /** Respuesta JSON por script (`pool-crear.sh` => [...]). Sin entrada, `['ok' => true]`. */
    public $respuestas = [];

    /** Scripts que fallan: nombre => mensaje de error (sale con exit 1 y un JSON de error). */
    public $fallas = [];

    public function responder(string $script, array $json): void
    {
        $this->respuestas[$script] = $json;
    }

    public function fallar(string $script, string $error): void
    {
        $this->fallas[$script] = $error;
    }

    /** Scripts que se invocaron, en orden (solo el nombre). */
    public function scripts_corridos(): array
    {
        $scripts = [];
        foreach ($this->comandos as $comando) {
            if (preg_match('#bash /root/prueba-gratuita/([a-z-]+\.sh)#', $comando, $m)) {
                $scripts[] = $m[1];
            }
        }

        return $scripts;
    }

    /** Los comandos que invocan un script dado. */
    public function comandos_de(string $script): array
    {
        return array_values(array_filter($this->comandos, function ($comando) use ($script) {
            return strpos($comando, '/root/prueba-gratuita/' . $script) !== false;
        }));
    }

    protected function ejecutar(string $comando, array $secretos): string
    {
        $this->comandos[] = $comando;

        if (preg_match('#bash /root/prueba-gratuita/([a-z-]+\.sh)#', $comando, $m)) {
            $script = $m[1];

            if (isset($this->fallas[$script])) {
                throw new \RuntimeException(
                    'El comando remoto falló (exit 1): ' . $comando . ' → ' . json_encode(['ok' => false, 'error' => $this->fallas[$script]])
                );
            }

            $json = isset($this->respuestas[$script]) ? $this->respuestas[$script] : ['ok' => true];

            return "linea de progreso\n" . json_encode($json) . "\n";
        }

        /* escribir_archivo(): printf %s '<b64>' | base64 -d > '<ruta>' ... */
        if (preg_match("#printf %s '([A-Za-z0-9+/=]+)' \| base64 -d > '([^']+)'#", $comando, $m)) {
            $this->archivos[$m[2]] = base64_decode($m[1]);

            return "ARCHIVO_OK\n";
        }

        /* escribir_variables_env(): `[ -f '<ruta>' ] || ...` seguido de un sed/printf por clave. */
        if (preg_match("#^\[ -f '([^']+)' \]#", $comando, $m)) {
            $ruta = $m[1];
            if (! isset($this->variables[$ruta])) {
                $this->variables[$ruta] = [];
            }
            if (preg_match_all("#printf '%s\\\\n' '([A-Z][A-Z0-9_]*)=([^']*)' >>#", $comando, $todas, PREG_SET_ORDER)) {
                foreach ($todas as $linea) {
                    $this->variables[$ruta][$linea[1]] = $linea[1] . '=' . $linea[2];
                }
            }

            return "ENV_OK\n";
        }

        return '';
    }
}

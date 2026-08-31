<?php

namespace Tests\Fakes;

use App\Services\RemoteCommandRunner;

/**
 * Reemplazo de RemoteCommandRunner para los tests: registra los comandos y devuelve salidas
 * preparadas, sin abrir una sola sesión SSH.
 *
 * 🔴 GUARDA LOS COMANDOS CRUDOS Y LOS REDACTADOS POR SEPARADO, y esa es la razón de ser del fake.
 * Con las dos listas un test puede afirmar las DOS cosas a la vez, que son distintas y las dos
 * importan:
 *
 *   - que el comando que se ejecutó es el correcto (con la contraseña de verdad adentro, porque el
 *     VPS la necesita) → $crudos;
 *   - que lo que se LOGUEA no tiene la contraseña → $redactados.
 *
 * Un fake que guardara una sola lista dejaría siempre una de las dos sin probar.
 *
 * 🔴 Sobreescribe la ejecución SSH —ejecutar()— y nada más de la lógica: la redacción, el armado
 * del mensaje de error y la decisión sobre el exit status son código real y corren de verdad en
 * cada test. El override de run() no cambia comportamiento: llama a la redacción REAL del padre
 * para anotar la línea y delega en parent::run().
 *
 * PHP 7.4.
 */
class RemoteCommandRunnerFake extends RemoteCommandRunner
{
    /**
     * Comandos tal cual se ejecutaron, con los secretos adentro.
     *
     * @var array<int, string>
     */
    public $crudos = [];

    /**
     * Los mismos comandos, ya pasados por la redacción real. Es lo que el runner manda al log.
     *
     * @var array<int, string>
     */
    public $redactados = [];

    /**
     * Salida por defecto de cualquier comando que no matchee ninguna regla.
     *
     * @var string
     */
    public $salida_por_defecto = '';

    /**
     * Reglas de salida, en orden de declaración. Gana la primera que matchea.
     *
     * Cada entrada: ['aguja' => string, 'salida' => string, 'exit' => int|false].
     *
     * @var array<int, array<string, mixed>>
     */
    private $salidas = [];

    /**
     * Prepara la salida de todo comando que contenga $aguja.
     *
     * @param  string     $aguja
     * @param  string     $salida
     * @param  int|false  $exit
     * @return void
     */
    public function responder(string $aguja, string $salida = '', $exit = 0): void
    {
        $this->salidas[] = ['aguja' => $aguja, 'salida' => $salida, 'exit' => $exit];
    }

    /**
     * Hace fallar todo comando que contenga $aguja.
     *
     * El texto de la salida es el que después clasifica clasificar_error(): es lo que permite
     * probar la idempotencia ("ya existe") y, sobre todo, el error desconocido, que tiene que hacer
     * fallar al llamador en vez de dar por bueno que el recurso estaba.
     *
     * @param  string  $aguja
     * @param  string  $salida
     * @param  int     $exit
     * @return void
     */
    public function fallar_con(string $aguja, string $salida, int $exit = 1): void
    {
        $this->salidas[] = ['aguja' => $aguja, 'salida' => $salida, 'exit' => $exit];
    }

    /**
     * Comandos crudos que contienen una aguja.
     *
     * @param  string  $aguja
     * @return array<int, string>
     */
    public function crudos_con(string $aguja): array
    {
        $encontrados = [];

        foreach ($this->crudos as $comando) {
            if (strpos($comando, $aguja) !== false) {
                $encontrados[] = $comando;
            }
        }

        return $encontrados;
    }

    /**
     * Todo lo que se logueó, junto, para asertar que un secreto no aparece en ningún lado.
     *
     * @return string
     */
    public function texto_redactado(): string
    {
        return implode("\n", $this->redactados);
    }

    /**
     * Anota la línea redactada —con la redacción REAL del padre— y delega.
     *
     * @param  string              $command
     * @param  array<int, string>  $secretos
     * @param  bool                $must_succeed
     * @return string
     */
    public function run(string $command, array $secretos = [], bool $must_succeed = true): string
    {
        $this->redactados[] = $this->redactar($command, $secretos);

        return parent::run($command, $secretos, $must_succeed);
    }

    /**
     * Registra el comando crudo y devuelve la salida preparada, sin tocar la red.
     *
     * @param  string  $command
     * @return array<string, mixed>
     */
    protected function ejecutar(string $command): array
    {
        $this->crudos[] = $command;

        foreach ($this->salidas as $regla) {
            if (strpos($command, (string) $regla['aguja']) !== false) {
                return ['salida' => (string) $regla['salida'], 'exit' => $regla['exit']];
            }
        }

        return ['salida' => $this->salida_por_defecto, 'exit' => 0];
    }
}

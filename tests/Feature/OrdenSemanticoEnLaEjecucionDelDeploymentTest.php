<?php

namespace Tests\Feature;

use App\Models\UpdateCommand;
use App\Models\UpdateSeeder;
use App\Models\Version;
use App\Models\VersionCommand;
use App\Models\VersionSeeder;
use App\Services\DeploymentService;
use Tests\TestCase;

/**
 * `DeploymentService` es el ÚNICO camino que ejecuta de verdad seeders y comandos contra
 * el servidor de un cliente. Ordenaba por `id` de la versión, el mismo criterio que esta
 * misión corrigió en todo el resto del sistema: con un hotfix cargado después de una
 * minor posterior (mismo caso que motivó `VersionNumberComparator`), los seeders corrían
 * fuera de orden aunque la interfaz mostrara el orden correcto.
 *
 * Se prueba el comparador (`compare_update_items`) a través de reflection: es privado y
 * `step_run_seeders()`/`step_run_commands()` no se pueden ejercitar sin SSH real ni mocks
 * de `phpseclib`, que este repo no tiene. Lo que sí se cubre acá es exactamente el
 * criterio de orden que esos dos métodos aplican antes de ejecutar nada.
 */
class OrdenSemanticoEnLaEjecucionDelDeploymentTest extends TestCase
{
    /**
     * Instancia de DeploymentService sin pasar por el constructor (que exige API destino
     * y credencial SSH). Solo se usa para invocar el comparador.
     *
     * @return DeploymentService
     */
    private function service_sin_constructor(): DeploymentService
    {
        $reflection = new \ReflectionClass(DeploymentService::class);

        return $reflection->newInstanceWithoutConstructor();
    }

    /**
     * @param  mixed  $a
     * @param  mixed  $b
     * @param  string  $relacion_padre
     * @return int
     */
    private function comparar($a, $b, string $relacion_padre): int
    {
        $metodo = new \ReflectionMethod(DeploymentService::class, 'compare_update_items');
        $metodo->setAccessible(true);

        return (int) $metodo->invoke($this->service_sin_constructor(), $a, $b, $relacion_padre);
    }

    /**
     * Version en memoria (sin tocar la base): solo importan `id` y `version`.
     *
     * @param  int  $id
     * @param  string  $codigo
     * @return Version
     */
    private function version_en_memoria(int $id, string $codigo): Version
    {
        $version          = new Version();
        $version->id      = $id;
        $version->version = $codigo;

        return $version;
    }

    /**
     * @param  int  $id
     * @param  Version  $version
     * @param  int  $execution_order
     * @return UpdateSeeder
     */
    private function update_seeder_en_memoria(int $id, Version $version, int $execution_order = 1): UpdateSeeder
    {
        $version_seeder                  = new VersionSeeder();
        $version_seeder->execution_order = $execution_order;
        $version_seeder->setRelation('version', $version);

        $update_seeder     = new UpdateSeeder();
        $update_seeder->id = $id;
        $update_seeder->setRelation('version_seeder', $version_seeder);

        return $update_seeder;
    }

    /**
     * @param  int  $id
     * @param  Version  $version
     * @param  int  $execution_order
     * @return UpdateCommand
     */
    private function update_command_en_memoria(int $id, Version $version, int $execution_order = 1): UpdateCommand
    {
        $version_command                  = new VersionCommand();
        $version_command->execution_order = $execution_order;
        $version_command->setRelation('version', $version);

        $update_command     = new UpdateCommand();
        $update_command->id = $id;
        $update_command->setRelation('version_command', $version_command);

        return $update_command;
    }

    /**
     * El caso que rompía: el hotfix 3.4.1.1 tiene el `id` más alto de los tres (se cargó
     * último) pero semánticamente va en el medio. Ordenando por `id` corría al final.
     *
     * @return void
     */
    public function test_los_seeders_se_ordenan_por_codigo_de_version_y_no_por_id(): void
    {
        $v_3_4_1   = $this->version_en_memoria(10, '3.4.1');
        $v_3_4_2   = $this->version_en_memoria(11, '3.4.2');
        $v_3_4_1_1 = $this->version_en_memoria(99, '3.4.1.1');

        $seeder_3_4_1   = $this->update_seeder_en_memoria(1, $v_3_4_1);
        $seeder_3_4_2   = $this->update_seeder_en_memoria(2, $v_3_4_2);
        $seeder_hotfix  = $this->update_seeder_en_memoria(3, $v_3_4_1_1);

        $ordenados = collect([$seeder_3_4_2, $seeder_hotfix, $seeder_3_4_1])
            ->sort(function ($a, $b) {
                return $this->comparar($a, $b, 'version_seeder');
            })
            ->values();

        $codigos = $ordenados->map(function ($update_seeder) {
            return $update_seeder->version_seeder->version->version;
        })->all();

        $this->assertSame(['3.4.1', '3.4.1.1', '3.4.2'], $codigos);
    }

    /**
     * Mismo criterio para los comandos.
     *
     * @return void
     */
    public function test_los_comandos_se_ordenan_por_codigo_de_version_y_no_por_id(): void
    {
        $v_3_4_1   = $this->version_en_memoria(10, '3.4.1');
        $v_3_4_2   = $this->version_en_memoria(11, '3.4.2');
        $v_3_4_1_1 = $this->version_en_memoria(99, '3.4.1.1');

        $comando_3_4_1  = $this->update_command_en_memoria(1, $v_3_4_1);
        $comando_3_4_2  = $this->update_command_en_memoria(2, $v_3_4_2);
        $comando_hotfix = $this->update_command_en_memoria(3, $v_3_4_1_1);

        $ordenados = collect([$comando_3_4_2, $comando_hotfix, $comando_3_4_1])
            ->sort(function ($a, $b) {
                return $this->comparar($a, $b, 'version_command');
            })
            ->values();

        $codigos = $ordenados->map(function ($update_command) {
            return $update_command->version_command->version->version;
        })->all();

        $this->assertSame(['3.4.1', '3.4.1.1', '3.4.2'], $codigos);
    }

    /**
     * Dentro de la MISMA versión el desempate sigue siendo `execution_order` y después el
     * `id` del ítem, igual que antes.
     *
     * @return void
     */
    public function test_dentro_de_la_misma_version_desempata_execution_order_y_despues_el_id(): void
    {
        $version = $this->version_en_memoria(10, '3.4.1');

        $primero = $this->update_seeder_en_memoria(50, $version, 1);
        $segundo = $this->update_seeder_en_memoria(20, $version, 2);
        $tercero = $this->update_seeder_en_memoria(80, $version, 2);

        $this->assertLessThan(0, $this->comparar($primero, $segundo, 'version_seeder'));
        $this->assertLessThan(0, $this->comparar($segundo, $tercero, 'version_seeder'));
        $this->assertGreaterThan(0, $this->comparar($tercero, $primero, 'version_seeder'));
    }
}

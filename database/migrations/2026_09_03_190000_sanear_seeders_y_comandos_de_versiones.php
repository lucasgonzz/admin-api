<?php

use App\Services\VersionItemSanitizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Deja `version_seeders.seeder_class` y `version_commands.command` en su forma saneada.
 *
 * 🔴 NO ES COSMETICA: SIN ESTO, REPUBLICAR UN GRUPO DUPLICA LOS ITEMS.
 *
 * `ClaudeVersionItemsIngestController::upsert_items()` busca la fila existente por CLAVE NATURAL:
 * `seeder_class` para los seeders y `command` para los comandos. Desde que la ingesta sanea el
 * payload antes del upsert, lo que se busca es la forma limpia (`Xxx`, `php artisan migrate
 * --force`) mientras que lo guardado en la base es la sucia (`Database\Seeders\Xxx`, `php artisan
 * migrate`). No matchean: el upsert no encuentra nada, crea una fila nueva, y la vieja queda —
 * porque el endpoint de ingesta no borra nunca. El resultado es el item DOS veces, y uno de ellos
 * todavia con el defecto que rompia el deployment.
 *
 * Lo levanto la verificacion independiente de la mision. Esta migracion alinea lo guardado con lo
 * que la ingesta va a buscar de ahora en mas, y con eso la idempotencia vuelve a funcionar.
 *
 * Es idempotente: lo que ya esta saneado no se toca.
 */
class SanearSeedersYComandosDeVersiones extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        $this->sanear(
            'version_seeders',
            'seeder_class',
            function ($valor) {
                return VersionItemSanitizer::sanear_seeder_class($valor);
            }
        );

        $this->sanear(
            'version_commands',
            'command',
            function ($valor) {
                return VersionItemSanitizer::sanear_comando($valor);
            }
        );
    }

    /**
     * Sanea un campo, resolviendo las colisiones que el saneamiento pueda crear.
     *
     * Sanear puede hacer que dos filas de la misma version terminen con el mismo valor (por
     * ejemplo `Database\Seeders\Xxx` y `Xxx` conviviendo). En ese caso se conserva UNA —la de id
     * mas bajo, que es la mas vieja y la que probablemente tenga el `execution_order` correcto— y
     * se borra la otra. Dejar las dos reintroduciria el duplicado que esto viene a evitar.
     *
     * @param  string    $tabla
     * @param  string    $campo
     * @param  callable  $sanear
     * @return void
     */
    private function sanear(string $tabla, string $campo, callable $sanear)
    {
        $cambiadas = 0;
        $borradas  = 0;

        foreach (DB::table($tabla)->orderBy('id')->get() as $fila) {
            $original = (string) $fila->{$campo};
            $saneado  = $sanear($original);

            if ($saneado === $original) {
                continue;
            }

            /* ¿Ya hay otra fila de esta misma version con el valor saneado? */
            $ya_existe = DB::table($tabla)
                ->where('version_id', $fila->version_id)
                ->where($campo, $saneado)
                ->where('id', '<>', $fila->id)
                ->exists();

            if ($ya_existe) {
                DB::table($tabla)->where('id', $fila->id)->delete();
                $borradas++;
                continue;
            }

            DB::table($tabla)->where('id', $fila->id)->update([$campo => $saneado]);
            $cambiadas++;
        }

        if ($cambiadas || $borradas) {
            Log::info(
                'Migracion sanear_seeders_y_comandos_de_versiones: ' . $tabla . '.' . $campo
                . ' — ' . $cambiadas . ' fila(s) saneada(s), ' . $borradas . ' duplicada(s) borrada(s).'
            );
        }
    }

    /**
     * No se revierte: volver a ensuciar el dato reintroduciria el defecto que rompio dos
     * deployments de masquito el 3/9/2026.
     *
     * @return void
     */
    public function down()
    {
        //
    }
}

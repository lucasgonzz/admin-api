<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Quién puso los títulos que ya existían (misión cruzada `ficha-del-cliente`, 11/11/2026, arreglo de la revisión):
 * antes de esta misión no se sabía, y Martín solo puede reemplazar un título con `name_source` null, `martin` o
 * `sistema`. Para no pisarle a nadie un nombre que puso a mano, los `name` no vacíos que todavía no tienen origen
 * pasan a `lucas`, salvo los de tickets del sistema del cliente (`source = erp`), que son el asunto que escribió el
 * cliente → `cliente` (además se sincronizan a su sistema).
 *
 * Solo toca filas con `name_source` null. El `down()` no deshace: después de correrla ya no se puede distinguir lo
 * que marcó esta migración de lo que se marcó a mano, y volverlo a null le devolvería a Martín títulos de Lucas.
 */
class MarcarNameSourceDeLosTitulosExistentes extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        DB::table('support_tickets')
            ->whereNull('name_source')
            ->whereNotNull('name')
            ->whereRaw("TRIM(name) <> ''")
            ->where('source', 'erp')
            ->update(['name_source' => 'cliente']);

        DB::table('support_tickets')
            ->whereNull('name_source')
            ->whereNotNull('name')
            ->whereRaw("TRIM(name) <> ''")
            ->update(['name_source' => 'lucas']);
    }

    /**
     * @return void
     */
    public function down()
    {
        // A propósito vacío (ver el docblock de la clase).
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Agranda la columna `log` de demo_updates de TEXT (65.535 bytes) a LONGTEXT.
 *
 * Motivo (13/7/2026): el pipeline vuelca la salida completa de `npm run build` al log
 * (~60 KB solo el build, más los comandos SSH que se loguean enteros). Al cruzar los
 * 65.535 bytes, MySQL en modo estricto tira SQLSTATE[22001] y el job muere sin poder
 * marcar el registro como fallido — quedaba en `ejecutandose` para siempre.
 */
class ChangeLogToLongtextInDemoUpdatesTable extends Migration
{
    /**
     * Aplica el cambio de tipo.
     *
     * @return void
     */
    public function up()
    {
        DB::statement('ALTER TABLE demo_updates MODIFY log LONGTEXT NULL');
    }

    /**
     * Revierte a TEXT. Atención: los logs que superen 65.535 bytes se truncan al revertir.
     *
     * @return void
     */
    public function down()
    {
        DB::statement('ALTER TABLE demo_updates MODIFY log TEXT NULL');
    }
}

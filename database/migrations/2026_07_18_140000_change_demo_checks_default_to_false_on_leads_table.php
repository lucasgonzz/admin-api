<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cambia el DEFAULT de las columnas `auto_check_ingreso_demo` y `auto_check_fin_demo`
 * de la tabla `leads` de `true` a `false`.
 *
 * Nueva dinámica (17/7/2026): Martín manda a mano el check de ingreso y el de fin
 * de demo; el recordatorio pre-demo sigue automático (auto_recordatorio_demo no cambia).
 * Cambio de DEFAULT de columna únicamente -- no retroactivo, los leads existentes
 * conservan el valor que ya tenían.
 */
class ChangeDemoChecksDefaultToFalseOnLeadsTable extends Migration
{
    /**
     * Ejecuta la migración: cambia el default de las dos columnas a false.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->boolean('auto_check_ingreso_demo')->default(false)->change();
            $table->boolean('auto_check_fin_demo')->default(false)->change();
        });
    }

    /**
     * Revierte la migración: vuelve el default de las dos columnas a true.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->boolean('auto_check_ingreso_demo')->default(true)->change();
            $table->boolean('auto_check_fin_demo')->default(true)->change();
        });
    }
}

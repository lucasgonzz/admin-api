<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega el campo `solo_si_ingreso_confirmado` a followup_templates.
 *
 * Permite bifurcar el seguimiento para leads en estado demo_agendada:
 * - false (default): aplica a leads que NO confirmaron ingreso a la demo ("¿pudiste hacerla?")
 * - true: aplica a leads que SÍ confirmaron ingreso pero no confirmaron que terminaron ("¿pudiste terminarla?")
 */
class AddSoloSiIngresoConfirmadoToFollowupTemplatesTable extends Migration
{
    /**
     * Ejecuta la migración agregando la columna booleana con default false.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('followup_templates', function (Blueprint $table) {
            /*
             * Cuando es true, esta plantilla solo aplica a leads con demo_ingreso_confirmado = true.
             * Cuando es false (default), aplica a leads con demo_ingreso_confirmado = false o null.
             */
            $table->boolean('solo_si_ingreso_confirmado')->default(false)->after('activa');
        });
    }

    /**
     * Revierte la migración eliminando la columna agregada.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('followup_templates', function (Blueprint $table) {
            $table->dropColumn('solo_si_ingreso_confirmado');
        });
    }
}

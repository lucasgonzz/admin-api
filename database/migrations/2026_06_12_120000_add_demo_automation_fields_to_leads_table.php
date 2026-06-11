<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega campos de automatización de demo a la tabla leads.
 *
 * - demo_check_ingreso_enviado: flag para evitar duplicar el check de ingreso
 * - demo_summary: resumen del lead generado por Claude antes del fin de la demo
 */
class AddDemoAutomationFieldsToLeadsTable extends Migration
{
    /**
     * Agrega los dos campos de automatización de demo.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('leads', function (Blueprint $table) {
            /* Flag que evita enviar múltiples check-ins de ingreso por demo. */
            $table->boolean('demo_check_ingreso_enviado')->default(false)->after('recordatorio_demo_enviado');

            /* Resumen del lead generado por Claude X minutos antes del fin de la demo (para el closer). */
            $table->text('demo_summary')->nullable()->after('demo_check_ingreso_enviado');
        });
    }

    /**
     * Elimina los campos de automatización de demo.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['demo_check_ingreso_enviado', 'demo_summary']);
        });
    }
}

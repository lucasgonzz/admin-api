<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega toggles de automatización del ciclo de demo por lead: permiten que el admin apague,
 * lead por lead, las automatizaciones puntuales (recordatorio, check de ingreso, check de fin,
 * resumen para el closer) o directamente todo el ciclo con el flag master
 * `automatizaciones_demo_activas`. Todas con default(true) para que los leads existentes
 * mantengan el comportamiento actual (automatización activa) sin cambios. Solo esquema, sin
 * lógica todavía (prompt 318).
 */
class AddAutomationTogglesToLeadsTable extends Migration
{
    /**
     * Agrega las 5 columnas booleanas después de claude_auto_reply.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('leads', function (Blueprint $table) {
            // Master: false = "lo manejo yo, no automatices nada del ciclo de demo".
            $table->boolean('automatizaciones_demo_activas')->default(true)->after('claude_auto_reply');
            $table->boolean('auto_recordatorio_demo')->default(true)->after('automatizaciones_demo_activas');
            $table->boolean('auto_check_ingreso_demo')->default(true)->after('auto_recordatorio_demo');
            $table->boolean('auto_check_fin_demo')->default(true)->after('auto_check_ingreso_demo');
            $table->boolean('auto_resumen_closer')->default(true)->after('auto_check_fin_demo');
        });
    }

    /**
     * Elimina las columnas.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn([
                'automatizaciones_demo_activas',
                'auto_recordatorio_demo',
                'auto_check_ingreso_demo',
                'auto_check_fin_demo',
                'auto_resumen_closer',
            ]);
        });
    }
}

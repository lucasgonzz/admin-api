<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trazabilidad de acciones del pipeline comercial de demo.
 *
 * Registra cuándo se ejecutó cada automatización y si fue manual o automática,
 * para mostrar historial y permitir re-ejecución desde admin-spa.
 */
class AddPipelineActionTraceFieldsToLeadsTable extends Migration
{
    /**
     * Agrega timestamps y flags manual/automático para acciones del pipeline.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('leads', function (Blueprint $table) {
            /* Demo setup: indica si la última corrida fue disparada desde admin-spa. */
            $table->boolean('demo_setup_last_run_manual')->nullable()->after('demo_setup_last_run_at');

            /* Recordatorio pre-demo: cuándo se generó y si fue manual o scheduler. */
            $table->timestamp('recordatorio_demo_enviado_at')->nullable()->after('recordatorio_demo_enviado');
            $table->boolean('recordatorio_demo_manual')->nullable()->after('recordatorio_demo_enviado_at');

            /* Check de ingreso post-demo. */
            $table->timestamp('demo_check_ingreso_enviado_at')->nullable()->after('demo_check_ingreso_enviado');
            $table->boolean('demo_check_ingreso_manual')->nullable()->after('demo_check_ingreso_enviado_at');

            /* Resumen para el closer generado por Claude. */
            $table->timestamp('demo_summary_generated_at')->nullable()->after('demo_summary');
            $table->boolean('demo_summary_manual')->nullable()->after('demo_summary_generated_at');
        });
    }

    /**
     * Elimina los campos de trazabilidad del pipeline.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn([
                'demo_setup_last_run_manual',
                'recordatorio_demo_enviado_at',
                'recordatorio_demo_manual',
                'demo_check_ingreso_enviado_at',
                'demo_check_ingreso_manual',
                'demo_summary_generated_at',
                'demo_summary_manual',
            ]);
        });
    }
}

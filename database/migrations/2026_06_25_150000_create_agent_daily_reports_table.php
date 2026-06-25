<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea la tabla agent_daily_reports para registrar los reportes diarios/semanales generados por el agente analizador.
 * Cada fila apunta a un archivo markdown en storage y guarda un resumen ejecutivo y métricas clave.
 */
class CreateAgentDailyReportsTable extends Migration
{
    /**
     * Ejecuta la migración creando la tabla agent_daily_reports.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('agent_daily_reports', function (Blueprint $table) {
            $table->id();

            // Fecha del período cubierto por el reporte (única por día).
            $table->date('report_date')->unique()->index();

            // Tipo de reporte: diario o semanal (lunes).
            $table->enum('report_type', ['daily', 'weekly']);

            // Ruta relativa dentro de storage/app/: 'agent_reports/2026-06-25.md'
            $table->string('file_path', 255)->nullable();

            // Resumen ejecutivo de 2-3 líneas para mostrar en el panel sin descargar el archivo.
            $table->text('executive_summary')->nullable();

            // Número de alertas detectadas (mensajes con error, leads caídos, etc.).
            $table->unsignedInteger('alert_count')->default(0);

            // Número de leads con actividad en el período.
            $table->unsignedInteger('active_leads_count')->default(0);

            // JSON con métricas clave del período (para visualización rápida en el panel).
            $table->json('metrics_snapshot')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Revierte la migración eliminando la tabla agent_daily_reports.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('agent_daily_reports');
    }
}

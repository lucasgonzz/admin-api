<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega al lead la fecha en que se asignó la demo y la ventana horaria
 * acordada para su uso (inicio y fin del día).
 */
class AddDemoUsageScheduleToLeadsTable extends Migration
{
    /**
     * Aplica columnas opcionales de agenda de demo sobre `leads`.
     */
    public function up()
    {
        Schema::table('leads', function (Blueprint $table) {
            // Día en que el operador registró la asignación de la demo al prospecto.
            $table->date('demo_assigned_on')->nullable()->after('demo_id');
            // Horario local de inicio y fin del uso de la demo (solo hora del día).
            $table->time('demo_usage_start_time')->nullable()->after('demo_assigned_on');
            $table->time('demo_usage_end_time')->nullable()->after('demo_usage_start_time');
        });
    }

    /**
     * Revierte las columnas de agenda de demo.
     */
    public function down()
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn([
                'demo_assigned_on',
                'demo_usage_start_time',
                'demo_usage_end_time',
            ]);
        });
    }
}

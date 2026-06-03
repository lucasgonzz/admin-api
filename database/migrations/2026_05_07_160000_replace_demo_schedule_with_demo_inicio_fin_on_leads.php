<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sustituye fecha + rango horario por inicio y fin de demo como timestamps completos.
 */
class ReplaceDemoScheduleWithDemoInicioFinOnLeads extends Migration
{
    /**
     * Elimina columnas previas de agenda y agrega demo_inicio / demo_fin.
     */
    public function up()
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn([
                'demo_assigned_on',
                'demo_usage_start_time',
                'demo_usage_end_time',
            ]);
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->timestamp('demo_inicio')->nullable()->after('demo_id');
            $table->timestamp('demo_fin')->nullable()->after('demo_inicio');
        });
    }

    /**
     * Restaura el esquema anterior de agenda de demo.
     */
    public function down()
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['demo_inicio', 'demo_fin']);
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->date('demo_assigned_on')->nullable()->after('demo_id');
            $table->time('demo_usage_start_time')->nullable()->after('demo_assigned_on');
            $table->time('demo_usage_end_time')->nullable()->after('demo_usage_start_time');
        });
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sustituye timestamps demo_inicio/demo_fin por fecha de demo + horas de inicio/fin en texto.
 */
class ReplaceDemoInicioFinWithDemoDateAndTimesOnLeads extends Migration
{
    /**
     * Reemplaza columnas de agenda de demo.
     */
    public function up()
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['demo_inicio', 'demo_fin']);
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->date('demo_date')->nullable()->after('demo_id');
            $table->string('demo_start_time', 32)->nullable()->after('demo_date');
            $table->string('demo_end_time', 32)->nullable()->after('demo_start_time');
        });
    }

    /**
     * Restaura demo_inicio y demo_fin.
     */
    public function down()
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['demo_date', 'demo_start_time', 'demo_end_time']);
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->timestamp('demo_inicio')->nullable()->after('demo_id');
            $table->timestamp('demo_fin')->nullable()->after('demo_inicio');
        });
    }
}

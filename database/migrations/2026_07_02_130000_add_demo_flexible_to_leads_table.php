<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega demo_flexible a leads: cuando está activo, la demo asignada (demo_id/demo_date/
 * demo_start_time/demo_end_time) sigue bloqueando la demo física normalmente, pero NO reserva
 * automáticamente una ventana de llamada para el closer — la coordinación de esa llamada queda
 * fuera del cálculo automático (se hace manual, cuando el lead confirma que terminó la demo).
 * Uso típico: leads que no pueden comprometerse a un horario puntual y se les deja la demo
 * abierta durante un rango amplio (ej. todo un día).
 */
class AddDemoFlexibleToLeadsTable extends Migration
{
    public function up()
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->boolean('demo_flexible')
                  ->default(false)
                  ->after('demo_end_time')
                  ->comment('Si está activo, la demo asignada no reserva automáticamente ventana de llamada para el closer.');
        });
    }

    public function down()
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('demo_flexible');
        });
    }
}

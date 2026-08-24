<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rangos horarios de un día del horario comercial de un cliente.
 *
 * Cada día tiene por defecto un rango y puede tener todos los que haga falta (ej. 8:00–13:00 y
 * 16:00–21:00 el mismo día).
 *
 * 🔴 Cero filas para un client_schedule_day = ese día el negocio está CERRADO. Es el contrato, y
 * es lo que eligió Lucas por sobre un checkbox `cerrado`.
 *
 * 🔴 Un rango no puede cruzar la medianoche: se exige end_time > start_time. Un rango 20:00–02:00
 * pertenece a dos días distintos y eso duplicaría la complejidad del resolvedor entero. Convención
 * declarada: un negocio que cierra a medianoche o después se carga con end_time = '23:59'.
 */
class CreateClientScheduleRangesTable extends Migration
{
    /**
     * Crea la tabla client_schedule_ranges.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('client_schedule_ranges', function (Blueprint $table) {
            // Identificador interno.
            $table->id();

            // Identificador público para rutas y UI.
            $table->uuid('uuid');

            // Día del horario al que pertenece este rango.
            $table->unsignedBigInteger('client_schedule_day_id')->index();

            // Hora de apertura.
            $table->time('start_time');

            // Hora de cierre. Siempre posterior a start_time (no se cruza la medianoche).
            $table->time('end_time');

            // Orden de presentación; se asigna por start_time ascendente al guardar.
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->foreign('client_schedule_day_id')
                ->references('id')
                ->on('client_schedule_days')
                ->onDelete('cascade');
        });
    }

    /**
     * Elimina la tabla client_schedule_ranges.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('client_schedule_ranges');
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Días del horario comercial de un cliente.
 *
 * 🔴 La fila del día existe por sí misma, independientemente de si tiene rangos o no: ese es
 * todo el motivo por el que los horarios son DOS tablas y no una. Con una sola tabla
 * (client_id, dia, desde, hasta), "el martes cerramos" sería cero filas del martes, y cero filas
 * sería indistinguible de "el martes no está configurado, heredá de Todos los días". Existir es
 * el hecho: día con fila y sin rangos = cerrado; día sin fila = hereda de la fila 'todos'.
 *
 * No se agrega ninguna columna `cerrado` ni ninguna `day_of_week` numérica: las dos serían estado
 * derivado guardado en su propio slot, capaz de contradecir a la fuente.
 */
class CreateClientScheduleDaysTable extends Migration
{
    /**
     * Crea la tabla client_schedule_days.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('client_schedule_days', function (Blueprint $table) {
            // Identificador interno.
            $table->id();

            // Identificador público para rutas y UI.
            $table->uuid('uuid');

            // Cliente dueño de la fila.
            $table->unsignedBigInteger('client_id')->index();

            // 'todos' | 'lunes' | 'martes' | 'miercoles' | 'jueves' | 'viernes' | 'sabado' | 'domingo'.
            // 🔴 Sin acentos y sin espacios: es un contrato de enumeración entre cliente y servidor,
            // declarado una sola vez en ClientScheduleDay::DAY_KEYS y expuesto por API.
            $table->string('day_key', 20);

            $table->timestamps();

            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');

            // Un cliente no puede tener dos filas del mismo día, aunque el controlador falle.
            $table->unique(['client_id', 'day_key']);
        });
    }

    /**
     * Elimina la tabla client_schedule_days.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('client_schedule_days');
    }
}

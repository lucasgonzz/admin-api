<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega la columna google_event_id a la tabla leads.
 *
 * Almacena el ID del evento creado en Google Calendar del closer
 * cuando se agenda una demo, para poder actualizarlo o eliminarlo
 * si la demo se reagenda o cancela.
 */
class AddGoogleEventIdToLeadsTable extends Migration
{
    /**
     * Ejecuta la migración: agrega la columna google_event_id.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('leads', function (Blueprint $table) {
            // ID del evento en Google Calendar del closer; null si no se creó evento.
            $table->string('google_event_id')->nullable()->after('closer_notified_at');
        });
    }

    /**
     * Revierte la migración: elimina la columna google_event_id.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('google_event_id');
        });
    }
}

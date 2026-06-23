<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega la columna meet_url a la tabla leads.
 *
 * Almacena la URL de Google Meet generada al crear el evento del closer
 * en Google Calendar. Base para integración futura con Recall.ai.
 */
class AddMeetUrlToLeadsTable extends Migration
{
    /**
     * Ejecuta la migración: agrega la columna meet_url después de google_event_id.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('leads', function (Blueprint $table) {
            // URL de Google Meet del evento del closer; null si Google no la generó.
            $table->string('meet_url')->nullable()->after('google_event_id');
        });
    }

    /**
     * Revierte la migración: elimina la columna meet_url.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('meet_url');
        });
    }
}

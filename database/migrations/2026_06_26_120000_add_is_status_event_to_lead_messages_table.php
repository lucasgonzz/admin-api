<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega la columna is_status_event a lead_messages.
 *
 * Diferencia los mensajes internos de cambio de estado (ej: "Lead pasado a En Pausa")
 * de los mensajes que realmente se intentaron enviar al lead por WhatsApp.
 * Los status events no deben actualizar last_message_at ni aparecer en notificaciones.
 */
class AddIsStatusEventToLeadMessagesTable extends Migration
{
    /**
     * Agrega columna boolean is_status_event con default false después de is_followup.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('lead_messages', function (Blueprint $table) {
            /* Flag para identificar mensajes de sistema que representan cambios de estado internos. */
            $table->boolean('is_status_event')->default(false)->after('is_followup');
        });
    }

    /**
     * Revierte la columna is_status_event.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('lead_messages', function (Blueprint $table) {
            $table->dropColumn('is_status_event');
        });
    }
}

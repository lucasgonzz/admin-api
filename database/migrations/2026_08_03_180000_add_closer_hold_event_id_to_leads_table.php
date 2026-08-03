<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega a `leads` el id del evento de RESERVA PREVENTIVA del closer (grupo 306, prompt 05).
 *
 * Deliberadamente separada de `google_event_id`: esa columna es la llamada CONFIRMADA (la
 * consumen `LeadCallService`, `CloserGoogleCalendarEventService::delete_event_for_lead()` y el
 * flujo de Recall.ai). Mezclarlas haría que el bot de transcripción se enganche a una reserva
 * vacía que el lead nunca confirmó.
 *
 * Sin foreign keys (regla del repo). Sin condicionar nombres/columnas por env(). Sin índice: se
 * lee solo por id de lead, nunca se busca por su contenido.
 */
return new class extends Migration
{
    /**
     * Agrega la columna `closer_hold_event_id` a `leads`.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('closer_hold_event_id')->nullable()->after('meet_url');
        });
    }

    /**
     * Revierte las columnas agregadas (vacío, como el resto de migraciones del repo).
     *
     * @return void
     */
    public function down()
    {
    }
};

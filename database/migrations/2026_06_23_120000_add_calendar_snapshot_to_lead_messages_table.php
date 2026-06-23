<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot legible de eventos del calendario Google del closer al ofrecer disponibilidad.
 *
 * Sin FK: se persiste como JSON en texto al crear el LeadMessage de la segunda llamada a Claude.
 */
class AddCalendarSnapshotToLeadMessagesTable extends Migration
{
    /**
     * Agrega columna nullable calendar_snapshot después de ai_reasoning.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('lead_messages', function (Blueprint $table) {
            $table->text('calendar_snapshot')->nullable()->after('ai_reasoning');
        });
    }

    /**
     * Revierte la columna calendar_snapshot.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('lead_messages', function (Blueprint $table) {
            $table->dropColumn('calendar_snapshot');
        });
    }
}

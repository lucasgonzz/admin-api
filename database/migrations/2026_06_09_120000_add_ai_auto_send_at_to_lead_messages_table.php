<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Momento programado de envío automático de una sugerencia de Claude por WhatsApp.
 *
 * Sin FK: se actualiza desde LeadAiSuggestionAutoSendScheduler.
 */
class AddAiAutoSendAtToLeadMessagesTable extends Migration
{
    /**
     * Agrega columna nullable para countdown en admin-spa.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('lead_messages', function (Blueprint $table) {
            $table->timestamp('ai_auto_send_at')->nullable()->after('sent_at')->index();
        });
    }

    /**
     * Revierte la columna ai_auto_send_at.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('lead_messages', function (Blueprint $table) {
            $table->dropColumn('ai_auto_send_at');
        });
    }
}

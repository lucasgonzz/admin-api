<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campos para sugerencia IA pendiente y envío automático programado en soporte WhatsApp.
 */
class AddAiPendingSuggestionToSupportTickets extends Migration
{
    /**
     * Agrega texto sugerido por Claude y timestamp de envío automático.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->text('ai_pending_suggestion')->nullable()->after('last_client_message_at');
            $table->timestamp('ai_suggestion_send_at')->nullable()->after('ai_pending_suggestion');
        });
    }

    /**
     * Revierte columnas de sugerencia IA pendiente.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropColumn([
                'ai_pending_suggestion',
                'ai_suggestion_send_at',
            ]);
        });
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reacción del lead (emoji de WhatsApp) sobre un mensaje saliente de la conversación.
 *
 * Sin FK declarativa: la reacción se asocia al mensaje por lead_messages.id en Eloquent.
 */
class AddLeadReactionToLeadMessagesTable extends Migration
{
    /**
     * Agrega columnas para persistir la reacción del lead sobre un mensaje existente.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('lead_messages', function (Blueprint $table) {
            // Emoji Unicode que el lead aplicó al mensaje (null si quitó la reacción).
            $table->string('lead_reaction_emoji', 32)->nullable()->after('whatsapp_message_id');
            // Momento en que Kapso notificó la reacción.
            $table->timestamp('lead_reaction_at')->nullable()->after('lead_reaction_emoji');
            // wamid del evento de reacción (idempotencia del webhook).
            $table->string('lead_reaction_whatsapp_message_id', 191)->nullable()->unique()->after('lead_reaction_at');
        });
    }

    /**
     * Revierte las columnas de reacción del lead.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('lead_messages', function (Blueprint $table) {
            $table->dropColumn([
                'lead_reaction_emoji',
                'lead_reaction_at',
                'lead_reaction_whatsapp_message_id',
            ]);
        });
    }
}

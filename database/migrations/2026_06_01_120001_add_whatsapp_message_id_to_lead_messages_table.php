<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddWhatsappMessageIdToLeadMessagesTable extends Migration
{
    /**
     * Agrega ID externo de Meta para idempotencia en mensajes de lead vía webhook.
     */
    public function up()
    {
        Schema::table('lead_messages', function (Blueprint $table) {
            // Identificador del mensaje en Meta Cloud API; evita duplicados en reintentos de Kapso.
            $table->string('whatsapp_message_id')->nullable()->unique();
        });
    }

    /**
     * Revierte la columna de idempotencia WhatsApp en lead_messages.
     */
    public function down()
    {
        Schema::table('lead_messages', function (Blueprint $table) {
            $table->dropColumn('whatsapp_message_id');
        });
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddWhatsappMessageIdToSupportMessagesTable extends Migration
{
    /**
     * Agrega ID externo de Meta para idempotencia en mensajes de WhatsApp.
     */
    public function up()
    {
        Schema::table('support_messages', function (Blueprint $table) {
            // Identificador del mensaje en Meta Cloud API; evita duplicados en webhooks.
            $table->string('whatsapp_message_id')->nullable()->unique();
        });
    }

    /**
     * Revierte la columna de idempotencia WhatsApp.
     */
    public function down()
    {
        Schema::table('support_messages', function (Blueprint $table) {
            $table->dropColumn('whatsapp_message_id');
        });
    }
}

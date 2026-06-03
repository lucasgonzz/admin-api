<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddWhatsappColumnsToSupportTicketsTable extends Migration
{
    /**
     * Agrega columnas de canal WhatsApp y seguimiento de demora en respuesta.
     */
    public function up()
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            // Canal de origen del ticket (ERP interno o WhatsApp vía Kapso).
            $table->enum('source', ['erp', 'whatsapp'])->default('erp')->index();
            // Número E.164 del cliente cuando el ticket proviene de WhatsApp.
            $table->string('whatsapp_phone')->nullable()->index();
            // Último mensaje recibido del cliente; base para alertas de demora.
            $table->timestamp('last_client_message_at')->nullable();
            // Marca de la última alerta enviada para no repetirla.
            $table->timestamp('alert_sent_at')->nullable();
        });
    }

    /**
     * Revierte columnas de integración WhatsApp en tickets.
     */
    public function down()
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropColumn([
                'source',
                'whatsapp_phone',
                'last_client_message_at',
                'alert_sent_at',
            ]);
        });
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSupportMessagesTable extends Migration
{
    /**
     * Crea mensajes del chat de soporte para admins.
     */
    public function up()
    {
        Schema::create('support_messages', function (Blueprint $table) {
            // Identificador interno.
            $table->id();
            // UUID de idempotencia entre APIs.
            $table->uuid('uuid')->unique();
            // Ticket al que pertenece el mensaje.
            $table->unsignedBigInteger('support_ticket_id')->index();
            // Tipo de emisor (user/admin).
            $table->string('sender_type', 20)->index();
            // Admin local emisor cuando aplica.
            $table->unsignedBigInteger('sender_admin_id')->nullable()->index();
            // UUID remoto de admin cuando el emisor fue otra instancia.
            $table->uuid('sender_admin_uuid')->nullable()->index();
            // Tipo de contenido.
            $table->string('kind', 20)->default('text')->index();
            // Cuerpo textual.
            $table->longText('body')->nullable();
            // Estado de entrega y lectura.
            $table->dateTime('delivered_at')->nullable();
            $table->dateTime('read_at')->nullable();
            // Marca de sincronización hacia empresa-api.
            $table->dateTime('synced_to_client_at')->nullable()->index();
            // Metadatos estándar.
            $table->timestamps();
        });
    }

    /**
     * Revierte creación de mensajes de soporte.
     */
    public function down()
    {
        Schema::dropIfExists('support_messages');
    }
}


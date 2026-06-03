<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSupportMessageAttachmentsTable extends Migration
{
    /**
     * Crea adjuntos multimedia de mensajes de soporte.
     */
    public function up()
    {
        Schema::create('support_message_attachments', function (Blueprint $table) {
            // Identificador interno.
            $table->id();
            // Relación al mensaje padre.
            $table->unsignedBigInteger('support_message_id')->index();
            // Disco y path para resolver archivo.
            $table->string('disk', 50)->default('public');
            $table->string('path');
            // Metadata del archivo.
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            // Metadatos estándar.
            $table->timestamps();
        });
    }

    /**
     * Revierte creación de adjuntos.
     */
    public function down()
    {
        Schema::dropIfExists('support_message_attachments');
    }
}


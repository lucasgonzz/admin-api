<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adjuntos multimedia de mensajes de leads (mismo patrón que support_message_attachments).
 *
 * Sin FK declarativa: relación por lead_message_id en Eloquent.
 */
class CreateLeadMessageAttachmentsTable extends Migration
{
    /**
     * Crea tabla de adjuntos para audios/imágenes descargados del webhook Kapso.
     */
    public function up()
    {
        Schema::create('lead_message_attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lead_message_id')->index();
            $table->string('disk', 50)->default('public');
            $table->string('path');
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Revierte creación de adjuntos de leads.
     */
    public function down()
    {
        Schema::dropIfExists('lead_message_attachments');
    }
}

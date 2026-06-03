<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateWhatsappConfigTable extends Migration
{
    /**
     * Crea tabla de configuración de la integración Kapso / Meta Cloud API.
     */
    public function up()
    {
        Schema::create('whatsapp_config', function (Blueprint $table) {
            // Identificador interno.
            $table->id();
            // API key de Kapso para envío y consultas salientes.
            $table->string('kapso_api_key');
            // Phone Number ID de Meta asociado al número de WhatsApp Business.
            $table->string('phone_number_id');
            // Secreto para validar firma de webhooks entrantes.
            $table->string('webhook_secret');
            // Indica si este registro es el activo en el panel.
            $table->boolean('is_active')->default(true)->index();
            // Metadatos estándar.
            $table->timestamps();
        });
    }

    /**
     * Revierte creación de configuración WhatsApp.
     */
    public function down()
    {
        Schema::dropIfExists('whatsapp_config');
    }
}

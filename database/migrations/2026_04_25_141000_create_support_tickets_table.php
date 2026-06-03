<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSupportTicketsTable extends Migration
{
    /**
     * Crea tickets de soporte en el panel administrativo.
     */
    public function up()
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            // Identificador interno.
            $table->id();
            // UUID compartido con empresa-api.
            $table->uuid('uuid')->unique();
            // Cliente (tenant) al que pertenece el ticket.
            $table->unsignedBigInteger('client_id')->index();
            // Usuario remoto del tenant que abre o participa del ticket.
            $table->unsignedBigInteger('client_user_id')->index();
            // Datos cacheados del usuario remoto para lista rápida.
            $table->string('client_user_name')->nullable();
            $table->string('client_user_email')->nullable();
            // Admin asignado actualmente (reasignable).
            $table->unsignedBigInteger('assigned_admin_id')->nullable()->index();
            // Nombre visible de ticket.
            $table->string('name')->nullable();
            // Estado de ciclo de vida.
            $table->string('status', 20)->default('open')->index();
            // Fechas operativas.
            $table->dateTime('opened_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            // Metadatos estándar.
            $table->timestamps();
        });
    }

    /**
     * Revierte creación de tickets de soporte.
     */
    public function down()
    {
        Schema::dropIfExists('support_tickets');
    }
}


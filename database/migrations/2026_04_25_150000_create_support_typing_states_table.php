<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSupportTypingStatesTable extends Migration
{
    /**
     * Crea snapshots de estado "escribiendo" para soporte en admin-api.
     */
    public function up()
    {
        Schema::create('support_typing_states', function (Blueprint $table) {
            // Identificador interno.
            $table->id();
            // Ticket asociado.
            $table->unsignedBigInteger('support_ticket_id')->index();
            // Tipo de actor (admin/user).
            $table->string('actor_type', 20)->index();
            // ID local del actor cuando exista.
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            // Timestamp de última escritura detectada.
            $table->dateTime('last_typing_at')->nullable();
            // Metadatos estándar.
            $table->timestamps();
        });
    }

    /**
     * Revierte tabla de typing states.
     */
    public function down()
    {
        Schema::dropIfExists('support_typing_states');
    }
}


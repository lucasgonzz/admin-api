<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPhoneToClientsTable extends Migration
{
    /**
     * Agrega teléfono de contacto al cliente para enrutar WhatsApp entrante.
     */
    public function up()
    {
        Schema::table('clients', function (Blueprint $table) {
            // Teléfono principal del cliente (formato libre; se normaliza al comparar).
            $table->string('phone', 50)->nullable()->index();
        });
    }

    /**
     * Revierte la columna de teléfono en clients.
     */
    public function down()
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('phone');
        });
    }
}

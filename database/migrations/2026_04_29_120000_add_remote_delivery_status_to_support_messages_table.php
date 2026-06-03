<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddRemoteDeliveryStatusToSupportMessagesTable extends Migration
{
    /**
     * Agrega estado cuando el mensaje está en admin-api pero no se replicó al empresa-api del cliente.
     */
    public function up()
    {
        Schema::table('support_messages', function (Blueprint $table) {
            $table->string('remote_delivery_status', 30)->nullable()->index();
        });
    }

    /**
     * Revierte la columna agregada.
     */
    public function down()
    {
        Schema::table('support_messages', function (Blueprint $table) {
            $table->dropColumn('remote_delivery_status');
        });
    }
}

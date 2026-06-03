<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca de lectura para mensajes entrantes del lead (badge y nav en admin-spa).
 *
 * Sin FK declarativa: relación por `lead_id` en Eloquent.
 */
class AddReadAtToLeadMessagesTable extends Migration
{
    /**
     * Agrega read_at a lead_messages.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('lead_messages', function (Blueprint $table) {
            $table->timestamp('read_at')->nullable()->after('sent_at')->index();
        });
    }

    /**
     * Revierte read_at.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('lead_messages', function (Blueprint $table) {
            $table->dropColumn('read_at');
        });
    }
}

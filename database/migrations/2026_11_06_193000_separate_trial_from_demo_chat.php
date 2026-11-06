<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SeparateTrialFromDemoChat extends Migration
{
    public function up()
    {
        Schema::table('demo_chat_conversaciones', function (Blueprint $table) {
            // El código de demo de una versión anterior jamás encuentra un hilo de prueba por lead_id.
            $table->unsignedBigInteger('lead_id')->nullable()->change();
            $table->unsignedBigInteger('prueba_lead_id')->nullable();
            $table->unsignedBigInteger('prueba_cliente_id')->nullable();
            $table->unique(['prueba_lead_id', 'prueba_cliente_id'], 'prueba_chat_hilo_unico');
        });
    }

    public function down()
    {
        if (DB::table('demo_chat_conversaciones')->whereNull('lead_id')->exists()) {
            throw new RuntimeException('Hay conversaciones de prueba para preservar. La versión anterior puede funcionar con este esquema.');
        }

        Schema::table('demo_chat_conversaciones', function (Blueprint $table) {
            $table->unsignedBigInteger('lead_id')->nullable(false)->change();
            $table->dropUnique('prueba_chat_hilo_unico');
            $table->dropColumn(['prueba_lead_id', 'prueba_cliente_id']);
        });
    }
}

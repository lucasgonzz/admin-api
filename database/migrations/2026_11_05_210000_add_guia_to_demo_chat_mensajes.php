<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddGuiaToDemoChatMensajes extends Migration
{
    public function up(): void
    {
        Schema::table('demo_chat_mensajes', function (Blueprint $table) {
            $table->json('guia')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('demo_chat_mensajes', function (Blueprint $table) {
            $table->dropColumn('guia');
        });
    }
}

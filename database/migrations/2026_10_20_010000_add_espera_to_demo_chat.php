<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddEsperaToDemoChat extends Migration
{
    public function up()
    {
        Schema::table('demo_chat_conversaciones', function (Blueprint $table) {
            $table->unsignedSmallInteger('espera_segundos')->default(10);
            $table->timestamp('espera_iniciada_at')->nullable();
        });
    }

    public function down()
    {
        Schema::table('demo_chat_conversaciones', function (Blueprint $table) {
            $table->dropColumn(['espera_segundos', 'espera_iniciada_at']);
        });
    }
}

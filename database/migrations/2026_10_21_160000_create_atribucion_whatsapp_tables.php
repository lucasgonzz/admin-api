<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAtribucionWhatsappTables extends Migration
{
    public function up()
    {
        Schema::create('landing_whatsapp_referencias', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('reference_hash', 64)->unique();
            $table->json('datos');
            $table->timestamp('created_at');
            $table->timestamp('expires_at')->index();
            $table->timestamp('claimed_at')->nullable();
            $table->unsignedBigInteger('claimed_lead_id')->nullable();
            $table->unsignedBigInteger('claimed_message_id')->nullable();
        });
        Schema::create('lead_atribucion_contactos', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('lead_id')->index();
            $table->unsignedBigInteger('message_id')->unique();
            $table->unsignedBigInteger('referencia_id')->nullable();
            $table->unsignedBigInteger('referral_id')->nullable();
            $table->string('tipo', 20);
            $table->string('evidencia', 30);
            $table->json('datos');
            $table->timestamp('contacto_at')->index();
            $table->timestamp('created_at');
        });
        Schema::create('lead_atribucion_reservas', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('lead_id')->unique();
            $table->unsignedBigInteger('contacto_id')->nullable();
            $table->string('evidencia', 30)->nullable();
            $table->json('datos')->nullable();
            $table->unsignedBigInteger('demo_id');
            $table->date('demo_date');
            $table->string('demo_start_time', 8);
            $table->string('demo_end_time', 8)->nullable();
            $table->timestamp('reserved_at')->index();
        });
    }

    public function down()
    {
        Schema::dropIfExists('lead_atribucion_reservas');
        Schema::dropIfExists('lead_atribucion_contactos');
        Schema::dropIfExists('landing_whatsapp_referencias');
    }
}

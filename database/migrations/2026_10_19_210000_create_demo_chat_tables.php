<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDemoChatTables extends Migration
{
    public function up()
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('demo_industria', 40)->nullable();
        });
        Schema::create('demo_chat_conversaciones', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('lead_id')->unique();
            $table->boolean('auto_responder')->default(true);
            $table->string('estado', 20)->default('inactivo');
            $table->unsignedInteger('revision')->default(0);
            $table->unsignedInteger('generacion')->default(0);
            $table->timestamp('iniciado_at')->nullable();
            $table->timestamp('responder_desde')->nullable();
            $table->text('ultimo_error')->nullable();
            $table->timestamps();
        });
        Schema::create('demo_chat_mensajes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('conversacion_id')->index();
            $table->string('rol', 20);
            $table->string('origen', 20)->default('lead');
            $table->string('estado', 20)->default('publicado');
            $table->text('contenido');
            $table->json('videos')->nullable();
            $table->string('cliente_uuid', 64)->nullable();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->json('revisiones')->nullable();
            $table->timestamps();
            $table->unique(['conversacion_id', 'cliente_uuid'], 'demo_chat_uuid_unico');
        });
        Schema::create('demo_chat_progresos', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('conversacion_id')->index();
            $table->string('clave', 50);
            $table->json('segundos_vistos')->nullable();
            $table->unsignedInteger('duracion')->default(0);
            $table->boolean('omitido')->default(false);
            $table->timestamp('completado_at')->nullable();
            $table->timestamps();
            $table->unique(['conversacion_id', 'clave'], 'demo_chat_progreso_unico');
        });
    }

    public function down()
    {
        Schema::dropIfExists('demo_chat_progresos');
        Schema::dropIfExists('demo_chat_mensajes');
        Schema::dropIfExists('demo_chat_conversaciones');
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('demo_industria');
        });
    }
}

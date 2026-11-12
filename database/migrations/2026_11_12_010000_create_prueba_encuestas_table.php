<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La encuesta de fin de prueba gratuita (misión cruzada prueba-e2e-campana-imperio, 11/11/2026).
 *
 * Una fila por cliente: se crea al vencer la prueba o al contratar, lleva el token del enlace público
 * (`GET prueba/encuesta/{token}`) y, cuando el negocio contesta, sus respuestas. Solo aditiva.
 */
class CreatePruebaEncuestasTable extends Migration
{
    public function up()
    {
        Schema::create('prueba_encuestas', function (Blueprint $table) {
            $table->id();
            /* Una sola encuesta por cliente: si venció y después contrató, es la misma. */
            $table->unsignedBigInteger('client_id')->unique();
            $table->unsignedBigInteger('lead_id')->nullable()->index();
            /* Token largo del enlace público. No se sirve por claude/query. */
            $table->string('token', 64)->unique();
            /* Por qué se le mandó: `vencida` o `contrato`. */
            $table->string('motivo', 20);
            $table->timestamp('enviada_at')->nullable();
            $table->timestamp('respondida_at')->nullable()->index();
            $table->unsignedTinyInteger('estrellas')->nullable();
            $table->boolean('atencion_buena')->nullable();
            $table->boolean('implementacion_practica')->nullable();
            $table->boolean('soporte_sirvio')->nullable();
            $table->boolean('probo_gestion')->nullable();
            $table->boolean('probo_tienda')->nullable();
            $table->boolean('probo_agentes')->nullable();
            $table->text('comentario')->nullable();
            /* Si al contestar ya era cliente (`clients.estado = activo`). */
            $table->boolean('contrato_al_responder')->nullable();
            /* Cuándo se le avisó a Lucas del resultado. */
            $table->timestamp('avisada_at')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('prueba_encuestas');
    }
}

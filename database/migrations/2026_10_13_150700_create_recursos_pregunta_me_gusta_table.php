<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los "me gusta" de las preguntas del centro de recursos (misión centro-de-recursos-clientes,
 * 13/10/2026). Uno por (pregunta, usuario): el botón alterna, así que dar y sacar es crear y borrar
 * la fila. Ordenan las preguntas por "más útiles" en la página.
 */
class CreateRecursosPreguntaMeGustaTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('recursos_pregunta_me_gusta')) {
            return;
        }

        Schema::create('recursos_pregunta_me_gusta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recursos_pregunta_id')->constrained('recursos_preguntas')->cascadeOnDelete();
            $table->foreignId('recursos_usuario_id')->constrained('recursos_usuarios')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['recursos_pregunta_id', 'recursos_usuario_id'], 'recursos_me_gusta_pregunta_usuario_unique');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('recursos_pregunta_me_gusta');
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las preguntas de los usuarios sobre cada video, y la respuesta de ComercioCity (misión
 * centro-de-recursos-clientes, 13/10/2026).
 *
 * Quién ve qué (decisión de Lucas, 13/10/2026): las RESPONDIDAS y no ocultas las ven todos los
 * clientes, con el nombre de pila del autor y sin su negocio; las SIN responder, solo su autor.
 * `oculta` saca una pregunta de la vista de todos, incluido su autor.
 *
 * `borrador` es la respuesta propuesta por IA desde el panel ("Proponer respuesta"); no se muestra a
 * nadie hasta que un admin la pasa a `respuesta`. `vista_por_autor_at` es cuándo el autor vio la
 * respuesta: mientras sea anterior a `respondido_at` (o nula), la página le avisa que le respondieron.
 */
class CreateRecursosPreguntasTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('recursos_preguntas')) {
            return;
        }

        Schema::create('recursos_preguntas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recursos_video_id')->constrained('recursos_videos')->cascadeOnDelete();
            $table->foreignId('recursos_usuario_id')->constrained('recursos_usuarios')->cascadeOnDelete();
            $table->text('texto');
            $table->text('respuesta')->nullable();
            // Sin FK: un admin dado de baja no tiene por qué llevarse sus respuestas.
            $table->unsignedBigInteger('respondido_por_admin_id')->nullable();
            $table->timestamp('respondido_at')->nullable();
            $table->text('borrador')->nullable();
            $table->boolean('oculta')->default(false);
            $table->timestamp('vista_por_autor_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('recursos_preguntas');
    }
}

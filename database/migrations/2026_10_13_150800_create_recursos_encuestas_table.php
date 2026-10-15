<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La encuesta que la página hace al terminar cada video (misión centro-de-recursos-clientes,
 * 13/10/2026): ¿te fue útil?, ¿se entendió?, ¿qué mejorarías? Una por (usuario, video); volver a
 * mandarla la actualiza. Es lo que ordena la solapa "Videos" del panel (peor puntuados primero).
 */
class CreateRecursosEncuestasTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('recursos_encuestas')) {
            return;
        }

        Schema::create('recursos_encuestas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recursos_usuario_id')->constrained('recursos_usuarios')->cascadeOnDelete();
            $table->foreignId('recursos_video_id')->constrained('recursos_videos')->cascadeOnDelete();
            $table->enum('util', ['si', 'no'])->nullable();
            $table->enum('entendio', ['si', 'mas_o_menos', 'no'])->nullable();
            $table->text('mejora')->nullable();
            $table->timestamps();

            $table->unique(['recursos_usuario_id', 'recursos_video_id'], 'recursos_encuestas_usuario_video_unique');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('recursos_encuestas');
    }
}

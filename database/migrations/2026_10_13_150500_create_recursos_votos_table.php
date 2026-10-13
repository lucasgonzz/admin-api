<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El 👍/👎 de cada usuario en cada video (misión centro-de-recursos-clientes, 13/10/2026).
 *
 * Un voto por (usuario, video): votar de nuevo lo cambia, y "sacar el voto" (valor 0 en la API)
 * borra la fila. `valor` es 1 o -1.
 */
class CreateRecursosVotosTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('recursos_votos')) {
            return;
        }

        Schema::create('recursos_votos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recursos_usuario_id')->constrained('recursos_usuarios')->cascadeOnDelete();
            $table->foreignId('recursos_video_id')->constrained('recursos_videos')->cascadeOnDelete();
            $table->tinyInteger('valor');
            $table->timestamps();

            $table->unique(['recursos_usuario_id', 'recursos_video_id'], 'recursos_votos_usuario_video_unique');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('recursos_votos');
    }
}

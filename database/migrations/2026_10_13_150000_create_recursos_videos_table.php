<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El catálogo del centro de recursos (misión centro-de-recursos-clientes, 13/10/2026): un video por
 * fila, tal como lo carga Claude por `POST claude/recursos/catalogo` a partir de los guiones aprobados.
 *
 * `codigo` es la identidad (ej. `T1.29`): el upsert va por él y es lo que la página y el progreso de
 * cada usuario usan para nombrar un video. 🔴 Los videos NO se borran nunca: un video que deja de
 * venir en el catálogo queda `activo = false`, porque tiene progreso, votos, preguntas y encuestas
 * colgando y borrarlo se llevaría todo eso.
 *
 * La carpeta no tiene tabla propia: viaja en cada video (`carpeta` + `carpeta_nombre`) y la lista de
 * carpetas del catálogo se deriva de los videos activos. Son ~20 carpetas que nunca cambian solas.
 *
 * `version` es el hash corto del archivo subido a R2: cada versión tiene un nombre nuevo en el bucket
 * (nunca se pisa un archivo servido, por el caché de Cloudflare), así que cambia junto con `url`.
 */
class CreateRecursosVideosTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('recursos_videos')) {
            return;
        }

        Schema::create('recursos_videos', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 20)->unique();
            $table->unsignedSmallInteger('carpeta');
            $table->string('carpeta_nombre', 255);
            $table->string('seccion', 255)->nullable();
            // Orden global del guion: el catálogo sale ordenado por acá.
            $table->unsignedInteger('orden')->default(0)->index();
            $table->string('titulo', 255);
            $table->text('para_que')->nullable();
            $table->text('cuando_no')->nullable();
            // Códigos de los videos relacionados (los chips de "Cuándo no conviene").
            $table->json('relacionados')->nullable();
            $table->mediumText('transcripcion')->nullable();
            // Segundos, con una décima (lo que mide ffprobe).
            $table->decimal('duracion', 7, 1)->default(0);
            $table->string('url', 500);
            $table->string('miniatura_url', 500)->nullable();
            $table->string('version', 16)->nullable();
            $table->boolean('activo')->default(true)->index();
            $table->timestamps();
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('recursos_videos');
    }
}

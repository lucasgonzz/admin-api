<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración: tabla `demo_eventos_recibidos` — el crudo de lo que la instancia de demo reporta
 * (misión 48, pieza 4).
 *
 * Guarda el evento tal cual llegó, antes de aplicarlo a los hitos. Se guarda aunque no le
 * corresponda ningún hito del plan: el registro crudo es lo que después alimenta el brief del
 * closer (`demo_experiencia.md` §3.9), y descartar lo que hoy no sabemos interpretar sería tirar
 * el dato justo antes de necesitarlo.
 *
 * El `uuid` único es la idempotencia del canal: la instancia reintenta ante un timeout y el
 * reintento NO puede duplicar la fila ni volver a mover un hito.
 *
 * Sin foreign keys: regla del workspace.
 */
class CreateDemoEventosRecibidosTable extends Migration
{
    /**
     * Ejecuta la migración (crea la tabla `demo_eventos_recibidos`).
     */
    public function up()
    {
        Schema::create('demo_eventos_recibidos', function (Blueprint $table) {
            // Clave primaria de la fila.
            $table->id();

            // Lead al que pertenece el evento. Sale del token del header, nunca del body.
            $table->unsignedBigInteger('lead_id')->index();

            // Identificador del evento generado por el emisor. Unique: es la idempotencia.
            $table->string('uuid', 64)->unique();

            // Nombre del evento (`demo.ingreso`, `clip.terminado`, `articulo.creado`, ...).
            $table->string('nombre', 60);

            // Clip al que refiere el evento, cuando aplica (`clip.terminado` lo trae siempre).
            $table->string('clip_id', 10)->nullable();

            // Momento en que ocurrió del lado de la instancia (no el de recepción, que va en
            // created_at): entre los dos puede haber minutos si el emisor estuvo reintentando.
            $table->timestamp('ocurrido_at')->nullable();

            // Carga libre del evento, para lo que todavía no sabemos leer.
            $table->json('datos')->nullable();

            // Timestamps estándar de creación/actualización.
            $table->timestamps();
        });
    }

    /**
     * Revierte la migración (elimina la tabla `demo_eventos_recibidos`).
     */
    public function down()
    {
        Schema::dropIfExists('demo_eventos_recibidos');
    }
}

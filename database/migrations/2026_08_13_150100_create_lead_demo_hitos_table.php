<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración: tabla `lead_demo_hitos` — los hitos del roadmap de la demo de un lead (misión 48).
 *
 * Se generan del plan congelado ({@see \App\Services\DemoHitosService::generar()}) y nacen TODOS
 * en `pendiente`: el roadmap se le muestra al lead completo desde el minuto cero y se va marcando
 * a medida que avanza. Un hito por clip de núcleo, más el hito común de ingreso.
 *
 * Los tres estados (`pendiente` | `parcial` | `completo`) no son dos: `parcial` —vio el tutorial
 * pero no hizo la acción— es el que le dice al closer dónde se trabó el lead, que es el dato más
 * útil de todos.
 *
 * Sin foreign keys: regla del workspace.
 */
class CreateLeadDemoHitosTable extends Migration
{
    /**
     * Ejecuta la migración (crea la tabla `lead_demo_hitos`).
     */
    public function up()
    {
        Schema::create('lead_demo_hitos', function (Blueprint $table) {
            // Clave primaria de la fila.
            $table->id();

            // Lead dueño del hito. Con índice: todas las lecturas son "los hitos de este lead".
            $table->unsignedBigInteger('lead_id')->index();

            // Orden de exhibición, global al roadmap (no por sección): el hito de ingreso es el 1
            // y los tutoriales siguen en orden de sección y de clip.
            $table->unsignedSmallInteger('orden');

            // `ingreso` | `tutorial`.
            $table->string('tipo', 20);

            // Sección del catálogo a la que pertenece el clip (`S1 - Listado`). Null en el hito
            // de ingreso, que no pertenece a ninguna sección.
            $table->string('seccion', 60)->nullable();

            // Id del clip en el catálogo (`1.1`). Null en el hito de ingreso.
            $table->string('clip_id', 10)->nullable();

            // Texto que se muestra en el roadmap.
            $table->string('titulo', 160);

            // Evento de negocio que cierra el hito, copiado del catálogo al congelar el plan.
            // Null = el hito no tiene acción verificable y se queda en `parcial` (a propósito).
            $table->string('evento_esperado', 60)->nullable();

            // `pendiente` | `parcial` | `completo`. Nace siempre en `pendiente`.
            $table->string('estado', 12)->default('pendiente');

            // Cuándo se vio el tutorial del clip (evento `clip.terminado`).
            $table->timestamp('tutorial_visto_at')->nullable();

            // Cuándo llegó el evento de negocio esperado.
            $table->timestamp('accion_hecha_at')->nullable();

            // Timestamps estándar de creación/actualización.
            $table->timestamps();

            // Hace idempotente la generación: correrla dos veces no puede duplicar hitos. El
            // hito de ingreso tiene clip_id null y en MySQL los NULL no colisionan entre sí, así
            // que la unicidad de ese hito la garantiza la propia generación (mira si ya hay
            // hitos antes de escribir), no este índice.
            $table->unique(['lead_id', 'tipo', 'clip_id'], 'lead_demo_hitos_lead_tipo_clip_unique');
        });
    }

    /**
     * Revierte la migración (elimina la tabla `lead_demo_hitos`).
     */
    public function down()
    {
        Schema::dropIfExists('lead_demo_hitos');
    }
}

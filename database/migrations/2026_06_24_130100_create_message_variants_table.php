<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Variantes de mensajes de onboarding para A/B testing (welcome, auto, etc.).
 */
class CreateMessageVariantsTable extends Migration
{
    /**
     * Crea la tabla de variantes de mensajes configurables desde el módulo Agente.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('message_variants', function (Blueprint $table) {
            /* Identificador interno de la variante. */
            $table->id();
            /* Slug legible único: control, pregunta_directa, etc. */
            $table->string('slug', 60)->unique();
            /* Nombre descriptivo para el panel del agente. */
            $table->string('name', 150);
            /* Tipo: welcome_with_name, welcome_without_name, auto_with_name, etc. */
            $table->string('message_type', 40)->default('welcome_with_name');
            /* Cuerpo del mensaje; soporta placeholder {nombre}. */
            $table->text('body');
            /* false = no se asigna a nuevos leads (puede seguir midiéndose histórico). */
            $table->boolean('active')->default(true);
            /* Contadores desnormalizados para métricas del agente analizador. */
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('responded_count')->default(0);
            $table->unsignedInteger('scheduled_count')->default(0);
            $table->unsignedInteger('attended_count')->default(0);
            /* Notas internas sobre la hipótesis o el origen de la variante. */
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Elimina la tabla de variantes de mensajes.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('message_variants');
    }
}

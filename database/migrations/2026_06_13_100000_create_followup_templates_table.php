<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plantillas Meta aprobadas para enviar seguimientos automáticos directos
 * (sin revisión humana) según estado del lead y número de día.
 */
class CreateFollowupTemplatesTable extends Migration
{
    /**
     * Crea la tabla `followup_templates`.
     */
    public function up()
    {
        Schema::create('followup_templates', function (Blueprint $table) {
            $table->id();
            // Estado del lead al que aplica la plantilla (nuevo, contactado, calificado, etc.).
            $table->string('estado', 40)->index();
            // Número de día dentro de la instancia de seguimiento (1, 2, 3...).
            $table->unsignedInteger('dia_numero');
            // Nombre exacto de la plantilla en Meta (ej: cc_seg_nuevo_d2).
            $table->string('template_name', 120);
            // Código de idioma de la plantilla en Meta.
            $table->string('language_code', 10)->default('es_AR');
            // Permite desactivar una plantilla sin borrarla.
            $table->boolean('activa')->default(true)->index();
            $table->timestamps();

            // Índice combinado para resolver rápidamente la plantilla por estado + día.
            $table->index(['estado', 'dia_numero'], 'fup_tpl_estado_dia_idx');
        });
    }

    /**
     * Elimina la tabla `followup_templates`.
     */
    public function down()
    {
        Schema::dropIfExists('followup_templates');
    }
}

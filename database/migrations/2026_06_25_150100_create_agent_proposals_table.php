<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea la tabla agent_proposals para registrar las propuestas generadas por el agente
 * (nuevas variantes, cambios de settings, desactivar variantes, etc.) que Lucas puede aprobar o rechazar.
 */
class CreateAgentProposalsTable extends Migration
{
    /**
     * Ejecuta la migración creando la tabla agent_proposals.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('agent_proposals', function (Blueprint $table) {
            $table->id();

            // Reporte del que nació esta propuesta (opcional: puede ingresarse manualmente).
            $table->unsignedBigInteger('report_id')->nullable()->index();

            // Tipo de propuesta: nueva_variante | desactivar_variante | cambiar_setting | cambiar_protocolo.
            $table->string('tipo', 40);

            // Descripción corta de la propuesta (para listar en el panel).
            $table->string('descripcion', 255);

            // Razonamiento extendido: por qué se recomienda este cambio.
            $table->text('razonamiento');

            // JSON con las métricas que respaldan la propuesta.
            $table->json('datos_de_soporte')->nullable();

            // JSON con los cambios a aplicar si se aprueba (slug variante, key setting, etc.).
            $table->json('accion_payload')->nullable();

            // Estado actual de la propuesta: pendiente | aprobada | rechazada.
            $table->string('estado', 20)->default('pendiente')->index();

            // Timestamps de resolución.
            $table->timestamp('aprobada_at')->nullable();
            $table->timestamp('rechazada_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Revierte la migración eliminando la tabla agent_proposals.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('agent_proposals');
    }
}

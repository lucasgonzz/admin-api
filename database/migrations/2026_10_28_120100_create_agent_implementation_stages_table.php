<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las ocho etapas de cada implementación de Agentes de IA (misión `implementacion-agentes-ia`,
 * 28/10/2026). Cada fila guarda el estado de la etapa y, en `data`, lo que se fue registrando en ella
 * (las claves de cada etapa están en `AgentImplementation::STAGES`) y las operaciones de WhatsApp
 * (`data.whatsapp_ops`, que nunca viajan al contexto de Martín).
 */
class CreateAgentImplementationStagesTable extends Migration
{
    /**
     * Crea la tabla agent_implementation_stages.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('agent_implementation_stages', function (Blueprint $table) {
            // Identificador interno.
            $table->id();
            // Implementación padre (sin FK: regla del repo; índice con nombre corto).
            $table->unsignedBigInteger('agent_implementation_id')->index('agis_impl_idx');
            // Número de etapa (1 a 8).
            $table->unsignedTinyInteger('stage_number')->default(1);
            // pending | in_progress | completed | skipped.
            $table->string('status', 20)->default('pending');
            // Lo registrado en la etapa (se MEZCLA en cada actualización, nunca se pisa entero).
            $table->json('data')->nullable();
            // Arranque y cierre de la etapa.
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Elimina la tabla agent_implementation_stages.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('agent_implementation_stages');
    }
}

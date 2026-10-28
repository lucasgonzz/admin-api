<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La bitácora de cada implementación de Agentes de IA (misión `implementacion-agentes-ia`,
 * 28/10/2026): notas de Martín o de Claude, avances de etapa, WhatsApps enviados y datos cargados.
 * Es lo que Martín lee para saber «dónde quedamos» con el cliente. Solo se agrega: nunca se edita.
 */
class CreateAgentImplementationEventsTable extends Migration
{
    /**
     * Crea la tabla agent_implementation_events.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('agent_implementation_events', function (Blueprint $table) {
            // Identificador interno.
            $table->id();
            // Implementación padre (sin FK: regla del repo; índice con nombre corto).
            $table->unsignedBigInteger('agent_implementation_id')->index('agie_impl_idx');
            // Etapa a la que se refiere (null = a la implementación en general).
            $table->unsignedTinyInteger('stage_number')->nullable();
            // nota | avance | whatsapp | dato.
            $table->string('kind', 20)->default('nota');
            // martin | claude | panel: quién lo dejó asentado.
            $table->string('source', 20)->default('claude');
            // El texto del evento (tope del contrato: 1000 caracteres).
            $table->string('texto', 1000)->default('');
            $table->timestamps();
        });
    }

    /**
     * Elimina la tabla agent_implementation_events.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('agent_implementation_events');
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Instancia de cada etapa dentro de una implementación concreta.
 */
class CreateImplementationStagesTable extends Migration
{
    /**
     * Crea la tabla implementation_stages.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('implementation_stages', function (Blueprint $table) {
            // Identificador interno.
            $table->id();
            // Implementación padre.
            $table->unsignedBigInteger('implementation_id');
            // Número de etapa (1–7).
            $table->unsignedTinyInteger('stage_number');
            // Estado de esta etapa.
            $table->enum('status', ['pending', 'in_progress', 'completed', 'skipped'])->default('pending')->index();
            // Tiempos de inicio y fin de la etapa.
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            // Control de alertas por demora.
            $table->timestamp('last_alert_sent_at')->nullable();
            $table->unsignedSmallInteger('alert_count')->default(0);
            // Respuestas recolectadas en esta etapa (JSON).
            $table->json('data')->nullable();
            $table->timestamps();

            $table->foreign('implementation_id')->references('id')->on('implementations')->onDelete('cascade');
            $table->unique(['implementation_id', 'stage_number']);
        });
    }

    /**
     * Elimina la tabla implementation_stages.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('implementation_stages');
    }
}

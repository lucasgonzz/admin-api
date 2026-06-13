<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Instancia de una etapa dentro de una implementación de ecommerce concreta.
 */
class CreateEcommerceImplementationStagesTable extends Migration
{
    /**
     * Crea la tabla ecommerce_implementation_stages.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('ecommerce_implementation_stages', function (Blueprint $table) {
            // Identificador interno.
            $table->id();
            // Implementación de ecommerce padre (nombre de índice corto: límite MySQL 64 caracteres).
            $table->unsignedBigInteger('ecommerce_implementation_id')->index('ecom_impl_stages_impl_id_idx');
            // Número de etapa (1–5).
            $table->unsignedTinyInteger('stage_number');
            // Estado de la etapa.
            $table->enum('status', ['pending', 'in_progress', 'completed'])->default('pending');
            // Respuestas y datos recolectados en la etapa.
            $table->json('data')->nullable();
            // Inicio y cierre de la etapa.
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            // $table->foreign('ecommerce_implementation_id')->references('id')->on('ecommerce_implementations')->onDelete('cascade');
        });
    }

    /**
     * Elimina la tabla ecommerce_implementation_stages.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('ecommerce_implementation_stages');
    }
}

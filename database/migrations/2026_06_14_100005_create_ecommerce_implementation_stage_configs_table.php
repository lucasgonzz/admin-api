<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configuración maestra de cada etapa del proceso de implementación de ecommerce (catálogo).
 */
class CreateEcommerceImplementationStageConfigsTable extends Migration
{
    /**
     * Crea la tabla ecommerce_implementation_stage_configs.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('ecommerce_implementation_stage_configs', function (Blueprint $table) {
            // Identificador interno.
            $table->id();
            // Número de etapa (1–5); único en el catálogo.
            $table->unsignedTinyInteger('stage_number')->unique();
            // Nombre corto visible en admin y mensajes.
            $table->string('name', 100);
            // Descripción operativa de la etapa.
            $table->string('description')->nullable();
            // Umbral de alerta en horas.
            $table->decimal('alert_threshold_hours', 5, 2)->default(24.00);
            // true = la etapa la ejecuta el sistema sin intervención humana.
            $table->boolean('is_automated')->default(false);
            // Etapa habilitada en el flujo.
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Elimina la tabla ecommerce_implementation_stage_configs.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('ecommerce_implementation_stage_configs');
    }
}

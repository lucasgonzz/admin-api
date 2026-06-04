<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configuración maestra de cada etapa del proceso de implementación de clientes.
 */
class CreateImplementationStageConfigsTable extends Migration
{
    /**
     * Crea la tabla implementation_stage_configs.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('implementation_stage_configs', function (Blueprint $table) {
            // Identificador interno.
            $table->id();
            // Número de etapa (1–7); único en el catálogo.
            $table->unsignedTinyInteger('stage_number')->unique();
            // Nombre corto visible en admin y mensajes.
            $table->string('name', 100);
            // Descripción operativa de la etapa.
            $table->text('description')->nullable();
            // Umbral de alerta en horas (admite decimales, p. ej. 0.1 para pruebas).
            $table->decimal('alert_threshold_hours', 5, 2)->default(24.00);
            // true = la etapa la ejecuta el sistema sin intervención humana.
            $table->boolean('is_automated')->default(false);
            // Etapa habilitada en el flujo.
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Elimina la tabla implementation_stage_configs.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('implementation_stage_configs');
    }
}

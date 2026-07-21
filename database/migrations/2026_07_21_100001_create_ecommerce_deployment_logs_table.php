<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Líneas de log por etapa de una corrida de instalación/actualización del ecommerce.
 *
 * Espeja deployment_logs pero apuntando a client_ecommerce_installations, para
 * alimentar el mismo panel de log en vivo que usa el pipeline de empresa.
 */
class CreateEcommerceDeploymentLogsTable extends Migration
{
    /**
     * Crea la tabla ecommerce_deployment_logs (solo created_at, sin updated_at).
     *
     * @return void
     */
    public function up()
    {
        Schema::create('ecommerce_deployment_logs', function (Blueprint $table) {
            // Identificador interno.
            $table->id();

            // Corrida de instalación/actualización a la que pertenece esta línea.
            $table->unsignedBigInteger('client_ecommerce_installation_id');

            // Identificador de la etapa del deployment (ej. ensure_clone, compile_spa, upload_spa, upload_api, composer_install, write_env, finalize).
            $table->string('step', 60);

            // Contenido de la línea de log.
            $table->text('line');

            // Nivel: info | success | error.
            $table->string('level', 20);

            // Solo marca de tiempo de creación (sin updated_at).
            $table->timestamp('created_at')->nullable();

            // Índice manual (sin foreign keys, por convención del proyecto).
            $table->index('client_ecommerce_installation_id', 'edl_installation_idx');
        });
    }

    /**
     * Elimina la tabla ecommerce_deployment_logs.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('ecommerce_deployment_logs');
    }
}

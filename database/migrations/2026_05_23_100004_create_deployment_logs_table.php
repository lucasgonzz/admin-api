<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Líneas de log por etapa de un upgrade (deployment automatizado).
 */
class CreateDeploymentLogsTable extends Migration
{
    /**
     * Crea la tabla deployment_logs (solo created_at, sin updated_at).
     *
     * @return void
     */
    public function up()
    {
        Schema::create('deployment_logs', function (Blueprint $table) {
            // Identificador interno.
            $table->id();

            // Upgrade al que pertenece esta línea de log.
            $table->unsignedBigInteger('client_version_upgrade_id');

            // Identificador de la etapa del deployment (ej. git_pull, migraciones).
            $table->string('step');

            // Contenido de la línea de log.
            $table->text('line');

            // Nivel: info | success | error.
            $table->string('level');

            // Solo marca de tiempo de creación (sin updated_at).
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Elimina la tabla deployment_logs.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('deployment_logs');
    }
}

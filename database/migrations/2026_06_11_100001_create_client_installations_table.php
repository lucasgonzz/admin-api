<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de instalaciones iniciales de sistema para clientes.
 *
 * Registra el proceso completo de instalación desde cero (distinto de una actualización),
 * incluyendo la versión a instalar, los valores manuales de .env y el estado del proceso.
 */
class CreateClientInstallationsTable extends Migration
{
    /**
     * Crea la tabla client_installations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('client_installations', function (Blueprint $table) {
            // Identificador interno autoincremental.
            $table->id();

            // UUID único para referencias externas (broadcast, URLs, jobs).
            $table->uuid('uuid')->unique();

            // Cliente al que pertenece esta instalación.
            $table->unsignedBigInteger('client_id');

            // API del cliente donde se instalará el sistema (puede quedar sin API si aún no está creada).
            $table->unsignedBigInteger('client_api_id')->nullable();

            // Versión inicial a instalar.
            $table->unsignedBigInteger('version_id')->nullable();

            // Estado del proceso: pendiente | instalando | completada | fallida.
            $table->string('status')->default('pendiente');

            // Valores de variables is_manual_on_create que carga el operador antes de iniciar.
            $table->json('env_manual_values')->nullable();

            // Razón de fallo (solo cuando status = fallida).
            $table->text('failure_reason')->nullable();

            // Marca de tiempo de inicio del proceso.
            $table->timestamp('started_at')->nullable();

            // Marca de tiempo de finalización (éxito o fallo).
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();

            // Restricciones de integridad referencial en Eloquent (sin FK en BD por convención del proyecto).
        });
    }

    /**
     * Elimina la tabla client_installations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('client_installations');
    }
}

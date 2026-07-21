<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de corridas del pipeline de instalación/actualización del ecommerce.
 *
 * Espeja client_installations pero para ClientEcommerce: registra cada corrida
 * (instalación desde cero o actualización) del ecommerce de un cliente, con su
 * estado y su razón de fallo si corresponde.
 */
class CreateClientEcommerceInstallationsTable extends Migration
{
    /**
     * Crea la tabla client_ecommerce_installations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('client_ecommerce_installations', function (Blueprint $table) {
            // Identificador interno autoincremental.
            $table->id();

            // UUID único para referencias externas (broadcast, URLs, jobs).
            $table->uuid('uuid');

            // Tienda (ecommerce) a la que pertenece esta corrida.
            $table->unsignedBigInteger('client_ecommerce_id');

            // Tipo de corrida: install (desde cero, con API + .env) | update (solo recompila y sobrescribe SPA + código de API).
            $table->string('mode', 20);

            // Estado del proceso: pendiente | instalando | completada | fallida.
            $table->string('status', 20)->default('pendiente');

            // Razón de fallo (solo cuando status = fallida).
            $table->text('failure_reason')->nullable();

            // Marca de tiempo de inicio del proceso.
            $table->timestamp('started_at')->nullable();

            // Marca de tiempo de finalización (éxito o fallo).
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();

            // Índices manuales (sin foreign keys, por convención del proyecto).
            $table->index('uuid', 'cei_uuid_idx');
            $table->index('client_ecommerce_id', 'cei_client_ecommerce_idx');
        });
    }

    /**
     * Elimina la tabla client_ecommerce_installations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('client_ecommerce_installations');
    }
}

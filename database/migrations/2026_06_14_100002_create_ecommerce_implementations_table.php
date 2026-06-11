<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proceso de implementación de la tienda online de un cliente (una por client_id).
 */
class CreateEcommerceImplementationsTable extends Migration
{
    /**
     * Crea la tabla ecommerce_implementations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('ecommerce_implementations', function (Blueprint $table) {
            // Identificador interno.
            $table->id();
            // Cliente dueño del proceso.
            $table->unsignedBigInteger('client_id')->index();
            // Tienda online asociada (se crea al iniciar la implementación).
            $table->unsignedBigInteger('client_ecommerce_id')->nullable()->index();
            // Estado global del proceso.
            $table->enum('status', ['pending', 'in_progress', 'completed'])->default('in_progress')->index();
            // Etapa actual del flujo (1–5).
            $table->unsignedTinyInteger('current_stage')->default(1);
            // Admin asignado al proceso.
            $table->unsignedBigInteger('assigned_admin_id')->nullable();
            // Inicio y cierre del proceso completo.
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            // Teléfono de contacto para coordinar la migración del dominio.
            $table->string('migration_contact_phone', 30)->nullable();
            $table->timestamps();

            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');
            $table->foreign('client_ecommerce_id')->references('id')->on('client_ecommerces')->onDelete('set null');
        });
    }

    /**
     * Elimina la tabla ecommerce_implementations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('ecommerce_implementations');
    }
}

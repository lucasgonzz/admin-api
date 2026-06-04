<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Implementación guiada por WhatsApp de un cliente (una por client_id).
 */
class CreateImplementationsTable extends Migration
{
    /**
     * Crea la tabla implementations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('implementations', function (Blueprint $table) {
            // Identificador interno.
            $table->id();
            // Cliente dueño del proceso.
            $table->unsignedBigInteger('client_id')->index();
            // Etapa actual del flujo (1–7).
            $table->unsignedTinyInteger('current_stage')->default(1);
            // Estado global del proceso.
            $table->enum('status', ['pending', 'in_progress', 'completed', 'paused'])->default('pending')->index();
            // Teléfono del responsable de migración (Etapa 2).
            $table->string('migration_contact_phone', 30)->nullable();
            // Inicio y cierre del proceso completo.
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            // Notas internas opcionales.
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');
        });
    }

    /**
     * Elimina la tabla implementations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('implementations');
    }
}

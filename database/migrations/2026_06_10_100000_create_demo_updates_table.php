<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea la tabla demo_updates que registra cada proceso de actualización
 * (pipeline SPA + API) aplicado a una demo en hosting compartido.
 */
class CreateDemoUpdatesTable extends Migration
{
    /**
     * Ejecuta la migración creando la tabla demo_updates.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('demo_updates', function (Blueprint $table) {
            $table->id();

            // UUID para identificación pública sin exponer el ID secuencial.
            $table->uuid('uuid')->unique();

            // Demo objetivo del pipeline de actualización.
            $table->foreignId('demo_id')->constrained('demos')->cascadeOnDelete();

            // Versión destino a la que se lleva la demo.
            $table->foreignId('version_id')->constrained('versions');

            // Admin que inició el proceso; null si fue disparado automáticamente.
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();

            // Estado del pipeline: pendiente → ejecutandose → completado | fallido.
            $table->enum('status', ['pendiente', 'ejecutandose', 'completado', 'fallido'])->default('pendiente');

            // Texto acumulado de log del pipeline (cada línea lleva timestamp [H:i:s]).
            $table->text('log')->nullable();

            // Momento en que comenzó la ejecución del job.
            $table->timestamp('started_at')->nullable();

            // Momento en que finalizó (exitoso o fallido).
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Revierte la migración eliminando la tabla demo_updates.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('demo_updates');
    }
}

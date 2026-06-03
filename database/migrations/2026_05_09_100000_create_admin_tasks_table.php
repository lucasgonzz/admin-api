<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAdminTasksTable extends Migration
{
    /**
     * Crea la tabla de tareas internas del panel administrativo.
     * Cada tarea tiene un título, contenido, subtareas en JSON, estado de realización
     * y un campo de orden para definir prioridad mediante drag & drop.
     */
    public function up()
    {
        Schema::create('admin_tasks', function (Blueprint $table) {
            // Identificador interno.
            $table->id();
            // Admin que creó la tarea.
            $table->unsignedBigInteger('created_by_admin_id')->index();
            // Admin asignado para resolver la tarea.
            $table->unsignedBigInteger('assigned_admin_id')->nullable()->index();
            // Título visible de la tarea.
            $table->string('title');
            // Descripción o cuerpo de la tarea.
            $table->text('content')->nullable();
            // Subtareas opcionales: array JSON de objetos { text, done }.
            $table->json('todos')->nullable();
            // Indica si la tarea fue completada.
            $table->boolean('is_done')->default(false)->index();
            // Orden de prioridad para drag & drop; valores menores = más importante.
            $table->unsignedInteger('sort_order')->default(0)->index();
            // Metadatos estándar.
            $table->timestamps();
        });
    }

    /**
     * Revierte la creación de la tabla de tareas.
     */
    public function down()
    {
        Schema::dropIfExists('admin_tasks');
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea la tabla de plantillas de tareas automáticas.
 * Cada plantilla define una tarea predefinida que se genera automáticamente
 * cuando se dispara un proceso determinado (ej. 'lead_a_cliente').
 */
class CreateTaskTemplatesTable extends Migration
{
    /**
     * Crea la tabla task_templates con todos sus campos.
     */
    public function up()
    {
        Schema::create('task_templates', function (Blueprint $table) {
            // Identificador interno.
            $table->id();

            // Identificador del proceso que dispara la creación de esta tarea.
            // Ejemplo: 'lead_a_cliente', 'nuevo_cliente_soporte', etc.
            $table->string('proceso');

            // Título visible de la tarea que se creará.
            $table->string('titulo');

            // Descripción o cuerpo de la tarea. Opcional.
            $table->text('descripcion')->nullable();

            // Lista de subtareas como array JSON de strings.
            // Al crear la AdminTask se convierten a [{text, done: false}].
            $table->json('checklist')->nullable();

            // Nombre del admin al que se asignará la tarea. Se resuelve por nombre en Admin.
            // Si no existe el admin, la tarea queda sin asignar.
            $table->string('asignado_a')->nullable();

            // Nivel de prioridad informativo (no se mapea directo al sort_order de AdminTask).
            $table->integer('prioridad')->default(0);

            // Posición de la plantilla dentro del proceso; determina el sort_order de la tarea creada.
            $table->integer('orden')->default(0);

            // Indica si la plantilla está activa y debe usarse al crear tareas.
            $table->boolean('activa')->default(true)->index();

            // Metadatos estándar.
            $table->timestamps();
        });
    }

    /**
     * Elimina la tabla task_templates.
     */
    public function down()
    {
        Schema::dropIfExists('task_templates');
    }
}

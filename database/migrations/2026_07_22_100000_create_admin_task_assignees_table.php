<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Crea la tabla pivot admin_task_assignees, que permite asignar una misma
 * tarea (admin_tasks) a varios admins a la vez (ej: todos los setters).
 *
 * Reemplaza en el uso diario a admin_tasks.assigned_admin_id (que solo
 * soportaba un único asignado), pero esa columna NO se elimina: queda
 * como legacy para poder revertir sin pérdida de datos si algo sale mal.
 *
 * Después de crear la tabla, esta migración migra los datos existentes:
 * por cada tarea que ya tenía assigned_admin_id, inserta la fila
 * correspondiente en la pivot. Usa insertOrIgnore para que sea idempotente
 * si se corre más de una vez (ej. en un reintento de deploy).
 */
class CreateAdminTaskAssigneesTable extends Migration
{
    /**
     * Crea la tabla pivot y migra las asignaciones existentes.
     */
    public function up()
    {
        Schema::create('admin_task_assignees', function (Blueprint $table) {
            // Tarea asignada.
            $table->unsignedBigInteger('admin_task_id');
            // Admin al que se le asignó la tarea.
            $table->unsignedBigInteger('admin_id');
            // Índice suelto: se consulta "tareas de este admin" constantemente.
            $table->index('admin_id');
            // Clave primaria compuesta: evita duplicados (mismo admin asignado dos veces a la misma tarea).
            $table->primary(['admin_task_id', 'admin_id']);
        });

        // Migrar datos existentes: cada admin_tasks.assigned_admin_id no nulo pasa a ser
        // una fila en la pivot. Se procesa en chunks de 200 para no cargar todo en memoria.
        DB::table('admin_tasks')
            ->whereNotNull('assigned_admin_id')
            ->orderBy('id')
            ->chunk(200, function ($tasks) {
                foreach ($tasks as $task) {
                    // insertOrIgnore evita error de clave duplicada si la migración se corre dos veces.
                    DB::table('admin_task_assignees')->insertOrIgnore([
                        'admin_task_id' => $task->id,
                        'admin_id'      => $task->assigned_admin_id,
                    ]);
                }
            });
    }

    /**
     * Revierte la migración eliminando solo la tabla pivot.
     * No se toca admin_tasks.assigned_admin_id: sigue existiendo en ambos sentidos.
     */
    public function down()
    {
        Schema::dropIfExists('admin_task_assignees');
    }
}

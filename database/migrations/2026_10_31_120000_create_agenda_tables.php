<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea las tres tablas del módulo Agenda del admin (misión agenda-admin, 31/10/2026).
 *
 * La Agenda es la copia de Structured adentro del admin: tareas con fecha, hora, duración, color e
 * ícono, subpasos internos, repetición (diaria / semanal / mensual) y una bandeja de tareas sin
 * fecha. Es PERSONAL: cada tarea es de un admin (`admin_id`) y el panel solo muestra las del admin
 * logueado. Claude las opera por `claude/agenda…`.
 *
 *  - `agenda_tasks`: la tarea. `date = null` es la bandeja. Una tarea repetida es UNA fila (la
 *    serie); las ocurrencias se calculan al leer (`App\Services\Agenda\AgendaService`), no se
 *    materializan.
 *  - `agenda_subtasks`: los subpasos de una tarea. En una repetida son de la serie, no de cada
 *    ocurrencia.
 *  - `agenda_task_completions`: una fila por ocurrencia HECHA de una tarea repetida. Las tareas
 *    no repetidas usan `agenda_tasks.completed_at` y no tocan esta tabla.
 *
 * Reglas del repo que se respetan acá:
 *  - SIN foreign keys: `admin_id` y `agenda_task_id` son enteros sueltos con índice. Borrar un admin
 *    no puede arrastrar ni trabar su agenda; el borrado de subpasos y ocurrencias hechas lo hace el
 *    servicio al borrar la tarea.
 *  - Índices con nombre corto (MySQL corta en 64 caracteres) y sin unique compuestos sobre strings.
 *  - Con guarda `hasTable`: correrla dos veces (o sobre una base que ya las tiene) no falla.
 *
 * ⚠️ `repeat` es palabra reservada de MySQL. El query builder de Laravel la escapa con backticks,
 * así que funciona; lo que NO se puede es nombrarla a pelo en un `whereRaw()`.
 */
class CreateAgendaTables extends Migration
{
    /**
     * Crea las tres tablas si todavía no existen.
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasTable('agenda_tasks')) {
            Schema::create('agenda_tasks', function (Blueprint $table) {
                $table->bigIncrements('id');

                // Dueño de la tarea: la agenda es personal, como en Structured.
                $table->unsignedBigInteger('admin_id')->index('agt_admin_idx');

                // Título visible y notas libres (descripción, links de reuniones, teléfonos).
                $table->string('title', 500);
                $table->text('notes')->nullable();

                // Día de la tarea; null = la tarea está en la bandeja (sin programar).
                $table->date('date')->nullable();

                // Hora de inicio; null si es de todo el día o si está en la bandeja.
                $table->time('start_time')->nullable();
                $table->boolean('all_day')->default(false);

                // Duración en minutos (5..1440); de acá sale la hora de fin.
                $table->unsignedSmallInteger('duration_minutes')->default(15);

                // Clave de la paleta (salmon, orange, …) y nombre de Bootstrap Icon sin el `bi-`.
                $table->string('color', 20)->default('salmon');
                $table->string('icon', 60)->default('check2-square');

                // Repetición: none / daily / weekly / monthly, y el último día inclusive (null = sin fin).
                $table->string('repeat', 10)->default('none');
                $table->date('repeat_until')->nullable();

                // Momento en que se marcó hecha. Solo para tareas NO repetidas: las repetidas
                // guardan cada ocurrencia hecha en agenda_task_completions.
                $table->dateTime('completed_at')->nullable();

                // Orden dentro de la bandeja.
                $table->integer('sort_order')->default(0);

                // Origen: panel (cargada a mano) | claude (por la API de Claude).
                $table->string('created_via', 20)->default('panel');

                $table->timestamps();

                // El listado por rango filtra siempre por dueño y fecha.
                $table->index(['admin_id', 'date'], 'agt_admin_date_idx');
            });
        }

        if (! Schema::hasTable('agenda_subtasks')) {
            Schema::create('agenda_subtasks', function (Blueprint $table) {
                $table->bigIncrements('id');

                // Tarea dueña del subpaso (sin foreign key, a propósito).
                $table->unsignedBigInteger('agenda_task_id')->index('ags_task_idx');

                $table->string('title', 500);
                $table->boolean('done')->default(false);
                $table->integer('sort_order')->default(0);

                $table->timestamps();
            });
        }

        if (! Schema::hasTable('agenda_task_completions')) {
            Schema::create('agenda_task_completions', function (Blueprint $table) {
                $table->bigIncrements('id');

                // Tarea repetida a la que pertenece la ocurrencia hecha.
                $table->unsignedBigInteger('agenda_task_id');

                // Fecha de la ocurrencia que se marcó hecha.
                $table->date('occurrence_date');

                // Momento en que se marcó.
                $table->dateTime('completed_at');

                $table->timestamps();

                // El listado trae las hechas de un rango con una sola consulta por (tarea, fecha).
                $table->index(['agenda_task_id', 'occurrence_date'], 'agtc_task_date_idx');
            });
        }
    }

    /**
     * Borra las tres tablas si existen.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('agenda_task_completions');
        Schema::dropIfExists('agenda_subtasks');
        Schema::dropIfExists('agenda_tasks');
    }
}

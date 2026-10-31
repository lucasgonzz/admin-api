<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una ocurrencia HECHA de una tarea repetida de la Agenda (misión agenda-admin, 31/10/2026).
 *
 * Hay una fila por fecha marcada; desmarcar la ocurrencia borra la fila. Las tareas no repetidas
 * no usan esta tabla (guardan `agenda_tasks.completed_at`).
 *
 * `occurrence_date` va sin cast por lo mismo que `AgendaTask::$date`: es un día sin hora y se lee
 * tal cual lo devuelve MySQL (`Y-m-d`).
 */
class AgendaTaskCompletion extends Model
{
    protected $table = 'agenda_task_completions';

    protected $guarded = [];

    protected $casts = [
        'agenda_task_id' => 'integer',
        'completed_at'   => 'datetime',
    ];

    /**
     * Tarea repetida a la que pertenece la ocurrencia.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function task()
    {
        return $this->belongsTo(AgendaTask::class, 'agenda_task_id');
    }
}

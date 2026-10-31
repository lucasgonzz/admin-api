<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Subpaso de una tarea de la Agenda (misión agenda-admin, 31/10/2026).
 *
 * En una tarea repetida los subpasos son de la SERIE, no de cada ocurrencia: tildar uno lo tilda
 * para todas las fechas de la serie (decisión 4 del plan de la misión).
 */
class AgendaSubtask extends Model
{
    protected $table = 'agenda_subtasks';

    protected $guarded = [];

    protected $casts = [
        'agenda_task_id' => 'integer',
        'done'           => 'boolean',
        'sort_order'     => 'integer',
    ];

    /**
     * Tarea dueña del subpaso.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function task()
    {
        return $this->belongsTo(AgendaTask::class, 'agenda_task_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tarea de la Agenda personal de un admin (misión agenda-admin, 31/10/2026).
 *
 * Una fila es UNA tarea, y si se repite es UNA serie: las ocurrencias de una repetida se calculan
 * al leer en {@see \App\Services\Agenda\AgendaService}, no se guardan una por una. Lo único que se
 * guarda por ocurrencia es si está hecha ({@see AgendaTaskCompletion}).
 *
 * 🔴 `date`, `repeat_until` y `start_time` NO tienen cast a propósito: se leen tal cual los
 * devuelve MySQL (`Y-m-d` y `H:i:s`). Un cast `date` los convertiría en un Carbon con hora y zona,
 * y una fecha sin hora no tiene zona: el día 31 tiene que seguir siendo el 31 en cualquier lado.
 *
 * ⚠️ `repeat` es palabra reservada de MySQL: el query builder la escapa, un `whereRaw()` no.
 */
class AgendaTask extends Model
{
    protected $table = 'agenda_tasks';

    protected $guarded = [];

    protected $casts = [
        'admin_id'         => 'integer',
        'all_day'          => 'boolean',
        'duration_minutes' => 'integer',
        'sort_order'       => 'integer',
        // Momento en que se marcó hecha (solo tareas no repetidas).
        'completed_at'     => 'datetime',
    ];

    /**
     * Admin dueño de la tarea.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function admin()
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }

    /**
     * Subpasos de la tarea, en el orden en que se muestran (sort_order y, a igualdad, el id).
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function subtasks()
    {
        return $this->hasMany(AgendaSubtask::class, 'agenda_task_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * Ocurrencias hechas de la tarea (solo tiene filas si la tarea se repite).
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function completions()
    {
        return $this->hasMany(AgendaTaskCompletion::class, 'agenda_task_id');
    }

    /**
     * Si la tarea es una serie que se repite (diaria, semanal o mensual).
     *
     * @return bool
     */
    public function is_recurring(): bool
    {
        return (string) $this->repeat !== '' && (string) $this->repeat !== 'none';
    }
}

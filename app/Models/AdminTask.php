<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tarea interna del panel administrativo.
 * Cada tarea puede asignarse a un admin, tener subtareas (todos) en JSON,
 * marcarse como realizada y ordenarse por prioridad mediante drag & drop.
 */
class AdminTask extends Model
{
    protected $table = 'admin_tasks';

    protected $guarded = [];

    protected $casts = [
        // Permite trabajar con todos como array PHP directamente.
        'todos'   => 'array',
        'is_done' => 'boolean',
        // Momento en que la tarea fue marcada como realizada.
        'done_at' => 'datetime',
    ];

    /**
     * Admin que creó la tarea.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function created_by_admin()
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }

    /**
     * Admin asignado para resolver la tarea.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function assigned_admin()
    {
        return $this->belongsTo(Admin::class, 'assigned_admin_id');
    }

    /**
     * Admins asignados para resolver la tarea (asignación múltiple).
     * Reemplaza en el uso diario a assigned_admin/assigned_admin_id, que se
     * mantienen como legacy porque todavía los usan otros consumidores.
     * Sin timestamps en la pivot: admin_task_assignees no los tiene.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function assigned_admins()
    {
        return $this->belongsToMany(Admin::class, 'admin_task_assignees', 'admin_task_id', 'admin_id');
    }

    /**
     * Admin que marcó la tarea como realizada.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function done_by_admin()
    {
        return $this->belongsTo(Admin::class, 'done_by_admin_id');
    }

    /**
     * Avisos de asignación generados para esta tarea (uno por admin asignado).
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function notifications()
    {
        return $this->hasMany(AdminTaskNotification::class, 'admin_task_id');
    }

    /**
     * Lead de origen cuando la tarea fue creada automáticamente por una alerta de Claude.
     * Null para tareas creadas manualmente por admins.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function lead()
    {
        return $this->belongsTo(\App\Models\Lead::class, 'lead_id');
    }

    /**
     * Scope que eager-carga las relaciones necesarias para los listados.
     *
     * @param  \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithAll($query)
    {
        return $query->with([
            'created_by_admin:id,name',
            'assigned_admin:id,name',
            // Asignación múltiple: admins asignados a la tarea.
            'assigned_admins:id,name',
            // Admin que marcó la tarea como realizada.
            'done_by_admin:id,name',
            'lead:id,contact_name,company_name',
        ]);
    }
}

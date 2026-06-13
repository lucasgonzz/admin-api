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
            'lead:id,contact_name,company_name',
        ]);
    }
}

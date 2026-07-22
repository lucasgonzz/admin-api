<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Aviso de asignación de una tarea interna a un admin puntual.
 * Existe una fila por cada par (tarea, admin) asignado: permite que cada
 * admin cierre su propio aviso sin afectar el de los demás asignados a
 * la misma tarea.
 */
class AdminTaskNotification extends Model
{
    protected $table = 'admin_task_notifications';

    protected $guarded = [];

    protected $casts = [
        // Momento en que el admin vio/cerró el aviso; null mientras esté pendiente.
        'seen_at' => 'datetime',
    ];

    /**
     * Tarea sobre la que se generó el aviso.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function task()
    {
        return $this->belongsTo(AdminTask::class, 'admin_task_id');
    }

    /**
     * Admin destinatario del aviso.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function admin()
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }

    /**
     * Scope: avisos todavía no vistos por un admin puntual.
     *
     * @param  \Illuminate\Database\Eloquent\Builder $query
     * @param  int                                    $admin_id
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePendingForAdmin($query, $admin_id)
    {
        return $query->where('admin_id', $admin_id)->whereNull('seen_at');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Registro de un aviso push de «mensaje esperando tu aprobación» (un registro por mensaje).
 *
 * Lo maneja {@see \App\Services\PendingApprovalPushService}: la unicidad de `source_key` es lo que impide avisar dos
 * veces el mismo mensaje.
 */
class PendingApprovalNotification extends Model
{
    /** @var string Nombre de la tabla. */
    protected $table = 'pending_approval_notifications';

    /** @var array<int, string> Todo asignable: sólo lo escribe el servicio. */
    protected $guarded = [];

    /** @var array<string, string> Conversión de columnas JSON y fechas. */
    protected $casts = [
        'admin_ids'                => 'array',
        'accepted_endpoint_hashes' => 'array',
        'attempts'                 => 'integer',
        'grouped_into_id'          => 'integer',
        'processing_until'         => 'datetime',
        'notified_at'              => 'datetime',
    ];

    /**
     * Scope estándar de modelos nuevos (sin relaciones que cargar).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return void
     */
    public function scopeWithAll($query)
    {
    }
}

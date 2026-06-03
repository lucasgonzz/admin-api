<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Reserva de bloque global de user_id.
 *
 * Cada registro representa el inicio del bloque (múltiplo de 100) asignado
 * a un sistema/cliente para evitar superposición de IDs entre empresas.
 */
class UserIdBlock extends Model
{
    protected $guarded = [];

    protected $casts = [
        'block_start' => 'integer',
    ];

    /**
     * Scope requerido por convención del proyecto.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     */
    public function scopeWithAll($query)
    {
        $query->with('lead', 'client');
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}

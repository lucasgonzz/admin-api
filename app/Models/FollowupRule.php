<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Regla de tiempo y cantidad de seguimientos automáticos por estado del lead.
 */
class FollowupRule extends Model
{
    protected $guarded = [];

    protected $casts = [
        'activa'        => 'boolean',
        'horas_espera'  => 'integer',
        'max_followups' => 'integer',
    ];

    /**
     * Scope estándar para contrato homogéneo con fullModel.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     */
    public function scopeWithAll($query)
    {
    }
}

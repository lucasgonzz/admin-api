<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Plantilla Meta aprobada para seguimientos automáticos directos por estado del lead.
 *
 * Cada fila representa la plantilla que corresponde enviar en un día concreto
 * dentro de la instancia de seguimiento de un estado (ver FollowupTemplatesSeeder).
 */
class FollowupTemplate extends Model
{
    protected $guarded = [];

    /**
     * Casts de tipos de los campos editables.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'activa'     => 'boolean',
        'dia_numero' => 'integer',
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

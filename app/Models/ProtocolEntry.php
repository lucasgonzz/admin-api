<?php

namespace App\Models;

use App\ModelProperties\ProtocolEntryProperties;
use Illuminate\Database\Eloquent\Model;

/**
 * Entrada del protocolo de ventas consumida por Claude al sugerir respuestas.
 */
class ProtocolEntry extends Model
{
    protected $guarded = [];

    protected $casts = [
        'activa'          => 'boolean',
        'followup_numero' => 'integer',
    ];

    /**
     * Esquema declarativo para admin-spa/meta (ABM del protocolo).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function properties()
    {
        return ProtocolEntryProperties::all();
    }

    /**
     * Scope estándar para contrato homogéneo con fullModel.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     */
    public function scopeWithAll($query)
    {
    }
}

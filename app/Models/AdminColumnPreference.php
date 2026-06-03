<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Registro de columnas (orden, ancho, visibilidad) que el admin eligió en el SPA.
 */
class AdminColumnPreference extends Model
{
    protected $table = 'admin_column_preferences';

    protected $guarded = [];

    protected $casts = [
        'properties' => 'array',
    ];

    function scopeWithAll($query) {}
}

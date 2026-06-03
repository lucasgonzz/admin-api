<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Credenciales SSH por tipo de hosting (shared_hosting, vps).
 */
class ClientSshCredential extends Model
{
    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * Casteos de atributos (password cifrado en base de datos).
     *
     * @var array<string, string>
     */
    protected $casts = [
        'password' => 'encrypted',
    ];

    /**
     * Sin eager loads adicionales (tabla de catálogo pequeña).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    function scopeWithAll($query)
    {
        return $query;
    }
}

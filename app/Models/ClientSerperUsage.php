<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientSerperUsage extends Model
{
    protected $guarded = [];
    protected $casts = [
        'occurred_at' => 'datetime',
        'ok' => 'boolean',
        'credits' => 'float',
        'auth_user_id' => 'integer',
        'duration_ms' => 'integer',
        'http_status' => 'integer',
    ];

    public function getOccurredAtAttribute($value)
    {
        // El evento llega con zona horaria y se persiste UTC; no reinterpretarlo como hora
        // argentina al serializarlo, porque se desplazaría tres horas por segunda vez.
        return $value === null ? null : \Carbon\Carbon::parse($value, 'UTC');
    }
}

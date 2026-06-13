<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Registro de lectura per-usuario de un mensaje de lead.
 *
 * Cada fila representa que un admin puntual (`admin_id`) ya leyó un mensaje
 * puntual (`lead_message_id`). La ausencia de fila implica "no leído" para ese admin.
 */
class LeadMessageRead extends Model
{
    /** La tabla solo guarda `read_at`; no usa created_at/updated_at. */
    public $timestamps = false;

    /** Asignación masiva abierta: la tabla es interna y controlada por el backend. */
    protected $guarded = [];

    /**
     * Casts de la marca temporal de lectura.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'read_at' => 'datetime',
    ];

    /**
     * Scope estándar para contrato homogéneo con fullModel (regla admin-api).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     */
    public function scopeWithAll($query) {}
}

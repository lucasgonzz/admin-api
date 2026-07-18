<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Llamada del closer con un lead (refactor "múltiples llamadas por lead", grupo 115, prompt 484).
 *
 * Antes un lead tenía una sola llamada, con los datos sueltos en columnas de `leads`. Ahora cada
 * `LeadCall` concentra, de forma propia (no compartida con otras llamadas del mismo lead): su
 * Meet, su evento de Google Calendar, su bot de Recall.ai, su transcripción completa y su resumen
 * estructurado. Los socios detectados/cargados en la llamada cuelgan de acá (`partners()`).
 */
class LeadCall extends Model
{
    /**
     * Campos asignables en masa.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * Casts de tipos: el resumen estructurado es JSON y las fechas de agenda/inicio son datetime.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'call_summary' => 'array',
        'scheduled_at' => 'datetime',
        'started_at'   => 'datetime',
    ];

    /**
     * Scope `withAll` requerido por la convención de admin-api (regla 20). Todavía no expone
     * relaciones precargadas por defecto; se completa si algún listado empieza a necesitarlas.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     */
    public function scopeWithAll($query)
    {
        // Sin relaciones precargadas por ahora.
    }

    /**
     * Lead dueño de la llamada.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function lead()
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }

    /**
     * Socios detectados/cargados EN esta llamada.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function partners()
    {
        return $this->hasMany(LeadPartner::class, 'lead_call_id');
    }
}

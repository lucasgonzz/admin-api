<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Socio adicional vinculado a un lead (detectado en llamada/WhatsApp o cargado manualmente).
 */
class LeadPartner extends Model
{
    /**
     * Campos asignables en masa desde el panel del closer.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * Casts de tipos para el modelo.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'pending_confirmation' => 'boolean',
    ];

    /**
     * Lead al que pertenece este socio.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function lead()
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }

    /**
     * Socios ya confirmados por el closer (no pendientes de alta).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeConfirmed($query)
    {
        return $query->where('pending_confirmation', false);
    }

    /**
     * Socios sugeridos por IA/transcripción pendientes de confirmación del closer.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePending($query)
    {
        return $query->where('pending_confirmation', true);
    }
}

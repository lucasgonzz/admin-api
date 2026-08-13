<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un hito del roadmap de la demo de un lead (misión 48, pieza 3).
 *
 * Se genera del plan congelado y nace en `pendiente`. Lo mueven los eventos que reporta la
 * instancia de demo, siempre hacia adelante ({@see \App\Services\DemoHitosService}).
 */
class LeadDemoHito extends Model
{
    /** Asignación masiva abierta: tabla interna, escrita sólo por DemoHitosService. */
    protected $guarded = [];

    /** Hito común a todos los leads: entrar a la demo. */
    const TIPO_INGRESO = 'ingreso';

    /** Hito de un clip de núcleo del plan. */
    const TIPO_TUTORIAL = 'tutorial';

    /** Nada pasó todavía. */
    const ESTADO_PENDIENTE = 'pendiente';

    /** Vio el tutorial pero no hizo la acción esperada: acá es donde el lead se trabó. */
    const ESTADO_PARCIAL = 'parcial';

    /** Vio el tutorial y llegó el evento de negocio esperado. */
    const ESTADO_COMPLETO = 'completo';

    /**
     * Orden de avance de los estados. Es lo que hace verificable la regla de que un hito NUNCA
     * retrocede: se compara la posición, no se confía en el orden en que llegan los eventos.
     *
     * @var array<string, int>
     */
    const PESO_ESTADOS = [
        self::ESTADO_PENDIENTE => 0,
        self::ESTADO_PARCIAL   => 1,
        self::ESTADO_COMPLETO  => 2,
    ];

    /**
     * Conversiones de tipo para las dos marcas temporales del avance.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'tutorial_visto_at' => 'datetime',
        'accion_hecha_at'   => 'datetime',
    ];

    /**
     * Scope estándar para contrato homogéneo con fullModel (regla admin-api).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     */
    public function scopeWithAll($query)
    {
        // Sin relaciones propias: el hito se lee siempre por lead_id.
    }

    /**
     * Lead dueño del hito.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }
}

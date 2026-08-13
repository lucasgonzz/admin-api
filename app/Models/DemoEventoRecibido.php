<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un evento crudo reportado por la instancia de demo (misión 48, pieza 4).
 *
 * Se guarda siempre, aunque no le corresponda ningún hito del plan: el crudo es lo que después
 * alimenta el brief del closer (`demo_experiencia.md` §3.9). El `uuid` es único y ahí vive la
 * idempotencia del canal.
 */
class DemoEventoRecibido extends Model
{
    /**
     * Nombre de tabla explícito: el pluralizador de Eloquent sólo pluraliza la ÚLTIMA palabra del
     * nombre de la clase, así que `DemoEventoRecibido` le da `demo_evento_recibidos` y no
     * `demo_eventos_recibidos`. Sin esta línea el endpoint devuelve 500 con "table doesn't exist".
     *
     * @var string
     */
    protected $table = 'demo_eventos_recibidos';

    /** Asignación masiva abierta: tabla interna, escrita sólo por DemoEventosController. */
    protected $guarded = [];

    /**
     * Conversiones de tipo: la carga libre del evento y su momento de ocurrencia.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'datos'       => 'array',
        'ocurrido_at' => 'datetime',
    ];

    /**
     * Scope estándar para contrato homogéneo con fullModel (regla admin-api).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     */
    public function scopeWithAll($query)
    {
        // Sin relaciones propias: se lee siempre por lead_id.
    }

    /**
     * Lead al que pertenece el evento.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }
}

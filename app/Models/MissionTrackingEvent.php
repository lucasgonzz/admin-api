<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un hito del historial corto de una misión (misión dotin-misiones-admin): arranque confirmado, corte técnico,
 * reanudación, pregunta, respuesta registrada o aplicada, detenida, terminada. Idempotente por `event_uid`.
 *
 * @property int                        $id
 * @property string                     $mission_key
 * @property string                     $event_uid
 * @property string                     $tipo
 * @property string|null                $estado
 * @property string|null                $fase
 * @property string|null                $texto
 * @property \Illuminate\Support\Carbon $ocurrido_at
 */
class MissionTrackingEvent extends Model
{
    /** @var string */
    protected $table = 'mission_tracking_events';

    /** @var array<int, string> */
    protected $fillable = ['mission_key', 'event_uid', 'tipo', 'estado', 'fase', 'texto', 'ocurrido_at'];

    /** @var array<string, string> */
    protected $casts = ['ocurrido_at' => 'datetime'];

    /**
     * Placeholder de consistencia con el resto de los modelos del admin (no hay relaciones que cargar).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return void
     */
    public function scopeWithAll($query)
    {
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * El estado de una misión tal como lo publica la Gamer en `PUT claude/missions/{slug}` (misión dotin-misiones-admin).
 *
 * Una fila por misión (`mission_key` = `cc:<slug>`). La escribe {@see \App\Services\Missions\MissionTrackingService}
 * y la leen El local (escena) y el conector MCP. `reportado_at` es la hora DEL ADMIN del último PUT aplicado: la
 * frescura se mide contra ella.
 *
 * @property int                             $id
 * @property string                          $mission_key
 * @property string                          $slug
 * @property string|null                     $host
 * @property string|null                     $pedido_id
 * @property string|null                     $hilo
 * @property string|null                     $titulo
 * @property string                          $origen
 * @property array|null                      $repos
 * @property string                          $estado
 * @property \Illuminate\Support\Carbon|null $estado_desde
 * @property string|null                     $fase
 * @property string|null                     $etapa
 * @property \Illuminate\Support\Carbon|null $fase_desde
 * @property string|null                     $ultimo_avance
 * @property string|null                     $pendiente
 * @property string|null                     $motivo
 * @property string|null                     $proximo_paso
 * @property int                             $ejecucion_n
 * @property int                             $intentos_tecnicos
 * @property \Illuminate\Support\Carbon|null $arranque_confirmado_at
 * @property \Illuminate\Support\Carbon|null $ultima_actividad_real_at
 * @property \Illuminate\Support\Carbon|null $reportado_at
 * @property int                             $seq
 * @property string|null                     $pregunta_abierta_uid
 * @property array|null                      $evidencia
 * @property \Illuminate\Support\Carbon|null $terminada_at
 */
class MissionTracking extends Model
{
    /** @var string */
    protected $table = 'mission_trackings';

    /** @var array<int, string> */
    protected $fillable = [
        'mission_key', 'slug', 'host', 'pedido_id', 'hilo', 'titulo', 'origen', 'repos', 'estado', 'estado_desde', 'fase',
        'etapa', 'fase_desde', 'ultimo_avance', 'pendiente', 'motivo', 'proximo_paso', 'ejecucion_n', 'intentos_tecnicos',
        'arranque_confirmado_at', 'ultima_actividad_real_at', 'reportado_at', 'seq', 'pregunta_abierta_uid', 'evidencia',
        'terminada_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'repos'                    => 'array',
        'evidencia'                => 'array',
        'ejecucion_n'              => 'integer',
        'intentos_tecnicos'        => 'integer',
        'seq'                      => 'integer',
        'estado_desde'             => 'datetime',
        'fase_desde'               => 'datetime',
        'arranque_confirmado_at'   => 'datetime',
        'ultima_actividad_real_at' => 'datetime',
        'reportado_at'             => 'datetime',
        'terminada_at'             => 'datetime',
    ];

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

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una fase del historial de una misión de «El local» (misión el-local-linux, 25/11/2026).
 *
 * Una fila por misión, fase y vuelta. La escribe {@see \App\Services\Local\PcSnapshotService} con el
 * `fase_historial` que manda el reporter y la lee {@see \App\Services\Local\EscenaService} para calcular la
 * demora. Es consultable por misión (`mission_key`) y por rango de `desde`.
 *
 * @property int         $id
 * @property string      $mission_key  Id de la misión (`cc:…`, `oc:…`, `codex:…`, `wt:…`).
 * @property string|null $origen
 * @property string|null $titulo
 * @property string      $fase         Fase fina (plan, construccion, revision-1, suite-final…).
 * @property string|null $etapa        plan | construccion | revision | tests | merge | cierre
 * @property int         $vuelta       N.º de aparición de la fase en la misión.
 * @property \Illuminate\Support\Carbon      $desde
 * @property \Illuminate\Support\Carbon|null $hasta
 * @property int|null    $segundos
 * @property bool        $inferida
 */
class LocalMissionPhase extends Model
{
    /**
     * @var string
     */
    protected $table = 'local_mission_phases';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'mission_key',
        'origen',
        'titulo',
        'fase',
        'etapa',
        'vuelta',
        'desde',
        'hasta',
        'segundos',
        'inferida',
        'reportado_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'vuelta'       => 'integer',
        'segundos'     => 'integer',
        'inferida'     => 'boolean',
        'desde'        => 'datetime',
        'hasta'        => 'datetime',
        'reportado_at' => 'datetime',
    ];

    /**
     * Scope de carga completa (convención del repo): esta tabla no tiene relaciones que cargar.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return void
     */
    public function scopeWithAll($query)
    {
    }
}

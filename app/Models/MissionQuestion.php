<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una pregunta de decisión que una misión le hace a Lucas (misión dotin-misiones-admin).
 *
 * Estados: `abierta` (se puede responder), `respondida` (hay una respuesta registrada que la Gamer todavía no
 * aplicó), `aplicada`, `cerrada` (la cerró la Gamer o se rechazó la respuesta) y `reemplazada` (otra pregunta ocupó
 * su lugar). `tipo = sensible` nunca se responde por el Admin ni por MCP.
 *
 * @property int                             $id
 * @property string                          $mission_key
 * @property string                          $question_uid
 * @property int                             $revision
 * @property string                          $tipo         `ordinaria` o `sensible`.
 * @property string                          $pregunta
 * @property array                           $opciones     Lista de `{clave, texto, impacto}`.
 * @property string|null                     $recomendada
 * @property string                          $estado
 * @property \Illuminate\Support\Carbon|null $answered_at
 * @property \Illuminate\Support\Carbon|null $applied_at
 * @property \Illuminate\Support\Carbon|null $closed_at
 * @property string|null                     $cierre_motivo
 */
class MissionQuestion extends Model
{
    /** @var string */
    protected $table = 'mission_questions';

    /** @var array<int, string> */
    protected $fillable = [
        'mission_key', 'question_uid', 'revision', 'tipo', 'pregunta', 'opciones', 'recomendada', 'estado', 'answered_at',
        'applied_at', 'closed_at', 'cierre_motivo',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'revision'    => 'integer',
        'opciones'    => 'array',
        'answered_at' => 'datetime',
        'applied_at'  => 'datetime',
        'closed_at'   => 'datetime',
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

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * La respuesta de Lucas a una pregunta de misión (misión dotin-misiones-admin), registrada desde el Admin o por MCP.
 *
 * Es la bandeja durable Admin -> Gamer: `registrada -> reclamada -> aplicada`, o `registrada|reclamada -> rechazada`.
 * Una sola respuesta vigente (`registrada|reclamada|aplicada`) por pregunta. `claim_token` identifica a quien la
 * reclamó (la Gamer lo genera y lo persiste antes de reclamar).
 *
 * @property int                             $id
 * @property int                             $question_id
 * @property string                          $mission_key
 * @property string                          $question_uid
 * @property int                             $revision
 * @property string                          $answer_uid
 * @property string|null                     $opcion
 * @property string|null                     $texto
 * @property int                             $admin_id
 * @property string                          $canal         `admin` o `mcp`.
 * @property string                          $estado
 * @property string|null                     $claim_token
 * @property \Illuminate\Support\Carbon|null $claimed_at
 * @property \Illuminate\Support\Carbon|null $applied_at
 * @property int|null                        $ejecucion_n
 * @property string|null                     $rechazo_motivo
 * @property string|null                     $rechazo_detalle
 */
class MissionAnswer extends Model
{
    /** @var string */
    protected $table = 'mission_answers';

    /** @var array<int, string> */
    protected $fillable = [
        'question_id', 'mission_key', 'question_uid', 'revision', 'answer_uid', 'opcion', 'texto', 'admin_id', 'canal',
        'estado', 'claim_token', 'claimed_at', 'applied_at', 'ejecucion_n', 'rechazo_motivo', 'rechazo_detalle',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'question_id' => 'integer',
        'revision'    => 'integer',
        'admin_id'    => 'integer',
        'ejecucion_n' => 'integer',
        'claimed_at'  => 'datetime',
        'applied_at'  => 'datetime',
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

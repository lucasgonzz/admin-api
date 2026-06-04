<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Instancia de una etapa dentro de una implementación concreta.
 *
 * @property int         $implementation_id Implementación padre.
 * @property int         $stage_number      Número de etapa (1–7).
 * @property string      $status            pending | in_progress | completed | skipped.
 * @property array|null  $data              Respuestas recolectadas en la etapa.
 * @property int         $alert_count       Cantidad de alertas enviadas.
 */
class ImplementationStage extends Model
{
    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'started_at'          => 'datetime',
        'completed_at'        => 'datetime',
        'last_alert_sent_at'  => 'datetime',
        'alert_count'         => 'integer',
        'data'                => 'array',
    ];

    /**
     * Implementación dueña de esta etapa.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function implementation()
    {
        return $this->belongsTo(Implementation::class);
    }

    /**
     * Configuración maestra de esta etapa (catálogo por stage_number).
     * Permite mostrar el nombre y descripción desde ImplementationStageConfig.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function config()
    {
        return $this->belongsTo(ImplementationStageConfig::class, 'stage_number', 'stage_number');
    }
}

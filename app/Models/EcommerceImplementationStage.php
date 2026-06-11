<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Instancia de una etapa dentro de una implementación de ecommerce concreta.
 *
 * @property int        $ecommerce_implementation_id Implementación padre.
 * @property int        $stage_number                Número de etapa (1–5).
 * @property string     $status                      pending | in_progress | completed.
 * @property array|null $data                        Respuestas recolectadas en la etapa.
 */
class EcommerceImplementationStage extends Model
{
    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'ecommerce_implementation_id',
        'stage_number',
        'status',
        'data',
        'started_at',
        'completed_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
        'data'         => 'array',
    ];

    /**
     * Implementación dueña de esta etapa.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function ecommerce_implementation()
    {
        return $this->belongsTo(EcommerceImplementation::class);
    }

    /**
     * Configuración maestra de esta etapa (catálogo por stage_number).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function config()
    {
        return $this->belongsTo(EcommerceImplementationStageConfig::class, 'stage_number', 'stage_number');
    }
}

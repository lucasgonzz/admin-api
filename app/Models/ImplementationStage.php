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
    /**
     * Al completarse la etapa 5 («Entrega») de la implementación de un cliente con la prueba pausada, la prueba se reanuda: siete
     * días desde la entrega (misión embudo-juan-pipeline, E2). Cubre al panel y a Claude, que completan la etapa con `->save()`.
     *
     * Va envuelto en try/catch: un error de la reanudación no puede tirar el avance de la etapa.
     *
     * @return void
     */
    protected static function booted()
    {
        static::saved(function (ImplementationStage $etapa) {
            if ((int) $etapa->stage_number !== 5 || ! $etapa->wasChanged('status') || (string) $etapa->status !== 'completed') {
                return;
            }

            try {
                $implementacion = Implementation::query()->find((int) $etapa->implementation_id);
                $cliente        = $implementacion !== null ? Client::query()->find((int) $implementacion->client_id) : null;
                $servicio       = app(\App\Services\ImplementacionContratadaService::class);

                if ($cliente !== null && $servicio->esta_pausada($cliente)) {
                    $servicio->reanudar_prueba($cliente, \App\Helpers\AppTime::now());
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::channel('daily')->error('ImplementationStage: no se pudo reanudar la prueba al entregar.', [
                    'implementation_id' => $etapa->implementation_id,
                    'error'             => $e->getMessage(),
                ]);
            }
        });
    }

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

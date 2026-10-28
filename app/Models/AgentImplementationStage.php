<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una de las ocho etapas de una implementación de Agentes de IA (misión `implementacion-agentes-ia`,
 * 28/10/2026).
 *
 * `data` guarda lo registrado en la etapa (las claves sugeridas de cada etapa están en
 * `AgentImplementation::STAGES`) y, bajo `whatsapp_ops`, las operaciones idempotentes de los WhatsApp
 * mandados por el ticket de soporte (`AgentImplementationSupportWhatsappService`). 🔴 `whatsapp_ops` es
 * interno: no viaja en el contexto de Martín y no se puede escribir desde `POST .../stages/{n}`.
 *
 * @property int                 $id
 * @property int                 $agent_implementation_id Implementación padre.
 * @property int                 $stage_number            1 a 8.
 * @property string              $status                  pending | in_progress | completed | skipped.
 * @property array|null          $data                    Lo registrado en la etapa.
 * @property \Carbon\Carbon|null $started_at
 * @property \Carbon\Carbon|null $completed_at
 */
class AgentImplementationStage extends Model
{
    /** Sin arrancar. */
    const STATUS_PENDING = 'pending';

    /** En curso. */
    const STATUS_IN_PROGRESS = 'in_progress';

    /** Terminada. */
    const STATUS_COMPLETED = 'completed';

    /** Salteada a propósito (por ejemplo el Entrenamiento en el camino `nuevo`). */
    const STATUS_SKIPPED = 'skipped';

    /** Los estados posibles de una etapa. */
    const STATUSES = [self::STATUS_PENDING, self::STATUS_IN_PROGRESS, self::STATUS_COMPLETED, self::STATUS_SKIPPED];

    /** Clave reservada de `data` donde viven las operaciones de WhatsApp (no viaja ni se escribe desde afuera). */
    const DATA_KEY_WHATSAPP_OPS = 'whatsapp_ops';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'agent_implementation_id',
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
        'agent_implementation_id' => 'integer',
        'stage_number'            => 'integer',
        'data'                    => 'array',
        'started_at'              => 'datetime',
        'completed_at'            => 'datetime',
    ];

    /**
     * Eager load de la implementación padre.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithAll($query)
    {
        return $query->with(['agent_implementation']);
    }

    /**
     * Implementación dueña de esta etapa.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function agent_implementation()
    {
        return $this->belongsTo(AgentImplementation::class);
    }

    /**
     * `data` sin las claves internas (hoy, `whatsapp_ops`): lo que se puede mostrar afuera. Nunca null:
     * una etapa sin nada registrado devuelve `[]`.
     *
     * @return array<string, mixed>
     */
    public function public_data()
    {
        $data = is_array($this->data) ? $this->data : [];
        unset($data[self::DATA_KEY_WHATSAPP_OPS]);

        return $data;
    }
}

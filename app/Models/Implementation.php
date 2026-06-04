<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Proceso de implementación guiada de un cliente (una instancia por client_id).
 *
 * @property int         $client_id      Cliente dueño.
 * @property int         $current_stage  Etapa actual (1–7).
 * @property string      $status         pending | in_progress | completed | paused.
 * @property string|null $migration_contact_phone Teléfono del responsable de migración.
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 */
class Implementation extends Model
{
    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'current_stage' => 'integer',
        'started_at'    => 'datetime',
        'completed_at'  => 'datetime',
        'status'        => 'string',
    ];

    /**
     * Eager load de relaciones habituales del panel.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithAll($query)
    {
        $query->with(['client', 'stages', 'messages']);
    }

    /**
     * Cliente dueño de esta implementación.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Etapas instanciadas de esta implementación.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function stages()
    {
        return $this->hasMany(ImplementationStage::class)->orderBy('stage_number', 'asc');
    }

    /**
     * Mensajes WhatsApp del flujo.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function messages()
    {
        return $this->hasMany(ImplementationMessage::class)->orderBy('sent_at', 'asc');
    }

    /**
     * Registro de la etapa actual según current_stage.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function current_stage_record()
    {
        return $this->hasOne(ImplementationStage::class)
            ->where('stage_number', $this->current_stage);
    }

    /**
     * Configuración maestra de la etapa actual.
     *
     * @return ImplementationStageConfig|null
     */
    public function current_stage_config()
    {
        return ImplementationStageConfig::where('stage_number', $this->current_stage)->first();
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Mensaje de la conversación WhatsApp de un lead (texto del lead, del setter o sugerencia de Claude).
 */
class LeadMessage extends Model
{
    protected $guarded = [];

    /**
     * Etiqueta legible del estado sugerido (para badges en admin-spa).
     *
     * @var array<int, string>
     */
    protected $appends = ['suggested_lead_status_label'];

    /**
     * Casts de tiempos de estado del mensaje.
     * Campo WhatsApp mass-assignable vía guarded=[]: whatsapp_message_id.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_followup'           => 'boolean',
        'requiere_verificacion' => 'boolean',
        'sent_at'               => 'datetime',
        'read_at'               => 'datetime',
    ];

    /**
     * Etiqueta del estado de pipeline sugerido por Claude en este mensaje.
     *
     * @return string|null
     */
    public function getSuggestedLeadStatusLabelAttribute(): ?string
    {
        return LeadPipelineStatus::label_for($this->suggested_lead_status);
    }

    /**
     * Lead dueño del mensaje.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * Scope estándar para contrato homogéneo con fullModel.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     */
    public function scopeWithAll($query)
    {
        $query->with('attachments');
    }

    /**
     * Adjuntos multimedia (audio, imagen, etc.) descargados del webhook.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function attachments()
    {
        return $this->hasMany(LeadMessageAttachment::class, 'lead_message_id');
    }
}

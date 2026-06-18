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
        'is_followup'             => 'boolean',
        'requiere_verificacion'   => 'boolean',
        'sent_at'                 => 'datetime',
        'read_at'                 => 'datetime',
        'ai_auto_send_at'         => 'datetime',
        /* Momento en que el lead reaccionó a este mensaje por WhatsApp. */
        'lead_reaction_at'        => 'datetime',
        /* Mensaje excluido del historial enviado a Claude (marcado manualmente por el operador). */
        'deleted_from_context'    => 'boolean',
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

    /**
     * Registros de lectura per-usuario de este mensaje (un admin = una fila).
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function reads()
    {
        return $this->hasMany(LeadMessageRead::class, 'lead_message_id');
    }

    /**
     * Mensajes que deben viajar en listados de leads (notificaciones / pendientes de acción).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForListNotifications($query)
    {
        return $query->where(function ($wrap) {
            $wrap->where(function ($sub) {
                $sub->where('sender', 'lead')->whereNull('read_at');
            })->orWhere(function ($sub) {
                $sub->where('sender', 'sistema')->where('status', 'sugerido');
            })->orWhere(function ($sub) {
                $sub->where('sender', 'lead')
                    ->where('status', 'enviado')
                    ->whereNotExists(function ($exists) {
                        $exists->selectRaw('1')
                            ->from('lead_messages as outbound')
                            ->whereColumn('outbound.lead_id', 'lead_messages.lead_id')
                            ->whereColumn('outbound.id', '>', 'lead_messages.id')
                            ->where(function ($outbound_wrap) {
                                $outbound_wrap->where(function ($setter) {
                                    $setter->where('outbound.sender', 'setter')
                                        ->whereIn('outbound.status', ['enviado', 'aprobado']);
                                })->orWhere(function ($sistema) {
                                    $sistema->where('outbound.sender', 'sistema')
                                        ->where('outbound.status', 'aprobado');
                                });
                            });
                    });
            });
        });
    }
}

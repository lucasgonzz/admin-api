<?php

namespace App\Models;

use App\Models\Concerns\UsesVirtualTime;
use Illuminate\Database\Eloquent\Model;

/**
 * Mensaje de la conversación WhatsApp de un lead (texto del lead, del setter o sugerencia de Claude).
 */
class LeadMessage extends Model
{
    use UsesVirtualTime;

    protected $guarded = [];

    /**
     * Al crear un mensaje, actualiza last_message_at del lead para ordenar la bandeja.
     *
     * @return void
     */
    protected static function booted()
    {
        static::created(function (LeadMessage $message) {
            if (! $message->lead_id) {
                return;
            }

            /* Los eventos de cambio de estado no son actividad real del hilo:
               no deben actualizar last_message_at ni generar badge de "sin leer". */
            if ($message->is_status_event) {
                return;
            }

            /** Preferir sent_at (webhook WhatsApp) sobre created_at del registro. */
            $timestamp = $message->sent_at ?? $message->created_at ?? \App\Helpers\AppTime::now();

            /** Lead dueño del mensaje: actualizar last_message_at y, si aplica, first_message_at. */
            $lead = Lead::query()->where('id', $message->lead_id)->first();
            if (! $lead) {
                return;
            }

            /** Siempre refrescar la actividad reciente del hilo. */
            $lead_updates = ['last_message_at' => $timestamp];

            /** Solo el primer mensaje del hilo define el inicio de conversación. */
            if ($lead->first_message_at === null) {
                $lead_updates['first_message_at'] = $timestamp;
            }

            $lead->update($lead_updates);
        });
    }

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
        /* True si el mensaje representa un evento interno de cambio de estado (no se envió por WhatsApp). */
        'is_status_event'                 => 'boolean',
        /* True si en este mensaje el agente confirmó por primera vez el ingreso a la demo. */
        'marca_demo_ingreso_confirmado'   => 'boolean',
        /* True si en este mensaje el agente confirmó por primera vez el fin de la demo. */
        'marca_demo_terminada_confirmada' => 'boolean',
        'requiere_verificacion'           => 'boolean',
        'sent_at'                 => 'datetime',
        'read_at'                 => 'datetime',
        'ai_auto_send_at'         => 'datetime',
        /* Momento en que el lead reaccionó a este mensaje por WhatsApp. */
        'lead_reaction_at'        => 'datetime',
        /* Mensaje excluido del historial enviado a Claude (marcado manualmente por el operador). */
        'deleted_from_context'    => 'boolean',
        /* Array de eventos de notificación a admins disparados por este mensaje. Cada elemento: ['evento' => ..., 'admins' => [...]]. */
        'admin_notifications'     => 'array',
        /* $parsed crudo de Claude sin aplicar, cuando el mensaje quedó pendiente por el motivo
           "agendamiento" (ver LeadAiService::requires_agendamiento_verification_gate). Null en el resto de los casos. */
        'pending_actions'         => 'array',
        /* Timestamps de entrega real de WhatsApp (populated por webhooks de Kapso). */
        'whatsapp_delivered_at'   => 'datetime',
        'whatsapp_seen_at'        => 'datetime',
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
        /* Excluir mensajes de sistema que solo registran cambios de estado internos:
           no representan actividad real ni requieren acción del operador. */
        return $query->where('is_status_event', false)->where(function ($wrap) {
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

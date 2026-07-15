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
    protected $appends = ['suggested_lead_status_label', 'pending_actions_summary', 'sent_by_admin_name'];

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
        /* True si el mensaje es un registro de ERROR de sistema (fallo de envío o de generación) que se
           muestra en el hilo como bloque rojo. Va siempre junto con is_status_event=true. */
        'is_error'                        => 'boolean',
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
        /* Lista legible (en español) de las acciones que efectivamente se aplicaron al enviar/aprobar
           este mensaje (agendar demo, guardar email, guardar nombre, cambio de estado, etc.). Se setea
           en LeadAiService::apply_parsed_response() a partir de LeadMessage::build_actions_summary().
           Null cuando el mensaje no ejecutó ninguna acción estructurada (prompt 277). */
        'applied_actions_summary' => 'array',
        /* Diff entre lo que sugirió Claude y lo que el admin dejó al aprobar (editó/desactivó
           acciones antes de aprobar). Cada elemento: ['campo' => ..., 'sugerido_por_claude' => ...,
           'elegido_por_admin' => ...]. Null cuando el admin no cambió ninguna acción (prompt 318). */
        'actions_override_log'    => 'array',
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
     * Nombre del admin que envió/aprobó este mensaje, para mostrar en la burbuja
     * del admin-spa (prompt 403). Solo se resuelve si la relación ya viene eager-loaded (la carga
     * la relación messages() del Lead); en cualquier otro contexto devuelve null sin
     * consultar la BD, para no generar N+1 al serializar hilos largos.
     *
     * @return string|null
     */
    public function getSentByAdminNameAttribute()
    {
        // Guarda: si la relación no fue eager-loaded, no disparar una consulta nueva por mensaje.
        if (! $this->relationLoaded('sent_by_admin')) {
            return null;
        }
        $admin = $this->getRelation('sent_by_admin');

        return $admin ? (string) $admin->name : null;
    }

    /**
     * Arma la lista legible (en español) de acciones a partir de un $parsed de Claude.
     * Compartido entre el resumen "pendiente" (pending_actions_summary) y el registro de
     * acciones "ejecutadas" (applied_actions_summary, seteado por LeadAiService al finalizar).
     *
     * @param array<string, mixed>|null $parsed      Paquete crudo devuelto/aplicado por Claude.
     * @param string|null               $lead_status Estado actual del lead (para decidir si el
     *                                                cambio de estado es real).
     *
     * @return array<int, string>
     */
    public static function build_actions_summary(?array $parsed, ?string $lead_status): array
    {
        if (empty($parsed) || ! is_array($parsed)) {
            return [];
        }

        $acciones = [];

        /* agendar_demo: mostrar día y hora si vienen presentes en el paquete. */
        if (! empty($parsed['agendar_demo']) && is_array($parsed['agendar_demo'])) {
            $agendar_demo = $parsed['agendar_demo'];
            $demo_date    = isset($agendar_demo['demo_date']) ? trim((string) $agendar_demo['demo_date']) : '';
            $demo_start   = isset($agendar_demo['demo_start_time']) ? trim((string) $agendar_demo['demo_start_time']) : '';
            $fecha_legible = '';
            if ($demo_date !== '') {
                try {
                    $fecha_legible = \Carbon\Carbon::createFromFormat('Y-m-d', $demo_date)->format('d/m');
                } catch (\Throwable $e) {
                    $fecha_legible = $demo_date;
                }
            }
            $detalle = trim($fecha_legible.($demo_start !== '' ? ' '.$demo_start : ''));
            $acciones[] = $detalle !== '' ? "Agendar demo: {$detalle}" : 'Agendar demo';
        }

        /* guardar_email: mostrar la dirección que se va a registrar y usar para el mail de acceso. */
        $guardar_email = isset($parsed['guardar_email']) ? trim((string) $parsed['guardar_email']) : '';
        if ($guardar_email !== '') {
            $acciones[] = "Enviar mail de acceso a la demo a {$guardar_email}";
        }

        /* cancelar_demo: reagendado en curso. */
        if (! empty($parsed['cancelar_demo'])) {
            $acciones[] = 'Cancelar/reagendar la demo actual';
        }

        /* guardar_nombre: nombre nuevo detectado para el lead. */
        $guardar_nombre = isset($parsed['guardar_nombre']) ? trim((string) $parsed['guardar_nombre']) : '';
        if ($guardar_nombre !== '') {
            $acciones[] = "Guardar nombre del lead: {$guardar_nombre}";
        }

        /* estado_sugerido: solo si implica un cambio real respecto al estado actual del lead. */
        $estado_sugerido = isset($parsed['estado_sugerido']) ? trim((string) $parsed['estado_sugerido']) : '';
        $lead_status     = (string) $lead_status;
        if ($estado_sugerido !== '' && $estado_sugerido !== $lead_status) {
            $label      = LeadPipelineStatus::label_for($estado_sugerido);
            $acciones[] = 'Cambiar estado del lead a: '.($label ?? $estado_sugerido);
        }

        /* requiere_intervencion_humana: alertar que este paquete deriva a revisión manual. */
        if (! empty($parsed['requiere_intervencion_humana'])) {
            $motivo     = isset($parsed['motivo_intervencion']) ? trim((string) $parsed['motivo_intervencion']) : '';
            $acciones[] = 'Requiere intervención humana'.($motivo !== '' ? ": {$motivo}" : '');
        }

        return $acciones;
    }

    /**
     * Lista legible (en español) de las acciones que se van a ejecutar si se aprueba este
     * mensaje pendiente (`pending_actions`, motivo agendamiento — ver
     * LeadAiService::create_pending_agendamiento_message()). Permite que admin-spa muestre a
     * Martín, antes de aprobar, qué va a pasar realmente (agendar tal día/hora, enviar mail a
     * tal dirección, cambiar el estado del lead), sin tener que interpretar el JSON crudo.
     *
     * @return array<int, string>
     */
    public function getPendingActionsSummaryAttribute(): array
    {
        $parsed = $this->pending_actions;
        if (empty($parsed) || ! is_array($parsed)) {
            return [];
        }

        return static::build_actions_summary($parsed, $this->lead ? (string) $this->lead->status : '');
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
     * Admin que envió o aprobó este mensaje saliente (null si lo auto-envió la IA
     * o si es historial importado). Ver columna sent_by_admin_id (prompt 403).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function sent_by_admin()
    {
        return $this->belongsTo(Admin::class, 'sent_by_admin_id');
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

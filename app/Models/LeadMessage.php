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

    /**
     * Valor de `sent_via` para los mensajes que envió Claude por los endpoints `claude/*`
     * (analítica y recuperación de leads). Mismo criterio que `admin_tasks.created_via`.
     *
     * La columna es nullable: null significa "origen no marcado", que es el estado de todo
     * lo anterior a la migración 2026_08_24_150000. Ver el docblock de esa migración.
     */
    public const SENT_VIA_CLAUDE = 'claude';

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
        /* Horarios que el TEXTO del mensaje declara haber ofrecido (grupo 306, prompt 04):
           [{fecha, desde, hasta}, ...]. Null = no se declaró nada (dinámica actual, o mensaje que no
           ofrece horarios); [] = se declaró explícitamente que no ofrece ninguno. Solo aplica a
           demo_experiencia='nueva'. LeadSuggestionSendService::send_suggestion() lo revalida contra
           un cálculo fresco de disponibilidad justo antes de enviar. */
        'horarios_ofrecidos'      => 'array',
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
     * ¿Alguno de los horarios que un mensaje declaró haber ofrecido cubre este (fecha, hora)?
     *
     * Lógica pura sobre el campo `horarios_ofrecidos`: no consulta la base ni decide
     * disponibilidad. Vive acá y no en LeadAiService porque es del modelo, se testea sin base, y
     * LeadAiService ya tiene 6000 líneas.
     *
     * Normaliza la hora con el MISMO criterio que ya usan
     * LeadAiService::descartar_agendamiento_fuera_de_slots() y ::revalidar_horarios_ofrecidos()
     * (preg_match de (\d{1,2}):(\d{2}) + cero a la izquierda): no se inventa una tercera forma de
     * normalizar una hora.
     *
     * 🔴 `hasta` es INCLUSIVO a propósito. La oferta primaria se declara con desde == hasta, así
     * que un `hasta` exclusivo no matchearía nunca el caso principal; y cuando el agente ofrece un
     * rango ("de 13 a 16:30") el texto nombró las 16:30, así que negárselas al lead es justo el bug
     * que este método arregla. Y NO se exige que la hora sea un slot de la grilla: este método da
     * PERMISO para ignorar el margen de anticipación, no decide disponibilidad — eso lo decide la
     * grilla fresca que recalcula LeadAiService::oferta_vigente_sin_margen(), que por construcción
     * solo trae slots reales. Para saber si "16:07" era slot al momento de ofrecer haría falta la
     * grilla de ese instante, que no guardamos.
     *
     * Si `hasta` viene vacío, ilegible o lexicográficamente menor que `desde`, el ítem se trata
     * como un punto (solo `desde`): una declaración mal formada no puede ensanchar el permiso.
     *
     * @param array<int, mixed> $horarios_ofrecidos Contenido de lead_messages.horarios_ofrecidos:
     *                                              [{fecha: Y-m-d, desde: HH:MM, hasta: HH:MM}, ...].
     * @param string            $fecha              Fecha buscada, en Y-m-d.
     * @param string            $hora               Hora buscada (se normaliza a HH:MM).
     *
     * @return bool true si ese (fecha, hora) figura entre lo ofrecido.
     */
    public static function horarios_ofrecidos_cubren(array $horarios_ofrecidos, string $fecha, string $hora): bool
    {
        $fecha = trim($fecha);
        $hora  = self::normalizar_hora_ofrecida($hora);

        if ($fecha === '' || $hora === '') {
            return false;
        }

        foreach ($horarios_ofrecidos as $item) {
            if (! is_array($item)) {
                continue;
            }

            $fecha_item = isset($item['fecha']) ? trim((string) $item['fecha']) : '';
            if ($fecha_item === '' || $fecha_item !== $fecha) {
                continue;
            }

            $desde = self::normalizar_hora_ofrecida(isset($item['desde']) ? (string) $item['desde'] : '');
            if ($desde === '') {
                continue;
            }

            $hasta = self::normalizar_hora_ofrecida(isset($item['hasta']) ? (string) $item['hasta'] : '');
            /* Declaración mal formada (hasta vacío, ilegible o anterior al desde): se trata como
             * un punto, no como un rango abierto. */
            if ($hasta === '' || $hasta < $desde) {
                $hasta = $desde;
            }

            /* Con cero a la izquierda, el orden lexicográfico de "HH:MM" ES el cronológico. */
            if ($hora >= $desde && $hora <= $hasta) {
                return true;
            }
        }

        return false;
    }

    /**
     * ¿Le ofrecimos ESTE (fecha, hora) a ESTE lead, en un mensaje que ya recibió?
     *
     * Es la fuente de verdad del permiso para ignorar el margen mínimo de anticipación
     * (ver LeadAiService::oferta_vigente_sin_margen()). Consulta `lead_messages.horarios_ofrecidos`
     * y delega el matching en horarios_ofrecidos_cubren().
     *
     * Qué mensajes cuentan y por qué:
     * - Solo del MISMO lead: el permiso es "se lo ofrecimos a ESE lead", no "existe en el sistema".
     * - Solo `sender = 'sistema'`: un mensaje del lead no ofrece nada.
     * - Solo `status = 'enviado'`: la fuente de verdad es lo que el lead EFECTIVAMENTE recibió. Un
     *   mensaje `sugerido` (sin aprobar) o `rechazado` no llegó, y darle permiso a saltar el margen
     *   por un texto que nadie mandó abriría la puerta a que una sugerencia descartada habilite un
     *   horario.
     * - Ventana de 24hs: no es arbitraria. En este flujo el lead solo puede aceptar escribiendo, y
     *   para que su mensaje entre por texto libre la ventana de WhatsApp tiene que estar abierta —
     *   24hs desde su último mensaje (ver LeadSuggestionSendService::is_within_whatsapp_window()).
     *   Una oferta de hace más de un día no puede estar siendo aceptada por un turno de esta
     *   conversación. Es una guarda defensiva barata: aunque no estuviera, la grilla fresca seguiría
     *   atajando "ya pasó" y "se ocupó".
     *
     * `whereNotNull('horarios_ofrecidos')` deja afuera solo los mensajes de
     * LeadConversationErrorLogger (que son `enviado` con ese campo en null). No hace falta índice
     * nuevo: `lead_id` y `status` ya están indexados, y la columna nunca se busca por contenido.
     *
     * @param int    $lead_id Lead dueño de la conversación.
     * @param string $fecha   Fecha buscada, en Y-m-d.
     * @param string $hora    Hora buscada (se normaliza a HH:MM).
     *
     * @return bool true si ese (fecha, hora) figura entre los horarios ofrecidos a este lead.
     */
    public static function horario_figura_como_ofrecido(int $lead_id, string $fecha, string $hora): bool
    {
        if ($lead_id <= 0 || trim($fecha) === '' || trim($hora) === '') {
            return false;
        }

        $desde_ts = \App\Helpers\AppTime::now()->copy()->subHours(24);

        $mensajes = self::query()
            ->where('lead_id', $lead_id)
            ->where('sender', 'sistema')
            ->where('status', 'enviado')
            ->whereNotNull('horarios_ofrecidos')
            ->where(function ($q) use ($desde_ts) {
                $q->where('sent_at', '>=', $desde_ts)
                  ->orWhere(function ($q2) use ($desde_ts) {
                      $q2->whereNull('sent_at')->where('created_at', '>=', $desde_ts);
                  });
            })
            ->orderBy('id', 'desc')
            ->limit(20)
            ->get(['id', 'horarios_ofrecidos']);

        foreach ($mensajes as $mensaje) {
            $horarios = $mensaje->horarios_ofrecidos;
            if (! is_array($horarios) || empty($horarios)) {
                continue;
            }

            if (self::horarios_ofrecidos_cubren($horarios, $fecha, $hora)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normaliza una hora suelta a "HH:MM", o string vacío si no es legible.
     *
     * Mismo criterio que LeadAiService::descartar_agendamiento_fuera_de_slots() (preg_match de
     * (\d{1,2}):(\d{2}) + str_pad a dos dígitos): tolera "9:05", " 09:05 " y "09:05:00".
     *
     * @param string $hora Hora cruda declarada por el agente.
     *
     * @return string "HH:MM" o '' si no se pudo leer.
     */
    private static function normalizar_hora_ofrecida(string $hora): string
    {
        if (! preg_match('/(\d{1,2}):(\d{2})/', $hora, $m)) {
            return '';
        }

        return str_pad($m[1], 2, '0', STR_PAD_LEFT) . ':' . $m[2];
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

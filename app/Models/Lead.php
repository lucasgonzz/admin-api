<?php

namespace App\Models;

use App\ModelProperties\LeadProperties;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\UsesVirtualTime;
use App\Models\LeadAdminNotification;
use App\Models\LeadPipelineStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Prospecto comercial cargado desde el panel de admin-api.
 *
 * Un Lead concentra:
 * - datos de contacto,
 * - configuración técnica del sistema (espejo del formulario de demo),
 * - referencia al empresa-api destino donde se corre la demo remotamente,
 * - api_url del sistema productivo del cliente (para user-setup una vez promovido),
 * - trazabilidad del mail de presentación y del setup remoto,
 * - fecha de la demo y horas de inicio/fin (texto) acordadas con el prospecto,
 * - tutoriales de video personalizados para el mail de demo (`personalized_demo_videos`).
 *
 * Ver migraciones de la tabla `leads` (create y alter) para el esquema de columnas.
 */
class Lead extends Model
{
    use HasUuid;
    use UsesVirtualTime;

    /**
     * Estados por defecto del pipeline (legacy; catálogo dinámico en {@see LeadPipelineStatus}).
     */
    public const PIPELINE_STATUSES = [
        'nuevo',
        'contactado',
        'calificado',
        'demo_agendada',
        'demo_realizada',
        'mail2_enviado',
        'cerrado_ganado',
        'cerrado_perdido',
        'en_pausa',
    ];

    /**
     * Estados en los que la verificación de mensajes se auto-enciende (latch): desde que el lead
     * entra a solicita_disponibilidad hasta closer_activo, inclusive. Incluye demo_realizada y
     * closer_activo, que NO están en LeadAiService::ESTADOS_REQUIEREN_SUPERVISION_AGENDAMIENTO
     * (esa const, más corta, la usa el gate de agendamiento y no se toca). Ver latch en booted().
     */
    public const ESTADOS_VENTANA_VERIFICACION_MENSAJES = [
        'solicita_disponibilidad',
        'demo_agendada',
        'ingresando_demo',
        'demo_en_curso',
        'demo_pendiente_de_ingreso',
        'demo_pendiente_de_terminar',
        'demo_realizada',
        'closer_activo',
    ];

    /**
     * Slugs válidos del pipeline (catálogo en BD o defaults).
     *
     * @return array<int, string>
     */
    public static function pipeline_status_slugs(): array
    {
        return LeadPipelineStatus::all_slugs();
    }

    /**
     * Elimina videos personalizados y mensajes de conversación al borrar el lead.
     *
     * @return void
     */
    protected static function booted()
    {
        // Defaults de sucursales y depósitos: todo lead nuevo arranca configurado para demo
        static::creating(function (Lead $lead) {
            if (! $lead->use_deposits) {
                $lead->use_deposits = true;
            }
            if (empty($lead->address_1)) {
                $lead->address_1 = 'Sucursal 1';
            }
            if (empty($lead->address_2)) {
                $lead->address_2 = 'Sucursal 2';
            }
            if (empty($lead->address_3)) {
                $lead->address_3 = 'Sucursal 3';
            }
        });

        // Auto-activación de la verificación de mensajes al ENTRAR a la ventana de verificación
        // (solicita_disponibilidad → closer_activo). Latch de una sola vez: si el lead cruza desde un
        // estado FUERA de la ventana hacia uno DENTRO, se enciende requiere_verificacion_mensajes. No se
        // vuelve a forzar después (si el admin lo apaga estando en la ventana, queda apagado — decisión de
        // Lucas, 15/7/2026, el toggle es libremente apagable). Tampoco se apaga al salir de la ventana: una
        // vez encendida, persiste hasta que el admin la apague. La red de las ACCIONES de agenda no depende
        // de esto: el gate de agendamiento de LeadAiService las retiene por su cuenta.
        static::saving(function (Lead $lead) {
            if (! $lead->isDirty('status')) {
                return;
            }

            $ventana  = self::ESTADOS_VENTANA_VERIFICACION_MENSAJES;
            $nuevo    = (string) $lead->status;
            $anterior = (string) $lead->getOriginal('status');

            $entra_a_la_ventana = in_array($nuevo, $ventana, true) && ! in_array($anterior, $ventana, true);

            if ($entra_a_la_ventana && ! (bool) $lead->requiere_verificacion_mensajes) {
                $lead->requiere_verificacion_mensajes = true;
            }
        });

        static::deleting(function (Lead $lead) {
            $lead->personalized_demo_videos()->delete();
            $lead->messages()->delete();
            $lead->partners()->delete();
        });
    }

    /**
     * Devuelve definición declarativa de propiedades para admin-spa.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function properties()
    {
        return LeadProperties::all();
    }

    protected $guarded = [];

    /**
     * Casts para mantener tipados los booleanos del formulario y las fechas
     * de agendamiento / trazabilidad (demo y producción).
     */
    protected $casts = [
        'demo_date'                    => 'date',
        'meeting_scheduled_at'         => 'datetime',
        'presentation_mail_sent_at'    => 'datetime',
        'demo_setup_last_run_at'       => 'datetime',
        'user_setup_last_run_at'       => 'datetime',
        'followup_mail_sent_at'        => 'datetime',
        'demo_mail_sent_at'            => 'datetime',

        'use_deposits'                 => 'boolean',
        'use_price_lists'              => 'boolean',
        'iva_included'                 => 'boolean',
        'ventas_con_fecha_de_entrega'  => 'boolean',
        'cajas'                        => 'boolean',
        'usar_codigos_de_barra'        => 'boolean',
        'codigos_de_barra_por_defecto' => 'boolean',
        'consultora_de_precios'        => 'boolean',
        'imagenes'                     => 'boolean',
        'produccion'                   => 'boolean',
        'ask_amount_in_vender'         => 'boolean',
        'redondear_centenas_en_vender' => 'boolean',
        'omitir_cuentas_corrientes'    => 'boolean',

        'tiene_sugerencia_pendiente'   => 'boolean',
        // Si false, Claude no genera ni envía sugerencias automáticas para este lead.
        'claude_auto_reply'            => 'boolean',

        // Master del ciclo de demo: false = "lo manejo yo, no automatices nada" (prompt 318).
        'automatizaciones_demo_activas' => 'boolean',
        // Toggles puntuales de automatización por lead (prompt 318): permiten apagar una
        // automatización específica del ciclo de demo sin afectar al resto.
        'auto_recordatorio_demo'       => 'boolean',
        'auto_check_ingreso_demo'      => 'boolean',
        'auto_check_fin_demo'          => 'boolean',
        'auto_resumen_closer'          => 'boolean',

        // Flag persistido: Claude (o el admin) marcó que este lead requiere intervención humana.
        'requiere_intervencion_humana' => 'boolean',
        // Toggle por lead: si true, todo mensaje de Claude se retiene para verificación humana antes
        // de salir (en cualquier estado). Si false, envío inmediato. Se auto-enciende (latch) al entrar
        // a la ventana solicita_disponibilidad → closer_activo. Default false. Consumido por LeadAiService (407).
        'requiere_verificacion_mensajes' => 'boolean',
        'requiere_seguimiento'         => 'boolean',
        'tiene_seguimiento_sin_ver'    => 'boolean',

        // Toggle manual por lead: notifica al admin por push cada mensaje entrante.
        'notificar_mensajes'           => 'boolean',

        // Flag de recordatorio pre-demo: evita generar el mensaje más de una vez por demo agendada.
        'recordatorio_demo_enviado'    => 'boolean',

        // Flag de recordatorio de mañana: evita enviar el mensaje más de una vez por demo agendada.
        'recordatorio_manana_enviado'  => 'boolean',

        // Flag de check de ingreso post-demo: evita duplicar el mensaje al lead.
        'demo_check_ingreso_enviado'   => 'boolean',

        // Flag: el lead confirmó por WhatsApp que pudo entrar a la demo.
        'demo_ingreso_confirmado'      => 'boolean',

        // Flag: ya se envió el mensaje preguntando si terminó la demo.
        'demo_fin_check_enviado'       => 'boolean',

        // Flag: la demo se dejó abierta en un rango amplio (sin horario puntual); no reservar
        // automáticamente ventana de llamada para el closer (se coordina aparte, manualmente).
        'demo_flexible'                => 'boolean',

        // Timestamp exacto en que Claude confirmó el ingreso del lead a la demo.
        'demo_ingreso_confirmado_at'        => 'datetime',

        // Flag: Claude infirió que la demo terminó (el lead confirmó el fin).
        'demo_terminada_confirmada'         => 'boolean',

        // Timestamp exacto en que se confirmó el fin de la demo.
        'demo_terminada_confirmada_at'      => 'datetime',

        // Flag de un solo disparo: ya se envió el seguimiento de fin (anti-duplicado).
        'demo_fin_seguimiento_enviado'      => 'boolean',

        // Flag de un solo disparo: ya se notificó a admins el timeout de fin (anti-duplicado).
        'demo_pendiente_terminar_notificado' => 'boolean',

        // Flag de un solo disparo: ya se notificó a admins el no-ingreso (anti-duplicado).
        'demo_no_ingreso_notificado'        => 'boolean',

        // Timestamp de llamada del closer tras la demo: parte del pipeline de cierre.
        'closer_called_at'             => 'datetime',

        // Último mensaje del hilo WhatsApp (desnormalizado para orden en listado).
        'last_message_at'              => 'datetime',

        // Primer mensaje del hilo WhatsApp (desnormalizado para filtrar inicio de conversación).
        'first_message_at'             => 'datetime',

        // Timestamp de fijado (pin global): null = no fijado; los leads fijados aparecen primero en la tabla.
        'pinned_at'                    => 'datetime',

        // Timestamp de "pendiente de revisión" (global): null = no pendiente; con fecha = fila roja en la
        // grilla (botón de revisión, prompt 302). Se limpia al abrir la conversación.
        'pendiente_revision_at'        => 'datetime',

        // Marca manual "no leído" (estilo WhatsApp) del admin autenticado sobre este lead. Calculado
        // per-request en scopeWithUnreadLeadMessagesCount, no es una columna real de `leads`.
        'manually_marked_unread'       => 'boolean',

        // Fila amarilla en la grilla de leads: true si el lead tiene ≥1 mensaje pendiente de verificación
        // del setter (requiere_verificacion + sugerido). Calculado en scopeWithUnreadLeadMessagesCount.
        'row_warning'                  => 'boolean',

        // Cuotas del contrato PDF: [{monto, fecha}]
        'contract_financiacion'              => 'array',

        // Fechas del contrato ComercioCity (inputs type="date" en admin-spa).
        'contract_fecha_emision'             => 'date',
        'contract_fecha_primer_pago_unico'   => 'date',
        'contract_fecha_primer_pago_mensual' => 'date',

        // Resumen estructurado generado por Claude: {empresa, situacion_actual, funcionalidades, puntos_dolor}
        'demo_summary_structured'      => 'array',

        // Resumen estructurado de la llamada del closer, extraído por Claude de la transcripción de Recall.ai.
        'call_summary'                 => 'array',

        // Snapshot de la variante de welcome asignada en A/B testing.
        'welcome_variant_id'           => 'integer',

        // Timestamps del flujo de alerta "Tomar llamada" (prompt 127).
        'closer_alert_sent_at'          => 'datetime',
        'closer_alert_accepted_at'      => 'datetime',
        'closer_delay_message_sent_at'  => 'datetime',
        'closer_no_show_rescheduled_at' => 'datetime',
    ];

    /**
     * Scope `withAll` requerido por la convención de admin-api (regla 20).
     * Precarga las relaciones habituales para evitar N+1 al listar leads.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     */
    public function scopeWithAll($query)
    {
        $query->with(
            'target_client',
            'promoted_client.implementation',
            'created_by_admin',
            'demo',
            'personalized_demo_videos',
            'messages.attachments',
            'partners'
        );
        $query->withUnreadLeadMessagesCount();
        // Conteo de seguimientos enviados (mensajes is_followup no rechazados) para el badge de la tabla.
        $query->withCount([
            'messages as followup_count' => function ($sub) {
                $sub->where('is_followup', true)
                    ->where('status', '!=', 'rechazado');
            },
        ]);
    }

    /**
     * Variante liviana para listados admin-spa: relaciones del lead + solo mensajes de notificación.
     *
     * Los mensajes se serializan bajo la clave `messages` (mismo contrato que el SPA) pero filtrados.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     */
    public function scopeWithAllForList($query)
    {
        $query->with(
            'target_client',
            'promoted_client.implementation',
            'created_by_admin',
            'demo',
            'personalized_demo_videos',
            'notification_messages'
        );
        $query->withUnreadLeadMessagesCount();
        // Conteo de seguimientos enviados (mensajes is_followup no rechazados) para el badge de la tabla.
        $query->withCount([
            'messages as followup_count' => function ($sub) {
                $sub->where('is_followup', true)
                    ->where('status', '!=', 'rechazado');
            },
        ]);
    }

    /**
     * Marca el alcance de mensajes incluidos en la respuesta JSON (`notification` | `full`).
     *
     * @param string $scope
     *
     * @return $this
     */
    public function mark_messages_scope(string $scope)
    {
        $this->setAttribute('messages_scope', $scope);

        return $this;
    }

    /**
     * Expone mensajes de notificación bajo `messages` para compatibilidad con admin-spa.
     *
     * @return void
     */
    public function expose_notification_messages_as_messages(): void
    {
        if ($this->relationLoaded('notification_messages')) {
            $this->setRelation('messages', $this->notification_messages);
            $this->unsetRelation('notification_messages');
        }
    }

    /**
     * Normaliza una colección o paginador de leads de listado para JSON del SPA.
     *
     * @param mixed $models
     *
     * @return mixed
     */
    public static function prepare_collection_for_list_json($models)
    {
        $collection = $models instanceof \Illuminate\Contracts\Pagination\Paginator
            ? $models->getCollection()
            : $models;

        if ($collection) {
            $collection->each(function (Lead $lead) {
                $lead->expose_notification_messages_as_messages();
                $lead->mark_messages_scope('notification');
            });
        }

        return $models;
    }

    /**
     * Agrega el conteo de mensajes del lead (sender = lead) sin leer para el admin autenticado.
     *
     * El conteo es per-usuario: un mensaje cuenta como no leído mientras no exista
     * su registro en lead_message_reads para el admin logueado.
     *
     * Expone dos alias con el mismo valor para mantener compatibilidad:
     * - `unread_messages_count`: usado por la pestaña Conversación para auto-marcar leído.
     * - `unread_count`: usado por el badge de la fila en la tabla de leads.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithUnreadLeadMessagesCount($query)
    {
        // Admin autenticado: si no hay sesión (ej. jobs), 0 hace que nada se cuente como no leído.
        $admin_id = (int) (Auth::id() ?? 0);

        // Filtro reutilizable: mensajes entrantes del lead sin registro de lectura del admin actual.
        $unread_filter = function ($sub) use ($admin_id) {
            $sub->where('sender', 'lead')
                ->whereNotExists(function ($exists) use ($admin_id) {
                    $exists->selectRaw('1')
                        ->from('lead_message_reads')
                        ->whereColumn('lead_message_reads.lead_message_id', 'lead_messages.id')
                        ->where('lead_message_reads.admin_id', $admin_id);
                });
        };

        // Filtro nuevo: TODOS los mensajes (cualquier sender) sin registro de lectura del admin.
        // Alimenta el badge gris "actividad no vista" en la tabla de leads.
        $unseen_filter = function ($sub) use ($admin_id) {
            $sub->whereNotExists(function ($exists) use ($admin_id) {
                $exists->selectRaw('1')
                    ->from('lead_message_reads')
                    ->whereColumn('lead_message_reads.lead_message_id', 'lead_messages.id')
                    ->where('lead_message_reads.admin_id', $admin_id);
            });
        };

        $query->withCount([
            'messages as unread_messages_count' => $unread_filter,
            'messages as unread_count'          => $unread_filter,
            // Actividad total no vista: mensajes de cualquier emisor sin lectura del admin.
            'messages as unseen_count'          => $unseen_filter,
            // Seguimientos por aprobar (badge violeta en la columna "Sin leer"): mensajes is_followup
            // que quedaron pendientes de aprobación del setter (prompt 283). Global, no per-admin:
            // cualquier admin puede aprobarlos, así que no se filtra por lead_message_reads.
            'messages as pending_followups_count' => function ($sub) {
                $sub->where('is_followup', true)
                    ->where('status', 'sugerido');
            },
        ]);

        // Marca manual de "no leído" (estilo WhatsApp) hecha por el admin actual sobre este lead.
        // Independiente de unread_count/unseen_count: la UI (admin-spa) solo la muestra como punto
        // sin número cuando ambos contadores reales están en 0 (ver LeadProperties::all(),
        // clave 'manually_unread_key').
        return $query->addSelect([
            'manually_marked_unread' => LeadManualUnreadMark::selectRaw('COUNT(*) > 0')
                ->whereColumn('lead_manual_unread_marks.lead_id', 'leads.id')
                ->where('lead_manual_unread_marks.admin_id', $admin_id),

            // Resaltado AMARILLO de la fila en la grilla de leads (fila que necesita revisión del setter,
            // ver prompt 295). Global (no per-admin): true si el lead tiene AL MENOS un mensaje pendiente
            // de verificación — requiere_verificacion = true + status = 'sugerido' (aún sin aprobar,
            // rechazar ni enviar). Cubre los dos casos sin distinción: (a) escalación conversacional del
            // agente (requiere_verificacion), y (b) todo el tramo de agenda desde solicita_disponibilidad
            // en adelante (los prompts 272/228 fuerzan requiere_verificacion en esos mensajes). Baja solo
            // cuando el mensaje sale de 'sugerido' (aprobado / rechazado / auto-enviado por el respaldo).
            'row_warning' => \App\Models\LeadMessage::selectRaw('COUNT(*) > 0')
                ->whereColumn('lead_messages.lead_id', 'leads.id')
                ->where('lead_messages.requiere_verificacion', true)
                ->where('lead_messages.status', 'sugerido'),
        ]);
    }

    /**
     * Empresa-api (Client registrado en admin-api) donde se corre la demo.
     */
    public function target_client()
    {
        return $this->belongsTo(Client::class, 'target_client_id');
    }

    /**
     * Client de producción creado automáticamente al promover el Lead.
     * Es el sistema real donde se corre el user-setup.
     */
    public function promoted_client()
    {
        return $this->belongsTo(Client::class, 'promoted_client_id');
    }

    /**
     * Admin que dio de alta el Lead.
     */
    public function created_by_admin()
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }

    /**
     * Admin que activó el toggle de notificaciones para este lead (recibe el push en mensajes entrantes).
     *
     * @deprecated Reemplazado por notification_admins() (tabla pivot lead_admin_notifications).
     *             Se mantiene hasta confirmar que el nuevo sistema funciona correctamente en producción.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function notify_admin()
    {
        return $this->belongsTo(Admin::class, 'notify_admin_id');
    }

    /**
     * Admins que recibirán un WhatsApp al llegar un mensaje de este lead.
     * Reemplaza la columna notify_admin_id: ahora múltiples admins pueden suscribirse.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function notification_admins()
    {
        return $this->belongsToMany(Admin::class, 'lead_admin_notifications', 'lead_id', 'admin_id')
                    ->using(LeadAdminNotification::class);
    }

    /**
     * Demo elegida para este lead desde el catálogo administrado.
     */
    public function demo()
    {
        return $this->belongsTo(Demo::class, 'demo_id');
    }

    /**
     * Tutoriales personalizados incluidos en el mail de demo (ordenados).
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function personalized_demo_videos()
    {
        return $this->hasMany(LeadPersonalizedDemoVideo::class, 'lead_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * Socios adicionales vinculados al lead (confirmados o pendientes).
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function partners()
    {
        return $this->hasMany(LeadPartner::class, 'lead_id');
    }

    /**
     * Socios ya confirmados por el closer (excluye sugerencias pendientes).
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function confirmed_partners()
    {
        return $this->hasMany(LeadPartner::class, 'lead_id')->where('pending_confirmation', false);
    }

    /**
     * Variante de mensaje de welcome asignada a este lead (snapshot A/B).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function welcome_variant()
    {
        return $this->belongsTo(MessageVariant::class, 'welcome_variant_id');
    }

    /**
     * Mensajes de la conversación WhatsApp (lead, setter, sugerencias de Claude).
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function messages()
    {
        // sent_by_admin:id,name (prompt 403) se eager-loadea solo acá (el hilo completo del lead),
        // no en notification_messages()/scopeWithAllForList(): esos listados no muestran el nombre
        // del emisor y el accessor sent_by_admin_name ya devuelve null por la guarda relationLoaded.
        return $this->hasMany(LeadMessage::class, 'lead_id')
            ->with(['attachments', 'sent_by_admin:id,name'])
            ->orderBy('id');
    }

    /**
     * Mensajes relevantes para notificaciones en listados (sin cargar el hilo completo).
     *
     * Incluye:
     * - mensajes del lead no leídos (`read_at` nulo),
     * - sugerencias de Claude pendientes (`status = sugerido`),
     * - mensajes del lead sin respuesta del setter/sistema posterior.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function notification_messages()
    {
        return $this->hasMany(LeadMessage::class, 'lead_id')
            ->forListNotifications()
            ->with('attachments')
            ->orderBy('id');
    }

    /**
     * Recalcula flags de sugerencias pendientes y seguimiento según mensajes `sugerido`.
     *
     * @return void
     */
    public function sync_suggestion_flags(): void
    {
        $pending = $this->messages()->where('status', 'sugerido')->exists();
        $this->tiene_sugerencia_pendiente = $pending;
        if (! $pending) {
            $this->requiere_seguimiento = false;
        }
        $this->save();
    }

    /**
     * Normaliza fecha de demo a Y-m-d; vacío limpia la columna.
     *
     * @param mixed $value input type="date", ISO o DateTimeInterface.
     */
    public function setDemoDateAttribute($value)
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            $this->attributes['demo_date'] = null;

            return;
        }
        if ($value instanceof \DateTimeInterface) {
            $this->attributes['demo_date'] = $value->format('Y-m-d');

            return;
        }
        $this->attributes['demo_date'] = substr((string) $value, 0, 10);
    }

    /**
     * Hora de inicio de demo: blancos → null.
     *
     * @param mixed $value texto libre (p. ej. 09:00).
     */
    public function setDemoStartTimeAttribute($value)
    {
        $this->attributes['demo_start_time'] = self::nullable_trimmed_string($value);
    }

    /**
     * Hora de fin de demo: blancos → null.
     *
     * @param mixed $value texto libre (p. ej. 18:00).
     */
    public function setDemoEndTimeAttribute($value)
    {
        $this->attributes['demo_end_time'] = self::nullable_trimmed_string($value);
    }

    /**
     * @param mixed $raw
     *
     * @return string|null
     */
    protected static function nullable_trimmed_string($raw)
    {
        if ($raw === null) {
            return null;
        }
        $trimmed = trim((string) $raw);

        return $trimmed === '' ? null : $trimmed;
    }
}

<?php

namespace App\Models;

use App\Helpers\AppTime;
use App\Models\Concerns\HasUuid;
use App\Services\SupportAiSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SupportTicket extends Model
{
    use HasUuid;

    /**
     * Valor de `support_tickets.status` que significa "el ticket sigue abierto".
     *
     * Va como constante porque lo miran DOS caminos que tienen que coincidir siempre
     * ({@see self::scopeSinResponder()} y la subconsulta de
     * {@see self::scopeWithIndicadoresDeInicio()}): un literal repetido es justo lo que se separa
     * sin que nada lo avise.
     */
    const STATUS_ABIERTO = 'open';

    /**
     * Campos asignables para alta y edición de tickets.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    protected $appends = ['support_attention'];

    // La bandeja recibe el resumen; nunca las reservas ni el diagnóstico privado del caso.
    protected $hidden = ['support_codex_case'];

    /**
     * Valor por defecto del interruptor del agente.
     *
     * Va acá y no solo en la migración porque un `SupportTicket::create()` devuelve el modelo con
     * lo que se le pasó, no con lo que la base rellenó: sin esto, el código que crea un ticket y
     * enseguida lee el flag sobre esa misma instancia lee null, y `(bool) null` es false — o sea,
     * el default se daría vuelta justo en el camino más peligroso.
     *
     * `requiere_verificacion_mensajes` NO está en esta lista y no es un olvido: su valor dejó de
     * ser una constante, lo decide la config global de Cuenta en el momento de crear el ticket, y
     * un array estático de propiedad de clase no puede consultar la base. Lo sella el hook
     * `creating` de abajo, que escribe SIEMPRE un booleano por exactamente el mismo motivo.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'claude_auto_reply' => true,
    ];

    /**
     * Casts de fechas y canal para respuestas JSON consistentes.
     * Campos WhatsApp mass-assignables vía guarded=[]: source, whatsapp_phone,
     * last_client_message_at, alert_sent_at.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'last_client_message_at' => 'datetime',
        'alert_sent_at' => 'datetime',
        'ai_suggestion_send_at' => 'datetime',
        /* Momento en que Claude escaló el ticket a revisión humana. */
        'escalated_at' => 'datetime',
        /* Interruptores del agente por ticket, espejo de los que ya tiene `leads`. */
        'claude_auto_reply' => 'boolean',
        'requiere_verificacion_mensajes' => 'boolean',
        /* Columnas CALCULADAS por scopeWithIndicadoresDeInicio(), no columnas reales de la tabla.
           El cast esta aca para que lleguen al JSON como true/false y no como el 1/0 que devuelve
           MySQL: el SPA filtra la bandeja por estas dos claves. Si el scope no corrio, el atributo
           no existe y el cast no se aplica a nada. */
        'sin_responder' => 'boolean',
        'abierto_hoy' => 'boolean',
    ];

    /**
     * Sella el régimen de verificación con el que nace cada ticket.
     *
     * @return void
     */
    protected static function booted()
    {
        static::creating(function (SupportTicket $ticket) {
            // El régimen del ticket se estampa al nacer con lo que diga la config global en ese
            // momento y no se relee nunca más: si se leyera en runtime, apagar el interruptor en
            // Cuenta dejaría contestando solos a tickets que un operador ya venía verificando a
            // mano. Después de nacer, solo lo cambia el botón del encabezado (decisión de Lucas).
            //
            // Va acá y no en los cuatro puntos de creación (webhook, inbound del ERP, alta manual
            // y apertura por WhatsApp) para que el quinto que se agregue no se lo olvide.
            $atributos = $ticket->getAttributes();
            if (array_key_exists('requiere_verificacion_mensajes', $atributos)
                && $atributos['requiere_verificacion_mensajes'] !== null
            ) {
                // Quien crea el ticket ya eligió el régimen a mano: la config global no lo pisa.
                //
                // Se mira la presencia de la clave en getAttributes() y no isDirty() ni filled():
                // con la clave sacada de $attributes, que esté presente significa exactamente
                // "alguien la eligió", mientras que isDirty() daría false cuando el valor pasado
                // coincide con el default y el hook terminaría pisando una elección explícita.
                return;
            }

            $ticket->requiere_verificacion_mensajes = SupportAiSettings::new_ticket_requires_verification();
        });
    }

    /**
     * Scope estándar para fullModel con relaciones completas.
     */
    public function scopeWithAll($query)
    {
        $query->with('client', 'client_employee', 'assigned_admin', 'messages.attachments', 'messages.sender_admin', 'support_codex_case');
    }

    public function support_codex_case()
    {
        return $this->hasOne(SupportCodexCase::class, 'support_ticket_id');
    }

    /** Misión y atención humana son ejes independientes: una no debe esconder a la otra. */
    public function getSupportAttentionAttribute(): ?array
    {
        // Solo la bandeja/detalle admin precargan este resumen. Un serializador genérico
        // no debe disparar consultas por fila ni agregar motivos internos a otros canales.
        if (! $this->relationLoaded('support_codex_case')) {
            return null;
        }
        if ($this->status !== self::STATUS_ABIERTO) {
            return ['needs_human' => false, 'reason' => null, 'mission' => null];
        }
        $case = $this->support_codex_case;
        $mission = $case ? $case->mission_lifecycle() : null;
        $human_reply_pause = $case !== null && $case->is_human_reply_pause();
        $needs_human = ! $human_reply_pause && (($case !== null && $case->state === 'manual_review')
            || ($mission === null && $this->escalated_at !== null));
        $reason = null;
        if ($needs_human) {
            $reason = $case !== null && $case->state === 'manual_review'
                ? (trim((string) $case->review_reason) !== '' ? $case->review_reason : 'La atención necesita revisión humana.')
                : $this->escalation_reason;
        }
        return ['needs_human' => $needs_human, 'reason' => $reason, 'mission' => $mission];
    }

    /**
     * Agrega atributo unread_messages_count: mensajes del usuario (user) sin read_at.
     * Usado en bandeja admin para badge de no leídos.
     */
    public function scopeWithUnreadMessagesCount($query)
    {
        return $query->withCount([
            'messages as unread_messages_count' => function ($sub) {
                $sub->where('sender_type', 'user')->whereNull('read_at');
            },
        ]);
    }

    /**
     * 🔴 LA definición en SQL de "este ticket está sin responder", y la única que hay.
     *
     * Ticket `open` cuyo ÚLTIMO mensaje real es del cliente. Es la definición que fijó Lucas,
     * textual: «ticket `open` cuyo último mensaje real (sin borradores de IA) es del cliente». Lo
     * que es un "mensaje real" lo define {@see SupportMessage::es_mensaje_real()} y su gemelo en
     * SQL {@see SupportMessage::apply_mensaje_real_conditions()}: un borrador de la IA sin aprobar
     * NO tapa nada, porque el cliente nunca lo recibió.
     *
     * Misma forma que {@see Lead::scopeRequiereRevision()}: un `whereExists` sobre los mensajes del
     * cliente con un `whereNotExists` correlacionado adentro que busca un mensaje real posterior.
     * Cualquier mensaje real posterior tapa al candidato, sea del cliente o del operador: lo que se
     * pregunta es quién habló último. La relación `messages` está ordenada por id, así que
     * "posterior" es "id mayor".
     *
     * ⚠️ DIVERGE A PROPÓSITO de la definición de leads en un punto: acá NO se mira la entrega. En
     * leads un saliente sin `whatsapp_message_id` no cuenta como respuesta
     * ({@see LeadMessage::is_reply_to_lead()}), porque lo que importa es si al lead le llegó algo.
     * Acá no se puede pedir lo mismo: los tickets del ERP (`source = 'erp'`, los que crea
     * `InboundSupportMessageController`) NUNCA tienen `whatsapp_message_id`, así que exigirlo
     * dejaría a todos como "sin responder". O sea: una respuesta del operador que no llegó cuenta
     * igual como respondida. El escenario "erp contestado sin whatsapp_message_id" del test de
     * paridad existe justamente para fijar esta divergencia por escrito.
     *
     * 🔴 Tiene un gemelo en PHP,
     * {@see \App\Services\SupportPendingReplyService::ticket_sin_responder()}, y un test que los
     * compara ticket por ticket (`TicketsSinResponderEnSqlYEnPhpCoincidenTest`). Si tocás uno,
     * tocá el otro.
     *
     * Es GLOBAL, no per-admin: no lleva ningún `Auth::id()` adentro. La bandeja filtra por
     * asignado aparte (`SupportTicketController::apply_assigned_filter_to_ticket_query()`), y si el
     * criterio dependiera de quién está logueado, el número de la tarjeta del panel de inicio y las
     * filas del listado se separarían — misma lección que
     * `test_el_scope_no_depende_del_admin_autenticado` del test de leads.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeSinResponder($query)
    {
        return $query->where('support_tickets.status', self::STATUS_ABIERTO)
            ->whereExists(function ($del_cliente) {
                $del_cliente->selectRaw('1')
                    ->from('support_messages')
                    ->whereColumn('support_messages.support_ticket_id', 'support_tickets.id');
                self::apply_condicion_ultimo_mensaje_real_del_cliente($del_cliente);
            });
    }

    /**
     * Tickets abiertos HOY: `opened_at` desde el comienzo del día en curso.
     *
     * La comparación es DIRECTA, sin conversión de zona: `config('app.timezone')` es
     * `America/Argentina/Buenos_Aires` y {@see AppTime::now()} devuelve ese mismo timezone, así que
     * el `startOfDay()` cae en el mismo reloj con el que la base guarda `opened_at`.
     *
     * `opened_at` es nullable y un ticket sin fecha de apertura no cuenta: el `>=` lo descarta solo
     * (en MySQL `NULL >= x` no es verdadero), igual que lo descarta el `IS NOT NULL` explícito de la
     * columna calculada de {@see self::scopeWithIndicadoresDeInicio()}.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeAbiertoHoy($query)
    {
        return $query->where('support_tickets.opened_at', '>=', self::inicio_del_dia());
    }

    /**
     * Suma a cada fila del listado las dos columnas calculadas que el panel de inicio usa como
     * deep link: `sin_responder` y `abierto_hoy`, las dos booleanas (ver el `$casts`).
     *
     * 🔴 Las dos reusan EXACTAMENTE el mismo criterio que los scopes que cuentan las tarjetas, no
     * una copia a mano: `sin_responder` pasa por
     * {@see self::apply_condicion_ultimo_mensaje_real_del_cliente()} —el mismo método que usa
     * {@see self::scopeSinResponder()}— y `abierto_hoy` por {@see self::inicio_del_dia()}, el mismo
     * momento que usa {@see self::scopeAbiertoHoy()}. Si el número de la tarjeta y el filtro de la
     * lista se escribieran dos veces, se separarían sin que nada lo avise: es el defecto "dice 2 y
     * veo una sola fila" que Lucas reportó el 2/9/2026 del lado de leads.
     *
     * El criterio no se puede compartir reusando el scope tal cual porque son dos FORMAS de
     * consulta distintas (un `whereExists` que filtra contra una subconsulta escalar que devuelve
     * un valor por fila), y Eloquent no deja convertir una en la otra. Por eso la condición a nivel
     * mensaje vive en un método aparte que las dos llaman.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithIndicadoresDeInicio($query)
    {
        /* Sin esto el addSelect PISA el select: con `columns` en null Eloquent arma
           `select sin_responder, abierto_hoy from support_tickets` y la fila llega sin sus columnas
           reales. Es el mismo recaudo que toma `withCount()` internamente — y por eso en
           `SupportTicketController::ticketQueryForInbox()` el defecto no se vería, porque ahí
           `withUnreadMessagesCount()` ya sentó el `support_tickets.*` antes. */
        if ($query->getQuery()->columns === null) {
            $query->select('support_tickets.*');
        }

        /* La parte "el ticket está abierto" viaja ADENTRO de la subconsulta, como referencia a la
           columna de la fila externa (`support_tickets.status`): es SQL válido en una subconsulta
           correlacionada y así la columna calculada no puede decir "sí" sobre un ticket cerrado,
           con el mismo literal de estado que usa el scope. */
        $sin_responder = SupportMessage::query()
            ->selectRaw('COUNT(*) > 0')
            ->whereColumn('support_messages.support_ticket_id', 'support_tickets.id')
            ->where('support_tickets.status', self::STATUS_ABIERTO);
        self::apply_condicion_ultimo_mensaje_real_del_cliente($sin_responder);

        $query->addSelect(['sin_responder' => $sin_responder]);

        /* `opened_at` es nullable y en MySQL `NULL >= x` devuelve NULL, no 0: el `IS NOT NULL`
           explícito es lo que hace que la columna llegue siempre como booleano y nunca como null. */
        return $query->selectRaw(
            '(support_tickets.opened_at IS NOT NULL AND support_tickets.opened_at >= ?) as abierto_hoy',
            [self::inicio_del_dia()]
        );
    }

    /**
     * La condición a nivel MENSAJE de "sin responder": este mensaje es del cliente, es real, y no
     * hay ningún mensaje real posterior en el mismo ticket.
     *
     * Se aplica sobre una subconsulta YA apuntada a `support_messages` y correlacionada con
     * `support_tickets.id`.
     *
     * 🔴 Existe como método aparte —y no inline en el scope— porque la MISMA condición la necesitan
     * las dos formas de consulta de arriba ({@see self::scopeSinResponder()} y
     * {@see self::scopeWithIndicadoresDeInicio()}), y escrita dos veces se separa sola.
     *
     * @param \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder $query Subconsulta sobre support_messages, correlacionada.
     *
     * @return void
     */
    protected static function apply_condicion_ultimo_mensaje_real_del_cliente($query): void
    {
        $query->where('support_messages.sender_type', 'user');

        /* Gemelo en SQL de SupportMessage::es_mensaje_real(). No lo vuelvas a escribir a mano acá. */
        SupportMessage::apply_mensaje_real_conditions($query, 'support_messages');

        $query->whereNotExists(function ($posterior) {
            $posterior->selectRaw('1')
                ->from('support_messages as posterior')
                ->whereColumn('posterior.support_ticket_id', 'support_messages.support_ticket_id')
                ->whereColumn('posterior.id', '>', 'support_messages.id');
            /* Cualquier mensaje real posterior tapa al candidato, sea del cliente o del operador:
               un borrador de IA sin aprobar, en cambio, no tapa nada. */
            SupportMessage::apply_mensaje_real_conditions($posterior, 'posterior');
        });
    }

    /**
     * Comienzo del día en curso, el mismo para el scope que cuenta la tarjeta "abiertos hoy" y para
     * la columna calculada del listado.
     *
     * Está acá y no repetido en los dos lugares por el mismo motivo que
     * {@see self::apply_condicion_ultimo_mensaje_real_del_cliente()}: dos `AppTime::now()`
     * separados también son dos criterios, y el reloj virtual de local los puede correr.
     *
     * @return \Carbon\Carbon
     */
    protected static function inicio_del_dia()
    {
        return AppTime::now()->startOfDay();
    }

    /**
     * Cliente (tenant) dueño del ticket.
     */
    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Empleado del cliente que participa del ticket (null si escribe el dueño/contacto principal).
     */
    public function client_employee()
    {
        return $this->belongsTo(ClientEmployee::class);
    }

    /**
     * Nombre visible del contacto remoto (dueño del cliente o empleado).
     *
     * @return string
     */
    public function resolve_contact_display_name()
    {
        if ($this->relationLoaded('client_employee') && $this->client_employee) {
            $employee_name = trim((string) ($this->client_employee->name ?? ''));
            if ($employee_name !== '') {
                return $employee_name;
            }
        }

        $cached_name = trim((string) ($this->client_user_name ?? ''));
        if ($cached_name !== '') {
            return $cached_name;
        }

        if ($this->relationLoaded('client') && $this->client) {
            $client_name = $this->client->resolve_display_name();
            if ($client_name !== '') {
                return $client_name;
            }
        }

        return 'Cliente';
    }

    /**
     * Admin actualmente asignado al ticket.
     */
    public function assigned_admin()
    {
        return $this->belongsTo(Admin::class, 'assigned_admin_id');
    }

    /**
     * Mensajes del ticket ordenados cronológicamente.
     */
    public function messages()
    {
        return $this->hasMany(SupportMessage::class)->orderBy('id');
    }

    /**
     * Último mensaje del hilo (id más reciente) para vista compacta en listado de bandeja.
     */
    public function lastMessage(): HasOne
    {
        return $this->hasOne(SupportMessage::class)->latestOfMany();
    }
}


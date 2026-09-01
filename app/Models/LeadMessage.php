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

    /**
     * Paleta de emojis con los que un admin puede reaccionar a un mensaje desde el panel.
     *
     * Va con escapes Unicode explícitos y no con el glifo pegado a propósito: un editor o un git
     * mal configurado se come el VARIATION SELECTOR-16 del corazón y la validación empieza a
     * rechazar reacciones legítimas sin que se vea nada raro en el diff.
     *
     * `LeadController::resolve_reaction_emoji()` compara contra esta lista y devuelve SIEMPRE la
     * forma canónica de acá, que es la que sale a Meta (nunca el literal que mandó el navegador).
     *
     * @var array<int, string>
     */
    public const REACCIONES_DEL_PANEL = [
        "\u{1F44D}",        // 👍 pulgar
        "\u{2764}\u{FE0F}", // ❤️ corazón (con selector de variación, como lo manda WhatsApp)
        "\u{1F602}",        // 😂 risa
        "\u{1F62E}",        // 😮 sorpresa
        "\u{1F622}",        // 😢 llanto
        "\u{1F64F}",        // 🙏 gracias
    ];

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
    protected $appends = ['suggested_lead_status_label', 'pending_actions_summary', 'sent_by_admin_name', 'admin_reaction_by_name'];

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
        /* Momento en que un admin reaccionó a este mensaje desde el panel. */
        'admin_reaction_at'       => 'datetime',
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
     * Nombre del admin que reaccionó a este mensaje desde el panel, para el tooltip de la pill
     * en el admin-spa. Misma guarda que sent_by_admin_name: si la relación no vino eager-loaded
     * (solo la carga messages() del Lead) devuelve null sin consultar la base, para no generar un
     * N+1 al serializar hilos largos.
     *
     * @return string|null
     */
    public function getAdminReactionByNameAttribute()
    {
        // Guarda: si la relación no fue eager-loaded, no disparar una consulta nueva por mensaje.
        if (! $this->relationLoaded('admin_reaction_by')) {
            return null;
        }
        $admin = $this->getRelation('admin_reaction_by');

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
        $fecha = self::normalizar_fecha_ofrecida($fecha);
        $hora  = self::normalizar_hora_ofrecida($hora);

        if ($fecha === '' || $hora === '') {
            return false;
        }

        foreach ($horarios_ofrecidos as $item) {
            if (! is_array($item)) {
                continue;
            }

            /* 🔴 is_scalar antes de cada cast, y no (string) a secas. `horarios_ofrecidos` lo
             * escribe el modelo de lenguaje y se guarda crudo, sin validar forma: un
             * {"fecha": ["2026-08-25"]} alcanza para que el cast emita "Array to string
             * conversion". Y en Laravel un E_NOTICE NO es cosmético — HandleExceptions corre con
             * error_reporting(-1) y lo convierte en ErrorException. Esa excepción no es
             * InvalidArgumentException, así que se saltearía el catch que devuelve 422 Y el
             * release() del lock de la instancia: el lock quedaría tomado sus 8s de TTL y toda
             * otra aprobación sobre esa demo caería en el camino de "no se pudo tomar el lock",
             * marcando para intervención humana a leads que no tenían nada. */
            /* 🔴 La fecha se NORMALIZA, no se compara cruda. La hora ya toleraba "9:05", " 09:05 "
             * y "17:05:00", pero la fecha se comparaba con !== contra el string tal cual vino: un
             * modelo que emita "2026-08-25T00:00:00" o "2026-08-25 00:00:00" hacía fallar el
             * rescate EN SILENCIO — y como este fix acopló dos perillas, el resultado no era
             * volver al comportamiento viejo sino caer en "no se envía nada". Se extrae el Y-m-d
             * con el MISMO criterio de sufijo/prefijo de fecha que ya usa el resto de
             * LeadAiService para las claves ("martes 2026-08-25"). Si no matchea, el ítem se
             * descarta, igual que antes. */
            $fecha_item = isset($item['fecha']) && is_scalar($item['fecha']) ? self::normalizar_fecha_ofrecida((string) $item['fecha']) : '';
            if ($fecha_item === '' || $fecha_item !== $fecha) {
                continue;
            }

            $desde = self::normalizar_hora_ofrecida(isset($item['desde']) && is_scalar($item['desde']) ? (string) $item['desde'] : '');
            if ($desde === '') {
                continue;
            }

            $hasta = self::normalizar_hora_ofrecida(isset($item['hasta']) && is_scalar($item['hasta']) ? (string) $item['hasta'] : '');
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
     * 🔴 NO hay filtro por antigüedad, y sacarlo fue deliberado (25/8/2026). Hasta acá había una
     * ventana de 24hs que comparaba AppTime::now() contra `sent_at`, y esos son DOS RELOJES
     * DISTINTOS: AppTime::now() respeta el reloj virtual con el que se prueba el sistema, mientras
     * que `sent_at` se escribe con el now() de Laravel, que es el reloj real. Con el reloj virtual
     * corrido, la consulta no devolvía NADA y el rescate no disparaba nunca — sin un solo log que
     * lo dijera. El razonamiento escrito para justificar la ventana además era incorrecto: la
     * ventana de WhatsApp son 24hs desde el ÚLTIMO MENSAJE DEL LEAD, no desde nuestro `sent_at`.
     *
     * Y no debilita nada material: la fecha del ítem tiene que coincidir EXACTO con la fecha que se
     * está confirmando, así que una oferta vieja es una oferta para una fecha vieja y no matchea; y
     * quien decide disponibilidad de verdad sigue siendo la grilla fresca, que ataja "ya pasó" y
     * "se ocupó".
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

        $mensajes = self::query()
            ->where('lead_id', $lead_id)
            ->where('sender', 'sistema')
            ->where('status', 'enviado')
            ->whereNotNull('horarios_ofrecidos')
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
     * 🔴 Y además VALIDA EL RANGO (00-23 / 00-59), que el criterio original no hacía. Acá no es
     * cosmético: "25:99" o "99:99" son "legibles" para el preg_match y, como la comparación de
     * rango es lexicográfica sobre "HH:MM", un `hasta: "25:99"` gana contra cualquier hora real y
     * convierte el ítem en "de `desde` hasta el fin del día" — o sea, una declaración basura del
     * modelo ensanchaba el permiso para saltarse el margen. Una hora fuera de rango es ilegible
     * ('') y, cuando viene en `hasta`, el ítem se degrada a punto (solo `desde`).
     *
     * @param string $hora Hora cruda declarada por el agente.
     *
     * @return string "HH:MM" o '' si no se pudo leer o está fuera de rango.
     */
    private static function normalizar_hora_ofrecida(string $hora): string
    {
        if (! preg_match('/(\d{1,2}):(\d{2})/', $hora, $m)) {
            return '';
        }

        $h = (int) $m[1];
        $i = (int) $m[2];
        if ($h < 0 || $h > 23 || $i < 0 || $i > 59) {
            return '';
        }

        return str_pad($m[1], 2, '0', STR_PAD_LEFT) . ':' . $m[2];
    }

    /**
     * Normaliza una fecha suelta a "Y-m-d", o string vacío si no es legible.
     *
     * Extrae el Y-m-d del texto con el mismo criterio que ya usa LeadAiService para las claves de
     * fecha del JSON de disponibilidad ("martes 2026-08-25"): así un "2026-08-25T00:00:00" o un
     * "2026-08-25 00:00:00" declarados por el modelo matchean igual que un "2026-08-25" pelado.
     *
     * @param string $fecha Fecha cruda.
     *
     * @return string "Y-m-d" o '' si no se pudo leer.
     */
    private static function normalizar_fecha_ofrecida(string $fecha): string
    {
        if (! preg_match('/(\d{4}-\d{2}-\d{2})/', $fecha, $m)) {
            return '';
        }

        return $m[1];
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
     * Admin que reaccionó a este mensaje desde el panel (null si nadie reaccionó).
     * Ver columna admin_reaction_by_admin_id.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function admin_reaction_by()
    {
        return $this->belongsTo(Admin::class, 'admin_reaction_by_admin_id');
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

<?php

namespace App\Models;

use App\Helpers\AppTime;
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

    /**
     * Estados de un saliente que el sistema dio por despachado.
     *
     * `aprobado` es histórico: hoy no lo escribe ningún camino de producción (solo el seeder de
     * demo), pero quedan filas de mayo/junio de 2026 con ese estado y se siguen respetando.
     *
     * @var array<int, string>
     */
    public const STATUSES_SALIENTE_DESPACHADO = ['enviado', 'aprobado'];

    /**
     * 🔴 LA definición de "a este mensaje del lead ya se le contestó", y la única que hay.
     *
     * Un saliente cuenta como respuesta **solo si efectivamente salió por WhatsApp**: sender del
     * setter o del sistema, estado despachado, y `whatsapp_message_id` cargado, que es lo que
     * Kapso/Meta devuelve cuando acepta el envío. Los eventos de estado no cuentan nunca.
     *
     * **Por qué el `whatsapp_message_id` es la parte que importa** (decisión de Lucas, 2/9/2026):
     * lo que el operador necesita saber es si al lead le llegó algo, no si el sistema generó algo.
     * De ahí salen los tres casos que antes se contaban mal:
     *
     *   - Una **sugerencia de la IA esperando verificación** (`sugerido`) no contestó nada: el lead
     *     sigue esperando y tiene que aparecer como sin responder hasta que alguien la mande.
     *   - Un saliente **que nunca salió** (`whatsapp_message_id` null: el 131008 de julio/agosto de
     *     2026, un rechazo de Meta en el momento del envío, una caída de Kapso) tampoco contestó.
     *   - Una **respuesta real del agente** (`sistema` + `enviado` + id de WhatsApp) SÍ contesta.
     *     Hasta el 2/9/2026 solo contaban `setter` y `sistema`+`aprobado`, y como el agente manda
     *     con `sistema`+`enviado` —10.365 de los 14.532 mensajes del sistema— casi toda
     *     conversación atendida por la IA figuraba como "sin responder": 497 leads en vez de 43.
     *
     * 🔴 Tiene un gemelo en SQL, {@see self::apply_reply_to_lead_conditions()}, porque hidratar el
     * hilo de todos los leads para contar una tarjeta no es viable. Los dos tienen que decir lo
     * mismo y hay un test que lo verifica (`RevisionDeLeadsEnSqlYEnPhpCoincidenTest`). Si tocás uno,
     * tocá el otro.
     *
     * @param LeadMessage $message Mensaje del hilo.
     *
     * @return bool
     */
    public static function is_reply_to_lead(LeadMessage $message): bool
    {
        if ($message->is_status_event) {
            return false;
        }

        if (! in_array((string) $message->sender, ['setter', 'sistema'], true)) {
            return false;
        }

        if (! in_array((string) $message->status, self::STATUSES_SALIENTE_DESPACHADO, true)) {
            return false;
        }

        return trim((string) ($message->whatsapp_message_id ?? '')) !== '';
    }

    /**
     * Gemelo en SQL de {@see self::is_reply_to_lead()}: aplica las mismas condiciones sobre el
     * alias de una subconsulta de `lead_messages`.
     *
     * Se pasa el alias porque los tres lugares que lo usan lo llaman distinto (`outbound` en dos
     * subconsultas correlacionadas, y la tabla pelada en otra).
     *
     * @param \Illuminate\Database\Query\Builder $query Subconsulta ya apuntada a lead_messages.
     * @param string                             $alias Alias con el que se referencian las columnas.
     *
     * @return void
     */
    public static function apply_reply_to_lead_conditions($query, string $alias): void
    {
        $query->where($alias . '.is_status_event', false)
            ->whereIn($alias . '.sender', ['setter', 'sistema'])
            ->whereIn($alias . '.status', self::STATUSES_SALIENTE_DESPACHADO)
            ->whereNotNull($alias . '.whatsapp_message_id')
            ->where($alias . '.whatsapp_message_id', '<>', '');
    }

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
     * Cuántos mensajes salientes se miran hacia atrás buscando el último que HABLÓ DE AGENDA.
     *
     * El mismo número que usa horario_figura_como_ofrecido(), y por el mismo motivo: la ventana no
     * está para acotar el permiso (de eso se ocupa el filtro de HOY) sino para que la consulta no
     * crezca con la conversación. Con 20 alcanza de sobra: entre la apertura flexible y la respuesta
     * del lead no hay más de un par de mensajes automáticos en el medio.
     */
    const MENSAJES_MIRADOS_PARA_OFERTA_FLEXIBLE = 20;

    /**
     * ¿La última cosa que ESTE lead escuchó del sistema SOBRE LA AGENDA fue una OFERTA FLEXIBLE?
     *
     * Una oferta flexible es la apertura "te la dejo lista ahora mismo, o para el horario que te
     * quede cómodo": ofrece la demo SIN nombrar ninguna hora.
     *
     * Es la SEGUNDA fuente del permiso para ignorar el margen mínimo de anticipación (ver
     * LeadAiService::oferta_vigente_sin_margen()). La primera —horario_figura_como_ofrecido()— no
     * puede cubrir este caso: cuando el lead contesta "dale, ahora" a una apertura flexible, el
     * horario que el sistema elige sale de una grilla fresca y nunca se le ofreció a nadie, así que
     * no figura en ningún `horarios_ofrecidos`. Sin esta segunda fuente, la oferta flexible movería
     * el bug de caducidad del mensaje de apertura al mensaje que confirma el turno.
     *
     * 🔴 POR QUÉ NO ALCANZA CON `horarios_ofrecidos === []` (corregido en la revisión de esta misma
     * rama). El `[]` NO lo escribe sólo la rama flexible: el prompt le pide SIEMPRE al modelo mandar
     * `[]` cuando su mensaje no ofrece horarios, y el modelo a veces ofrece un horario EN PROSA y
     * declara `[]` igual — es exactamente la patología que documenta el warning de
     * oferta_vigente_sin_margen(). Con el `[]` como única marca, un mensaje que nunca fue una oferta
     * flexible habilitaba el rescate del margen.
     *
     * Tampoco sirve `pending_actions` (que sí trae el `oferta_flexible` crudo del modelo): se limpia
     * a null en el momento de aplicar las acciones, o sea SIEMPRE antes de que el mensaje quede en
     * `enviado` (LeadAiService::apply_parsed_response(), rama `$existing_message !== null`). Un
     * mensaje enviado nunca lo conserva, así que no hay nada que leer ahí. Sin migración, la marca
     * que sí sobrevive es el TEXTO: se verifica sobre `content` con el mismo detector con el que el
     * servidor le otorgó la credencial al generarlo (texto_menciona_una_hora()). Si el texto que le
     * llegó al lead nombra una hora, esa hora se la ofrecimos y el permiso flexible no corresponde.
     *
     * Qué se exige, y por qué cada cosa:
     * - `sender = 'sistema'` y `status = 'enviado'`: mismo criterio que horario_figura_como_ofrecido().
     *   El permiso es "esto se lo dijimos NOSOTROS y le llegó"; una sugerencia sin aprobar no llegó.
     * - `is_status_event = false` e `is_error = false`: los eventos internos y los bloques rojos no
     *   son mensajes que el lead haya leído, y encima traen `horarios_ofrecidos` en null.
     * - `horarios_ofrecidos` estrictamente `[]` (el cast `array` distingue `[]` de `null`).
     * - `suggested_lead_status` dentro de $estados_de_agenda: acota el permiso al tramo de agenda.
     * - Y el texto NO nombra ninguna hora.
     *
     * 🔴 SE BUSCA EL ÚLTIMO QUE HABLÓ DE AGENDA, no el último a secas. Mirar sólo el último mensaje
     * enviado era frágil por el otro lado: cualquier saliente automático en el medio —la respuesta de
     * credenciales del webhook, un seguimiento por plantilla— mataba un permiso legítimo. Un mensaje
     * "no habló de agenda" cuando tiene `horarios_ofrecidos` en null Y su `suggested_lead_status`
     * está fuera del tramo: null es la marca de que ni siquiera pasó por el bloque de disponibilidad.
     * Esos se saltean; el primero que sí habló decide, y no se sigue mirando atrás.
     *
     * 🔴 VENTANA: sólo mensajes creados HOY, contra `created_at` y NO contra `sent_at`. Dos motivos
     * distintos y los dos importan. (1) `created_at` de este modelo lo estampa el trait
     * UsesVirtualTime con AppTime::now(), o sea EL MISMO reloj con el que se compara acá; `sent_at`
     * lo escribe el webhook con el reloj real, y mezclar los dos es el bug que dejó a
     * horario_figura_como_ofrecido() sin rescatar nada nunca (ver su docblock). (2) `sent_at` es
     * nullable, así que ordenar por él deja un mensaje sin `sent_at` fuera del "último" para siempre;
     * por eso acá se ordena por `id` desc, igual que la función hermana. Un día es la ventana
     * correcta porque el rescate del margen sólo existe para slots de HOY (gate de fecha de
     * oferta_vigente_sin_margen()): una apertura flexible de ayer no puede justificar nada de hoy.
     *
     * ⚠️ Alcance conocido: `suggested_lead_status` se guarda en null cuando el estado sugerido
     * coincide con el que el lead YA tenía (ver LeadAiService, `$estado !== $previous_status`). O sea
     * que una SEGUNDA apertura flexible seguida, con el lead ya en `solicita_disponibilidad`, no da
     * permiso. Es el lado seguro del error —el sistema se comporta como antes de esta misión y el
     * horario se frena— y cubre el caso real, que es la primera apertura: ahí el lead viene de
     * `calificado` y el estado sí cambia.
     *
     * 🔴 La lista de estados llega POR PARÁMETRO y no se importa de LeadAiService: el modelo no
     * depende del service (la dependencia va al revés, y ya es fuerte).
     *
     * @param int               $lead_id            Lead dueño de la conversación.
     * @param array<int,string> $estados_de_agenda  Slugs del tramo de agenda
     *                                              (LeadAiService::ESTADOS_REQUIEREN_SUPERVISION_AGENDAMIENTO).
     *
     * @return bool true si lo último que el sistema le dijo sobre la agenda fue una oferta flexible.
     */
    public static function ultima_oferta_fue_flexible(int $lead_id, array $estados_de_agenda): bool
    {
        if ($lead_id <= 0 || empty($estados_de_agenda)) {
            return false;
        }

        $mensajes = self::query()
            ->where('lead_id', $lead_id)
            ->where('sender', 'sistema')
            ->where('status', 'enviado')
            ->where('is_status_event', false)
            ->where('is_error', false)
            ->where('created_at', '>=', AppTime::now()->startOfDay())
            ->orderBy('id', 'desc')
            ->limit(self::MENSAJES_MIRADOS_PARA_OFERTA_FLEXIBLE)
            ->get(['id', 'content', 'horarios_ofrecidos', 'suggested_lead_status']);

        foreach ($mensajes as $mensaje) {
            $horarios  = $mensaje->horarios_ofrecidos;
            $estado    = (string) $mensaje->suggested_lead_status;
            $del_tramo = $estado !== '' && in_array($estado, $estados_de_agenda, true);

            /* Este mensaje no habló de agenda: no cuenta ni a favor ni en contra, se sigue atrás. */
            if ($horarios === null && ! $del_tramo) {
                continue;
            }

            /* Y este sí habló: acá se decide y no se mira más atrás. Si declaró horarios, lo último
             * que le dijimos fue una oferta CON hora y el permiso flexible no corresponde. */
            if (! is_array($horarios) || $horarios !== [] || ! $del_tramo) {
                return false;
            }

            return ! self::texto_menciona_una_hora((string) $mensaje->content);
        }

        return false;
    }

    /**
     * Patrones que cuentan como "este texto nombra una hora".
     *
     * Se listan acá, juntos y comentados uno por uno, porque el criterio es UNO SOLO y lo usan las
     * dos puntas del contrato de la apertura flexible: el servidor cuando decide si le cree al
     * modelo que su mensaje no nombró ninguna hora (LeadAiService::mensaje_menciona_una_hora()) y
     * el permiso del margen cuando relee el texto que efectivamente le llegó al lead
     * (ultima_oferta_fue_flexible(), arriba). Dos implementaciones distintas serían dos criterios.
     *
     * 🔴 Estos patrones son PROPIOS y NO son los que detectan el horario que propone el LEAD
     * (LeadAiService, bloque de $lead_proposed_time). Aquéllos alimentan otro camino, valen para las
     * dos dinámicas y son estrictos a propósito; endurecerlos para esto les cambiaría el sentido.
     */
    private const PATRONES_DE_HORA = [
        /* "12:30", "12:30 hs", "9:05". La forma canónica. */
        '/\b(\d{1,2})(?::(\d{2}))\s*(?:hs?|h)?\b/i',
        /* "12hs", "9 h", "5pm", "8am", "5 p.m.". */
        '/\b(\d{1,2})\s*(?:hs|h|am|pm|a\.?m\.?|p\.?m\.?)\b/i',
        /* "13.30": la hora escrita con punto. Los minutos tienen que ser 00-59 y no puede seguir
           otro dígito, que es lo que deja afuera a los precios ("$15.000", "$1.500"). */
        '/(?<![\d.,])\b([01]?\d|2[0-3])\.([0-5]\d)\b(?!\d)/',
        /* "a las 12", "a la 1", "para las 8", "hasta las 18". El "y media"/"y cuarto" que pueda
           venir después ya queda cubierto por el número. */
        '/\b(?:a|para|desde|hasta|hacia|sobre|tipo|entre)\s+las?\s+\d{1,2}\b/i',
        /* Lo mismo con el número escrito en palabras: "a las cinco de la tarde", "a la una". */
        '/\b(?:a|para|desde|hasta|hacia|sobre|tipo|entre)\s+las?\s+(?:una|dos|tres|cuatro|cinco|seis|siete|ocho|nueve|diez|once|doce)\b/i',
        /* "12 y media", "doce y cuarto", sin preposición adelante. */
        '/\b(?:\d{1,2}|una|dos|tres|cuatro|cinco|seis|siete|ocho|nueve|diez|once|doce)\s+y\s+(?:media|cuarto)\b/i',
        /* "al mediodía", "medio día", "a la medianoche". Sin \b alrededor de la palabra acentuada:
           con /u PHP no activa UCP, así que \b sigue siendo ASCII y la í rompería el límite. */
        '/medio\s?d[ií]a/iu',
        '/\bmedianoche\b/i',
        /* "en 5 minutos", "en cinco minutos", "en unos minutos", "en 10 min". Exige el "en" adelante
           justamente para no confundir una DURACIÓN ("dura 40 min", "la recorrida son 40 minutos")
           con un momento. */
        '/\ben\s+(?:\d{1,3}|un|una|unos|unas|dos|tres|cuatro|cinco|seis|siete|ocho|nueve|diez|quince|veinte|treinta|cuarenta|cincuenta|sesenta|noventa)\s+min(?:\.|utos?\b|\b)/i',
    ];

    /**
     * ¿Este texto nombra una hora concreta?
     *
     * Sólo se usa para ENDURECER: si devuelve true, un mensaje que se declaró "oferta flexible"
     * pierde esa credencial y se frena para revisión humana, y el permiso del margen no se otorga.
     * Nunca al revés.
     *
     * QUÉ CUBRE (cada forma tiene su patrón comentado en self::PATRONES_DE_HORA):
     *   - "12:30", "12:30 hs", "9:05"
     *   - "12hs", "9 h", "5pm", "8am", "5 p.m."
     *   - "13.30" (hora con punto)
     *   - "a las 12", "a la 1", "para las 8", "hasta las 18", con o sin "y media" / "y cuarto"
     *   - "a las cinco de la tarde" (números escritos en palabras, una…doce, detrás de la preposición)
     *   - "12 y media", "doce y cuarto"
     *   - "mediodía", "medio día", "medianoche"
     *   - "en 5 minutos", "en cinco minutos", "en unos minutos", "en 10 min"
     *
     * QUÉ NO CUBRE, a propósito:
     *   - FRANJAS del día ("a la tarde", "temprano", "después del almuerzo"). Detectarlas es leer
     *     prosa y da falsos positivos; la prohibición de franjas vive en el prompt y en el `.md`.
     *   - Un número suelto sin preposición ni sufijo ("nos vemos 5"): es indistinguible de cualquier
     *     otro número de la conversación.
     *   - "a eso de las cinco", "cerca de las cinco": la preposición no está pegada al "las".
     *
     * ⚠️ QUÉ SÍ DA TRUE aunque no sea una hora, y se deja así a propósito porque el lado seguro es
     * frenar: "24 hs" o "48 hs" como PLAZO ("te contesto dentro de las 24 hs") entran por el patrón
     * de sufijo, igual que ya entraban antes de esta misión. Un mensaje de apertura no tiene por qué
     * mencionar un plazo en horas, y si lo hiciera, el costo es una revisión humana de más.
     *
     * @param string $texto Texto a revisar.
     *
     * @return bool true si el texto nombra una hora.
     */
    public static function texto_menciona_una_hora(string $texto): bool
    {
        if (trim($texto) === '') {
            return false;
        }

        foreach (self::PATRONES_DE_HORA as $patron) {
            if (preg_match($patron, $texto) === 1) {
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
                            ->whereColumn('outbound.id', '>', 'lead_messages.id');
                        /* Misma definición de "ya se le contestó" que usan el botón de revisión y
                           las tarjetas. Ver LeadMessage::is_reply_to_lead(). */
                        self::apply_reply_to_lead_conditions($exists, 'outbound');
                    });
            });
        });
    }
}

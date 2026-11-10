<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

class SupportMessage extends Model
{
    use HasUuid;

    public const MARCA_ERP_VERIFICADO_PARA_CODEX = 'codex_inbound_verified';
    public const MARCA_TRANSICION_ERP_PARA_CODEX = 'codex_erp_legacy_transition';
    public const MARCA_PREGUNTA_ROBOTITO = 'robotito_followup';
    public const VERSION_ROBOTITO_ERP = 1;

    /**
     * 🔴 LA definición de "este mensaje del hilo es real", y la única que hay.
     *
     * Un mensaje es real cuando **no** es un borrador de la IA sin aprobar
     * (`is_ai_suggestion_draft`). Un borrador existe en el hilo y se ve en la pantalla con
     * `sender_type = 'admin'`, pero el cliente nunca lo recibió: no contesta nada, y no puede tapar
     * un mensaje del cliente que sigue esperando.
     *
     * ⚠️ Y no hay nada más que descartar: `support_messages` NO tiene `is_status_event` (verificado
     * contra las ocho migraciones de la tabla), a diferencia de `lead_messages`. Si algún día se
     * agrega una columna de eventos de estado, va acá y en el gemelo, nunca en los call-sites.
     *
     * 🔴 Tiene un gemelo en SQL, {@see self::apply_mensaje_real_conditions()}, porque hidratar el
     * hilo de todos los tickets para contar una tarjeta del panel de inicio no es viable. Los dos
     * tienen que decir lo mismo y hay un test que lo verifica
     * (`TicketsSinResponderEnSqlYEnPhpCoincidenTest`). Si tocás uno, tocá el otro — y no lo vuelvas
     * a escribir a mano en ningún lugar nuevo: usá este método o su gemelo. Es la misma lección que
     * en `LeadMessage::is_reply_to_lead()` costó tres copias que se separaron solas.
     *
     * @param SupportMessage $mensaje Mensaje del hilo.
     *
     * @return bool
     */
    public static function es_mensaje_real(SupportMessage $mensaje): bool
    {
        return ! $mensaje->is_ai_suggestion_draft;
    }

    /**
     * Gemelo en SQL de {@see self::es_mensaje_real()}: aplica la misma condición sobre el alias de
     * una subconsulta de `support_messages`.
     *
     * El alias se pasa por parámetro porque los dos call-sites lo llaman distinto: la tabla pelada
     * (`support_messages`) en la subconsulta correlacionada de
     * {@see \App\Models\SupportTicket::scopeSinResponder()}, y `posterior` en el `whereNotExists`
     * que busca un mensaje real más nuevo. Mismo criterio que
     * {@see \App\Models\LeadMessage::apply_reply_to_lead_conditions()}.
     *
     * ⚠️ La rama `whereNull()` es DEFENSA, no un caso de hoy: `is_ai_suggestion_draft` es
     * `boolean NOT NULL default false` e indexada
     * (`2026_06_03_180000_add_ai_draft_fields_to_support_messages.php:20`), así que hoy no hay forma
     * de insertar un mensaje con ese campo en null y la rama no se puede ejercitar. Queda escrita
     * para el día que la columna se vuelva nullable, y así las dos implementaciones se siguen
     * comportando igual (del lado PHP, `! null` también da true). Es exactamente el mismo caso que
     * la rama `whereNull('kind')` del scope de leads, documentado al final de `armar_matriz()` en
     * `RevisionDeLeadsEnSqlYEnPhpCoincidenTest`.
     *
     * @param \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder $query Subconsulta ya apuntada a support_messages.
     * @param string                                                                   $alias Alias con el que se referencian las columnas.
     *
     * @return void
     */
    public static function apply_mensaje_real_conditions($query, string $alias): void
    {
        $query->where(function ($real) use ($alias) {
            $real->whereNull($alias . '.is_ai_suggestion_draft')
                ->orWhere($alias . '.is_ai_suggestion_draft', false);
        });
    }

    /**
     * Campos asignables para guardar mensajes rápidamente.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    protected $hidden = ['whatsapp_remitente_crudo', 'erp_auto_reply'];

    /**
     * Casts de tiempos de estado del mensaje.
     * Campo WhatsApp mass-assignable vía guarded=[]: whatsapp_message_id.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'erp_auto_reply'          => 'array',
        'delivered_at'            => 'datetime',
        'read_at'                 => 'datetime',
        'synced_to_client_at'     => 'datetime',
        'is_ai_suggestion_draft'  => 'boolean',
        'is_trial_import_notice'  => 'boolean',
        'ai_auto_send_at'         => 'datetime',
        /* Sello de que el texto lo escribió el agente; sobrevive a la aprobación humana. */
        'ai_generated_at'         => 'datetime',
        /* Ticks de WhatsApp (webhook de estados de Kapso): llegó al teléfono / lo leyó el cliente. */
        'whatsapp_delivered_at'   => 'datetime',
        'whatsapp_seen_at'        => 'datetime',
        /* Reacción del operador a un mensaje del cliente. */
        'admin_reaction_at'       => 'datetime',
    ];

    /**
     * Reacciones rápidas que el panel permite mandar al cliente (misma paleta que leads).
     *
     * Cada una está en su forma canónica, tal como la manda WhatsApp (el corazón lleva el selector
     * de variación U+FE0F). Lo que sale a Meta es siempre esta forma, nunca lo que mande el SPA.
     *
     * @var array<int, string>
     */
    public const REACCIONES_DEL_PANEL = LeadMessage::REACCIONES_DEL_PANEL;

    /**
     * Scope estándar para fullModel y respuestas de API.
     */
    public function scopeWithAll($query)
    {
        $query->with('ticket', 'attachments', 'sender_admin', 'reply_to_message');
    }

    /**
     * Mensaje al que este responde citándolo (null si no cita nada o si el original ya no existe).
     *
     * Solo trae lo que hace falta para dibujar la cita en la burbuja; el `whatsapp_message_id` NO
     * viaja a la pantalla (se arma aparte en {@see self::reply_to_whatsapp_message_id()}).
     *
     * 🔴 El cuerpo se corta a 300 caracteres EN LA CONSULTA: la pantalla solo muestra 140 y este
     * mensaje viaja anidado en el evento de Pusher (`SupportMessageReceived`), que tiene un tope de
     * 10 KB y no pasa por ninguna guarda. Citar un mensaje largo no puede hacer que el broadcast del
     * mensaje corto que lo responde reviente (y con eso el envío o la respuesta del agente).
     */
    public function reply_to_message()
    {
        return $this->belongsTo(SupportMessage::class, 'reply_to_message_id')
            ->selectRaw('id, support_ticket_id, sender_type, kind, LEFT(body, 300) AS body');
    }

    /**
     * wamid del mensaje citado, para mandar la cita nativa de WhatsApp. null si no hay cita, si el
     * original no salió/llegó por WhatsApp (sin wamid) o si es un id simulado del modo de prueba.
     *
     * @return string|null
     */
    public function reply_to_whatsapp_message_id(): ?string
    {
        if (empty($this->reply_to_message_id)) {
            return null;
        }

        $wamid = trim((string) SupportMessage::where('id', $this->reply_to_message_id)->value('whatsapp_message_id'));

        if ($wamid === '' || strncmp($wamid, 'test-', 5) === 0) {
            return null;
        }

        return $wamid;
    }

    /**
     * Id local del mensaje de un ticket que tiene ese wamid (para resolver la cita de un entrante).
     *
     * @param int         $ticket_id Ticket donde se busca.
     * @param string|null $wamid     wamid del mensaje citado por el cliente.
     *
     * @return int|null
     */
    public static function local_id_by_wamid(int $ticket_id, ?string $wamid): ?int
    {
        $wamid = trim((string) $wamid);
        if ($wamid === '') {
            return null;
        }

        $id = SupportMessage::where('support_ticket_id', $ticket_id)->where('whatsapp_message_id', $wamid)->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * Ticket contenedor del mensaje.
     */
    public function ticket()
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    /**
     * Admin local emisor cuando sender_type=admin.
     */
    public function sender_admin()
    {
        return $this->belongsTo(Admin::class, 'sender_admin_id');
    }

    /**
     * Adjuntos multimedia del mensaje.
     */
    public function attachments()
    {
        return $this->hasMany(SupportMessageAttachment::class, 'support_message_id');
    }
}


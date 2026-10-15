<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

class SupportMessage extends Model
{
    use HasUuid;

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

    /**
     * Casts de tiempos de estado del mensaje.
     * Campo WhatsApp mass-assignable vía guarded=[]: whatsapp_message_id.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'delivered_at'            => 'datetime',
        'read_at'                 => 'datetime',
        'synced_to_client_at'     => 'datetime',
        'is_ai_suggestion_draft'  => 'boolean',
        'ai_auto_send_at'         => 'datetime',
        /* Sello de que el texto lo escribió el agente; sobrevive a la aprobación humana. */
        'ai_generated_at'         => 'datetime',
    ];

    /**
     * Scope estándar para fullModel y respuestas de API.
     */
    public function scopeWithAll($query)
    {
        $query->with('ticket', 'attachments', 'sender_admin');
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


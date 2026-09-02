<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Services\SupportAiSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SupportTicket extends Model
{
    use HasUuid;

    /**
     * Campos asignables para alta y edición de tickets.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

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
        $query->with('client', 'client_employee', 'assigned_admin', 'messages.attachments', 'messages.sender_admin');
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


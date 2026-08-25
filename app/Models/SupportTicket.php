<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
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
     * Valores por defecto de los interruptores del agente.
     *
     * Van acá y no solo en la migración porque un `SupportTicket::create()` devuelve el modelo
     * con lo que se le pasó, no con lo que la base rellenó: sin esto, el código que crea un
     * ticket y enseguida lee `requiere_verificacion_mensajes` sobre esa misma instancia lee
     * null, y `(bool) null` es false — o sea, el default se daría vuelta justo en el camino
     * más peligroso, el de mandarle algo al cliente sin que nadie lo lea.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'claude_auto_reply' => true,
        'requiere_verificacion_mensajes' => true,
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


<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

class SupportMessage extends Model
{
    use HasUuid;

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
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
        'synced_to_client_at' => 'datetime',
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


<?php

namespace App\Models;

use App\ModelProperties\AdminProperties;
use App\Models\AdminCalendarConnection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Admin extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function properties()
    {
        return AdminProperties::all();
    }

    protected $table = 'admins';

    protected $guarded = [];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at'        => 'datetime',
        'is_default_support_owner' => 'boolean',
        // Flag para asignación automática al crear nuevas tareas internas.
        'is_default_task_assignee' => 'boolean',
        // Flag que identifica al admin como closer (responsable de llamadas post-demo).
        'is_closer'                => 'boolean',
        // Flag para recibir WhatsApp cuando el agente escala una conversación de lead.
        'notify_lead_escalation_whatsapp' => 'boolean',
        // Flag para recibir WhatsApp cuando se agenda una demo.
        'notify_demo_scheduled_whatsapp'  => 'boolean',
        // Flag para recibir WhatsApp cuando falla el envío automático de un mensaje del sistema.
        'notify_send_errors_whatsapp'     => 'boolean',
        // Flag para recibir WhatsApp cuando una sugerencia queda pendiente de verificación manual.
        'notify_verificacion_whatsapp'    => 'boolean',
    ];

    /**
     * Relación con la conexión de Google Calendar del admin closer.
     */
    public function calendar_connection()
    {
        return $this->hasOne(AdminCalendarConnection::class, 'admin_id');
    }

    function scopeWithAll($query) {
        // placeholder para mantener consistencia con empresa-api
    }

    /**
     * Tickets de soporte asignados al admin.
     */
    public function support_tickets()
    {
        return $this->hasMany(SupportTicket::class, 'assigned_admin_id');
    }

    /**
     * Devices con suscripción Web Push activa para este admin.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function push_subscriptions()
    {
        return $this->hasMany(AdminPushSubscription::class, 'admin_id');
    }
}

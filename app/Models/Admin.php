<?php

namespace App\Models;

use App\ModelProperties\AdminProperties;
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
    ];

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
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Suscripción Web Push de un device de un admin (teléfono, notebook, etc.).
 * Generada por el navegador al aceptar el permiso de notificaciones en la PWA.
 */
class AdminPushSubscription extends Model
{
    protected $guarded = [];

    protected $casts = [
        'last_used_at' => 'datetime',
    ];

    /**
     * Admin propietario de este device.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function admin()
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }
}

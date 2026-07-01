<?php

namespace App\Models;

use App\Models\Concerns\UsesVirtualTime;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Pivot que registra qué admins tienen activa la notificación WhatsApp
 * de mensajes entrantes para un lead específico.
 *
 * La tabla lead_admin_notifications tiene clave primaria compuesta (lead_id, admin_id).
 * No lleva timestamps: la suscripción es binaria (existe/no existe la fila).
 */
class LeadAdminNotification extends Pivot
{
    use UsesVirtualTime;

    /** @var string Nombre explícito de la tabla pivot. */
    protected $table = 'lead_admin_notifications';

    /** Sin timestamps: la suscripción no necesita registro de tiempo. */
    public $timestamps = false;

    /** @var array Sin restricción de asignación masiva dado que es un pivot simple. */
    protected $guarded = [];
}

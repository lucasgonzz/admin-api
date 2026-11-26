<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un chat del número PERSONAL de WhatsApp: una fila por teléfono de cliente (E.164) con el cursor de lectura.
 *
 * `leido_hasta` es un cursor INTERNO del admin: marcar leído no manda ningún acuse a Meta.
 */
class WhatsappPersonalChat extends Model
{
    /**
     * Nombre de la tabla.
     *
     * @var string
     */
    protected $table = 'whatsapp_personal_chats';

    /**
     * Todo es asignable: sólo lo escriben los servicios de WhatsappPersonal.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * Casteos.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'last_message_at' => 'datetime',
        'last_inbound_at' => 'datetime',
        'leido_hasta'     => 'datetime',
    ];

    /**
     * Scope vacío por convención del repo para modelos nuevos.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query Consulta.
     *
     * @return void
     */
    public function scopeWithAll($query)
    {
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Mensaje WhatsApp del flujo de implementación (entrada o salida).
 *
 * @property int         $implementation_id Implementación asociada.
 * @property int         $stage_number      Etapa en la que se registró el mensaje.
 * @property string      $direction         inbound | outbound.
 * @property string|null $phone             Teléfono E.164 de la contraparte (destino en outbound, remitente en inbound).
 * @property string      $body              Contenido del mensaje.
 * @property string|null $whatsapp_message_id Id externo para idempotencia.
 */
class ImplementationMessage extends Model
{
    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'sent_at' => 'datetime',
    ];

    /**
     * Implementación dueña de este mensaje.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function implementation()
    {
        return $this->belongsTo(Implementation::class);
    }
}

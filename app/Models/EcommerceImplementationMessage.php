<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Mensaje WhatsApp del flujo de implementación de ecommerce (entrada o salida).
 *
 * @property int         $ecommerce_implementation_id Implementación asociada.
 * @property int         $stage_number                Etapa en la que se registró el mensaje.
 * @property string      $direction                   inbound | outbound.
 * @property string      $body                        Contenido del mensaje.
 * @property string|null $whatsapp_message_id         Id externo para idempotencia.
 */
class EcommerceImplementationMessage extends Model
{
    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'ecommerce_implementation_id',
        'stage_number',
        'direction',
        'body',
        'whatsapp_message_id',
        'sent_at',
    ];

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
    public function ecommerce_implementation()
    {
        return $this->belongsTo(EcommerceImplementation::class);
    }
}

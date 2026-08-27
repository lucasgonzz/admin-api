<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Atribución de un anuncio Click-to-WhatsApp: qué aviso de Meta trajo a un teléfono.
 *
 * Cada fila es un clic en un anuncio CTWA, capturado del bloque `referral` que Meta manda en el
 * primer mensaje de la persona. Lo persiste {@see \App\Http\Controllers\MetaRawWebhookController}
 * desde el webhook crudo de Meta (`kind: meta` de Kapso), que es el ÚNICO que trae ese bloque: el
 * payload procesado de Kapso tiene campos fijos y no lo incluye.
 *
 * 🔴 El vínculo con el lead es por teléfono normalizado y nada más. No le agregues `lead_id`: el
 * referral llega antes de que el lead exista, y atarlo obligaría a este camino a crear leads —
 * justo lo que el endpoint tiene prohibido hacer.
 */
class WhatsappAdReferral extends Model
{
    /**
     * Nombre de la tabla en base de datos.
     *
     * @var string
     */
    protected $table = 'whatsapp_ad_referrals';

    /**
     * Campos asignables. El controlador arma el array completo desde el payload de Meta.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'phone',
        'ctwa_clid',
        'source_id',
        'source_type',
        'source_url',
        'headline',
        'body',
        'media_type',
        'thumbnail_url',
        'wamid',
        'received_at',
        'raw',
    ];

    /**
     * Casteos de tipos para lectura y persistencia consistente.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'received_at' => 'datetime',
        'raw'         => 'array',
    ];

    /**
     * Lead que corresponde a este teléfono, si ya existe.
     *
     * Es `belongsTo` por teléfono y no por id: cuando la fila se crea, el lead puede no existir
     * todavía. Devuelve null hasta que el webhook de Kapso lo dé de alta.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function lead()
    {
        return $this->belongsTo(Lead::class, 'phone', 'phone');
    }
}

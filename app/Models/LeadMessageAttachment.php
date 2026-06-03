<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Archivo multimedia persistido para un mensaje de lead (audio de WhatsApp, etc.).
 */
class LeadMessageAttachment extends Model
{
    /**
     * Campos asignables al crear adjunto desde WhatsappInboundMediaService.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * Scope estándar de compatibilidad con fullModel.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     */
    public function scopeWithAll($query)
    {
        $query->with('message');
    }

    /**
     * Mensaje padre del adjunto.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function message()
    {
        return $this->belongsTo(LeadMessage::class, 'lead_message_id');
    }
}

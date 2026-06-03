<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportMessageAttachment extends Model
{
    /**
     * Campos asignables para crear adjuntos de soporte.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * Scope estándar de compatibilidad con fullModel.
     */
    public function scopeWithAll($query)
    {
        $query->with('message');
    }

    /**
     * Mensaje al que pertenece el adjunto.
     */
    public function message()
    {
        return $this->belongsTo(SupportMessage::class, 'support_message_id');
    }
}


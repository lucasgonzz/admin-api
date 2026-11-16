<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Registro de la transición y de los destinatarios ya despachados, independiente de WhatsApp. */
class SupportAttentionNotification extends Model
{
    protected $guarded = [];

    protected $casts = [
        'generation' => 'integer',
        'emailed_admin_ids' => 'array',
        'email_notified_at' => 'datetime',
        'dispatched_admin_ids' => 'array',
        'accepted_endpoint_hashes' => 'array',
        'processing_until' => 'datetime',
        'notified_at' => 'datetime',
        'enqueued_at' => 'datetime',
    ];
}

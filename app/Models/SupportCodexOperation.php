<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** El intento se guarda ANTES de tocar WhatsApp: una caída nunca habilita otro envío. */
class SupportCodexOperation extends Model
{
    protected $guarded = [];

    protected $casts = ['approval_payload' => 'array'];
}

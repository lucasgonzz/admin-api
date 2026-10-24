<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Estado durable de atención; no depende de que la PC permanezca prendida. */
class SupportCodexCase extends Model
{
    protected $guarded = [];

    protected $hidden = ['lease_hash'];

    protected $casts = [
        'lease_expires_at' => 'datetime',
        'confirmation_due_at' => 'datetime',
        'follow_up_sent' => 'boolean',
    ];
}

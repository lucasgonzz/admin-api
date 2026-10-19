<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SharedProductFact extends Model
{
    protected $guarded = [];
    protected $casts = [
        'product' => 'array',
        'expires_at' => 'datetime',
        'lease_expires_at' => 'datetime',
    ];
    protected $hidden = ['lease_token_hash', 'lease_client_id', 'auth_user_id', 'auth_user_name'];
}

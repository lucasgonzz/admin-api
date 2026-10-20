<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DemoChatMensaje extends Model
{
    protected $table = 'demo_chat_mensajes';
    protected $guarded = [];
    protected $casts = ['videos' => 'array', 'revisiones' => 'array'];
    protected $hidden = ['revisiones', 'admin_id'];
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DemoChatConversacion extends Model
{
    protected $table = 'demo_chat_conversaciones';
    protected $guarded = [];
    protected $casts = [
        'espera_segundos' => 'integer', 'espera_iniciada_at' => 'datetime',
        'auto_responder' => 'boolean', 'revision' => 'integer', 'generacion' => 'integer',
        'iniciado_at' => 'datetime', 'responder_desde' => 'datetime',
    ];

    public function mensajes()
    {
        return $this->hasMany(DemoChatMensaje::class, 'conversacion_id');
    }

    public function progresos()
    {
        return $this->hasMany(DemoChatProgreso::class, 'conversacion_id');
    }
}

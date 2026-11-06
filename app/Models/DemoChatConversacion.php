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
        'prueba_cliente_id' => 'integer',
    ];

    public function es_de_prueba(): bool
    {
        return $this->prueba_cliente_id !== null;
    }

    public static function identidad(Lead $lead, ?int $prueba_cliente_id): array
    {
        return $prueba_cliente_id === null ? ['lead_id' => $lead->id] : [
            'prueba_lead_id' => $lead->id, 'prueba_cliente_id' => $prueba_cliente_id,
        ];
    }

    public function scopeDelLead($query, Lead $lead, ?int $prueba_cliente_id = null)
    {
        return $query->where(self::identidad($lead, $prueba_cliente_id));
    }

    public function mensajes()
    {
        return $this->hasMany(DemoChatMensaje::class, 'conversacion_id');
    }

    public function progresos()
    {
        return $this->hasMany(DemoChatProgreso::class, 'conversacion_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DemoChatProgreso extends Model
{
    protected $table = 'demo_chat_progresos';
    protected $guarded = [];
    protected $casts = ['segundos_vistos' => 'array', 'duracion' => 'integer', 'omitido' => 'boolean', 'completado_at' => 'datetime'];
}

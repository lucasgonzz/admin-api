<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Cuánto vio un usuario de un video (misión centro-de-recursos-clientes, 13/10/2026). Ver la
 * migración por el formato de `vistos` y por qué `terminado_at` no se borra.
 *
 * @property int         $id
 * @property int         $recursos_usuario_id
 * @property int         $recursos_video_id
 * @property string|null $vistos
 * @property float       $porcentaje
 * @property float       $ultima_posicion
 * @property \Carbon\Carbon|null $terminado_at
 */
class RecursosProgreso extends Model
{
    protected $table = 'recursos_progresos';

    protected $guarded = [];

    protected $casts = [
        'recursos_usuario_id' => 'integer',
        'recursos_video_id'   => 'integer',
        'porcentaje'          => 'float',
        'ultima_posicion'     => 'float',
        'terminado_at'        => 'datetime',
    ];

    public function usuario()
    {
        return $this->belongsTo(RecursosUsuario::class, 'recursos_usuario_id');
    }

    public function video()
    {
        return $this->belongsTo(RecursosVideo::class, 'recursos_video_id');
    }
}

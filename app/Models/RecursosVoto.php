<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * El 👍 (1) o 👎 (-1) de un usuario en un video (misión centro-de-recursos-clientes, 13/10/2026).
 *
 * @property int $id
 * @property int $recursos_usuario_id
 * @property int $recursos_video_id
 * @property int $valor
 */
class RecursosVoto extends Model
{
    protected $table = 'recursos_votos';

    protected $guarded = [];

    protected $casts = [
        'recursos_usuario_id' => 'integer',
        'recursos_video_id'   => 'integer',
        'valor'               => 'integer',
    ];

    public function video()
    {
        return $this->belongsTo(RecursosVideo::class, 'recursos_video_id');
    }
}

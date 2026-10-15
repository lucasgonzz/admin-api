<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * La encuesta al terminar un video (misión centro-de-recursos-clientes, 13/10/2026).
 *
 * @property int         $id
 * @property int         $recursos_usuario_id
 * @property int         $recursos_video_id
 * @property string|null $util      si | no
 * @property string|null $entendio  si | mas_o_menos | no
 * @property string|null $mejora
 */
class RecursosEncuesta extends Model
{
    const UTIL = ['si', 'no'];

    const ENTENDIO = ['si', 'mas_o_menos', 'no'];

    protected $table = 'recursos_encuestas';

    protected $guarded = [];

    protected $casts = [
        'recursos_usuario_id' => 'integer',
        'recursos_video_id'   => 'integer',
    ];

    public function usuario()
    {
        return $this->belongsTo(RecursosUsuario::class, 'recursos_usuario_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un "me gusta" de un usuario del centro en una pregunta (misión centro-de-recursos-clientes,
 * 13/10/2026).
 *
 * @property int $id
 * @property int $recursos_pregunta_id
 * @property int $recursos_usuario_id
 */
class RecursosPreguntaMeGusta extends Model
{
    protected $table = 'recursos_pregunta_me_gusta';

    protected $guarded = [];

    protected $casts = [
        'recursos_pregunta_id' => 'integer',
        'recursos_usuario_id'  => 'integer',
    ];
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una sesión del centro de recursos (misión centro-de-recursos-clientes, 13/10/2026). Solo guarda el
 * sha256 del token; el token en claro lo tiene la página y nadie más.
 *
 * @property int         $id
 * @property int         $recursos_usuario_id
 * @property string      $token_hash
 * @property \Carbon\Carbon|null $expires_at
 * @property \Carbon\Carbon|null $last_used_at
 */
class RecursosSesion extends Model
{
    protected $table = 'recursos_sesiones';

    protected $guarded = [];

    protected $casts = [
        'recursos_usuario_id' => 'integer',
        'expires_at'          => 'datetime',
        'last_used_at'        => 'datetime',
    ];

    public function usuario()
    {
        return $this->belongsTo(RecursosUsuario::class, 'recursos_usuario_id');
    }
}

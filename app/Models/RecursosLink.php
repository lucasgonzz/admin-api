<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * El link personal del dueño al centro de recursos (misión centro-de-recursos-clientes, 13/10/2026).
 * Reutilizable hasta `expires_at`.
 *
 * `token_cifrado` va con `encrypted` y oculto: es lo que permite volver a mostrar el MISMO link
 * vigente desde el panel (ver la migración), y no tiene por qué salir en ningún payload.
 *
 * @property int         $id
 * @property int         $recursos_usuario_id
 * @property string      $token_hash
 * @property string|null $token_cifrado
 * @property \Carbon\Carbon|null $expires_at
 * @property int         $usos
 * @property int|null    $creado_por_admin_id
 */
class RecursosLink extends Model
{
    protected $table = 'recursos_links';

    protected $guarded = [];

    protected $casts = [
        'recursos_usuario_id' => 'integer',
        'token_cifrado'       => 'encrypted',
        'expires_at'          => 'datetime',
        'usos'                => 'integer',
        'creado_por_admin_id' => 'integer',
    ];

    protected $hidden = ['token_hash', 'token_cifrado'];

    public function usuario()
    {
        return $this->belongsTo(RecursosUsuario::class, 'recursos_usuario_id');
    }
}

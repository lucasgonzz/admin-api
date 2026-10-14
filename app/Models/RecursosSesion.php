<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una sesión del centro de recursos (misión centro-de-recursos-clientes, 13/10/2026). Solo guarda el
 * sha256 del token; el token en claro lo tiene la página y nadie más.
 *
 * `solo_lectura`: la abrió un pase de login maestro (soporte mirando como el dueño). La sesión se ve
 * como la del dueño pero no puede escribir (ver `App\Http\Middleware\RecursosEscritura`).
 *
 * @property int         $id
 * @property int         $recursos_usuario_id
 * @property string      $token_hash
 * @property \Carbon\Carbon|null $expires_at
 * @property \Carbon\Carbon|null $last_used_at
 * @property bool        $solo_lectura
 */
class RecursosSesion extends Model
{
    protected $table = 'recursos_sesiones';

    protected $guarded = [];

    protected $casts = [
        'recursos_usuario_id' => 'integer',
        'expires_at'          => 'datetime',
        'last_used_at'        => 'datetime',
        'solo_lectura'        => 'boolean',
    ];

    /**
     * El hash del token no sale serializado por ningún lado. Las rutas genéricas del panel ni siquiera
     * resuelven este modelo (`GeneralHelper::MODELOS_NO_EXPUESTOS`); esto es la segunda reja.
     *
     * @var array<int, string>
     */
    protected $hidden = ['token_hash'];

    public function usuario()
    {
        return $this->belongsTo(RecursosUsuario::class, 'recursos_usuario_id');
    }
}

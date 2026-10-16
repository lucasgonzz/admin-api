<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una búsqueda hecha en el buscador del centro de recursos (misión busquedas-centro-recursos,
 * 16/10/2026): la consulta normalizada, cuántos videos devolvió el buscador y, si el usuario abrió uno,
 * cuál y en qué lugar de la lista estaba. Ver `App\Services\RecursosBusquedasService`.
 *
 * @property int         $id
 * @property string      $evento
 * @property int|null    $recursos_usuario_id   NULL = invitado.
 * @property string      $consulta
 * @property int         $resultados
 * @property string|null $primero_codigo
 * @property bool        $completo
 * @property string|null $abierto_codigo
 * @property int|null    $abierto_posicion
 */
class RecursosBusqueda extends Model
{
    protected $table = 'recursos_busquedas';

    protected $guarded = [];

    protected $casts = [
        'recursos_usuario_id' => 'integer',
        'resultados'          => 'integer',
        'completo'            => 'boolean',
        'abierto_posicion'    => 'integer',
    ];

    public function usuario()
    {
        return $this->belongsTo(RecursosUsuario::class, 'recursos_usuario_id');
    }
}

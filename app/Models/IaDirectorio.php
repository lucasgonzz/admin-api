<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una fila del directorio de la app `ia.comerciocity.com`: el dueño de UN cliente, con su usuario
 * hasheado (misión cruzada `app-ia-y-memoria`, 29/10/2026). La escribe `IaDirectorioService`.
 *
 * 🔴 `usuario_hash` es el único rastro del usuario: nunca el usuario en claro ni la contraseña.
 *
 * @property int                 $id
 * @property int                 $client_id
 * @property string|null         $usuario_hash
 * @property string|null         $nombre_dueno
 * @property bool                $extension_activa
 * @property \Carbon\Carbon|null $sincronizado_en
 */
class IaDirectorio extends Model
{
    protected $table = 'ia_directorio';

    protected $guarded = [];

    protected $casts = [
        'client_id'        => 'integer',
        'extension_activa' => 'boolean',
        'sincronizado_en'  => 'datetime',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un par de tokens (acceso + refresh) del conector MCP (misión dots-openclaw). Opacos: en la base sólo viven los
 * sha256. Cada refresh crea una fila nueva de la misma `family` y deja la anterior sin `refresh_hash` útil
 * (rotación); reusar un refresh viejo revoca toda la familia.
 *
 * @property int                             $id
 * @property string                          $access_hash        sha256 (hex) del token de acceso.
 * @property string|null                     $refresh_hash       sha256 (hex) del refresh (null una vez usado).
 * @property string                          $family             Cadena de refresh.
 * @property int|null                        $code_id            Código con el que nació la familia.
 * @property string                          $client_id
 * @property int                             $admin_id
 * @property string                          $scope
 * @property string                          $resource
 * @property \Illuminate\Support\Carbon      $access_expires_at
 * @property \Illuminate\Support\Carbon|null $refresh_expires_at
 * @property \Illuminate\Support\Carbon|null $revoked_at
 * @property \Illuminate\Support\Carbon|null $last_used_at
 */
class McpOauthToken extends Model
{
    /** @var string */
    protected $table = 'mcp_oauth_tokens';

    /** @var array<int, string> */
    protected $fillable = [
        'access_hash', 'refresh_hash', 'family', 'code_id', 'client_id', 'admin_id', 'scope', 'resource',
        'access_expires_at', 'refresh_expires_at', 'revoked_at', 'last_used_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'admin_id'           => 'integer',
        'code_id'            => 'integer',
        'access_expires_at'  => 'datetime',
        'refresh_expires_at' => 'datetime',
        'revoked_at'         => 'datetime',
        'last_used_at'       => 'datetime',
    ];

    /**
     * Sin relaciones que cargar: placeholder para mantener consistencia con el resto de los modelos del admin.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return void
     */
    public function scopeWithAll($query)
    {
    }
}

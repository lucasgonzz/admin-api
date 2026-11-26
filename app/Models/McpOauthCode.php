<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un código de autorización del conector MCP (misión dots-openclaw): de un solo uso, vence a los pocos minutos y
 * queda atado a cliente, admin, `redirect_uri`, desafío PKCE, scope y recurso. En la base sólo vive su sha256.
 *
 * @property int                             $id
 * @property string                          $code_hash      sha256 (hex) del código.
 * @property string                          $client_id
 * @property int                             $admin_id
 * @property string                          $redirect_uri
 * @property string                          $code_challenge Desafío PKCE (S256, base64url).
 * @property string                          $scope
 * @property string                          $resource
 * @property \Illuminate\Support\Carbon      $expires_at
 * @property \Illuminate\Support\Carbon|null $used_at
 */
class McpOauthCode extends Model
{
    /** @var string */
    protected $table = 'mcp_oauth_codes';

    /** @var array<int, string> */
    protected $fillable = [
        'code_hash', 'client_id', 'admin_id', 'redirect_uri', 'code_challenge', 'scope', 'resource', 'expires_at', 'used_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'admin_id'   => 'integer',
        'expires_at' => 'datetime',
        'used_at'    => 'datetime',
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

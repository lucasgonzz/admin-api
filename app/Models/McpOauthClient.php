<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un cliente OAuth registrado por registro dinámico (DCR) para el conector MCP de sólo lectura (misión
 * dots-openclaw). Hoy el único cliente real es ChatGPT; guarda sus `redirect_uri` (validados contra
 * `config/mcp.php` al registrarlo) y el nombre que mostró al registrarse.
 *
 * @property int                             $id
 * @property string                          $client_id     Identificador público (`ccm_c_` + 32 hex).
 * @property string|null                     $client_name   Nombre que dio el cliente.
 * @property array<int, string>              $redirect_uris Redirects registrados (JSON en la base).
 * @property \Illuminate\Support\Carbon|null $last_used_at
 */
class McpOauthClient extends Model
{
    /** @var string */
    protected $table = 'mcp_oauth_clients';

    /** @var array<int, string> */
    protected $fillable = ['client_id', 'client_name', 'redirect_uris', 'last_used_at'];

    /** @var array<string, string> */
    protected $casts = [
        'redirect_uris' => 'array',
        'last_used_at'  => 'datetime',
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

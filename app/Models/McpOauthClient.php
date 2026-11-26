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
 * @property array<int, string>|null         $grant_types   Grants con los que se registró (null = los dos, clientes anteriores a D7).
 * @property \Illuminate\Support\Carbon|null $last_used_at
 */
class McpOauthClient extends Model
{
    /** @var string */
    protected $table = 'mcp_oauth_clients';

    /** @var array<int, string> */
    protected $fillable = ['client_id', 'client_name', 'redirect_uris', 'grant_types', 'last_used_at'];

    /** @var array<string, string> */
    protected $casts = [
        'redirect_uris' => 'array',
        'grant_types'   => 'array',
        'last_used_at'  => 'datetime',
    ];

    /**
     * Los grants que el cliente puede usar: los que registró, o los dos soportados si no hay registro (clientes
     * anteriores a la columna `grant_types`).
     *
     * @return array<int, string>
     */
    public function grants_permitidos()
    {
        $grants = $this->grant_types;

        return is_array($grants) && count($grants) > 0 ? array_values($grants) : ['authorization_code', 'refresh_token'];
    }

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

<?php

namespace App\Models;

use App\ModelProperties\ClientApiProperties;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * Endpoint de API de un cliente (URL + path) para deployment.
 *
 * @property int         $id
 * @property string      $uuid
 * @property int         $client_id
 * @property string      $url
 * @property string      $path
 * @property string|null $spa_url
 * @property string      $hosting_type          shared_hosting | vps
 * @property string|null $vps_path
 * @property array|null  $provisioning_secrets  Credenciales que generó el aprovisionamiento (cifradas)
 * @property \Carbon\Carbon|null $hosting_provisioned_at
 */
class ClientApi extends Model
{
    use HasUuid;

    /**
     * Meta declarativa consumida por admin-spa (MetaController).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function properties()
    {
        return ClientApiProperties::all();
    }

    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * Conversiones de tipos.
     *
     * 🔴 `encrypted:array` y no `array`: acá adentro viven la contraseña de la base de datos del
     * cliente y, en VPS, las de los sitios de CloudPanel. En claro serían secretos nuevos escritos
     * en texto plano en la base del admin. El valor que llega a MySQL es el string de Laravel Crypt,
     * y por eso la columna es `text` y no `json` (está explicado en la migración).
     *
     * @var array<string, string>
     */
    protected $casts = [
        'provisioning_secrets'   => 'encrypted:array',
        'hosting_provisioned_at' => 'datetime',
    ];

    /**
     * Atributos que NUNCA salen serializados.
     *
     * 🔴 provisioning_secrets queda oculto de entrada, no por las dudas: esta relación se carga con
     * scopeWithAll() y viaja en el index y en el show de instalaciones, de upgrades y de clientes.
     * Sin este $hidden, la contraseña de la base de cada cliente saldría descifrada en cada uno de
     * esos payloads, y quedaría además en cualquier log de request o en la caché del navegador.
     *
     * Las credenciales se entregan por un endpoint aparte, bajo demanda y explícito.
     *
     * @var array<int, string>
     */
    protected $hidden = ['provisioning_secrets'];

    /**
     * Eager load del cliente asociado.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    function scopeWithAll($query)
    {
        $query->with('client');
    }

    /**
     * Cliente dueño de este endpoint.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}

<?php

namespace App\Models;

use App\ModelProperties\ClientApiProperties;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * Endpoint de API de un cliente (URL + path) para deployment.
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

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un renglón de un lote de cambio de .env: una variable, en la API de un cliente.
 *
 * Es previsualización y auditoría a la vez. Los valores de variables sensibles se guardan
 * enmascarados — ver EnvBulkChangeService::mask_value().
 */
class EnvChangeItem extends Model
{
    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * Casteos de atributos.
     *
     * El valor nuevo real viaja cifrado en base, igual que ClientSshCredential::password: es la
     * API key o la contraseña que se va a escribir en el servidor del cliente.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'new_value_encrypted' => 'encrypted',
    ];

    /**
     * No se expone nunca el valor real en una respuesta JSON, sólo su versión enmascarada.
     *
     * @var array<int, string>
     */
    protected $hidden = ['new_value_encrypted'];

    /**
     * Eager load del cliente y la API destino.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    function scopeWithAll($query)
    {
        return $query->with('client', 'client_api');
    }

    /**
     * Lote al que pertenece este renglón.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function batch()
    {
        return $this->belongsTo(EnvChangeBatch::class, 'env_change_batch_id');
    }

    /**
     * Cliente sobre el que se opera.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * API destino sobre la que se escribe el .env.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function client_api()
    {
        return $this->belongsTo(ClientApi::class);
    }
}

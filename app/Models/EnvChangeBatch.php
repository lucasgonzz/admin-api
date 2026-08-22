<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Lote de cambio masivo de variables .env sobre los clientes.
 *
 * Nace como previsualización (nada escrito) y sólo se aplica presentando su token, una sola vez y
 * antes de que venza.
 */
class EnvChangeBatch extends Model
{
    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * Casteos de atributos.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'expires_at' => 'datetime',
        'applied_at' => 'datetime',
    ];

    /**
     * Eager load de los renglones del lote con su cliente.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    function scopeWithAll($query)
    {
        return $query->with('items', 'items.client');
    }

    /**
     * Renglones del lote: uno por (cliente, API, variable).
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function items()
    {
        return $this->hasMany(EnvChangeItem::class);
    }

    /**
     * Indica si el lote todavía se puede aplicar.
     *
     * Un lote se aplica una sola vez: 'applied' no vuelve a 'previewed'. Sin esto, reenviar el
     * mismo token reescribiría el .env de todos los clientes del lote.
     *
     * @return bool
     */
    public function is_applicable(): bool
    {
        return $this->status === 'previewed' && $this->expires_at !== null && $this->expires_at->isFuture();
    }
}

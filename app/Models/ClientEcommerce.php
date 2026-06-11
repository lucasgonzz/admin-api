<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tienda online (ecommerce) asociada a un cliente.
 *
 * @property int         $client_id            Cliente dueño de la tienda.
 * @property string|null $domain               Dominio final de la tienda.
 * @property string|null $api_url              URL de la API de la tienda.
 * @property string|null $spa_url              URL del SPA de la tienda.
 * @property string|null $api_path             Path de instalación del API.
 * @property string|null $spa_path             Path de instalación del SPA.
 * @property string      $status               pending | installing | active.
 * @property array|null  $ecommerce_setup_data Configuración recolectada por WhatsApp.
 */
class ClientEcommerce extends Model
{
    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'client_id',
        'domain',
        'api_url',
        'spa_url',
        'api_path',
        'spa_path',
        'status',
        'ecommerce_setup_data',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'ecommerce_setup_data' => 'array',
    ];

    /**
     * Cliente dueño de esta tienda.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}

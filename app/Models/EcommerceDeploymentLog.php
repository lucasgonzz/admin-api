<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Línea de log de una etapa del pipeline de instalación/actualización del ecommerce.
 *
 * Espeja DeploymentLog pero apuntando a client_ecommerce_installations, para
 * alimentar el panel de log en vivo del ecommerce.
 */
class EcommerceDeploymentLog extends Model
{
    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * Solo created_at; no hay updated_at en la tabla.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var array<int, string>
     */
    protected $dates = [
        'created_at',
    ];

    /**
     * Carga la corrida asociada a este log.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return void
     */
    public function scopeWithAll($query)
    {
        $query->with([
            'installation',
        ]);
    }

    /**
     * Corrida de instalación/actualización a la que pertenece esta línea.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function installation()
    {
        return $this->belongsTo(ClientEcommerceInstallation::class, 'client_ecommerce_installation_id');
    }
}

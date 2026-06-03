<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Línea de log de una etapa de deployment (client_version_upgrade).
 */
class DeploymentLog extends Model
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
     * Upgrade al que pertenece esta línea.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function client_version_upgrade()
    {
        return $this->belongsTo(ClientVersionUpgrade::class);
    }
}

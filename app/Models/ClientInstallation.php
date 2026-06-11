<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * Instalación inicial de sistema para un cliente.
 *
 * Registra el pipeline completo de instalación desde cero: compilación de SPA,
 * subida de API, escritura del .env y ejecución del user-setup inicial.
 * Difiere de ClientVersionUpgrade en que no parte de una versión anterior
 * ni ejecuta migraciones/seeders de actualización.
 *
 * @property int         $id
 * @property string      $uuid
 * @property int         $client_id
 * @property int|null    $client_api_id
 * @property int|null    $version_id
 * @property string      $status            pendiente | instalando | completada | fallida
 * @property array|null  $env_manual_values Valores de variables is_manual_on_create
 * @property string|null $failure_reason
 * @property \Carbon\Carbon|null $started_at
 * @property \Carbon\Carbon|null $finished_at
 */
class ClientInstallation extends Model
{
    use HasUuid;

    /**
     * Permite asignación masiva de todos los campos.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * Conversiones de tipos para campos de fecha y JSON.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'started_at'        => 'datetime',
        'finished_at'       => 'datetime',
        'env_manual_values' => 'array',
    ];

    /**
     * Carga todas las relaciones necesarias para mostrar una instalación completa.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return void
     */
    public function scopeWithAll($query)
    {
        $query->with([
            'client',
            'client_api',
            'version',
            'deployment_logs' => function ($relation_query) {
                $relation_query->orderBy('created_at');
            },
        ]);
    }

    /**
     * Cliente al que pertenece esta instalación.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * API del cliente donde se instalará el sistema.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function client_api()
    {
        return $this->belongsTo(ClientApi::class);
    }

    /**
     * Versión inicial a instalar.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function version()
    {
        return $this->belongsTo(Version::class);
    }

    /**
     * Líneas de log del proceso de instalación.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function deployment_logs()
    {
        return $this->hasMany(DeploymentLog::class)->orderBy('id');
    }
}

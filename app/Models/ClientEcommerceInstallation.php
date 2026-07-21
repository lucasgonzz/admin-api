<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * Corrida del pipeline de instalación/actualización del ecommerce de un cliente.
 *
 * Registra cada ejecución (instalación desde cero o actualización) del ecommerce,
 * con su estado y sus líneas de log, de forma análoga a ClientInstallation para
 * el sistema principal de empresa.
 *
 * @property int         $id
 * @property string      $uuid
 * @property int         $client_ecommerce_id
 * @property string      $mode              install | update
 * @property string      $status            pendiente | instalando | completada | fallida
 * @property string|null $failure_reason
 * @property \Carbon\Carbon|null $started_at
 * @property \Carbon\Carbon|null $finished_at
 */
class ClientEcommerceInstallation extends Model
{
    use HasUuid;

    /**
     * Permite asignación masiva de todos los campos.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * Conversiones de tipos para campos de fecha.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
    ];

    /**
     * Carga todas las relaciones necesarias para mostrar una corrida completa.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return void
     */
    public function scopeWithAll($query)
    {
        $query->with([
            'client_ecommerce',
            'logs',
        ]);
    }

    /**
     * Tienda (ecommerce) a la que pertenece esta corrida.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function client_ecommerce()
    {
        return $this->belongsTo(ClientEcommerce::class);
    }

    /**
     * Líneas de log de esta corrida, ordenadas por fecha de creación.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function logs()
    {
        return $this->hasMany(EcommerceDeploymentLog::class)->orderBy('created_at');
    }

    /**
     * Crea y persiste una línea de log para esta corrida.
     *
     * Helper usado por los servicios de instalación/deployment (prompts 584/585)
     * para ir registrando el progreso del pipeline paso a paso.
     *
     * @param  string $step  Identificador de la etapa (ej. compile_spa, upload_api).
     * @param  string $line  Contenido de la línea de log.
     * @param  string $level Nivel del log: info | success | error. Por defecto 'info'.
     * @return \App\Models\EcommerceDeploymentLog
     */
    public function add_log($step, $line, $level = 'info')
    {
        return $this->logs()->create([
            'step'  => $step,
            'line'  => $line,
            'level' => $level,
        ]);
    }
}

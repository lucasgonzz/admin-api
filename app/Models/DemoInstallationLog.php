<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una línea de log de una etapa del pipeline de instalación de una demo.
 *
 * Es el equivalente de DeploymentLog para el camino de las demos. Vive en su propia tabla y no en
 * `deployment_logs` para no tocar el camino de producción de los clientes — el porqué largo está
 * en el docblock de la migración `2026_08_31_100300_create_demo_installation_logs_table`.
 *
 * @property int         $id
 * @property int         $demo_installation_id
 * @property string      $step
 * @property string      $line
 * @property string      $level  info | success | error | warning
 */
class DemoInstallationLog extends Model
{
    /**
     * Todos los campos son asignables en masa: las escribe únicamente el service.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * La tabla tiene created_at pero no updated_at: una línea de log no se modifica nunca.
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
     * Corrida de instalación a la que pertenece esta línea.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function demo_installation()
    {
        return $this->belongsTo(DemoInstallation::class);
    }
}

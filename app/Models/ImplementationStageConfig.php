<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Configuración maestra de una etapa del proceso de implementación (catálogo).
 *
 * @property int         $stage_number          Número de etapa (1–7).
 * @property string      $name                  Nombre corto de la etapa.
 * @property string|null $description           Descripción operativa.
 * @property float       $alert_threshold_hours Umbral de alerta en horas.
 * @property bool        $is_automated          Si la etapa la ejecuta el sistema solo.
 * @property bool        $active                Si la etapa está habilitada.
 */
class ImplementationStageConfig extends Model
{
    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'alert_threshold_hours' => 'decimal:2',
        'is_automated'          => 'boolean',
        'active'                => 'boolean',
    ];
}

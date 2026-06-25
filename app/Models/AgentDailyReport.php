<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Registro de un reporte diario o semanal generado por el agente analizador.
 * Almacena la ruta al archivo markdown, el resumen ejecutivo y métricas clave.
 */
class AgentDailyReport extends Model
{
    /**
     * Todos los campos son asignables masivamente.
     *
     * @var array
     */
    protected $guarded = [];

    /**
     * Conversiones de tipo para atributos del modelo.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'report_date'        => 'date',
        'metrics_snapshot'   => 'array',
        'alert_count'        => 'integer',
        'active_leads_count' => 'integer',
    ];

    /**
     * Propuestas generadas a partir de este reporte.
     *
     * @return HasMany
     */
    public function proposals(): HasMany
    {
        return $this->hasMany(AgentProposal::class, 'report_id');
    }

    /**
     * Genera la URL de descarga del archivo markdown de este reporte.
     *
     * @return string URL nombrada de la ruta agent.report.download.
     */
    public function download_url(): string
    {
        return route('agent.report.download', ['id' => $this->id]);
    }
}

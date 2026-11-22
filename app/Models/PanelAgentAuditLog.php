<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Fila de auditoría de un pedido de un agente al panel (misión admin-control-total).
 *
 * Se escribe desde `PanelAgentAuditLogger`; el payload y el extracto de respuesta llegan ya redactados.
 */
class PanelAgentAuditLog extends Model
{
    /**
     * Tabla de auditoría del agente.
     *
     * @var string
     */
    protected $table = 'panel_agent_audit_logs';

    /**
     * Todo es asignable: sólo lo escribe el servicio de auditoría.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * Casts de tipos.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'braked'      => 'boolean',
        'confirmed'   => 'boolean',
        'dry_run'     => 'boolean',
        'status_code' => 'integer',
        'duration_ms' => 'integer',
    ];

    /**
     * Scope estándar de los modelos del proyecto (queda vacío: no hay relaciones que cargar).
     *
     * @param  \Illuminate\Database\Eloquent\Builder $query
     * @return void
     */
    public function scopeWithAll($query) {}
}

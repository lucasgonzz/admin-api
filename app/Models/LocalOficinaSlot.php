<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un slot del pool de la PC de Lucas, tal como lo vio el reporter local en su último minuto (misión
 * el-local, 3/11/2026). Lo escribe {@see \App\Services\Local\OficinaIngestService} y lo lee la escena de
 * «El local» ({@see \App\Services\Local\EscenaService}) para dibujar las mesas de las oficinas.
 *
 * Es un espejo: una fila por `slot`, reescrita en cada reporte.
 *
 * @property int         $slot
 * @property string|null $proyecto
 * @property string      $estado    libre | trabajando | colgado | huerfano
 * @property string|null $rama
 * @property string|null $mision
 * @property string|null $fase      tomado | plan | construccion | revision | tests | merge | informe | novedad | cierre
 * @property int         $sucios
 * @property array|null  $agentes   [{rol, runtime, estado, desde_at, nombre}]
 */
class LocalOficinaSlot extends Model
{
    /** Estados de un slot (contrato A). */
    const ESTADOS = ['libre', 'trabajando', 'colgado', 'huerfano'];

    /** Fases de una misión (contrato A). */
    const FASES = ['tomado', 'plan', 'construccion', 'revision', 'tests', 'merge', 'informe', 'novedad', 'cierre'];

    /** Roles de un agente (contrato A). */
    const ROLES = ['orquestador', 'planificador', 'constructor', 'revisor', 'verificador', 'redactor', 'otro'];

    /** De qué está hecho el agente (contrato A). */
    const RUNTIMES = ['claude', 'codex'];

    /** Estado de un agente (contrato A). */
    const ESTADOS_DE_AGENTE = ['busy', 'activo', 'idle'];

    /**
     * @var string
     */
    protected $table = 'local_oficina_slots';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'slot',
        'proyecto',
        'estado',
        'rama',
        'mision',
        'fase',
        'claim_iniciado_at',
        'ultima_actividad_at',
        'sucios',
        'agentes',
        'reportado_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'slot'                => 'integer',
        'sucios'              => 'integer',
        'agentes'             => 'array',
        'claim_iniciado_at'   => 'datetime',
        'ultima_actividad_at' => 'datetime',
        'reportado_at'        => 'datetime',
    ];
}

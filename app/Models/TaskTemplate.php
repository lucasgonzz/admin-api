<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Plantilla de tarea automática del panel administrativo.
 *
 * Define una tarea predefinida que se crea automáticamente cuando
 * se dispara un proceso interno (p. ej. 'lead_a_cliente').
 * La plantilla describe qué Admin recibirá la tarea, el contenido,
 * las subtareas y el orden de creación.
 *
 * @property string      $proceso     Identificador del proceso que dispara la plantilla.
 * @property string      $titulo      Título de la tarea que se generará.
 * @property string|null $descripcion Contenido descriptivo de la tarea.
 * @property array|null  $checklist   Array de strings con los ítems del checklist.
 * @property int|null    $assigned_admin_id ID del admin asignado (preferido).
 * @property string|null $asignado_a        Nombre legacy; se usa solo si no hay assigned_admin_id.
 * @property int         $prioridad   Nivel de prioridad informativo.
 * @property int         $orden       Posición relativa dentro del proceso.
 * @property bool        $activa      Indica si la plantilla se usa al disparar el proceso.
 */
class TaskTemplate extends Model
{
    protected $table = 'task_templates';

    protected $guarded = [];

    protected $casts = [
        // checklist se maneja como array PHP directamente.
        'checklist'           => 'array',
        'activa'              => 'boolean',
        'prioridad'           => 'integer',
        'orden'               => 'integer',
        'assigned_admin_id'   => 'integer',
    ];

    /**
     * Admin asignado para las tareas que genera esta plantilla.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function assigned_admin()
    {
        return $this->belongsTo(Admin::class, 'assigned_admin_id');
    }

    /**
     * Scope que filtra solo las plantillas activas de un proceso,
     * ordenadas por campo orden ascendente.
     *
     * @param  \Illuminate\Database\Eloquent\Builder $query
     * @param  string                                $proceso Identificador del proceso.
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActiveForProcess($query, string $proceso)
    {
        return $query
            ->where('proceso', $proceso)
            ->where('activa', true)
            ->orderBy('orden');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un pedido de reset de datos de negocio de un cliente (misión cruzada `reset-datos-cliente`, 21/11/2026): quién lo pidió, desde
 * dónde, si fue simulación o ejecución, y qué contestó el `empresa-api` del cliente.
 *
 * 🔴 No guarda la `clients.api_key` ni el token de simulación; `resumen` lleva sólo conteos y datos del respaldo.
 *
 * @property int         $client_id
 * @property int|null    $client_api_id
 * @property int|null    $admin_id
 * @property string      $origen        `panel` o `claude`.
 * @property string      $solicitante
 * @property string|null $motivo
 * @property bool        $dry_run
 * @property string      $estado        `ok`, `ok_con_advertencias`, `rechazado`, `error` o `sin_respuesta`.
 * @property string|null $codigo
 * @property int|null    $http_status
 * @property array|null  $resumen
 */
class ClientDataReset extends Model
{
    /** El pedido vino del panel (admin-spa). */
    const ORIGEN_PANEL = 'panel';

    /** El pedido vino de un agente por `claude/`. */
    const ORIGEN_CLAUDE = 'claude';

    /** Salió bien. */
    const ESTADO_OK = 'ok';

    /** Salió bien pero con advertencias (archivos que no se pudieron mover, filas que se colaron). */
    const ESTADO_OK_CON_ADVERTENCIAS = 'ok_con_advertencias';

    /** El cliente (o el admin antes de llamar) frenó el pedido por una regla: nada se borró. */
    const ESTADO_RECHAZADO = 'rechazado';

    /** Falla del cliente o respuesta que no es el contrato. */
    const ESTADO_ERROR = 'error';

    /** El admin no recibió respuesta (timeout o conexión): hay que mirar los registros del cliente antes de reintentar. */
    const ESTADO_SIN_RESPUESTA = 'sin_respuesta';

    protected $table = 'client_data_resets';

    protected $guarded = [];

    protected $casts = [
        'client_id'     => 'integer',
        'client_api_id' => 'integer',
        'admin_id'      => 'integer',
        'dry_run'       => 'boolean',
        'http_status'   => 'integer',
        'resumen'       => 'array',
    ];

    /**
     * Scope estándar del repo para los modelos que se exponen; este no necesita relaciones.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query Consulta.
     *
     * @return void
     */
    public function scopeWithAll($query)
    {
    }
}

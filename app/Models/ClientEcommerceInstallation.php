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
 * @property string|null $created_via       claude | NULL (panel del admin, y todo lo anterior a la columna)
 * @property string|null $failure_reason
 * @property \Carbon\Carbon|null $started_at
 * @property \Carbon\Carbon|null $finished_at
 */
class ClientEcommerceInstallation extends Model
{
    use HasUuid;

    /**
     * Valor de `created_via` para las corridas disparadas por Claude
     * (POST claude/ecommerce/updates y POST claude/ecommerce/updates/batch).
     *
     * La columna es nullable y sin default: NULL = origen no marcado (los tres botones del panel
     * en EcommerceInstallationController, y todo lo anterior a la migración 2026_08_28_120000).
     *
     * 🔴 No es sólo trazabilidad: es lo que le permite al lote distinguir sus propias corridas de
     * las del panel para aplicar el cooldown. Ver el docblock de la migración.
     */
    const CREATED_VIA_CLAUDE = 'claude';

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
        /* `client_ecommerce.demo` viaja anidado desde el 31/8/2026, cuando una tienda pasó a poder
         * pertenecer a un Client O a una Demo: sin eso, la grilla de demos recibe la corrida sin
         * nada con qué nombrar de quién es.
         *
         * 🔴 Y `client_ecommerce.client` NO se carga, aunque parezca la contraparte simétrica. La
         * grilla de clientes no la necesita —resuelve el nombre contra su propio `clients_by_id`
         * usando `client_ecommerce.client_id`—, y cargarla sale caro: `Client` tiene cuatro
         * `$appends` (ecommerce_spa_url, ecommerce_api_url, ecommerce_spa_path, ecommerce_api_path)
         * que resuelven contra `$this->client_ecommerce`, que en esa ruta anidada no queda
         * eager-cargada. O sea una consulta extra por fila al serializar, más un Client entero por
         * corrida en el payload — el mismo N+1 que ClaudeClientOpsController documenta en su
         * cabecera, metido en una pantalla del camino de producción a cambio de nada. */
        $query->with([
            'client_ecommerce.demo',
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

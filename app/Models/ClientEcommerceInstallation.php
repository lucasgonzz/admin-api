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
 * @property int|null    $ecommerce_version_id Versión de ecommerce que despliega esta corrida (la
 *                                             pedida, o la última publicada que resolvió el pipeline
 *                                             al arrancar). Null = vía vieja (master en el VPS).
 * @property bool        $ecommerce_version_requested True si la versión la PIDIÓ quien creó la
 *                                             corrida; false si es la última publicada resuelta sola.
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
        'started_at'                  => 'datetime',
        'finished_at'                 => 'datetime',
        // Si la versión la pidió quien creó la corrida (ver la migración 2026_10_02_100000).
        'ecommerce_version_requested' => 'boolean',
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
            /* La versión que la tienda tiene instalada HOY y la que despliega esta corrida (misión
             * `versiones-tienda`, 1/10/2026): las dos son un belongsTo a una tabla de decenas de
             * filas, así que viajan en la misma consulta agregada y el panel no pregunta de a una. */
            'client_ecommerce.ecommerce_version',
            'ecommerce_version',
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
     * Versión de ecommerce que despliega esta corrida (misión `versiones-tienda`, 1/10/2026).
     *
     * Null en las corridas anteriores a la misión y en las que fueron por la vía vieja (compilando
     * la última de `master` en el VPS de builds, sólo con `DEPLOY_PERMITIR_BUILD_EN_VPS=true`).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function ecommerce_version()
    {
        return $this->belongsTo(EcommerceVersion::class);
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

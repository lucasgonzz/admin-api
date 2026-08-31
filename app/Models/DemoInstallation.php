<?php

namespace App\Models;

use App\ModelProperties\DemoInstallationProperties;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * Instalación desde cero del SISTEMA (ERP) de una demo.
 *
 * Es el espejo de ClientInstallation para el catálogo de demos. A diferencia de DemoUpdate —que
 * lleva una demo YA instalada de una versión a otra— este recurso arranca de un subdominio y una
 * base vacíos (los crea Lucas a mano en hPanel) y deja la demo booteando y con datos: sube
 * public/, el SPA compilado y la API, escribe el .env, corre los artisan de cierre, dispara el
 * demo-setup y verifica por HTTP que la demo responda.
 *
 * @property int         $id
 * @property string      $uuid
 * @property int         $demo_id
 * @property int|null    $version_id
 * @property int|null    $created_by_admin_id
 * @property string      $status            pendiente | instalando | completada | fallida
 * @property array|null  $env_manual_values Valores de las variables is_manual_on_create
 * @property string|null $failure_reason
 * @property \Carbon\Carbon|null $started_at
 * @property \Carbon\Carbon|null $finished_at
 */
class DemoInstallation extends Model
{
    use HasUuid;

    /**
     * Los cuatro estados posibles de una corrida.
     *
     * Constantes de clase y no un enum de PHP: admin-api corre PHP 7.4 en producción.
     *
     * @var string
     */
    const STATUS_PENDIENTE  = 'pendiente';
    const STATUS_INSTALANDO = 'instalando';
    const STATUS_COMPLETADA = 'completada';
    const STATUS_FALLIDA    = 'fallida';

    /**
     * @var array<int, string>
     */
    const STATUSES = [
        self::STATUS_PENDIENTE,
        self::STATUS_INSTALANDO,
        self::STATUS_COMPLETADA,
        self::STATUS_FALLIDA,
    ];

    /**
     * Definición declarativa del recurso, consumida por admin-spa/meta.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function properties()
    {
        return DemoInstallationProperties::all();
    }

    /**
     * Sin guarded: todos los campos son asignables en masa.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * Valor por defecto de status en toda instancia nueva.
     *
     * 🔴 Repite el default de la migración a propósito, por el mismo motivo que
     * ClientInstallation::$attributes: sin esto, `DemoInstallation::create()` devuelve un modelo
     * SIN el atributo (Eloquent no relee lo que puso la base) y la respuesta 201 de store_json()
     * sale sin la clave, así que la grilla no tiene con qué pintar el estado de la fila que acaba
     * de crear hasta que el operador recarga.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => self::STATUS_PENDIENTE,
    ];

    /**
     * Conversiones de tipo para fechas y para el JSON de valores manuales.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'started_at'        => 'datetime',
        'finished_at'       => 'datetime',
        'env_manual_values' => 'array',
    ];

    /**
     * Demo a la que se le instala el sistema.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function demo()
    {
        return $this->belongsTo(Demo::class);
    }

    /**
     * Versión que se instala.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function version()
    {
        return $this->belongsTo(Version::class);
    }

    /**
     * Admin que disparó la instalación (nullable: puede haber sido automática).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function created_by_admin()
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }

    /**
     * Líneas de log del pipeline, en el orden en que se escribieron.
     *
     * Se ordena por `id` y no por `created_at`: la columna de fecha tiene precisión de un segundo
     * y una etapa escribe varias líneas dentro del mismo, así que ordenar por fecha las mezcla.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function logs()
    {
        return $this->hasMany(DemoInstallationLog::class)->orderBy('id');
    }

    /**
     * Carga todo lo que el panel necesita para mostrar una corrida completa.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return void
     */
    public function scopeWithAll($query)
    {
        $query->with([
            'demo',
            'version',
            'created_by_admin',
            'logs',
        ]);
    }
}

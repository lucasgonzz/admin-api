<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Implementación de Agentes de IA de un cliente: conectar su número de WhatsApp a la extensión
 * `agentes_ia` de su sistema (misión `implementacion-agentes-ia`, 28/10/2026).
 *
 * 🔴 POR QUÉ EXISTE. El proceso vivía en `herramientas/implementar-agentes/README.md` y en la cabeza de
 * quien lo llevaba: nada en el admin decía en qué etapa estaba cada cliente, qué se le había mandado ni
 * qué faltaba. Con este modelo cada etapa queda registrada (por `claude/agent-implementations/*`) y Martín
 * la ve en el contexto del ticket del cliente, como ve la del sistema y la de la tienda.
 *
 * Puede haber varias por cliente a lo largo del tiempo (una cancelada y otra nueva), pero UNA SOLA
 * abierta (`in_progress`) a la vez: lo controla el alta (409 `ya_tiene_una_abierta`), no la base, porque
 * el repo no usa unique compuestos.
 *
 * @property int              $id
 * @property int              $client_id             Cliente dueño del número.
 * @property string           $status                in_progress | completed | cancelled.
 * @property string|null      $camino                nuevo | propio.
 * @property int              $current_stage         Primera etapa que no está completed ni skipped (8 si están todas).
 * @property string|null      $kapso_customer_id     Cliente de Kapso.
 * @property string|null      $phone_number_id       Id del número en Meta (que exista = conectado).
 * @property string|null      $waba_id               Cuenta de WhatsApp Business.
 * @property string|null      $business_portfolio_id Portfolio comercial de Meta.
 * @property \Carbon\Carbon|null $started_at
 * @property \Carbon\Carbon|null $completed_at       Cierre (completed o cancelled).
 */
class AgentImplementation extends Model
{
    /** Estado de la implementación en curso (la única que cuenta como «abierta»). */
    const STATUS_IN_PROGRESS = 'in_progress';

    /** Implementación terminada: el agente quedó en marcha. */
    const STATUS_COMPLETED = 'completed';

    /** Implementación abandonada (el cliente no siguió, se reinició con otro camino...). */
    const STATUS_CANCELLED = 'cancelled';

    /** Los estados posibles de la implementación. */
    const STATUSES = [self::STATUS_IN_PROGRESS, self::STATUS_COMPLETED, self::STATUS_CANCELLED];

    /** Camino con una línea nueva (sin historial de chats que leer). */
    const CAMINO_NUEVO = 'nuevo';

    /** Camino con el número que el negocio ya usa en WhatsApp. */
    const CAMINO_PROPIO = 'propio';

    /** Los caminos posibles. */
    const CAMINOS = [self::CAMINO_NUEVO, self::CAMINO_PROPIO];

    /** Primera etapa. */
    const FIRST_STAGE = 1;

    /** Última etapa (En marcha). */
    const LAST_STAGE = 8;

    /**
     * Las ocho etapas: nombre y las claves que se registran en `data` de cada una (todas opcionales; la
     * lista es la guía de qué anotar, no una validación: `data` acepta otras claves).
     *
     * Es la misma tabla del plan de la misión y de `herramientas/implementar-agentes/README.md`. Si se
     * cambia acá, se cambia allá (la skill `/implementar-agentes` y el protocolo de Martín la leen).
     *
     * @var array<int, array{nombre: string, registra: array<int, string>}>
     */
    const STAGES = [
        1 => [
            'nombre'   => 'Preparación',
            'registra' => ['camino', 'web_https', 'plan_kapso_ok', 'version_ok', 'extension_ok', 'guia_enviada'],
        ],
        2 => [
            'nombre'   => 'Entrenamiento',
            'registra' => ['whatsapp_web_vinculado', 'chats_leidos', 'borrador_enviado', 'revisado_por_el_duenio', 'sesion_cerrada'],
        ],
        3 => [
            'nombre'   => 'Conexión con Meta',
            'registra' => ['link_enviado', 'chats_exportados', 'cuenta_eliminada', 'conectado'],
        ],
        4 => [
            'nombre'   => 'Conexión al sistema',
            'registra' => ['salud_ok', 'webhook_ok', 'conexion_cargada', 'plantillas_sembradas'],
        ],
        5 => [
            'nombre'   => 'Plantillas',
            'registra' => ['creadas', 'aprobadas'],
        ],
        6 => [
            'nombre'   => 'Agente y pruebas',
            'registra' => ['embeddings', 'textos_cargados', 'simulacion_con_el_duenio', 'prueba_real'],
        ],
        7 => [
            'nombre'   => 'Pago y verificación',
            'registra' => ['tarjeta_cargada', 'papeles_recibidos', 'verificacion_presentada', 'verificacion_aprobada'],
        ],
        8 => [
            'nombre'   => 'En marcha',
            'registra' => ['con_aprobacion', 'responde_solo'],
        ],
    ];

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'client_id',
        'status',
        'camino',
        'current_stage',
        'kapso_customer_id',
        'phone_number_id',
        'waba_id',
        'business_portfolio_id',
        'started_at',
        'completed_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'client_id'     => 'integer',
        'current_stage' => 'integer',
        'started_at'    => 'datetime',
        'completed_at'  => 'datetime',
    ];

    /**
     * Eager load de lo que se muestra siempre: el cliente, las etapas y los eventos.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithAll($query)
    {
        return $query->with(['client', 'stages', 'events']);
    }

    /**
     * Cliente dueño del número.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Las etapas, en orden.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function stages()
    {
        return $this->hasMany(AgentImplementationStage::class)->orderBy('stage_number', 'asc');
    }

    /**
     * La bitácora, más nuevos primero (es lo que se muestra: «lo último que pasó»).
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function events()
    {
        return $this->hasMany(AgentImplementationEvent::class)->orderBy('id', 'desc');
    }

    /**
     * ¿Está abierta? Solo `in_progress` (mismo criterio que la del sistema y la de la tienda).
     *
     * @return bool
     */
    public function is_open()
    {
        return (string) $this->status === self::STATUS_IN_PROGRESS;
    }

    /**
     * El nombre de una etapa, o «Etapa N» si el número no está en la tabla.
     *
     * @param int $stage_number Número de etapa.
     *
     * @return string
     */
    public static function stage_name($stage_number)
    {
        $stage_number = (int) $stage_number;

        return isset(self::STAGES[$stage_number]) ? self::STAGES[$stage_number]['nombre'] : ('Etapa ' . $stage_number);
    }

    /**
     * Recalcula `current_stage` a partir de las etapas cargadas: la primera que no está `completed` ni
     * `skipped`; si están todas cerradas, la última (8). No guarda: devuelve el número.
     *
     * 🔴 Se calcula y no se avanza «de a uno» a propósito: las etapas se pueden registrar fuera de orden
     * (el pago de Meta se puede adelantar, el entrenamiento se saltea en el camino `nuevo`), y un contador
     * que solo sube quedaría apuntando a una etapa que no es la que falta.
     *
     * @param iterable<AgentImplementationStage> $stages Las etapas de la implementación.
     *
     * @return int
     */
    public static function compute_current_stage($stages)
    {
        $closed = [];

        foreach ($stages as $stage) {
            if (in_array((string) $stage->status, [AgentImplementationStage::STATUS_COMPLETED, AgentImplementationStage::STATUS_SKIPPED], true)) {
                $closed[(int) $stage->stage_number] = true;
            }
        }

        for ($number = self::FIRST_STAGE; $number <= self::LAST_STAGE; $number++) {
            if (! isset($closed[$number])) {
                return $number;
            }
        }

        return self::LAST_STAGE;
    }
}

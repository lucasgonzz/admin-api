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
 * @property int              $current_stage         Etapa más alta iniciada (en curso o completa; la 7 no cuenta); ver compute_current_stage().
 * @property string|null      $kapso_customer_id     Cliente de Kapso.
 * @property string|null      $phone_number_id       Id del número en Meta (que exista = conectado).
 * @property string|null      $waba_id               Cuenta de WhatsApp Business.
 * @property string|null      $business_portfolio_id Portfolio comercial de Meta.
 * @property string|null      $contact_phone         Teléfono al que se le escribe al dueño si no es el de la ficha
 *                                                   (camino `propio`, después de eliminar su cuenta). Dato personal:
 *                                                   no viaja a Martín ni a la bitácora.
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

    /** Etapas que se pueden adelantar y por eso no cuentan para saber hasta dónde llegó la implementación (7 = Pago y verificación). */
    const ADELANTABLES = [7];

    /** Primera etapa. */
    const FIRST_STAGE = 1;

    /** Última etapa (En marcha). */
    const LAST_STAGE = 8;

    const PAQUETE_FULL = 'full';
    const PAQUETE_LITE = 'lite';
    const PAQUETES = [self::PAQUETE_FULL, self::PAQUETE_LITE];
    const FLUJO_ENTREGA_PERSONAL = 2;

    /** Las tres entregas comunes; su evidencia se registra en data de Preparación, sin renumerar el historial. */
    const ENTREGA_PERSONAL = [
        'whatsapp' => [
            'nombre' => 'Configurar y probar el WhatsApp del dueño',
            'hitos' => [
                'version_ok' => 'Sistema actualizado',
                'extension_ok' => 'Agentes de IA activos',
                'numero_personal_solicitado' => 'Número personal solicitado',
                'numero_personal_cargado' => 'Número personal configurado',
                'asistente_whatsapp_activo' => 'Asistente por WhatsApp activo',
                'agente_personal_probado' => 'Respuesta probada desde el WhatsApp del dueño',
            ],
        ],
        'onboarding' => [
            'nombre' => 'Acceso y contexto del negocio',
            'hitos' => [
                'app_link_enviado' => 'Acceso a ia.comerciocity.com enviado',
                'acceso_sistema_verificado' => 'Asistente personal disponible en el sistema',
                'onboarding_completado' => 'Contexto del negocio contado por el dueño (app o WhatsApp)',
            ],
        ],
        'guia' => [
            'nombre' => 'Entregar la guía de los agentes',
            'hitos' => [
                'pdf_agentes_enviado' => 'PDF con capacidades y ejemplos enviado',
            ],
        ],
    ];

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
            'registra' => ['camino', 'web_https', 'plan_kapso_ok', 'version_ok', 'extension_ok', 'guia_enviada', 'numero_personal_solicitado', 'numero_personal_cargado', 'asistente_whatsapp_activo', 'agente_personal_probado', 'app_link_enviado', 'onboarding_completado', 'acceso_sistema_verificado', 'pdf_agentes_enviado', 'pdf_agentes_url'],
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
        'paquete',
        'flujo_version',
        'status',
        'camino',
        'current_stage',
        'kapso_customer_id',
        'phone_number_id',
        'waba_id',
        'business_portfolio_id',
        'contact_phone',
        'started_at',
        'completed_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'client_id'     => 'integer',
        'flujo_version' => 'integer',
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
     * Recalcula `current_stage` a partir de las etapas cargadas, midiendo el AVANCE REAL. No guarda:
     * devuelve el número.
     *
     * La regla (misión agenda-openclaw-empuje, 25/11/2026):
     *  1. `H` es la etapa MÁS ALTA que está `in_progress` o `completed`, sin contar las
     *     {@see ADELANTABLES} (el pago de Meta se puede adelantar y no dice hasta dónde se llegó).
     *  2. Sin `H` (nada iniciado) rige la regla vieja: la primera que no está `completed` ni `skipped`
     *     (8 si están todas cerradas).
     *  3. Si `H` está en curso, la actual es `H`.
     *  4. Si `H` está completa, la actual es la primera etapa por encima de `H` que no está `completed`
     *     ni `skipped` (la 7 incluida); si no hay ninguna, 8.
     *
     * 🔴 Antes era solo la regla 2 y daba mal en las altas históricas (`flujo_version = 1`): nadie
     * cierra la Preparación (sus hitos v2 quedan abiertos), así que Pablo, que iba por la 6, figuraba en
     * la 1. Clase del error: un estado derivado que mira el primer hueco y no el avance real. No lo
     * «simplifiques» de vuelta a «la primera abierta».
     *
     * Se calcula y no se avanza «de a uno» a propósito: las etapas se pueden registrar fuera de orden
     * (el pago de Meta se puede adelantar, el entrenamiento se saltea en el camino `nuevo`), y un contador
     * que solo sube quedaría apuntando a una etapa que no es la que falta.
     *
     * @param iterable<AgentImplementationStage> $stages Las etapas de la implementación.
     *
     * @return int
     */
    public static function compute_current_stage($stages)
    {
        // Estado de cada etapa por número.
        $status_by_number = [];

        foreach ($stages as $stage) {
            $status_by_number[(int) $stage->stage_number] = (string) $stage->status;
        }

        // Etapa más alta iniciada (en curso o completa), sin contar las adelantables.
        $highest = null;

        foreach ($status_by_number as $number => $status) {
            if (in_array($number, self::ADELANTABLES, true)) {
                continue;
            }

            if (in_array($status, [AgentImplementationStage::STATUS_IN_PROGRESS, AgentImplementationStage::STATUS_COMPLETED], true)
                && ($highest === null || $number > $highest)) {
                $highest = $number;
            }
        }

        // Nada iniciado: la primera que no está cerrada (regla de siempre).
        if ($highest === null) {
            return self::first_open_stage($status_by_number, self::FIRST_STAGE);
        }

        // La más alta está en curso: la implementación va por ahí.
        if ($status_by_number[$highest] === AgentImplementationStage::STATUS_IN_PROGRESS) {
            return $highest;
        }

        // La más alta está completa: sigue la primera abierta por encima de ella.
        return self::first_open_stage($status_by_number, $highest + 1);
    }

    /**
     * La primera etapa desde `$from` que no está `completed` ni `skipped`; 8 si no queda ninguna abierta.
     *
     * @param array<int, string> $status_by_number Estado de cada etapa por número.
     * @param int                $from             Primera etapa a mirar.
     *
     * @return int
     */
    protected static function first_open_stage(array $status_by_number, $from)
    {
        for ($number = $from; $number <= self::LAST_STAGE; $number++) {
            $status = isset($status_by_number[$number]) ? $status_by_number[$number] : AgentImplementationStage::STATUS_PENDING;

            if (! in_array($status, [AgentImplementationStage::STATUS_COMPLETED, AgentImplementationStage::STATUS_SKIPPED], true)) {
                return $number;
            }
        }

        return self::LAST_STAGE;
    }
}

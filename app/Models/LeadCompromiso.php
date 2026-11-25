<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un compromiso de contacto con un lead: «el viernes lo llama Juan», «el martes le escribe Martín», «el lead avisa el
 * lunes» (misión leads-referidos-compromisos, etapa E2).
 *
 * La lógica vive en {@see \App\Services\LeadCompromisoService}; el modelo sólo guarda los datos y sus constantes.
 *
 * @property int                 $id
 * @property int                 $lead_id
 * @property \Carbon\Carbon      $vence_at                  Cuándo toca (hora de Argentina).
 * @property string              $accion                    `llamar`, `escribir` o `esperar`.
 * @property string              $origen                    De dónde salió ({@see self::ORIGENES}).
 * @property int|null            $origen_id                 Llamada, mensaje o compromiso que lo originó, según el origen.
 * @property string|null         $resumen
 * @property string|null         $cita_del_lead             Lo que dijo el lead, tal cual.
 * @property string              $status                    Uno de {@see self::STATUSES}.
 * @property int|null            $baseline_lead_message_id  Huella: último mensaje del hilo al crearlo.
 * @property int|null            $baseline_llamada_id       Huella: última llamada al crearlo.
 * @property string|null         $estado_lead_al_crear      Huella: estado del lead al crearlo.
 * @property int|null            $reemplazado_por_id
 * @property string|null         $resultado
 * @property int|null            $admin_task_id
 * @property int|null            $lead_message_id
 * @property int                 $intentos
 * @property \Carbon\Carbon|null $procesando_desde
 * @property int|null            $created_by_admin_id
 * @property int|null            $cerrado_por_admin_id
 * @property \Carbon\Carbon|null $ejecutado_at
 */
class LeadCompromiso extends Model
{
    /** Acción: Juan llama al lead (se ejecuta como una tarea). */
    const ACCION_LLAMAR = 'llamar';

    /** Acción: Martín le escribe al lead (se ejecuta como un mensaje sugerido o enviado). */
    const ACCION_ESCRIBIR = 'escribir';

    /** Acción: se espera que el lead avise; si no avisó, Martín lo retoma (se ejecuta como `escribir`). */
    const ACCION_ESPERAR = 'esperar';

    /** Las tres acciones posibles. */
    const ACCIONES = [self::ACCION_LLAMAR, self::ACCION_ESCRIBIR, self::ACCION_ESPERAR];

    /** Origen: la fecha de «Registrar llamada». */
    const ORIGEN_LLAMADA = 'llamada';

    /** Origen: lo que el lead escribió por WhatsApp, detectado por la IA. */
    const ORIGEN_IA_MENSAJE = 'ia_mensaje';

    /** Origen: lo que salió de la transcripción de una llamada grabada (etapa E3). */
    const ORIGEN_TRANSCRIPCION = 'transcripcion';

    /** Origen: lo cargó una persona desde el panel. */
    const ORIGEN_MANUAL = 'manual';

    /** Origen: lo cargó un agente por `claude/*`. */
    const ORIGEN_AGENTE = 'agente';

    /** Origen: la re-evaluación de otro compromiso lo reprogramó acá. */
    const ORIGEN_REEVALUACION = 'reevaluacion';

    /** Los orígenes posibles. */
    const ORIGENES = [
        self::ORIGEN_LLAMADA, self::ORIGEN_IA_MENSAJE, self::ORIGEN_TRANSCRIPCION,
        self::ORIGEN_MANUAL, self::ORIGEN_AGENTE, self::ORIGEN_REEVALUACION,
    ];

    /** Status: espera su hora. Como mucho hay UNO por lead. */
    const STATUS_VIGENTE = 'vigente';

    /** Status: una corrida del comando lo tomó y lo está ejecutando (el candado contra corridas dobles). */
    const STATUS_PROCESANDO = 'procesando';

    /** Status: se ejecutó (tarea para Juan, mensaje de Martín o ambos). */
    const STATUS_EJECUTADO = 'ejecutado';

    /** Status: la re-evaluación lo movió a otra fecha (el nuevo es otro compromiso). */
    const STATUS_REPROGRAMADO = 'reprogramado';

    /** Status: otro compromiso más nuevo lo reemplazó. */
    const STATUS_REEMPLAZADO = 'reemplazado';

    /** Status: hubo contacto antes de su hora (una llamada sin fecha nueva): ya no hace falta. */
    const STATUS_CUMPLIDO = 'cumplido';

    /** Status: una persona o la IA lo canceló. */
    const STATUS_CANCELADO = 'cancelado';

    /** Status: el sistema no pudo decidir solo (IA caída, respuesta rara, reintentos agotados): Juan lo mira. */
    const STATUS_REVISAR = 'revisar';

    /** Todos los status. */
    const STATUSES = [
        self::STATUS_VIGENTE, self::STATUS_PROCESANDO, self::STATUS_EJECUTADO, self::STATUS_REPROGRAMADO,
        self::STATUS_REEMPLAZADO, self::STATUS_CUMPLIDO, self::STATUS_CANCELADO, self::STATUS_REVISAR,
    ];

    /**
     * Los status de un compromiso «abierto»: el lead tiene un contacto acordado pendiente. Es lo que suspende los
     * seguimientos genéricos y lo que Martín ve en su contexto.
     */
    const STATUSES_ABIERTOS = [self::STATUS_VIGENTE, self::STATUS_PROCESANDO];

    /**
     * @var string
     */
    protected $table = 'lead_compromisos';

    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'lead_id'                  => 'integer',
        'vence_at'                 => 'datetime',
        'origen_id'                => 'integer',
        'baseline_lead_message_id' => 'integer',
        'baseline_llamada_id'      => 'integer',
        'reemplazado_por_id'       => 'integer',
        'admin_task_id'            => 'integer',
        'lead_message_id'          => 'integer',
        'intentos'                 => 'integer',
        'procesando_desde'         => 'datetime',
        'created_by_admin_id'      => 'integer',
        'cerrado_por_admin_id'     => 'integer',
        'ejecutado_at'             => 'datetime',
    ];

    /**
     * Fechas serializadas como hora local `Y-m-d H:i:s` (nunca ISO con `Z`: es la trampa de las tres horas).
     *
     * @param \DateTimeInterface $date
     *
     * @return string
     */
    protected function serializeDate(\DateTimeInterface $date)
    {
        return $date->format('Y-m-d H:i:s');
    }

    /**
     * Scope requerido por convención del workspace (`Controller::fullModel()`); este modelo no expone relaciones.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return void
     */
    public function scopeWithAll($query)
    {
    }

    /**
     * El lead al que se le debe el contacto.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function lead()
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }

    /**
     * Los compromisos todavía abiertos (vigente o procesando).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeAbiertos($query)
    {
        return $query->whereIn('status', self::STATUSES_ABIERTOS);
    }
}

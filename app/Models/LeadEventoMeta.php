<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una conversión de un lead de anuncio que se le manda (o se le mandó) a Meta por la Conversions
 * API for Business Messaging (misión origen-del-lead-y-conversiones-meta, 13/10/2026).
 *
 * Una fila por (lead, evento). El esquema y el porqué de cada columna están en la migración
 * `2026_10_13_120100_create_lead_eventos_meta_table`; la lógica, en `MetaConversionesService`.
 */
class LeadEventoMeta extends Model
{
    /** El lead llegó a calificado o a cualquier etapa posterior. */
    const EVENTO_LEAD_SUBMITTED = 'LeadSubmitted';

    /** El lead agendó la demo o llegó a cualquier etapa posterior (Meta no tiene «demo agendada»). */
    const EVENTO_QUALIFIED_LEAD = 'QualifiedLead';

    /** El lead se promovió a cliente. */
    const EVENTO_PURCHASE = 'Purchase';

    /** Los tres, en el orden en que se alcanzan y se mandan. */
    const EVENTOS = [
        self::EVENTO_LEAD_SUBMITTED,
        self::EVENTO_QUALIFIED_LEAD,
        self::EVENTO_PURCHASE,
    ];

    const ESTADO_PENDIENTE = 'pendiente';
    const ESTADO_ENVIANDO = 'enviando';
    const ESTADO_ENVIADO = 'enviado';
    const ESTADO_ERROR = 'error';
    const ESTADO_SIN_CONFIGURAR = 'sin_configurar';

    /**
     * Etapa que el lead alcanzó ANTES de que existiera la medición (la registra el backfill del
     * origen al cargarle el `ctwa_clid` a un lead viejo). No se manda nunca: el reclamo del envío no
     * acepta este estado, y el conciliador no lo toca.
     */
    const ESTADO_OMITIDO = 'omitido';

    /** Los seis estados válidos. */
    const ESTADOS = [
        self::ESTADO_PENDIENTE,
        self::ESTADO_ENVIANDO,
        self::ESTADO_ENVIADO,
        self::ESTADO_ERROR,
        self::ESTADO_SIN_CONFIGURAR,
        self::ESTADO_OMITIDO,
    ];

    /** El porqué que queda escrito en `ultimo_error` de una fila `omitido`. */
    const MOTIVO_OMITIDO = 'Alcanzado antes de que existiera la medición; no se manda.';

    /** Tope de intentos de envío (el primero más dos reintentos del conciliador). */
    const MAX_INTENTOS = 3;

    /** Un `enviando` más viejo que esto es un proceso que murió sin soltar la fila. */
    const MINUTOS_PARA_DAR_POR_COLGADO = 10;

    /** Meta no acepta eventos con `event_time` de más de siete días: más viejo, no se reintenta. */
    const DIAS_DE_VIGENCIA = 7;

    /**
     * @var string
     */
    protected $table = 'lead_eventos_meta';

    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'lead_id'    => 'integer',
        'intentos'   => 'integer',
        'event_time' => 'datetime',
        'enviado_at' => 'datetime',
        'respuesta'  => 'array',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }
}

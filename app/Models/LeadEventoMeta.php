<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una conversión de un lead que se le manda (o se le mandó) a Meta por la Conversions API (misión
 * origen-del-lead-y-conversiones-meta, 13/10/2026), por uno de dos canales:
 *
 *   - `whatsapp`: los leads de anuncio Click-to-WhatsApp, por la CAPI for Business Messaging, al
 *     conjunto de datos de la WABA (`LeadSubmitted`, `QualifiedLead`, `Purchase`).
 *   - `web`: los leads que vinieron de la web (segunda tanda, 13/10/2026), al conjunto de datos del
 *     píxel (`Lead`, `Schedule`, `Purchase`, eventos estándar).
 *
 * Una fila por (lead, canal, evento). El esquema y el porqué de cada columna están en las
 * migraciones `2026_10_13_120100_create_lead_eventos_meta_table` y
 * `2026_10_13_130000_add_canal_to_lead_eventos_meta_table`; la lógica, en `MetaConversionesService`.
 */
class LeadEventoMeta extends Model
{
    /** Canal de los leads de anuncio Click-to-WhatsApp (CAPI for Business Messaging). */
    const CANAL_WHATSAPP = 'whatsapp';

    /** Canal de los leads que vinieron de la web (conjunto de datos del píxel). */
    const CANAL_WEB = 'web';

    /** Los dos canales. */
    const CANALES = [
        self::CANAL_WHATSAPP,
        self::CANAL_WEB,
    ];

    /** El lead llegó a calificado o a cualquier etapa posterior. */
    const EVENTO_LEAD_SUBMITTED = 'LeadSubmitted';

    /** El lead agendó la demo o llegó a cualquier etapa posterior (Meta no tiene «demo agendada»). */
    const EVENTO_QUALIFIED_LEAD = 'QualifiedLead';

    /** El lead se promovió a cliente. */
    const EVENTO_PURCHASE = 'Purchase';

    /** Los tres del canal `whatsapp`, en el orden en que se alcanzan y se mandan. */
    const EVENTOS = [
        self::EVENTO_LEAD_SUBMITTED,
        self::EVENTO_QUALIFIED_LEAD,
        self::EVENTO_PURCHASE,
    ];

    /** Canal `web`: el lead llegó a calificado o a cualquier etapa posterior (evento estándar `Lead`). */
    const EVENTO_WEB_LEAD = 'Lead';

    /** Canal `web`: el lead agendó la demo o llegó a cualquier etapa posterior (evento estándar `Schedule`). */
    const EVENTO_WEB_SCHEDULE = 'Schedule';

    /** Los tres del canal `web`, en el mismo orden. `Purchase` se llama igual en los dos canales. */
    const EVENTOS_WEB = [
        self::EVENTO_WEB_LEAD,
        self::EVENTO_WEB_SCHEDULE,
        self::EVENTO_PURCHASE,
    ];

    const ESTADO_PENDIENTE = 'pendiente';
    const ESTADO_ENVIANDO = 'enviando';
    const ESTADO_ENVIADO = 'enviado';
    const ESTADO_ERROR = 'error';
    const ESTADO_SIN_CONFIGURAR = 'sin_configurar';

    /**
     * Etapa que el lead alcanzó ANTES de que existiera la medición (la registra el backfill del
     * origen al cargarle el `ctwa_clid` a un lead viejo, o al dejarlo como `landing`). No se manda
     * nunca: el reclamo del envío no acepta este estado, y el conciliador no lo toca.
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

    /**
     * El conciliador vuelve a encolar un `pendiente` o un `error` solo si su `updated_at` tiene más
     * que esto: un `pendiente` más viejo es un job que se perdió, y con un `error` más nuevo,
     * reencolar en cada corrida quemaría los tres intentos en ráfaga si Meta está caído.
     */
    const MINUTOS_ANTES_DE_REENCOLAR = 15;

    /**
     * Ventana de reenvío del conciliador. Meta no acepta eventos con `event_time` de más de siete
     * días; se usan seis para que un evento que sale tarde por la cola no caiga afuera de lo que
     * Meta acepta. Más viejo, no se reenvía.
     */
    const DIAS_DE_VIGENCIA = 6;

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

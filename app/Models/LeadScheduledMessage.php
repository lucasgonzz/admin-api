<?php

namespace App\Models;

use App\Helpers\AppTime;
use App\Models\Concerns\UsesVirtualTime;
use Illuminate\Database\Eloquent\Model;

/**
 * Un mensaje de WhatsApp que un operador dejó PROGRAMADO para un lead y todavía no salió.
 *
 * Vive en su propia tabla y no como un estado más de `lead_messages` a propósito: ver el docblock
 * de la migración `2026_09_10_120000_create_lead_scheduled_messages_table` para el porqué completo
 * (en dos palabras: el historial que ve el agente de IA filtra por exclusión, así que un programado
 * ahí adentro se le leería como algo que ya se dijo).
 *
 * Cuando sale, se crea un `LeadMessage` normal y esta fila pasa a `enviado` apuntándolo con
 * `sent_lead_message_id`. Los frenos —cuáles se pueden programar y cuáles salen efectivamente—
 * viven todos en {@see \App\Services\LeadScheduledMessageService}, no acá.
 */
class LeadScheduledMessage extends Model
{
    /* Mismo trait que LeadMessage: el reloj virtual de local/tests tiene que valer para las dos
       tablas de la conversación, o un mensaje programado con tiempo virtual nacería con
       created_at real y la comparación contra scheduled_send_at daría cualquier cosa. */
    use UsesVirtualTime;

    /** Todavía no salió: es el único estado que se puede editar o cancelar. */
    public const STATUS_PENDIENTE = 'pendiente';

    /** Salió por WhatsApp y quedó su LeadMessage en el hilo. */
    public const STATUS_ENVIADO = 'enviado';

    /** No va a salir: lo canceló una persona o lo canceló un freno del despacho. */
    public const STATUS_CANCELADO = 'cancelado';

    /** Se intentó mandarlo y no se pudo. Queda visible en la conversación con el motivo. */
    public const STATUS_ERROR = 'error';

    /**
     * Estados que siguen vivos para el operador: los dos que la conversación muestra.
     *
     * `error` cuenta como vivo aunque ya no vaya a salir solo — es justamente lo que el operador
     * tiene que ver para reprogramarlo a mano. Los `enviado` ya son un LeadMessage normal y los
     * `cancelado` no le sirven a nadie: ninguno de los dos viaja al SPA.
     *
     * @var array<int, string>
     */
    public const STATUSES_VISIBLES = [self::STATUS_PENDIENTE, self::STATUS_ERROR];

    /** Texto escrito a mano por el operador. Solo se puede DENTRO de la ventana de 24 hs de Meta. */
    public const MODE_TEXTO_LIBRE = 'texto_libre';

    /** Plantilla Meta aprobada. Es la única salida cuando la ventana está (o va a estar) cerrada. */
    public const MODE_PLANTILLA = 'plantilla';

    /** Los dos modos posibles, para validar de un solo lugar. */
    public const MODES = [self::MODE_TEXTO_LIBRE, self::MODE_PLANTILLA];

    /** Lo canceló una persona desde el panel. */
    public const CANCELED_MANUAL = 'manual';

    /** El lead escribió después de programarlo y el programado tenía el check prendido. */
    public const CANCELED_LEAD_RESPONDIO = 'lead_respondio';

    /** El lead quedó marcado como que ya no recibe mensajes entre programar y enviar. */
    public const CANCELED_LEAD_NO_RECIBE = 'lead_no_recibe';

    /** El lead pasó a ser cliente entre programar y enviar. */
    public const CANCELED_LEAD_PROMOVIDO = 'lead_promovido';

    /** Al lead le sacaron el teléfono entre programar y enviar: no hay a dónde mandar. */
    public const CANCELED_SIN_TELEFONO = 'sin_telefono';

    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'scheduled_send_at'        => 'datetime',
        'template_variables'       => 'array',
        'cancel_if_lead_replies'   => 'boolean',
        'lead_id'                  => 'integer',
        'baseline_lead_message_id' => 'integer',
        'sent_lead_message_id'     => 'integer',
        'created_by_admin_id'      => 'integer',
    ];

    /**
     * Lead al que se le va a mandar.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function lead()
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }

    /**
     * Los que todavía esperan su turno.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePendientes($query)
    {
        return $query->where('status', self::STATUS_PENDIENTE);
    }

    /**
     * Los pendientes cuya hora ya se cumplió: exactamente lo que el comando de cada minuto despacha.
     *
     * El "ahora" sale de AppTime y no de Carbon::now() por lo mismo que el trait de arriba: en
     * local con tiempo virtual, el reloj del sistema es el de AppTime y no el de la máquina.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeVencidos($query)
    {
        return $query->pendientes()->where('scheduled_send_at', '<=', AppTime::now());
    }
}

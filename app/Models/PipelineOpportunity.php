<?php

namespace App\Models;

use App\Models\Concerns\UsesVirtualTime;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Oportunidad del CRM (misión pipelines-crm, 27/9/2026): un cliente o un lead parado en una etapa
 * de un pipeline, con responsable, próxima acción e historial.
 *
 * 🔴 EL SUJETO SON DOS COLUMNAS (`client_id`, `lead_id`), NO UN `morphTo`. Exactamente una tiene
 * valor. admin-api no tiene morph map y agregar `Client` a uno cambiaría `getMorphClass()` en todo
 * el sistema (lo usa el `morphToMany` de `RestrictsToClients` sobre `version_item_clients`). Con
 * dos columnas el eager loading es correcto sin trucos, filtrar "las oportunidades del cliente X"
 * es un `where`, y sumar otro tipo de sujeto mañana es otra columna. `subject_type` es un accessor
 * calculado, no una columna.
 *
 * 🔴 NO HAY COLUMNA DE ESTADO: abierta / ganada / perdida sale del `type` de `stage`. `closed_at`
 * sí se guarda porque es un hecho (cuándo se cerró).
 *
 * Toda regla de negocio (alta, mover, notas, próxima acción, responsable) vive en
 * `PipelineOpportunityService`; el JSON lo arma `PipelinePresenter`.
 *
 * @property int                             $id
 * @property int                             $pipeline_id
 * @property int                             $stage_id
 * @property int|null                        $client_id
 * @property int|null                        $lead_id
 * @property int|null                        $owner_admin_id
 * @property \Illuminate\Support\Carbon|null $next_action_at    Hora local; sin hora = 00:00:00.
 * @property string|null                     $next_action_note
 * @property string|null                     $next_action_source agenda | manual | null.
 * @property int|null                        $next_action_calendar_admin_id   Admin dueño del calendario donde está el evento de la próxima acción (Google Calendar); null = sin evento.
 * @property string|null                     $next_action_calendar_event_id   Id del evento en Google Calendar; null = sin evento. Lo escribe solo `PipelineCalendarSync`.
 * @property \Illuminate\Support\Carbon|null $stage_entered_at
 * @property \Illuminate\Support\Carbon|null $closed_at
 * @property string|null                     $lost_reason
 * @property int|null                        $created_by_admin_id
 * @property-read string|null                $subject_type      client | lead.
 */
class PipelineOpportunity extends Model
{
    use UsesVirtualTime;

    /** Sujeto cliente. */
    const SUBJECT_CLIENT = 'client';

    /** Sujeto lead. */
    const SUBJECT_LEAD = 'lead';

    /** La próxima acción la fijó el campo agenda de una etapa (al mover o al dar de alta). */
    const SOURCE_AGENDA = 'agenda';

    /** La próxima acción la cargó una persona (PUT o mover con `next_action_at`). */
    const SOURCE_MANUAL = 'manual';

    /**
     * @var string
     */
    protected $table = 'pipeline_opportunities';

    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'pipeline_id'         => 'integer',
        'stage_id'            => 'integer',
        'client_id'           => 'integer',
        'lead_id'             => 'integer',
        'owner_admin_id'      => 'integer',
        'created_by_admin_id' => 'integer',
        'next_action_at'      => 'datetime',
        'stage_entered_at'    => 'datetime',
        'closed_at'           => 'datetime',
    ];

    /**
     * Fechas serializadas como hora local `Y-m-d H:i:s`, nunca ISO con `Z` (ver el docblock de
     * `Pipeline::serializeDate()`: es la trampa de las tres horas).
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
     * Scope requerido por convención del workspace (Controller::fullModel()): lo que el presenter
     * necesita para armar la tarjeta sin disparar una consulta por fila.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return void
     */
    public function scopeWithAll($query)
    {
        $query->with('stage', 'client', 'lead', 'owner');
    }

    /**
     * `client` o `lead`, según cuál de las dos columnas tenga valor.
     *
     * Accessor calculado a propósito, no una columna: guardarlo aparte de `client_id` / `lead_id`
     * sería otra copia del mismo dato que algún día diría otra cosa.
     *
     * @return string|null
     */
    public function getSubjectTypeAttribute()
    {
        if ($this->client_id !== null) {
            return self::SUBJECT_CLIENT;
        }

        if ($this->lead_id !== null) {
            return self::SUBJECT_LEAD;
        }

        return null;
    }

    /**
     * Si la próxima acción SOBREVIVE a un movimiento hacia una etapa abierta que no la fija ni la
     * trae en el payload (regla 3 de la ronda de arreglos R1): solo si la cargó una persona y
     * todavía no pasó.
     *
     * 🔴 ES LA ÚNICA DEFINICIÓN. La usan `PipelineOpportunityService::mover()` para decidir si la
     * conserva o la borra, y el presenter para publicar `next_action_carries_over`, que es lo que
     * la SPA mira para precargar (o no) el editor de próxima acción del modal de mover. Si la SPA
     * volviera a implementar la regla, algún día mostraría "se mantiene" y el back la borraría.
     *
     * Una próxima acción que vino de la agenda de la etapa que se deja (la fecha de la reunión), o
     * que ya venció, no sobrevive: el movimiento mismo es la interacción que la reemplaza.
     *
     * @param Carbon $ahora El reloj del request (`AppTime::now()`).
     *
     * @return bool
     */
    public function proxima_accion_se_conserva(Carbon $ahora)
    {
        return $this->next_action_source === self::SOURCE_MANUAL
            && $this->next_action_at !== null
            && $this->next_action_at->gt($ahora);
    }

    /**
     * Pipeline de la oportunidad.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function pipeline()
    {
        return $this->belongsTo(Pipeline::class, 'pipeline_id');
    }

    /**
     * Etapa actual (de ella sale el estado).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function stage()
    {
        return $this->belongsTo(PipelineStage::class, 'stage_id');
    }

    /**
     * Cliente sujeto (nulo si el sujeto es un lead, o si el cliente se borró).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /**
     * Lead sujeto (nulo si el sujeto es un cliente, o si el lead se borró).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function lead()
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }

    /**
     * Admin responsable.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function owner()
    {
        return $this->belongsTo(Admin::class, 'owner_admin_id');
    }

    /**
     * Admin que la dio de alta.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function created_by_admin()
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }

    /**
     * Historial, de la más nueva a la más vieja (el mismo orden que muestra la ficha).
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function activities()
    {
        return $this->hasMany(PipelineActivity::class, 'opportunity_id')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');
    }
}

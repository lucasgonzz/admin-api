<?php

namespace App\Models;

use App\Models\Concerns\UsesVirtualTime;
use Illuminate\Database\Eloquent\Model;

/**
 * Una entrada del historial de una oportunidad del CRM (misión pipelines-crm, 27/9/2026).
 *
 * 🔴 GUARDA FOTOS, NO REFERENCIAS VIVAS: `from_stage_name`, `to_stage_name` y `data` se copian en
 * el momento. Si mañana se renombra una etapa o se borra un campo de su definición, el historial se
 * sigue leyendo igual. Los `*_stage_id` quedan para contar (el embudo), no para mostrar.
 *
 * Qué lleva `data` según `type`:
 *  - `created` / `stage_change`: `[{key, label, type, value}]`, los campos de la etapa destino que
 *    vinieron con valor, en el orden de la definición.
 *  - `next_action`: `{from, to, note}` con las fechas como `Y-m-d H:i:s` (hora local).
 *  - `owner`: `{from, to}` con los NOMBRES de los admins (foto: si el admin se renombra o se borra,
 *    el historial no cambia).
 *  - `note`: nada; el texto va en `body` y el canal en `channel`.
 *
 * Solo las notas se pueden borrar: el historial de etapas no (lo frena la API con 422).
 *
 * @property int                             $id
 * @property int                             $opportunity_id
 * @property int                             $pipeline_id
 * @property int|null                        $admin_id
 * @property string                          $type
 * @property int|null                        $from_stage_id
 * @property string|null                     $from_stage_name
 * @property int|null                        $to_stage_id
 * @property string|null                     $to_stage_name
 * @property string|null                     $body
 * @property string|null                     $channel
 * @property array|null                      $data
 * @property \Illuminate\Support\Carbon      $occurred_at
 */
class PipelineActivity extends Model
{
    use UsesVirtualTime;

    const TYPE_CREATED      = 'created';
    const TYPE_STAGE_CHANGE = 'stage_change';
    const TYPE_NOTE         = 'note';
    const TYPE_NEXT_ACTION  = 'next_action';
    const TYPE_OWNER        = 'owner';

    /**
     * Tipos de actividad con su etiqueta. Única definición: la publica `GET pipelines/meta`.
     *
     * @var array<string, string>
     */
    const TYPE_LABELS = [
        self::TYPE_CREATED      => 'Alta',
        self::TYPE_STAGE_CHANGE => 'Cambio de etapa',
        self::TYPE_NOTE         => 'Nota',
        self::TYPE_NEXT_ACTION  => 'Próxima acción',
        self::TYPE_OWNER        => 'Responsable',
    ];

    /**
     * Canales de una nota con su etiqueta. Única definición: la publica `GET pipelines/meta`.
     *
     * 🔴 Es solo una etiqueta de lo que pasó: el módulo no manda ningún mensaje por ningún canal.
     *
     * @var array<string, string>
     */
    const CHANNEL_LABELS = [
        'call'      => 'Llamada',
        'whatsapp'  => 'WhatsApp',
        'meeting'   => 'Reunión',
        'email'     => 'Mail',
        'in_person' => 'En persona',
        'other'     => 'Otro',
    ];

    /**
     * @var string
     */
    protected $table = 'pipeline_activities';

    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'opportunity_id' => 'integer',
        'pipeline_id'    => 'integer',
        'admin_id'       => 'integer',
        'from_stage_id'  => 'integer',
        'to_stage_id'    => 'integer',
        'data'           => 'array',
        'occurred_at'    => 'datetime',
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
     * Scope requerido por convención del workspace (Controller::fullModel()).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return void
     */
    public function scopeWithAll($query)
    {
        $query->with('admin');
    }

    /**
     * Oportunidad dueña de la entrada.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function opportunity()
    {
        return $this->belongsTo(PipelineOpportunity::class, 'opportunity_id');
    }

    /**
     * Pipeline (repetido para contar el embudo sin join).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function pipeline()
    {
        return $this->belongsTo(Pipeline::class, 'pipeline_id');
    }

    /**
     * Admin que hizo el movimiento o escribió la nota.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function admin()
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }
}

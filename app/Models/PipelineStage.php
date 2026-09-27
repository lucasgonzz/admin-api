<?php

namespace App\Models;

use App\Models\Concerns\UsesVirtualTime;
use Illuminate\Database\Eloquent\Model;

/**
 * Etapa de un pipeline del CRM (misión pipelines-crm, 27/9/2026).
 *
 * 🔴 `type` (open | won | lost) es LA fuente del estado de las oportunidades que están acá
 * adentro: una oportunidad no guarda si está abierta o cerrada, lo deduce de su etapa. Es la clase
 * "estado derivado guardado aparte de su fuente" (APRENDER_NO_PARCHEAR.md): si la oportunidad
 * guardara su propio estado, algún día diría "abierta" parada en una etapa "ganada". Para que no se
 * desalinee nunca, el `type` de una etapa no se puede cambiar mientras tenga oportunidades
 * (lo frena `PipelineConfigService` con 422).
 *
 * `fields` es la definición de los campos que la etapa pide al entrar; la valida y la normaliza
 * `PipelineFieldsService`, que es el único que sabe qué es una definición válida.
 *
 * @property int        $id
 * @property int        $pipeline_id
 * @property string     $name
 * @property string     $color       Hex #rrggbb.
 * @property string     $type        open | won | lost.
 * @property int        $sort_order
 * @property array|null $fields      [{key, label, type, required, agenda, options}].
 */
class PipelineStage extends Model
{
    use UsesVirtualTime;

    /** Etapa abierta: la oportunidad sigue en juego. */
    const TYPE_OPEN = 'open';

    /** Etapa ganada: cierra la oportunidad como ganada. */
    const TYPE_WON = 'won';

    /** Etapa perdida: cierra la oportunidad y exige motivo. */
    const TYPE_LOST = 'lost';

    /**
     * Tipos de etapa con su etiqueta. Es la ÚNICA definición: `GET pipelines/meta` la publica tal
     * cual y la SPA no la escribe de nuevo (clase "contrato de enumeración partido entre cliente y
     * servidor", APRENDER_NO_PARCHEAR.md).
     *
     * @var array<string, string>
     */
    const TYPE_LABELS = [
        self::TYPE_OPEN => 'Abierta',
        self::TYPE_WON  => 'Ganada',
        self::TYPE_LOST => 'Perdida',
    ];

    /** Color por defecto de una etapa nueva (el mismo default de la columna). */
    const DEFAULT_COLOR = '#adb5bd';

    /**
     * @var string
     */
    protected $table = 'pipeline_stages';

    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'pipeline_id' => 'integer',
        'sort_order'  => 'integer',
        'fields'      => 'array',
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
        $query->with('pipeline');
    }

    /**
     * Pipeline dueño de la etapa.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function pipeline()
    {
        return $this->belongsTo(Pipeline::class, 'pipeline_id');
    }

    /**
     * Oportunidades que están HOY en esta etapa.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function opportunities()
    {
        return $this->hasMany(PipelineOpportunity::class, 'stage_id');
    }

    /**
     * Si es una etapa abierta.
     *
     * @return bool
     */
    public function is_open()
    {
        return $this->type === self::TYPE_OPEN;
    }

    /**
     * Si es una etapa de cierre (ganada o perdida).
     *
     * @return bool
     */
    public function is_closed()
    {
        return $this->type === self::TYPE_WON || $this->type === self::TYPE_LOST;
    }

    /**
     * La definición de campos como lista, nunca null.
     *
     * @return array<int, array<string, mixed>>
     */
    public function definicion_de_campos()
    {
        return is_array($this->fields) ? array_values($this->fields) : [];
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\UsesVirtualTime;
use Illuminate\Database\Eloquent\Model;

/**
 * Pipeline del CRM del admin (misión pipelines-crm, 27/9/2026): una campaña de contactos con sus
 * etapas ordenadas. El primero es "Agentes" (lo siembra `PipelineAgentesSeeder`).
 *
 * 🔴 No confundir con el pipeline de LEADS (`LeadPipelineStatus`, `leads.status`), que es otra
 * cosa y no se toca desde acá: un lead puede ser el sujeto de una oportunidad de un pipeline, pero
 * su estado comercial sigue siendo el suyo.
 *
 * El JSON que ve la SPA NO sale de serializar este modelo: lo arma `PipelinePresenter`, que es el
 * único productor de esa forma.
 *
 * @property int                             $id
 * @property string                          $name
 * @property string|null                     $slug                 Solo para el seeder idempotente.
 * @property string|null                     $description
 * @property array|null                      $lost_reasons         Lista de motivos de pérdida.
 * @property int                             $sort_order
 * @property \Illuminate\Support\Carbon|null $archived_at          Nulo = activo.
 * @property int|null                        $created_by_admin_id
 */
class Pipeline extends Model
{
    use UsesVirtualTime;

    /**
     * @var string
     */
    protected $table = 'pipelines';

    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'lost_reasons'        => 'array',
        'sort_order'          => 'integer',
        'archived_at'         => 'datetime',
        'created_by_admin_id' => 'integer',
    ];

    /**
     * Fechas serializadas como hora local `Y-m-d H:i:s`, nunca ISO con `Z`.
     *
     * 🔴 No es cosmético: el `toArray()` por defecto de Laravel 8 serializa en UTC con `Z`, y una
     * SPA que reasigna ese valor a un campo de fecha lo corre tres horas (trampa medida el
     * 21/9/2026). El JSON de la SPA lo arma el presenter con el mismo formato; esto cubre cualquier
     * otra serialización del modelo (logs, dumps, un `toArray()` de alguien que venga después).
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
        $query->with('stages');
    }

    /**
     * Etapas del pipeline, en el orden del tablero.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function stages()
    {
        return $this->hasMany(PipelineStage::class, 'pipeline_id')->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Oportunidades del pipeline (abiertas y cerradas).
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function opportunities()
    {
        return $this->hasMany(PipelineOpportunity::class, 'pipeline_id');
    }

    /**
     * Admin que creó el pipeline (nulo si lo creó el seeder).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function created_by_admin()
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }

    /**
     * Los motivos de pérdida como lista, nunca null.
     *
     * @return array<int, string>
     */
    public function motivos_de_perdida()
    {
        return is_array($this->lost_reasons) ? array_values($this->lost_reasons) : [];
    }
}

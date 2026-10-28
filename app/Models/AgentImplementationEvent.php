<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un evento de la bitácora de una implementación de Agentes de IA (misión `implementacion-agentes-ia`,
 * 28/10/2026): una nota, un avance de etapa, un WhatsApp enviado o un dato cargado.
 *
 * 🔴 El texto lo lee Martín en el contexto del ticket: nunca se escribe acá un teléfono ni un id de
 * Meta (los ids van a las columnas de `AgentImplementation`, que no viajan). Los eventos que arma el
 * propio admin cuidan eso; los que llegan escritos por alguien, son responsabilidad de quien escribe.
 *
 * @property int                 $id
 * @property int                 $agent_implementation_id Implementación padre.
 * @property int|null            $stage_number            Etapa a la que se refiere (null = general).
 * @property string              $kind                    nota | avance | whatsapp | dato.
 * @property string              $source                  martin | claude | panel.
 * @property string              $texto                   Hasta 1000 caracteres.
 */
class AgentImplementationEvent extends Model
{
    /** Nota libre. */
    const KIND_NOTA = 'nota';

    /** Cambio de estado de una etapa o de la implementación. */
    const KIND_AVANCE = 'avance';

    /** WhatsApp mandado al cliente por el ticket de soporte. */
    const KIND_WHATSAPP = 'whatsapp';

    /** Dato cargado (ids de Kapso/Meta, camino...). */
    const KIND_DATO = 'dato';

    /** Los tipos posibles. */
    const KINDS = [self::KIND_NOTA, self::KIND_AVANCE, self::KIND_WHATSAPP, self::KIND_DATO];

    /** Lo dejó Martín (el soporte de Codex). */
    const SOURCE_MARTIN = 'martin';

    /** Lo dejó una sesión de Claude (la skill `/implementar-agentes`). */
    const SOURCE_CLAUDE = 'claude';

    /** Lo dejó una persona desde el panel. */
    const SOURCE_PANEL = 'panel';

    /** Los orígenes posibles. */
    const SOURCES = [self::SOURCE_MARTIN, self::SOURCE_CLAUDE, self::SOURCE_PANEL];

    /** Tope del texto (el largo de la columna). */
    const MAX_TEXTO = 1000;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'agent_implementation_id',
        'stage_number',
        'kind',
        'source',
        'texto',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'agent_implementation_id' => 'integer',
        'stage_number'            => 'integer',
    ];

    /**
     * Eager load de la implementación padre.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithAll($query)
    {
        return $query->with(['agent_implementation']);
    }

    /**
     * Implementación dueña del evento.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function agent_implementation()
    {
        return $this->belongsTo(AgentImplementation::class);
    }

    /**
     * Recorta un texto al tope de la columna (con «…» si se cortó), para que un evento armado por el
     * admin con un texto largo (un WhatsApp de 4000 caracteres) no falle al guardarse.
     *
     * @param mixed $texto Texto crudo.
     *
     * @return string
     */
    public static function fit_texto($texto)
    {
        $texto = trim((string) $texto);

        if (mb_strlen($texto) <= self::MAX_TEXTO) {
            return $texto;
        }

        return mb_substr($texto, 0, self::MAX_TEXTO - 1) . '…';
    }
}

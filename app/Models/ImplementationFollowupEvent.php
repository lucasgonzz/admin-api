<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un evento de la bitácora del seguimiento de una implementación (misión cruzada `seguimiento-de-implementaciones`,
 * 11/11/2026). Lo lee Martín en el contexto del seguimiento (los últimos 20).
 *
 * @property int                       $id
 * @property int                       $implementation_followup_id
 * @property string                    $kind              Ver `KINDS`.
 * @property string                    $source            sistema | martin | lucas.
 * @property string|null               $detail
 * @property array<string, mixed>|null $data
 * @property int|null                  $support_ticket_id
 */
class ImplementationFollowupEvent extends Model
{
    const KIND_CREADO    = 'creado';
    const KIND_ENVIADO   = 'enviado';
    const KIND_POSPUESTO = 'pospuesto';
    const KIND_PAUSADO   = 'pausado';
    const KIND_REANUDADO = 'reanudado';
    const KIND_FRENADO   = 'frenado';
    const KIND_TERMINADO = 'terminado';
    const KIND_OMITIDO   = 'omitido';
    const KIND_HITOS     = 'hitos';
    const KIND_FALLIDO   = 'fallido';

    /**
     * Cambio de Lucas por `PATCH claude/implementation-followups/{id}` que no es un cambio de estado (intervalo,
     * tope, hitos que no aplican). No está en la lista del contrato: se agregó para no disfrazar una edición de otra
     * cosa (ver el informe de la misión).
     */
    const KIND_EDITADO = 'editado';

    /** Los tipos posibles. */
    const KINDS = [
        self::KIND_CREADO, self::KIND_ENVIADO, self::KIND_POSPUESTO, self::KIND_PAUSADO, self::KIND_REANUDADO,
        self::KIND_FRENADO, self::KIND_TERMINADO, self::KIND_OMITIDO, self::KIND_HITOS, self::KIND_FALLIDO,
        self::KIND_EDITADO,
    ];

    const SOURCE_SISTEMA = 'sistema';
    const SOURCE_MARTIN  = 'martin';
    const SOURCE_LUCAS   = 'lucas';

    /**
     * @var array<int, string>
     */
    protected $fillable = ['implementation_followup_id', 'kind', 'source', 'detail', 'data', 'support_ticket_id'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'implementation_followup_id' => 'integer',
        'data'                       => 'array',
        'support_ticket_id'          => 'integer',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function followup()
    {
        return $this->belongsTo(ImplementationFollowup::class, 'implementation_followup_id');
    }
}

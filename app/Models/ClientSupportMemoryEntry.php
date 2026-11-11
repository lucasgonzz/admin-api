<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una entrada de la ficha del cliente que escribe Martín (o Lucas desde el panel): una idea por fila, con categoría,
 * texto (≤ 600), vencimiento opcional y estado (misión cruzada `ficha-del-cliente`, 11/11/2026).
 *
 * 🔴 No se edita en el lugar. Reemplazar = fila nueva vigente con `replaces_id` + la vieja `reemplazada`; archivar =
 * `archivada` con motivo. Las reglas (tope de 60 vigentes, dato sensible, entrada ajena) viven en
 * `App\Services\ClientMemory\ClientSupportMemoryService`; este modelo no las sabe.
 */
class ClientSupportMemoryEntry extends Model
{
    const STATUS_VIGENTE = 'vigente';
    const STATUS_REEMPLAZADA = 'reemplazada';
    const STATUS_ARCHIVADA = 'archivada';

    const SOURCE_MARTIN = 'martin';
    const SOURCE_LUCAS = 'lucas';

    /**
     * Las categorías, en el orden en que viajan en `ficha_cliente` (compromisos y decisiones primero).
     */
    const CATEGORIAS = ['compromiso', 'decision', 'problema', 'preferencia', 'implementacion', 'negocio', 'otro'];

    /** @var string */
    protected $table = 'client_support_memory_entries';

    /** @var array<int, string> */
    protected $fillable = [
        'client_id',
        'categoria',
        'texto',
        'vigente_hasta',
        'status',
        'replaces_id',
        'source',
        'support_ticket_id',
        'admin_id',
        'archived_reason',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'client_id' => 'integer',
        'replaces_id' => 'integer',
        'support_ticket_id' => 'integer',
        'admin_id' => 'integer',
        'vigente_hasta' => 'date',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function admin()
    {
        return $this->belongsTo(Admin::class);
    }
}

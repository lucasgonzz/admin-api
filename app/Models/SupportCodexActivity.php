<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una acción de Martín registrada por el monitor (misión cruzada `auditoria-de-martin`, 11/11/2026): qué subcomando
 * corrió (`command`), de qué tipo (`kind`), un resumen en castellano sin secretos ni datos crudos (`summary`), un
 * `detail` chico y si salió bien (`ok`).
 *
 * 🔴 No se edita ni se borra: es auditoría. La ingesta y sus reglas (idempotencia por `dedupe_hash`, `client_id`
 * derivado) viven en `App\Services\MartinActivity\MartinActivityService`.
 */
class SupportCodexActivity extends Model
{
    const KIND_LECTURA = 'lectura';
    /** Lo que Martín escribe en el SISTEMA DEL CLIENTE: importaciones, imágenes, reparar configuración, confirmar actualización. */
    const KIND_ESCRITURA = 'escritura';
    /** Lo que Martín deja asentado en el ADMIN (interno): ficha, título, notas de implementación, pasos, misiones, pausas, tour. */
    const KIND_REGISTRO = 'registro';
    const KIND_ENVIO = 'envio';
    const KIND_CONSULTA = 'consulta';
    const KIND_PLAN = 'plan';
    const KIND_ERROR = 'error';

    /** Los tipos válidos, en el orden del contrato. */
    const KINDS = [self::KIND_LECTURA, self::KIND_ESCRITURA, self::KIND_REGISTRO, self::KIND_ENVIO, self::KIND_CONSULTA, self::KIND_PLAN, self::KIND_ERROR];

    /** @var string */
    protected $table = 'support_codex_activities';

    /** @var array<int, string> */
    protected $fillable = [
        'support_ticket_id',
        'implementation_followup_id',
        'client_id',
        'run_id',
        'kind',
        'command',
        'summary',
        'detail',
        'ok',
        'occurred_at',
        'dedupe_hash',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'support_ticket_id' => 'integer',
        'implementation_followup_id' => 'integer',
        'client_id' => 'integer',
        'detail' => 'array',
        'ok' => 'boolean',
        'occurred_at' => 'datetime',
    ];

    /** @var array<int, string> */
    protected $hidden = ['dedupe_hash'];
}

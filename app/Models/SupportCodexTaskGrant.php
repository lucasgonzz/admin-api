<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * El permiso delegado de una tarea nativa de Martín (misión cruzada `importaciones-de-martin`, 11/11/2026): 24 h, un
 * ticket, un alcance (`pdf` | `web`) y el token guardado solo como hash. Las reglas viven en
 * `App\Services\ImportacionesDeMartin\ImportacionesDeMartinService`.
 */
class SupportCodexTaskGrant extends Model
{
    /** Los alcances que se pueden pedir. */
    const ALCANCE_PDF = 'pdf';
    const ALCANCE_WEB = 'web';
    const ALCANCES = [self::ALCANCE_PDF, self::ALCANCE_WEB];

    /** Horas de vida del permiso. */
    const HORAS = 24;

    /** @var string */
    protected $table = 'support_codex_task_grants';

    /** @var array<int, string> */
    protected $fillable = [
        'support_ticket_id',
        'client_id',
        'alcance',
        'token_hash',
        'vence_at',
        'revocado_at',
        'usos',
        'usado_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'support_ticket_id' => 'integer',
        'client_id' => 'integer',
        'usos' => 'integer',
        'vence_at' => 'datetime',
        'revocado_at' => 'datetime',
        'usado_at' => 'datetime',
    ];

    /** @var array<int, string> El hash no sale nunca en un JSON. */
    protected $hidden = ['token_hash'];
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * El plan que Martín declara en una atención (misión cruzada `auditoria-de-martin`, 11/11/2026): un objetivo y hasta 15
 * pasos `{texto, estado}`. Uno por ejecución (`run_id` único); el monitor lo reemplaza entero cada vez que Martín lo
 * actualiza. Lo muestra la tarjeta «Recorrido de Martín» del panel.
 */
class SupportCodexPlan extends Model
{
    /** Los estados de un paso. */
    const ESTADOS = ['pendiente', 'en_curso', 'hecho', 'descartado'];

    /** Tope de pasos y largo de cada uno. */
    const MAX_PASOS = 15;
    const LARGO_PASO = 200;
    const LARGO_OBJETIVO = 300;

    /** @var string */
    protected $table = 'support_codex_plans';

    /** @var array<int, string> */
    protected $fillable = [
        'support_ticket_id',
        'implementation_followup_id',
        'run_id',
        'objetivo',
        'pasos',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'support_ticket_id' => 'integer',
        'implementation_followup_id' => 'integer',
        'pasos' => 'array',
    ];
}

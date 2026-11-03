<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un WhatsApp de la prueba gratuita que el admin le mandó (o dejó en cola) a un negocio en prueba
 * (misión cruzada prueba-martin-importacion, 3/11/2026): la bienvenida de Martín, los empujones por
 * hito faltante y los avisos a Tomás.
 *
 * Es el registro con el que se cumplen las reglas del programa: la bienvenida una sola vez por
 * cliente, como mucho UN empujón por día, y un aviso a Tomás por motivo. `dia` es la fecha de
 * Argentina del envío, para el tope diario; `plantilla` es el nombre de la plantilla de Meta o
 * `aviso_tomas` (con el motivo en `hito`).
 */
class PruebaWhatsappEnvio extends Model
{
    /** Salió por WhatsApp (hay wamid). */
    const ESTADO_ENVIADO = 'enviado';

    /** La plantilla todavía no está aprobada: se manda cuando Meta la apruebe. */
    const ESTADO_EN_COLA = 'en_cola';

    /** Meta rechazó el envío o no había a quién mandarle. */
    const ESTADO_FALLIDO = 'fallido';

    /** `plantilla` de los avisos al closer (el motivo va en `hito`: `dia_5`, `vencida`, `manual`). */
    const PLANTILLA_AVISO_TOMAS = 'aviso_tomas';

    protected $table = 'prueba_whatsapp_envios';

    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'lead_id'            => 'integer',
        'client_id'          => 'integer',
        'support_ticket_id'  => 'integer',
        'support_message_id' => 'integer',
        'enviado_at'         => 'datetime',
    ];

    /**
     * Scope estándar para contrato homogéneo con fullModel (regla admin-api).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     */
    public function scopeWithAll($query)
    {
    }
}

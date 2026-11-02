<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un cambio de contacto de un cliente (misión telefono-personal-del-dueno, 2/11/2026): el campo, el valor de
 * antes y el de después, quién lo hizo (`origen`) y por qué (`motivo`). Solo se inserta; nunca se actualiza.
 *
 * @property int         $client_id
 * @property string      $campo           `phone`, `asistente_phone` o `asistente_whatsapp_activo`.
 * @property string|null $valor_anterior
 * @property string|null $valor_nuevo
 * @property string      $origen          Hoy siempre `claude` (las rutas `claude/*`).
 * @property string|null $motivo
 */
class ClientContactChange extends Model
{
    const ORIGEN_CLAUDE = 'claude';

    /** Solo `created_at`: es una tabla de rastro, no se modifica. */
    const UPDATED_AT = null;

    protected $table = 'client_contact_changes';

    protected $guarded = [];

    protected $casts = [
        'client_id' => 'integer',
    ];
}

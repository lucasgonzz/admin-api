<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Token de un solo uso y vida corta (2 minutos) que autoriza la vista en
 * vivo del PDF de una Factura C de mensualidad sin pasar por `auth:sanctum`
 * (prompt 362). Réplica exacta en espíritu de `SalePdfAccessToken`
 * (empresa-api).
 */
class MensualidadInvoicePdfAccessToken extends Model
{
    /**
     * Sin whitelist de mass-assignment: modelo trivial, mismo criterio que
     * `SalePdfAccessToken`.
     *
     * @var array
     */
    protected $guarded = [];
}

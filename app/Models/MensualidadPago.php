<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un pago recibido por la mensualidad de un cliente, imputado a un mes (misión modulo-cobranzas,
 * 18/9/2026).
 *
 * El estado del mes (`MensualidadPeriodo.estado`) se recalcula desde la suma de estos pagos cada
 * vez que se agrega o se borra uno. Un pago con `monto` nulo es un pago importado de la planilla
 * como "PAGADO" sin monto: existe para dejar constancia, pero NO suma.
 *
 * @property int         $client_id   Cliente que pagó.
 * @property string      $periodo     Mes al que se imputa, 'YYYY-MM'.
 * @property string|null $monto       Cuánto entró (nulo = sin monto, no suma).
 * @property string|null $fecha_pago  Cuándo entró.
 * @property string|null $medio       Transferencia | Mercado Pago | Efectivo | Otro.
 * @property string|null $observacion Nota libre.
 * @property int|null    $admin_id    Quién lo registró (nulo si fue el importador).
 * @property bool        $importado   Si la escribió el importador de la planilla.
 */
class MensualidadPago extends Model
{
    /**
     * @var string
     */
    protected $table = 'mensualidad_pagos';

    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'client_id'  => 'integer',
        'admin_id'   => 'integer',
        'monto'      => 'decimal:2',
        'fecha_pago' => 'date',
        'importado'  => 'boolean',
    ];

    /**
     * Scope requerido por convención del workspace (Controller::fullModel()).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return void
     */
    public function scopeWithAll($query)
    {
        $query->with('client', 'admin');
    }

    /**
     * Cliente que pagó.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Admin que registró el pago (nulo si lo escribió el importador).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function admin()
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un pago al vendedor a cuenta de la comisión de una venta (misión ventas-y-comisiones,
 * 28/10/2026). Lo registra Lucas desde Cobranzas → Comisiones; el vendedor lo ve en "Mis
 * comisiones".
 *
 * `vendedor_admin_id` queda fijo en el pago: si la venta cambia de vendedor, lo que ya se le pagó
 * al anterior sigue siendo suyo. La moneda es la de la venta.
 *
 * @property int                 $id
 * @property int                 $venta_id
 * @property int                 $vendedor_admin_id
 * @property string              $monto
 * @property string              $moneda
 * @property \Carbon\Carbon|null $fecha
 * @property string|null         $nota
 * @property int|null            $registrado_por_admin_id
 */
class ComisionPago extends Model
{
    /**
     * @var string
     */
    protected $table = 'comision_pagos';

    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'venta_id'                => 'integer',
        'vendedor_admin_id'       => 'integer',
        'monto'                   => 'decimal:2',
        'fecha'                   => 'date',
        'registrado_por_admin_id' => 'integer',
    ];

    /**
     * Scope requerido por convención del workspace (Controller::fullModel()).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return void
     */
    public function scopeWithAll($query)
    {
        $query->with(['venta', 'vendedor', 'registrado_por']);
    }

    /**
     * Venta a cuenta de cuya comisión se pagó.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function venta()
    {
        return $this->belongsTo(Venta::class, 'venta_id');
    }

    /**
     * Vendedor que cobró.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function vendedor()
    {
        return $this->belongsTo(Admin::class, 'vendedor_admin_id');
    }

    /**
     * Admin que registró el pago.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function registrado_por()
    {
        return $this->belongsTo(Admin::class, 'registrado_por_admin_id');
    }
}

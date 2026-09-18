<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una cuota de la licencia (el pago único del contrato) de un cliente (misión modulo-cobranzas,
 * 18/9/2026). Es la celda de la hoja LICENCIAS del Excel: mes, monto, moneda y color.
 *
 * `monto_pagado` acumula lo que entró y el estado se recalcula desde ahí en
 * `LicenciaCuotaService::registrar_pago()`. Las cuotas importadas de la planilla tienen el estado
 * que decía el color de la celda y `monto_pagado` nulo, porque la planilla no dice cuánto entró
 * (salvo en la nota).
 *
 * @property int         $client_id    Cliente.
 * @property int         $numero       Número de cuota (1, 2, 3...).
 * @property string      $periodo      Mes, 'YYYY-MM'.
 * @property string|null $vencimiento  Fecha puntual de vencimiento, si el contrato la tiene.
 * @property string      $monto        Monto de la cuota.
 * @property string      $moneda       'USD' | 'ARS'.
 * @property string      $estado       pendiente | parcial | pagada.
 * @property string|null $monto_pagado Lo que entró hasta ahora (nulo = no se sabe o nada).
 * @property string|null $fecha_pago   Cuándo entró el último pago.
 * @property string|null $observacion  Nota libre.
 * @property bool        $importado    Si la escribió el importador de la planilla.
 */
class LicenciaCuota extends Model
{
    /** No entró nada. */
    const ESTADO_PENDIENTE = 'pendiente';

    /** Entró una parte. */
    const ESTADO_PARCIAL = 'parcial';

    /** Cubierta (por monto o porque Lucas la dio por completa). */
    const ESTADO_PAGADA = 'pagada';

    /**
     * @var array<int, string>
     */
    const ESTADOS = [
        self::ESTADO_PENDIENTE,
        self::ESTADO_PARCIAL,
        self::ESTADO_PAGADA,
    ];

    /**
     * Monedas admitidas. La planilla mezcla las dos, incluso dentro de un mismo cliente.
     *
     * @var array<int, string>
     */
    const MONEDAS = ['USD', 'ARS'];

    /**
     * @var string
     */
    protected $table = 'licencia_cuotas';

    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'client_id'    => 'integer',
        'numero'       => 'integer',
        'vencimiento'  => 'date',
        'monto'        => 'decimal:2',
        'monto_pagado' => 'decimal:2',
        'fecha_pago'   => 'date',
        'importado'    => 'boolean',
    ];

    /**
     * Scope requerido por convención del workspace (Controller::fullModel()).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return void
     */
    public function scopeWithAll($query)
    {
        $query->with('client');
    }

    /**
     * Cliente dueño de la cuota.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Cuánto se considera cobrado de esta cuota.
     *
     * Una cuota `pagada` sin `monto_pagado` (las importadas, o las que Lucas cerró sin cargar el
     * monto) se considera cobrada por su monto entero: si no, el resumen diría que de una licencia
     * pagada no entró nada.
     *
     * @return float
     */
    public function monto_cobrado(): float
    {
        if ($this->estado === self::ESTADO_PAGADA) {
            return $this->monto_pagado !== null ? (float) $this->monto_pagado : (float) $this->monto;
        }

        return (float) ($this->monto_pagado ?? 0);
    }

    /**
     * Cuánto falta cobrar de esta cuota (nunca negativo).
     *
     * @return float
     */
    public function monto_pendiente(): float
    {
        if ($this->estado === self::ESTADO_PAGADA) {
            return 0.0;
        }

        return max(0.0, (float) $this->monto - (float) ($this->monto_pagado ?? 0));
    }
}

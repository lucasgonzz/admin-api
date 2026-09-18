<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * El estado AFIRMADO de un mes de mensualidad de un cliente (misión modulo-cobranzas, 18/9/2026).
 *
 * Es la celda del Excel: existe solo cuando alguien dijo algo de ese mes (lo pagó, lo pagó a
 * medias, no se cobra, se reabrió). Los meses sin fila los calcula solo
 * `CobranzasMensualidadService::estado_de()` mirando la Factura C, el mes de inicio del cliente
 * y el mes corriente. Por eso los estados de acá son cuatro y los que ve la pantalla son siete:
 * `facturado`, `no_aplica` y `futuro` nunca se guardan, se deducen.
 *
 * @property int         $client_id      Cliente.
 * @property string      $periodo        Mes, 'YYYY-MM'.
 * @property string      $estado         pendiente | parcial | pagado | sin_cargo.
 * @property string|null $monto_esperado Cuánto se esperaba ese mes (nulo = el total actual del cliente).
 * @property string|null $observacion    Nota del mes.
 * @property bool        $importado      Si la escribió el importador de la planilla.
 */
class MensualidadPeriodo extends Model
{
    /** El mes se reabrió o se importó "sin marca": se le reclama. */
    const ESTADO_PENDIENTE = 'pendiente';

    /** Entró una parte; el saldo sale de `monto_esperado` menos los pagos. */
    const ESTADO_PARCIAL = 'parcial';

    /** El mes está cobrado. */
    const ESTADO_PAGADO = 'pagado';

    /** Ese mes no se cobra. */
    const ESTADO_SIN_CARGO = 'sin_cargo';

    /**
     * Los estados que se pueden GUARDAR en la fila (los demás se deducen).
     *
     * @var array<int, string>
     */
    const ESTADOS = [
        self::ESTADO_PENDIENTE,
        self::ESTADO_PARCIAL,
        self::ESTADO_PAGADO,
        self::ESTADO_SIN_CARGO,
    ];

    /**
     * @var string
     */
    protected $table = 'mensualidad_periodos';

    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'client_id'      => 'integer',
        'monto_esperado' => 'decimal:2',
        'importado'      => 'boolean',
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
     * Cliente dueño del mes.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Pagos imputados a este mismo (cliente, mes). No es una FK: se cruza por las dos columnas.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function pagos_query()
    {
        return MensualidadPago::query()
            ->where('client_id', $this->client_id)
            ->where('periodo', $this->periodo);
    }
}

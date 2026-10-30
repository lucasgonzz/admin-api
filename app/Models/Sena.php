<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una seña: un pago a cuenta que el cliente deja antes (o aparte) de las cuotas de una venta
 * (misión senas-en-ventas, 30/10/2026).
 *
 * La seña NO es una cuota: al registrarse se aplica sola a las cuotas pendientes más próximas de la
 * venta (`App\Services\SenaService`, que reusa `LicenciaCuotaService::registrar_pago()`), así que
 * la deuda, lo cobrado y la comisión liberada del vendedor se calculan como con cualquier cobro. La
 * fila guarda el registro: cuánto, cuándo, quién, la nota y a qué cuotas se aplicó (`aplicaciones`).
 *
 * Dos estados:
 *  - **Aplicada** (`aplicada_at` no nulo): ya está dentro de las cuotas de `venta_id`. Una seña
 *    registrada directo en una venta nace aplicada.
 *  - **Pendiente** (`venta_id` y `aplicada_at` nulos): se registró en un lead que todavía no se
 *    promovió. Se aplica sola cuando la promoción crea la venta del contrato.
 *
 * @property int                 $id
 * @property int|null            $venta_id                Venta a la que se aplicó (null = pendiente).
 * @property int|null            $lead_id                 Lead en el que se registró, si fue ahí.
 * @property int|null            $client_id               Cliente de la venta (null = pendiente).
 * @property string              $monto                   Importe de la seña.
 * @property string              $moneda                  'USD' | 'ARS'.
 * @property \Carbon\Carbon      $fecha                   Cuándo la dejó el cliente.
 * @property string|null         $nota                    Nota libre.
 * @property int|null            $registrada_por_admin_id Admin que la registró.
 * @property string              $origen                  manual | claude.
 * @property array|null          $aplicaciones            [{cuota_id, monto}] a qué cuotas se aplicó.
 * @property \Carbon\Carbon|null $aplicada_at             Cuándo se aplicó a las cuotas.
 */
class Sena extends Model
{
    /** Registrada a mano desde el panel del admin. */
    const ORIGEN_MANUAL = 'manual';

    /** Pedida desde Claude/ChatGPT por `POST claude/ventas/{id}/senas` o `claude/leads/{id}/senas`. */
    const ORIGEN_CLAUDE = 'claude';

    /**
     * @var array<int, string>
     */
    const ORIGENES = [
        self::ORIGEN_MANUAL,
        self::ORIGEN_CLAUDE,
    ];

    /**
     * Se declara la tabla a mano: no se deja que Laravel la deduzca con su pluralizador en inglés,
     * que no está pensado para palabras en español.
     *
     * @var string
     */
    protected $table = 'senas';

    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'venta_id'                => 'integer',
        'lead_id'                 => 'integer',
        'client_id'               => 'integer',
        'monto'                   => 'decimal:2',
        'fecha'                   => 'date',
        'registrada_por_admin_id' => 'integer',
        'aplicaciones'            => 'array',
        'aplicada_at'             => 'datetime',
    ];

    /**
     * Scope requerido por convención del workspace (Controller::fullModel()).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return void
     */
    public function scopeWithAll($query)
    {
        $query->with('venta');
    }

    /**
     * Venta a la que se aplicó la seña (null mientras es de un lead sin promover).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function venta()
    {
        return $this->belongsTo(Venta::class, 'venta_id');
    }

    /**
     * ¿La seña ya está aplicada a las cuotas de una venta?
     *
     * @return bool
     */
    public function esta_aplicada(): bool
    {
        return $this->aplicada_at !== null;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una venta a un cliente (misión ventas-y-comisiones, 28/10/2026): el sistema de gestión, el
 * e-commerce, los agentes, un desarrollo a medida u otro motivo, con sus cuotas y la comisión del
 * vendedor que la cerró.
 *
 * Reemplaza, como unidad de negocio, a las "licencias" sueltas: las cuotas (`licencia_cuotas`)
 * siguen existiendo igual y ahora cuelgan de una venta por `venta_id`. La lógica (alta, edición,
 * baja, precio sugerido, porcentaje y vendedor por defecto, liberación de la comisión) vive en
 * `App\Services\VentaService`; los pagos al vendedor, en `App\Services\ComisionService`.
 *
 * 🔴 La comisión se libera a medida que paga el cliente (decisión de Lucas, 28/10/2026): lo que el
 * vendedor puede cobrar es `comision_monto × (cobrado de la venta / monto_total)`. La columna
 * `comision_monto` guarda la comisión TOTAL, no la liberada; la liberada se calcula siempre.
 *
 * @property int                 $id
 * @property int                 $client_id           Cliente.
 * @property int|null            $lead_id             Lead de origen, si se conoce.
 * @property array               $productos           Claves de self::PRODUCTOS.
 * @property string|null         $descripcion         Texto libre (para desarrollo y otro).
 * @property \Carbon\Carbon|null $fecha               Fecha de la venta.
 * @property string              $monto_total         Total de la venta.
 * @property string              $moneda              'USD' | 'ARS'.
 * @property string              $origen              contrato | manual | claude | planilla.
 * @property int|null            $vendedor_admin_id   Vendedor al que se le liquida la comisión.
 * @property string|null         $comision_porcentaje Porcentaje de comisión.
 * @property string|null         $comision_monto      Comisión total (monto_total × porcentaje / 100).
 * @property string|null         $observacion         Nota libre.
 * @property int|null            $created_by_admin_id Quién la dio de alta.
 */
class Venta extends Model
{
    /** Sistema de gestión (el ERP). */
    const PRODUCTO_GESTION = 'gestion';

    /** Tienda online. */
    const PRODUCTO_ECOMMERCE = 'ecommerce';

    /** Agentes de IA. */
    const PRODUCTO_AGENTES = 'agentes';

    /** Desarrollo a medida: importe libre. */
    const PRODUCTO_DESARROLLO = 'desarrollo';

    /** Cualquier otro motivo: importe libre. */
    const PRODUCTO_OTRO = 'otro';

    /**
     * Productos vendibles: clave → etiqueta que ve el admin. Es contrato con la SPA (las casillas
     * del formulario y los badges de la tabla salen de acá) y con lo guardado en `ventas.productos`:
     * agregar uno es sumar una entrada; renombrar una clave rompe las ventas ya cargadas.
     *
     * @var array<string, string>
     */
    const PRODUCTOS = [
        self::PRODUCTO_GESTION    => 'Sistema de gestión',
        self::PRODUCTO_ECOMMERCE  => 'E-commerce',
        self::PRODUCTO_AGENTES    => 'Agentes de IA',
        self::PRODUCTO_DESARROLLO => 'Desarrollo a medida',
        self::PRODUCTO_OTRO       => 'Otro',
    ];

    /**
     * Los tres sistemas de ComercioCity: son los que tienen precio de lista y los únicos que
     * pueden venir de `contract_sistemas`.
     *
     * @var array<int, string>
     */
    const SISTEMAS = [
        self::PRODUCTO_GESTION,
        self::PRODUCTO_ECOMMERCE,
        self::PRODUCTO_AGENTES,
    ];

    /** Nació de la promoción de un lead con contrato (`ClientContratoService`). */
    const ORIGEN_CONTRATO = 'contrato';

    /** Cargada a mano desde el admin. */
    const ORIGEN_MANUAL = 'manual';

    /** Pedida desde Claude/ChatGPT por `POST claude/clients/{id}/ventas`. */
    const ORIGEN_CLAUDE = 'claude';

    /** Agrupada desde cuotas importadas de la planilla (`ventas:migrar-licencias`). */
    const ORIGEN_PLANILLA = 'planilla';

    /**
     * @var array<int, string>
     */
    const ORIGENES = [
        self::ORIGEN_CONTRATO,
        self::ORIGEN_MANUAL,
        self::ORIGEN_CLAUDE,
        self::ORIGEN_PLANILLA,
    ];

    /** Comisión por defecto del vendedor, en porcentaje. */
    const COMISION_PORCENTAJE_DEFAULT = 15;

    /**
     * Comisión cuando se vende el sistema de gestión en modalidad Suscripción (contratos del
     * esquema del 13/10/2026), en porcentaje.
     */
    const COMISION_PORCENTAJE_SUSCRIPCION = 30;

    /**
     * Precios de lista precargados (política del 26/10/2026, `manual_sistema/general/
     * precios-y-contratacion.md`), en dólares. Todo es editable en cada venta: esto solo precarga.
     *
     * - `los_tres`: gestión + e-commerce + agentes juntos (en 3 cuotas si no es de contado).
     * - `contado_descuento`: porcentaje de descuento por pagar de contado.
     *
     * Desarrollo a medida y Otro no tienen precio: el importe es libre.
     *
     * @var array<string, int>
     */
    const PRECIOS = [
        self::PRODUCTO_GESTION   => 800,
        self::PRODUCTO_ECOMMERCE => 500,
        self::PRODUCTO_AGENTES   => 500,
        'los_tres'               => 1500,
        'contado_descuento'      => 10,
    ];

    /** Cuotas en que se reparten los tres sistemas juntos si no se pagan de contado. */
    const CUOTAS_LOS_TRES = 3;

    /**
     * @var string
     */
    protected $table = 'ventas';

    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'client_id'           => 'integer',
        'lead_id'             => 'integer',
        'productos'           => 'array',
        'fecha'               => 'date',
        'monto_total'         => 'decimal:2',
        'vendedor_admin_id'   => 'integer',
        'comision_porcentaje' => 'decimal:2',
        'comision_monto'      => 'decimal:2',
        'created_by_admin_id' => 'integer',
    ];

    /**
     * Scope requerido por convención del workspace (Controller::fullModel()).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return void
     */
    public function scopeWithAll($query)
    {
        $query->with(['client', 'cuotas', 'vendedor', 'comision_pagos']);
    }

    /**
     * Cliente al que se le vendió.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Cuotas de la venta, por número.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function cuotas()
    {
        return $this->hasMany(LicenciaCuota::class, 'venta_id')->orderBy('numero')->orderBy('id');
    }

    /**
     * Vendedor al que se le liquida la comisión.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function vendedor()
    {
        return $this->belongsTo(Admin::class, 'vendedor_admin_id');
    }

    /**
     * Pagos hechos al vendedor a cuenta de la comisión de esta venta, del más viejo al más nuevo.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function comision_pagos()
    {
        return $this->hasMany(ComisionPago::class, 'venta_id')->orderBy('fecha')->orderBy('id');
    }
}

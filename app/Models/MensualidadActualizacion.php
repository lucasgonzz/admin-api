<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una actualización de precios de la mensualidad de un cliente (misión modulo-cobranzas,
 * 18/9/2026): la foto de los cinco precios en una fecha, y si esa foto es la actualización
 * OFICIAL (la de IPC, la que cuenta para el contrato) o un retoque.
 *
 * El módulo de Cobranzas calcula "hace cuánto no se actualiza" y "cuándo vence la próxima" desde la
 * última fila con `es_oficial` = true de cada cliente. Ver `CobranzasMensualidadService::resumen_actualizacion()`.
 *
 * @property int         $client_id            Cliente.
 * @property int|null    $admin_id             Quién la registró (nulo si fue un comando).
 * @property string      $fecha                Desde cuándo rigen estos precios ('YYYY-MM-DD').
 * @property string      $precio_plan          Plan base.
 * @property string      $precio_por_cuenta    Precio por cuenta empleado.
 * @property string|null $precio_ecommerce     Precio del módulo ecommerce (nulo = cae a por cuenta).
 * @property string|null $precio_mercado_libre Precio del módulo Mercado Libre.
 * @property string|null $precio_tienda_nube   Precio del módulo Tienda Nube.
 * @property bool        $es_oficial           Si es la actualización oficial de la mensualidad.
 * @property string|null $observacion          Nota libre.
 */
class MensualidadActualizacion extends Model
{
    /**
     * Nombre explícito: Laravel pluralizaría "actualizacions".
     *
     * @var string
     */
    protected $table = 'mensualidad_actualizaciones';

    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'client_id'            => 'integer',
        'admin_id'             => 'integer',
        'fecha'                => 'date',
        'precio_plan'          => 'decimal:2',
        'precio_por_cuenta'    => 'decimal:2',
        'precio_ecommerce'     => 'decimal:2',
        'precio_mercado_libre' => 'decimal:2',
        'precio_tienda_nube'   => 'decimal:2',
        'es_oficial'           => 'boolean',
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
     * Cliente al que se le actualizaron los precios.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Admin que registró la actualización (nulo si la escribió un comando).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function admin()
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }
}

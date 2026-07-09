<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Configuración fiscal (AFIP) propia de ComercioCity.
 *
 * Es una config global de una sola fila (no hay relación con `clients`):
 * guarda los datos que hoy cada cliente carga en `AfipInformation` de su
 * empresa-api, pero acá una única vez para la empresa dueña del producto,
 * de forma que ComercioCity pueda emitir sus propias facturas (Factura C
 * como Monotributista, con el select preparado para pasar a Responsable
 * Inscripto a futuro).
 */
class ComerciocityAfipConfig extends Model
{
    /**
     * Nombre de la tabla en base de datos.
     *
     * @var string
     */
    protected $table = 'comerciocity_afip_config';

    /**
     * Todos los campos son asignables: es una config de una sola fila
     * administrada exclusivamente desde el panel de admin.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * Casteos de tipos para lectura y persistencia consistente.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'punto_venta' => 'integer',
        'afip_produccion' => 'boolean',
        'inicio_actividades' => 'date',
    ];

    /**
     * Devuelve la única fila de configuración fiscal de ComercioCity,
     * creándola con valores por defecto si todavía no existe.
     *
     * Pensado para ser usado desde los servicios AFIP que necesiten
     * los datos fiscales sin tener que preocuparse por si la fila existe.
     *
     * @return self
     */
    public static function current(): self
    {
        return static::firstOrCreate([], [
            'condicion_iva' => 'Monotributista',
            'afip_produccion' => false,
        ]);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un paquete de suscripción de IA de ComercioCity (misión foto-sucursal-y-asistente-configurable,
 * 17/9/2026): un precio en dólares y los dos topes mensuales/diarios que viajan a la instancia de
 * cada cliente que lo tenga asignado.
 *
 * Ver la migración `create_ai_plans_table` para por qué los topes son nullable (null = sin tope, no
 * corta) y por qué `activo` es baja lógica.
 *
 * @property int         $id
 * @property string      $nombre                     Nombre del paquete (Básico, Intermedio, Pro).
 * @property float       $precio_usd                 Precio mensual en dólares.
 * @property int|null    $tope_tokens_mensual        Tope de tokens del mes; null/0 = sin tope.
 * @property int|null    $tope_interacciones_diarias Tope de interacciones diarias; null/0 = sin tope.
 * @property bool        $activo                     Si se ofrece en el ABM (baja lógica).
 * @property int         $orden                      Orden de presentación.
 */
class AiPlan extends Model
{
    /**
     * Todas las filas las escribe el ABM del admin, validadas por el controlador: no hay entrada de
     * afuera de la que protegerse con una lista blanca acá.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * Los topes viajan como enteros (o null) para que la comparación con el consumo del lado de
     * empresa sea aritmética y no de strings. El precio como decimal para no arrastrar floats sucios.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'precio_usd'                 => 'decimal:2',
        'tope_tokens_mensual'        => 'integer',
        'tope_interacciones_diarias' => 'integer',
        'activo'                     => 'boolean',
        'orden'                      => 'integer',
    ];

    /**
     * Los clientes que tienen este paquete asignado.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function clients()
    {
        return $this->hasMany(Client::class, 'ai_plan_id');
    }
}

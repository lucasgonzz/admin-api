<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Catálogo de métodos de pago disponibles en el formulario de configuración de implementación.
 *
 * Tabla de referencia estática que lista los métodos de pago (Efectivo, Débito, Crédito, Transferencia, Cheque, Mercado Pago)
 * que se exponen como opciones en el select "Método de pago" del formulario público.
 *
 * La `key` (ej: 'efectivo', 'debito') es el valor estable que se guarda en las respuestas del formulario
 * y mapea directamente al método de pago creado por defecto en el UserSetup de empresa-api.
 * El `label` es el texto visible en la UI (ej: 'Efectivo', 'Débito').
 *
 * @property int    $id       Clave primaria.
 * @property string $key      Valor estable sin tildes ni mayúsculas (ej: 'efectivo', 'mercado_pago').
 * @property string $label    Texto visible con formato (ej: 'Efectivo', 'Mercado Pago').
 * @property int    $position Orden de aparición en el select.
 */
class ImplementationPaymentMethodOption extends Model
{
    /**
     * Campos asignables en mass assignment.
     *
     * @var array<int, string>
     */
    protected $fillable = ['key', 'label', 'position'];

    /**
     * Casteos de atributos.
     * La posición es integer; key y label son strings simples.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'position' => 'integer',
    ];

    /**
     * Scope para cargar relaciones (si las hay en futuro).
     * Por ahora vacío pero requerido por el patrón de base_controller.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithAll($query)
    {
        // Sin relaciones por ahora; modelo de referencia estática.
        return $query;
    }
}

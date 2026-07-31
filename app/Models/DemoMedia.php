<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * URL de una pieza multimedia de la demo, keyeada por `slot_id` (grupo 300, prompt 02).
 *
 * La estructura de los slots (qué clips existen, orden, tipo) vive en el catálogo del repo
 * (`contexto/demo_catalogo.json`, ver {@see \App\Services\DemoCatalogoService}); acá solo se
 * persiste el link cargado desde el admin. Una fila ausente para un `slot_id` significa "sin
 * cargar" (se muestra el placeholder), no un error.
 */
class DemoMedia extends Model
{
    /** Asignación masiva abierta: tabla interna controlada por el backend. */
    protected $guarded = [];

    /**
     * Scope estándar para contrato homogéneo con fullModel (regla admin-api).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     */
    public function scopeWithAll($query)
    {
        // Sin relaciones propias: la tabla es un mapa plano slot_id => url.
    }

    /**
     * Devuelve un mapa `slot_id => url` con todas las filas que tienen URL cargada.
     *
     * Una sola consulta: la usa la página inmersiva para armar su payload y no puede
     * disparar una query por cada uno de los 43 slots del catálogo.
     *
     * @return array<string, string>
     */
    public static function url_por_slot(): array
    {
        return self::query()
            ->whereNotNull('url')
            ->where('url', '!=', '')
            ->pluck('url', 'slot_id')
            ->all();
    }
}

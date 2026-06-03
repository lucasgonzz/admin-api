<?php

namespace App\Models;

use App\ModelProperties\DemoProperties;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * Catálogo de demos disponibles para asignar a leads.
 */
class Demo extends Model
{
    use HasUuid;

    /**
     * Definición declarativa consumida por admin-spa/meta.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function properties()
    {
        return DemoProperties::all();
    }

    protected $guarded = [];

    /**
     * Scope estándar para mantener contrato homogéneo con BaseController/fullModel.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return void
     */
    public function scopeWithAll($query)
    {
        // Este recurso no requiere relaciones eager por ahora.
    }
}

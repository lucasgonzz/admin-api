<?php

namespace App\Models;

use App\ModelProperties\ClientEmployeeProperties;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * Empleado o contacto operativo de un cliente (soporte vía WhatsApp).
 *
 * @property int         $client_id Cliente dueño del registro.
 * @property string      $name      Nombre visible en bandeja de soporte.
 * @property string      $phone     Teléfono de contacto (formato libre).
 * @property string|null $notes     Notas internas opcionales.
 * @property int|null    $empresa_employee_id Id del User empleado en empresa-api cuando proviene de sincronización.
 * @property bool        $can_query_system Habilita el canal "sistema:" de WhatsApp para este empleado.
 */
class ClientEmployee extends Model
{
    use HasUuid;

    /**
     * Meta declarativa consumida por admin-spa (MetaController).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function properties()
    {
        return ClientEmployeeProperties::all();
    }

    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * Casts de atributos.
     *
     * @var array<string, string>
     */
    protected $casts = [
        // Permiso para usar el canal "sistema:" de WhatsApp (consultas al sistema del cliente).
        'can_query_system' => 'boolean',
    ];

    /**
     * Eager load del cliente asociado.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithAll($query)
    {
        $query->with('client');
    }

    /**
     * Cliente dueño de este empleado.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}

<?php

namespace App\Models;

use App\ModelProperties\DemoUpdateProperties;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * Representa un proceso de actualización (pipeline SPA + API) ejecutado
 * sobre una demo en hosting compartido, disparado desde admin-spa.
 */
class DemoUpdate extends Model
{
    use HasUuid;

    /**
     * Definición declarativa del recurso, consumida por admin-spa/meta.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function properties()
    {
        return DemoUpdateProperties::all();
    }

    /**
     * Sin guarded: todos los campos son mass-assignable.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * Casteos de atributos: fechas de inicio/fin tratadas como Carbon.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
    ];

    /**
     * Demo a la que pertenece este proceso de actualización.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function demo()
    {
        return $this->belongsTo(Demo::class);
    }

    /**
     * Versión destino a la que se actualiza la demo.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function version()
    {
        return $this->belongsTo(Version::class);
    }

    /**
     * Admin que inició el proceso (nullable: puede ser nulo si fue automático).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function created_by_admin()
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }

    /**
     * Scope de eager loading estándar para listados y detalle en admin-spa.
     * Carga demo, version y admin creador en una sola consulta.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return void
     */
    public function scopeWithAll($query)
    {
        $query->with(['demo', 'version', 'created_by_admin']);
    }
}

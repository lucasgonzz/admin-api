<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * Un rango horario dentro de un día del horario comercial de un cliente.
 *
 * Un día puede tener varios (ej. 8:00–13:00 y 16:00–21:00). Cero rangos = ese día está cerrado.
 *
 * 🔴 end_time siempre es posterior a start_time: un rango NO cruza la medianoche. Un negocio que
 * cierra a medianoche o después se carga con end_time = '23:59'.
 *
 * @property int    $client_schedule_day_id Día al que pertenece el rango.
 * @property string $start_time             Hora de apertura (formato de columna `time`).
 * @property string $end_time               Hora de cierre (formato de columna `time`).
 * @property int    $sort_order             Orden de presentación, por start_time ascendente.
 */
class ClientScheduleRange extends Model
{
    use HasUuid;

    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * Día del horario al que pertenece este rango.
     *
     * ⚠️ La clave foránea va explícita: por el nombre del método (schedule_day) Laravel buscaría
     * `schedule_day_id`, que no existe.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function schedule_day()
    {
        return $this->belongsTo(ClientScheduleDay::class, 'client_schedule_day_id');
    }
}

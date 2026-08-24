<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * Un día del horario comercial de un cliente.
 *
 * La fila del día existe por sí misma: tener cero rangos NO es lo mismo que no tener fila.
 *
 * - Fila del día con rangos     → ese día rige ese horario.
 * - Fila del día SIN rangos     → ese día el negocio está cerrado.
 * - Sin fila del día            → rige la fila 'todos' si existe; si tampoco existe, el día está
 *                                 SIN CONFIGURAR (que no es lo mismo que cerrado).
 *
 * @property int    $client_id Cliente dueño de la fila.
 * @property string $day_key   Una de DAY_KEYS.
 */
class ClientScheduleDay extends Model
{
    use HasUuid;

    /**
     * Enumeración completa de días válidos.
     *
     * 🔴 Sin acentos y sin espacios: es un contrato entre cliente y servidor y se declara UNA sola
     * vez acá. La SPA no lo hardcodea, lo pide por API.
     *
     * @var array<int, string>
     */
    const DAY_KEYS = [
        'todos',
        'lunes',
        'martes',
        'miercoles',
        'jueves',
        'viernes',
        'sabado',
        'domingo',
    ];

    /**
     * Día de la semana según su número de Carbon::dayOfWeek (0 = domingo).
     *
     * ⚠️ Indexado a propósito igual que CloserAgendaService::NOMBRES_DIA, que es el precedente de
     * la casa. No inventar otro orden.
     *
     * @var array<int, string>
     */
    const DAY_KEYS_BY_DOW = ['domingo', 'lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado'];

    /**
     * Etiquetas visibles (con acentos) para cada clave.
     *
     * @var array<string, string>
     */
    const DAY_LABELS = [
        'todos'     => 'Todos los días',
        'lunes'     => 'Lunes',
        'martes'    => 'Martes',
        'miercoles' => 'Miércoles',
        'jueves'    => 'Jueves',
        'viernes'   => 'Viernes',
        'sabado'    => 'Sábado',
        'domingo'   => 'Domingo',
    ];

    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * Etiqueta visible de una clave de día, o la clave misma si no se conoce.
     *
     * @param string $day_key Clave de día (una de DAY_KEYS).
     *
     * @return string
     */
    public static function label_for($day_key)
    {
        $day_key = (string) $day_key;

        return isset(self::DAY_LABELS[$day_key]) ? self::DAY_LABELS[$day_key] : $day_key;
    }

    /**
     * Enumeración de días lista para viajar por API (clave + etiqueta), en orden de presentación.
     *
     * @return array<int, array<string, string>>
     */
    public static function day_keys_payload()
    {
        $payload = [];
        foreach (self::DAY_KEYS as $day_key) {
            $payload[] = ['key' => $day_key, 'label' => self::label_for($day_key)];
        }

        return $payload;
    }

    /**
     * Cliente dueño de este día.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Rangos horarios de este día. Cero rangos = día cerrado.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function schedule_ranges()
    {
        return $this->hasMany(ClientScheduleRange::class)->orderBy('start_time', 'asc');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * La grabación de una llamada de Juan con un lead (misión leads-referidos-compromisos, etapa E3).
 *
 * La lógica vive en {@see \App\Services\LeadLlamadaGrabacionService} (subida por partes) y en
 * {@see \App\Services\TranscripcionDeLlamadaService} (transcripción y resumen); el modelo sólo guarda los datos y sus constantes.
 * El archivo está en el disco privado `local`, carpeta `lead_llamadas/<lead_id>/<id>/`, y no se borra nunca por tiempo (decisión de
 * Lucas, 25/11/2026): sólo se borra junto con el lead.
 *
 * @property int                 $id
 * @property int                 $lead_llamada_id
 * @property int                 $lead_id
 * @property string              $nombre_original
 * @property string              $extension           En minúsculas.
 * @property string|null         $mime
 * @property int                 $bytes_declarados
 * @property int                 $bytes_recibidos
 * @property string|null         $partes_recibidas    JSON `{indice: bytes}`.
 * @property string|null         $sha256
 * @property string|null         $ruta                Ruta relativa al disco `local` de la grabación ya unida.
 * @property string              $status              Uno de {@see self::STATUSES}.
 * @property string|null         $error_text
 * @property int|null            $duracion_segundos
 * @property int|null            $tramos
 * @property int                 $intento             Número de intento de transcripción (sube con cada «pendiente»).
 * @property string|null         $modelo
 * @property string|null         $transcripcion
 * @property string|null         $resumen
 * @property string|null         $compromiso_propuesto
 * @property int|null            $compromiso_id
 * @property \Carbon\Carbon|null $started_at
 * @property \Carbon\Carbon|null $finished_at
 * @property int|null            $created_by_admin_id
 */
class LeadLlamadaGrabacion extends Model
{
    /** Status: se está subiendo (faltan partes o falta «completar»). */
    const STATUS_SUBIENDO = 'subiendo';

    /** Status: el archivo está completo y espera su turno en la cola `transcripciones`. */
    const STATUS_PENDIENTE = 'pendiente';

    /** Status: el worker la está transcribiendo. */
    const STATUS_TRANSCRIBIENDO = 'transcribiendo';

    /** Status: transcripta (y resumida, si la IA contestó). */
    const STATUS_LISTA = 'lista';

    /** Status: algo falló; `error_text` dice qué y «Reintentar» la vuelve a poner en cola. */
    const STATUS_ERROR = 'error';

    /** Todos los status. */
    const STATUSES = [
        self::STATUS_SUBIENDO, self::STATUS_PENDIENTE, self::STATUS_TRANSCRIBIENDO, self::STATUS_LISTA, self::STATUS_ERROR,
    ];

    /** Cola de `database` donde corre la transcripción (tiene su propio worker en el scheduler). */
    const COLA = 'transcripciones';

    /**
     * @var string
     */
    protected $table = 'lead_llamada_grabaciones';

    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'lead_llamada_id'     => 'integer',
        'lead_id'             => 'integer',
        'bytes_declarados'    => 'integer',
        'bytes_recibidos'     => 'integer',
        'duracion_segundos'   => 'integer',
        'tramos'              => 'integer',
        'intento'             => 'integer',
        'compromiso_id'       => 'integer',
        'started_at'          => 'datetime',
        'finished_at'         => 'datetime',
        'created_by_admin_id' => 'integer',
    ];

    /**
     * Fechas serializadas como hora local `Y-m-d H:i:s` (nunca ISO con `Z`: es la trampa de las tres horas).
     *
     * @param \DateTimeInterface $date
     *
     * @return string
     */
    protected function serializeDate(\DateTimeInterface $date)
    {
        return $date->format('Y-m-d H:i:s');
    }

    /**
     * Scope requerido por convención del workspace (`Controller::fullModel()`); este modelo no expone relaciones.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return void
     */
    public function scopeWithAll($query)
    {
    }

    /**
     * La llamada a la que pertenece la grabación.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function llamada()
    {
        return $this->belongsTo(LeadLlamada::class, 'lead_llamada_id');
    }

    /**
     * Las partes que ya llegaron: `indice => bytes`.
     *
     * @return array<int, int>
     */
    public function partes(): array
    {
        $decodificado = json_decode((string) $this->partes_recibidas, true);
        $partes       = [];
        if (is_array($decodificado)) {
            foreach ($decodificado as $indice => $bytes) {
                $partes[(int) $indice] = (int) $bytes;
            }
        }

        return $partes;
    }

    /**
     * Carpeta de la grabación dentro del disco `local` (partes y archivo unido).
     *
     * @return string
     */
    public function carpeta(): string
    {
        return self::carpeta_de((int) $this->lead_id, (int) $this->id);
    }

    /**
     * Carpeta de una grabación dentro del disco `local`.
     *
     * @param int $lead_id
     * @param int $grabacion_id
     *
     * @return string
     */
    public static function carpeta_de(int $lead_id, int $grabacion_id): string
    {
        return 'lead_llamadas/' . $lead_id . '/' . $grabacion_id;
    }
}

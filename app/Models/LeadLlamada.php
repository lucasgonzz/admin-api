<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una llamada de Juan (el vendedor) con un lead, registrada desde el tablero (misión embudo-juan-pipeline, E1 y E1b).
 *
 * @property int                 $id
 * @property int                 $lead_id
 * @property int|null            $opportunity_id
 * @property int|null            $admin_id             Quién hizo la llamada.
 * @property bool                $atendio
 * @property string|null         $resultado            Clave de {@see self::RESULTADOS}.
 * @property string|null         $motivo
 * @property string|null         $cotizado
 * @property string|null         $notas
 * @property \Carbon\Carbon|null $proxima_llamada_at
 * @property string              $origen               `manual` o `audio_juan`.
 * @property int|null            $cargada_por_admin_id Quién la cargó (el agente en `audio_juan`).
 * @property string|null         $origen_clave
 * @property string|null         $transcripcion
 */
class LeadLlamada extends Model
{
    /** El resultado «sigue solo con la prueba». */
    const RESULTADO_SIGUE_SOLO = 'sigue_solo';

    /** El resultado «implementación acordada». */
    const RESULTADO_IMPLEMENTACION_ACORDADA = 'implementacion_acordada';

    /** El resultado «pagó la mitad». */
    const RESULTADO_PAGO_LA_MITAD = 'pago_la_mitad';

    /** El resultado «volver a llamar». */
    const RESULTADO_VOLVER_A_LLAMAR = 'volver_a_llamar';

    /** El resultado «no le interesa». */
    const RESULTADO_NO_LE_INTERESA = 'no_le_interesa';

    /** El resultado «ya contrató». */
    const RESULTADO_YA_CONTRATO = 'ya_contrato';

    /** Origen: cargada a mano desde el modal. */
    const ORIGEN_MANUAL = 'manual';

    /** Origen: cargada por el agente desde un audio de Juan. */
    const ORIGEN_AUDIO_DE_JUAN = 'audio_juan';

    /**
     * Clave => etiqueta de cada resultado posible de una llamada atendida.
     *
     * @var array<string, string>
     */
    const RESULTADOS = [
        self::RESULTADO_SIGUE_SOLO              => 'Sigue solo con la prueba',
        self::RESULTADO_IMPLEMENTACION_ACORDADA => 'Implementación acordada',
        self::RESULTADO_PAGO_LA_MITAD           => 'Pagó la mitad',
        self::RESULTADO_VOLVER_A_LLAMAR         => 'Volver a llamar',
        self::RESULTADO_NO_LE_INTERESA          => 'No le interesa',
        self::RESULTADO_YA_CONTRATO             => 'Ya contrató',
    ];

    /**
     * @var string
     */
    protected $table = 'lead_llamadas';

    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'lead_id'              => 'integer',
        'opportunity_id'       => 'integer',
        'admin_id'             => 'integer',
        'cargada_por_admin_id' => 'integer',
        'atendio'              => 'boolean',
        'proxima_llamada_at'   => 'datetime',
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
     * Scope requerido por convención del workspace (`Controller::fullModel()`).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return void
     */
    public function scopeWithAll($query)
    {
        $query->with(['admin', 'cargada_por']);
    }

    /**
     * El admin que hizo la llamada.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function admin()
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }

    /**
     * El admin que la cargó (el agente, en `audio_juan`).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function cargada_por()
    {
        return $this->belongsTo(Admin::class, 'cargada_por_admin_id');
    }

    /**
     * La etiqueta legible del resultado (null si no hay).
     *
     * @return string|null
     */
    public function resultado_label()
    {
        return $this->resultado !== null && isset(self::RESULTADOS[$this->resultado]) ? self::RESULTADOS[$this->resultado] : null;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * El seguimiento proactivo de la implementación de sistema de un cliente (misión cruzada
 * `seguimiento-de-implementaciones`, 11/11/2026). Uno por cliente.
 *
 * Martín (el soporte de Codex, por el monitor) le escribe al cliente cada `interval_business_days` días hábiles
 * para avanzar al próximo paso. El reloj (`next_due_at`) se reinicia cada vez que el cliente escribe; tras
 * `max_unanswered` seguimientos sin respuesta se frena y se le avisa a Lucas. Sigue vivo («puesta en marcha»)
 * hasta que los hitos obligatorios de uso están cumplidos (o marcados «no aplica»), aunque las 7 etapas ya estén
 * completas. Las reglas viven en `App\Services\ImplementationFollowups\ImplementationFollowupService`.
 *
 * @property int                         $id
 * @property int                         $client_id
 * @property int|null                    $implementation_id
 * @property string                      $status                 activo | pausado | frenado | terminado.
 * @property int                         $interval_business_days
 * @property int                         $max_unanswered
 * @property int                         $unanswered_count
 * @property \Carbon\Carbon|null         $next_due_at
 * @property \Carbon\Carbon|null         $paused_until
 * @property string|null                 $pause_reason
 * @property \Carbon\Carbon|null         $last_sent_at
 * @property \Carbon\Carbon|null         $last_client_contact_at
 * @property array<string, mixed>|null   $hitos
 * @property \Carbon\Carbon|null         $hitos_at
 * @property array<int, string>|null     $hitos_no_aplican
 * @property string|null                 $lease_token            sha256 del token de la reserva.
 * @property \Carbon\Carbon|null         $lease_expires_at
 * @property \Carbon\Carbon|null         $lease_claimed_at
 * @property string|null                 $run_id
 */
class ImplementationFollowup extends Model
{
    /** Martín escribe cuando vence. */
    const STATUS_ACTIVO = 'activo';

    /** El cliente pidió tiempo: no se escribe hasta `paused_until`. */
    const STATUS_PAUSADO = 'pausado';

    /** Llegó a `max_unanswered` sin respuesta: no se escribe más hasta que el cliente conteste o Lucas lo reactive. */
    const STATUS_FRENADO = 'frenado';

    /** Implementación cerrada y negocio operando (o lo cerró Lucas). */
    const STATUS_TERMINADO = 'terminado';

    /** Los estados posibles. */
    const STATUSES = [self::STATUS_ACTIVO, self::STATUS_PAUSADO, self::STATUS_FRENADO, self::STATUS_TERMINADO];

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'client_id',
        'implementation_id',
        'status',
        'interval_business_days',
        'max_unanswered',
        'unanswered_count',
        'next_due_at',
        'paused_until',
        'pause_reason',
        'last_sent_at',
        'last_client_contact_at',
        'hitos',
        'hitos_at',
        'hitos_no_aplican',
    ];

    /**
     * El token de la reserva no sale nunca serializado.
     *
     * @var array<int, string>
     */
    protected $hidden = ['lease_token'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'client_id'              => 'integer',
        'implementation_id'      => 'integer',
        'interval_business_days' => 'integer',
        'max_unanswered'         => 'integer',
        'unanswered_count'       => 'integer',
        'next_due_at'            => 'datetime',
        'paused_until'           => 'date',
        'last_sent_at'           => 'datetime',
        'last_client_contact_at' => 'datetime',
        'hitos'                  => 'array',
        'hitos_at'               => 'datetime',
        'hitos_no_aplican'       => 'array',
        'lease_expires_at'       => 'datetime',
        'lease_claimed_at'       => 'datetime',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function implementation()
    {
        return $this->belongsTo(Implementation::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function events()
    {
        return $this->hasMany(ImplementationFollowupEvent::class);
    }

    /**
     * Si hay una reserva del monitor vigente.
     *
     * @return bool
     */
    public function reserva_vigente()
    {
        return $this->lease_token !== null && $this->lease_expires_at !== null && $this->lease_expires_at->isFuture();
    }
}

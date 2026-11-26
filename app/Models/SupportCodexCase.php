<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Estado durable de atención; no depende de que la PC permanezca prendida. */
class SupportCodexCase extends Model
{
    protected static function booted()
    {
        static::saved(function (self $case) { SupportCodexMission::capture($case); });
    }

    public function missions()
    {
        return $this->hasMany(SupportCodexMission::class, 'support_ticket_id', 'support_ticket_id')->orderBy('id');
    }

    const HUMAN_REPLY_REVIEW_REASON = 'Lucas retomó la atención desde el admin.';

    /**
     * Comienzo del motivo con el que el monitor pone en revisión un ticket tras dos turnos de Martín sin respuesta
     * registrada (`soporte.py` `MOTIVO_TURNOS_VACIOS`). Misión martin-descripcion-y-actualizaciones (25/11/2026):
     * esa revisión se reabre cuando el cliente vuelve a escribir; antes quedaba trabada en rojo.
     */
    const EMPTY_TURNS_REVIEW_PREFIX = 'Martín terminó dos turnos seguidos sin registrar respuesta';

    protected $guarded = [];

    protected $hidden = ['lease_hash'];

    protected $casts = [
        'lease_expires_at' => 'datetime',
        'confirmation_due_at' => 'datetime',
        'mission_started_at' => 'datetime',
        'follow_up_sent' => 'boolean',
        // Carril «no necesita respuesta» (contrato C1): el último entrante atendido sin responder.
        'no_reply_message_id' => 'integer',
    ];

    /** El inicio es un hecho registrado, no se deduce de que exista un diagnóstico. */
    public function mission_lifecycle(): ?array
    {
        if (trim((string) $this->mission) === '') {
            return null;
        }
        $started = $this->mission_thread_id !== null && $this->mission_started_at !== null;
        return [
            'status' => $started ? 'started' : 'pending',
            'thread_id' => $started ? $this->mission_thread_id : null,
            'started_at' => $started ? $this->mission_started_at->toIso8601String() : null,
        ];
    }

    /** La cesión de un turno ya contestado no es un error que Lucas deba resolver. */
    public function is_human_reply_pause(): bool
    {
        return $this->review_requested_message_id === null && $this->state === 'manual_review'
            && $this->review_reason === self::HUMAN_REPLY_REVIEW_REASON;
    }

    /**
     * Revisión puesta por el monitor tras dos turnos vacíos: no es un bloqueo técnico, así que un entrante nuevo
     * del cliente la vuelve a ofrecer a Martín.
     *
     * @return bool
     */
    public function is_empty_turns_review(): bool
    {
        return $this->state === 'manual_review'
            && strncmp((string) $this->review_reason, self::EMPTY_TURNS_REVIEW_PREFIX, strlen(self::EMPTY_TURNS_REVIEW_PREFIX)) === 0;
    }

    /** La respuesta del cliente puede despejar un pedido del agente, nunca un bloqueo técnico. */
    public function is_agent_requested_review(): bool
    {
        return $this->review_requested_message_id !== null && trim((string) $this->review_reason) !== '';
    }
}

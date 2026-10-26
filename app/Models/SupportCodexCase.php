<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Estado durable de atención; no depende de que la PC permanezca prendida. */
class SupportCodexCase extends Model
{
    const HUMAN_REPLY_REVIEW_REASON = 'Lucas retomó la atención desde el admin.';

    protected $guarded = [];

    protected $hidden = ['lease_hash'];

    protected $casts = [
        'lease_expires_at' => 'datetime',
        'confirmation_due_at' => 'datetime',
        'mission_started_at' => 'datetime',
        'follow_up_sent' => 'boolean',
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
        return $this->state === 'manual_review' && $this->review_reason === self::HUMAN_REPLY_REVIEW_REASON;
    }
}

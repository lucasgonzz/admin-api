<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Marca manual de "no leído" por admin sobre un lead (estilo WhatsApp).
 *
 * La existencia de una fila implica que ese admin marcó manualmente el lead como no leído.
 * Se borra automáticamente al volver a abrir la conversación (ver
 * LeadController::mark_whatsapp_messages_read_json) o al volver a togglear desde la grilla
 * (LeadController::toggle_manual_unread_json).
 */
class LeadManualUnreadMark extends Model
{
    /** La tabla solo guarda `marked_at`; no usa created_at/updated_at. */
    public $timestamps = false;

    /** Asignación masiva abierta: la tabla es interna y controlada por el backend. */
    protected $guarded = [];

    /**
     * Casts de la marca temporal.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'marked_at' => 'datetime',
    ];

    /**
     * Scope estándar para contrato homogéneo con fullModel (regla admin-api).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     */
    public function scopeWithAll($query) {}
}

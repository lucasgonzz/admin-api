<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;

/**
 * Archivo multimedia persistido para un mensaje de lead (audio, documento, imagen, etc.).
 */
class LeadMessageAttachment extends Model
{
    /**
     * Campos asignables al crear adjunto desde WhatsappInboundMediaService.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * URL firmada y nombre legible expuestos al serializar en la API.
     *
     * @var array<int, string>
     */
    protected $appends = ['public_url', 'display_filename'];

    /**
     * Scope estándar de compatibilidad con fullModel.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     */
    public function scopeWithAll($query)
    {
        $query->with('message');
    }

    /**
     * Mensaje padre del adjunto.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function message()
    {
        return $this->belongsTo(LeadMessage::class, 'lead_message_id');
    }

    /**
     * URL firmada para abrir el adjunto en nueva pestaña sin depender del symlink /storage.
     *
     * @return string|null
     */
    public function getPublicUrlAttribute(): ?string
    {
        $path = trim((string) ($this->path ?? ''));
        if ($path === '' || ! $this->id) {
            return null;
        }

        return URL::temporarySignedRoute(
            'lead.message.attachment.file',
            now()->addHours(24),
            ['id' => $this->id]
        );
    }

    /**
     * Nombre de archivo legible derivado del path persistido en disco public.
     *
     * @return string|null
     */
    public function getDisplayFilenameAttribute(): ?string
    {
        $path = trim((string) ($this->path ?? ''));
        if ($path === '') {
            return null;
        }

        $basename = basename($path);

        return $basename !== '' ? $basename : null;
    }
}

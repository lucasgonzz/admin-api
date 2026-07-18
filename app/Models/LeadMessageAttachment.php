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
    protected $appends = ['public_url', 'display_filename', 'download_url'];

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

        // Expiración anclada al inicio del día calendario (no a "ahora"): todas las URLs
        // firmadas generadas para este adjunto durante el mismo día quedan IDÉNTICAS (mismo
        // timestamp de expiración -> misma firma -> mismo string de URL). Antes usaba
        // now()->addHours(24), que generaba un expires distinto en cada serialización (a
        // veces con 1 segundo de diferencia) y el navegador interpretaba cada URL como una
        // imagen nueva, re-descargándola en cada refetch/polling/evento de Pusher — causa
        // del "too many attempts" reportado por Lucas (17/7/2026).
        return URL::temporarySignedRoute(
            'lead.message.attachment.file',
            now()->startOfDay()->addDays(2),
            ['id' => $this->id]
        );
    }

    /**
     * Nombre de archivo legible: prioriza el nombre real que mandó el lead (original_filename);
     * si no vino en el webhook, cae al basename del path generado en disco (comportamiento previo).
     *
     * @return string|null
     */
    public function getDisplayFilenameAttribute(): ?string
    {
        // Nombre original persistido desde el webhook Kapso (prompt 464); es el que preferimos
        // mostrar/descargar porque es el que el lead reconoce.
        $original = trim((string) ($this->original_filename ?? ''));
        if ($original !== '') {
            return $original;
        }

        $path = trim((string) ($this->path ?? ''));
        if ($path === '') {
            return null;
        }

        $basename = basename($path);

        return $basename !== '' ? $basename : null;
    }

    /**
     * URL firmada que fuerza la descarga (Content-Disposition: attachment) con el nombre real
     * del archivo, agregando el parámetro disposition=attachment a la misma ruta firmada que
     * public_url (que se mantiene sin ese parámetro para el modo inline).
     *
     * @return string|null
     */
    public function getDownloadUrlAttribute(): ?string
    {
        $path = trim((string) ($this->path ?? ''));
        if ($path === '' || ! $this->id) {
            return null;
        }

        return URL::temporarySignedRoute(
            'lead.message.attachment.file',
            now()->addHours(24),
            ['id' => $this->id, 'disposition' => 'attachment']
        );
    }
}

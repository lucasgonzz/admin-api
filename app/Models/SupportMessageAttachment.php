<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;

class SupportMessageAttachment extends Model
{
    /**
     * Campos asignables para crear adjuntos de soporte.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * Campos calculados que viajan en cada `attachments[]` de la API (todos opcionales para el SPA:
     * si falta alguno, el SPA viejo sigue armando `/storage/<path>` como antes).
     *
     * @var array<int, string>
     */
    protected $appends = ['public_url', 'download_url', 'playback_url', 'display_filename'];

    /**
     * Scope estándar de compatibilidad con fullModel.
     */
    public function scopeWithAll($query)
    {
        $query->with('message');
    }

    /**
     * Mensaje al que pertenece el adjunto.
     */
    public function message()
    {
        return $this->belongsTo(SupportMessage::class, 'support_message_id');
    }

    /**
     * URL firmada para ver el adjunto inline sin depender del symlink /storage.
     *
     * @return string|null
     */
    public function getPublicUrlAttribute(): ?string
    {
        return $this->build_signed_file_url([]);
    }

    /**
     * URL firmada que fuerza la descarga (Content-Disposition: attachment).
     *
     * @return string|null
     */
    public function getDownloadUrlAttribute(): ?string
    {
        return $this->build_signed_file_url(['disposition' => 'attachment']);
    }

    /**
     * URL firmada para reproducir un audio: pide la copia compatible con Safari/iOS si el servidor
     * puede generarla. Es null cuando el adjunto no es un audio.
     *
     * @return string|null
     */
    public function getPlaybackUrlAttribute(): ?string
    {
        if (! $this->is_audio()) {
            return null;
        }

        return $this->build_signed_file_url(['playable' => 1]);
    }

    /**
     * Nombre de archivo legible: el basename del path guardado.
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

    /**
     * Indica si el adjunto es un audio, por mime y, si el mime falta o es genérico, por extensión.
     *
     * @return bool
     */
    public function is_audio(): bool
    {
        // Mime guardado, sin parámetros y en minúsculas (`audio/ogg; codecs=opus` → `audio/ogg`).
        $mime = strtolower(trim((string) strtok((string) ($this->mime ?? ''), ';')));
        if (strpos($mime, 'audio/') === 0) {
            return true;
        }

        // Filas viejas sin mime útil: se mira la extensión del archivo guardado.
        $is_generic = $mime === '' || $mime === 'application/octet-stream';
        $extension = strtolower(pathinfo((string) ($this->path ?? ''), PATHINFO_EXTENSION));

        return $is_generic && in_array($extension, ['ogg', 'oga', 'opus', 'mp3', 'm4a', 'aac', 'amr', 'wav'], true);
    }

    /**
     * Arma la URL firmada de la ruta de archivo con parámetros extra (que viajan dentro de la firma).
     *
     * La expiración se ancla al inicio del día (misma URL durante todo el día): si cambiara en cada
     * serialización, el navegador tomaría cada URL como un archivo nuevo y lo re-descargaría con cada
     * refetch o evento de Pusher (mismo motivo que en LeadMessageAttachment).
     *
     * @param array<string, mixed> $extra_parameters Parámetros adicionales de la query.
     *
     * @return string|null null si el adjunto no tiene id o path.
     */
    private function build_signed_file_url(array $extra_parameters): ?string
    {
        $path = trim((string) ($this->path ?? ''));
        if ($path === '' || ! $this->id) {
            return null;
        }

        return URL::temporarySignedRoute(
            'support.message.attachment.file',
            now()->startOfDay()->addDays(2),
            array_merge(['id' => $this->id], $extra_parameters)
        );
    }
}

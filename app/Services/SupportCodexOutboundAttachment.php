<?php

namespace App\Services;

use App\Models\SupportMessage;
use App\Models\SupportMessageAttachment;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class SupportCodexOutboundAttachment
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const MAX_ENCODED_LENGTH = 6990508;
    public const MAX_CAPTION_LENGTH = 1024;

    private const FORMATS = [
        'pdf' => 'application/pdf',
        'txt' => 'text/plain',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
    ];

    public function prepare(array $input): array
    {
        $filename = $input['filename'];
        $expected_mime = self::FORMATS[strtolower(pathinfo($filename, PATHINFO_EXTENSION))] ?? null;
        if ($expected_mime === null || preg_match('/[\\\\\/:*?"<>|\x00-\x1f\x7f]/', $filename)) {
            $this->reject('Usá PDF, TXT, Word, Excel, PowerPoint, JPG o PNG con un nombre de archivo válido.');
        }

        $bytes = base64_decode($input['content_base64'], true);
        if ($bytes === false || $bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            $this->reject('El documento tiene que ser válido y pesar hasta 5 MiB.');
        }

        $temporary = tmpfile();
        if ($temporary === false) {
            $this->reject('No se pudo preparar el documento.');
        }
        try {
            if (fwrite($temporary, $bytes) !== strlen($bytes) || ! fflush($temporary)) {
                $this->reject('No se pudo preparar el documento completo.');
            }
            $file = new UploadedFile(stream_get_meta_data($temporary)['uri'], $filename, null, null, true);
            $prepared = app(SupportOutboundMediaService::class)->prepare($file, 'document', true);
            if ($prepared['error'] !== null || $prepared['mime'] !== $expected_mime) {
                $this->reject('El contenido del documento no coincide con su extensión o WhatsApp no admite ese formato.');
            }
        } finally {
            fclose($temporary);
        }

        return ['filename' => $filename, 'mime' => $expected_mime, 'bytes' => $bytes];
    }

    public function persist(SupportMessage $message, array $attachment): void
    {
        $path = 'support_codex_outbound/' . $message->support_ticket_id . '/'
            . bin2hex(random_bytes(16)) . '/' . $attachment['filename'];
        $disk = Storage::disk('local');
        try {
            if (! $disk->put($path, $attachment['bytes']) || $disk->size($path) !== strlen($attachment['bytes'])) {
                throw new \RuntimeException('No se pudo guardar el documento completo.');
            }
            $message->attachments()->create([
                'disk' => 'local', 'path' => $path,
                'mime' => $attachment['mime'], 'size' => strlen($attachment['bytes']),
            ]);
            $message->kind = strpos($attachment['mime'], 'image/') === 0 ? 'image' : 'document';
            $message->save();
        } catch (\Throwable $exception) {
            $disk->delete($path);
            throw $exception;
        }
    }

    public static function is_private(SupportMessageAttachment $attachment): bool
    {
        if ($attachment->disk !== 'local') {
            return false;
        }
        $message = $attachment->message;

        return $message !== null && $message->sender_type === 'admin' && $message->ai_generated_at !== null
            && preg_match('~\Asupport_codex_outbound/' . (int) $message->support_ticket_id . '/[0-9a-f]{32}/[^/\\\\]+\z~', (string) $attachment->path) === 1;
    }

    public static function resolve_private(SupportMessageAttachment $attachment): ?string
    {
        $disk = Storage::disk('local');
        $root = realpath($disk->path('support_codex_outbound'));
        $file = realpath($disk->path($attachment->path));
        if ($root === false || $file === false || ! is_file($file)) {
            return null;
        }

        $prefix = $root . DIRECTORY_SEPARATOR;
        $inside = DIRECTORY_SEPARATOR === '\\' ? stripos($file, $prefix) === 0 : strpos($file, $prefix) === 0;
        return $inside ? $file : null;
    }

    private function reject(string $message): void
    {
        throw new HttpResponseException(response()->json(['code' => 'invalid_attachment', 'message' => $message], 422));
    }
}

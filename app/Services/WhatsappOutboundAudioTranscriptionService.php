<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Transcribe con OpenAI los audios de WhatsApp que MANDA el equipo (leads y tickets de soporte).
 *
 * Los audios entrantes los transcribe Kapso dentro del webhook; los salientes nacen en el panel del admin y
 * nadie los transcribía, así que el historial, el monitor de soporte y la ficha del cliente los veían como
 * `[Audio enviado]` o como un cuerpo vacío. Este servicio es el calco del helper de empresa-api
 * (`TranscripcionDeAudioIaHelper`) adaptado al admin.
 *
 * Está APAGADO por defecto: hace falta el interruptor `WHATSAPP_OUTBOUND_AUDIO_TRANSCRIPTION=true` y una clave
 * `OPENAI_TRANSCRIPTION_API_KEY` (ver `config/services.php`). Nunca lanza: ante cualquier falla devuelve null y
 * el mensaje queda como estaba.
 */
class WhatsappOutboundAudioTranscriptionService
{
    /** Dirección del endpoint de transcripción de OpenAI. */
    const OPENAI_URL = 'https://api.openai.com/v1/audio/transcriptions';

    /** Tope de caracteres que se guardan de una transcripción. */
    const MAX_CHARACTERS = 10000;

    /**
     * Indica si la transcripción de audios salientes está prendida y tiene clave para funcionar.
     *
     * @return bool
     */
    public function is_enabled(): bool
    {
        return config('services.openai_transcription.enabled') === true
            && trim((string) config('services.openai_transcription.api_key')) !== '';
    }

    /**
     * Transcribe un archivo de audio y devuelve el texto, o null si no se pudo.
     *
     * Devuelve null cuando: está deshabilitado, el archivo no se puede leer, hay una excepción de red, OpenAI
     * contesta distinto de 2xx, la respuesta no trae `text` o el texto queda vacío. El texto transcripto
     * (dato personal) nunca se loguea: sólo el largo y el estado HTTP.
     *
     * @param string $absolute_path Ruta absoluta del archivo de audio.
     * @param string $filename      Nombre con la extensión real (ogg, webm, m4a, mp4, mp3), que OpenAI usa
     *                              para detectar el formato.
     *
     * @return string|null
     */
    public function transcribe(string $absolute_path, string $filename): ?string
    {
        if (! $this->is_enabled()) {
            return null;
        }

        try {
            // Contenido del audio; si el archivo no se puede leer no hay nada que transcribir.
            $contents = is_file($absolute_path) ? @file_get_contents($absolute_path) : false;
            if ($contents === false || $contents === '') {
                Log::warning('Transcripción de audio saliente: el archivo no se pudo leer.', ['filename' => $filename]);

                return null;
            }

            $response = Http::withToken((string) config('services.openai_transcription.api_key'))
                ->timeout(30)
                ->withOptions(['connect_timeout' => 10])
                ->attach('file', $contents, $filename)
                ->post(self::OPENAI_URL, [
                    'model'           => (string) config('services.openai_transcription.model', 'gpt-4o-mini-transcribe'),
                    'language'        => 'es',
                    'response_format' => 'json',
                ]);

            if (! $response->successful()) {
                Log::warning('Transcripción de audio saliente: OpenAI no respondió bien.', [
                    'filename' => $filename,
                    'status'   => $response->status(),
                ]);

                return null;
            }

            $text = $response->json('text');
            if (! is_string($text) || trim($text) === '') {
                Log::warning('Transcripción de audio saliente: la respuesta no trajo texto.', ['filename' => $filename]);

                return null;
            }

            $text = trim($text);
            if (mb_strlen($text) > self::MAX_CHARACTERS) {
                $text = mb_substr($text, 0, self::MAX_CHARACTERS);
            }

            Log::info('Transcripción de audio saliente lista.', ['filename' => $filename, 'length' => mb_strlen($text)]);

            return $text;
        } catch (ConnectionException $e) {
            Log::warning('Transcripción de audio saliente: no hubo conexión con OpenAI.', ['filename' => $filename]);

            return null;
        } catch (\Throwable $e) {
            Log::warning('Transcripción de audio saliente: error inesperado.', [
                'filename' => $filename,
                'error'    => get_class($e),
            ]);

            return null;
        }
    }
}

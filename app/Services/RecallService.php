<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadCall;
use App\Models\RecallConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Gestiona toda la comunicación con la API de Recall.ai.
 *
 * Responsabilidades:
 * - Mandar el bot de Recall.ai a la URL de Google Meet del lead.
 * - Obtener la transcripción de un bot que terminó.
 * - Formatear las utterances crudas en texto plano legible.
 *
 * Todas las operaciones son best-effort: si fallan, loguean y continúan
 * sin lanzar excepciones para no romper el flujo principal.
 */
class RecallService
{
    /** URL base de la API de Recall.ai (región US East, default para nuevas cuentas). */
    private const API_BASE = 'https://us-west-2.recall.ai/api/v1';

    /**
     * Obtiene la configuración activa de Recall.ai desde la BD.
     *
     * Devuelve null si no hay ninguna configuración activa.
     *
     * @return RecallConfig|null
     */
    protected function get_config(): ?RecallConfig
    {
        return RecallConfig::where('is_active', true)->first();
    }

    /**
     * Manda el bot de Recall.ai a la reunión de Google Meet del lead.
     *
     * Guarda el recall_bot_id en el lead si el bot se crea correctamente.
     * Si falla por cualquier motivo (sin config, error de red, etc.), loguea y continúa.
     *
     * @param Lead $lead Lead al que se enviará el bot; debe tener meet_url asignada.
     *
     * @return void
     */
    public function send_bot_for_lead(Lead $lead): void
    {
        /* Verificar que el lead tenga URL de reunión para enviar el bot. */
        if (empty($lead->meet_url)) {
            Log::channel('daily')->warning('[RECALL] No se puede mandar bot: lead sin meet_url.', [
                'lead_id' => $lead->id,
            ]);
            return;
        }

        /* Crear el bot en Recall.ai a través del método compartido; devuelve null si falla. */
        $bot_id = $this->create_bot_for_meeting_url($lead->meet_url);
        if ($bot_id === null) {
            return;
        }

        /* Persistir el ID del bot en el lead para rastrear la transcripción. */
        $lead->update(['recall_bot_id' => $bot_id]);

        Log::channel('daily')->info('[RECALL] Bot creado y enviado a la reunión.', [
            'lead_id'  => $lead->id,
            'bot_id'   => $bot_id,
            'meet_url' => $lead->meet_url,
        ]);
    }

    /**
     * Crea un bot de Recall.ai para una URL de Meet dada y devuelve su id, o null si falla
     * (sin config activa, error de red, error HTTP). No persiste nada — el llamador decide
     * dónde guardar el bot_id (Lead o LeadCall según el caso).
     *
     * @param string $meeting_url URL de Google Meet a la que se manda el bot.
     *
     * @return string|null
     */
    private function create_bot_for_meeting_url(string $meeting_url): ?string
    {
        /* Obtener la configuración activa de Recall; si no hay, nada que hacer. */
        $config = $this->get_config();
        if (!$config) {
            Log::channel('daily')->warning('[RECALL] No hay RecallConfig activa configurada.');
            return null;
        }

        try {
            /* Crear el bot en Recall.ai con transcripción en español. */
            $response = Http::withHeaders([
                'Authorization' => 'Token ' . $config->recall_api_key,
                'Content-Type'  => 'application/json',
            ])->post(self::API_BASE . '/bot/', [
                'meeting_url'      => $meeting_url,
                'recording_config' => [
                    'transcript' => [
                        'provider' => [
                            'recallai_streaming' => ['language_code' => 'es']
                        ],
                    ],
                ],
                'bot_name' => 'ComercioCity',
            ]);

            if ($response->failed()) {
                Log::channel('daily')->warning('[RECALL] Error al crear bot.', [
                    'meeting_url' => $meeting_url,
                    'status'      => $response->status(),
                    'body'        => substr($response->body(), 0, 500),
                ]);
                return null;
            }

            return $response->json('id');
        } catch (\Throwable $e) {
            Log::channel('daily')->error('[RECALL] Excepción al crear bot.', [
                'meeting_url' => $meeting_url,
                'error'       => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Manda el bot de Recall.ai a la reunión de una LeadCall puntual.
     * Persiste recall_bot_id en la llamada (no en el lead). Best-effort:
     * si falla, loguea y no lanza excepción.
     *
     * @param LeadCall $call Llamada a la que se envía el bot; debe tener meet_url asignada.
     *
     * @return void
     */
    public function send_bot_for_call(LeadCall $call): void
    {
        /* Verificar que la llamada tenga URL de reunión para enviar el bot. */
        if (empty($call->meet_url)) {
            Log::channel('daily')->warning('[RECALL] No se puede mandar bot: llamada sin meet_url.', [
                'lead_call_id' => $call->id,
                'lead_id'      => $call->lead_id,
            ]);
            return;
        }

        /* Crear el bot en Recall.ai a través del método compartido; devuelve null si falla. */
        $bot_id = $this->create_bot_for_meeting_url($call->meet_url);
        if ($bot_id === null) {
            return;
        }

        /* Persistir el ID del bot en la llamada (no en el lead). */
        $call->update(['recall_bot_id' => $bot_id]);

        Log::channel('daily')->info('[RECALL] Bot creado y enviado a la reunión (por llamada).', [
            'lead_call_id' => $call->id,
            'lead_id'      => $call->lead_id,
            'bot_id'       => $bot_id,
            'meet_url'     => $call->meet_url,
        ]);
    }

    /**
     * Obtiene la transcripción completa de un bot que terminó de grabar.
     *
     * El endpoint legacy `GET /bot/{id}/transcript/` (API v1.10) ya no está disponible
     * para workspaces creados en la API nueva (v1.11), que es la que usamos para crear
     * el bot (recording_config.transcript.provider). En la API nueva la transcripción se
     * obtiene en dos pasos: 1) Retrieve Bot trae el array `recordings[]`, 2) cada recording
     * expone `media_shortcuts.transcript.data.download_url`, que es la URL pre-firmada
     * desde donde se descarga el JSON de utterances (sin necesidad de header de auth).
     *
     * Devuelve el array de utterances crudas tal como las devuelve Recall,
     * o null si no hay configuración activa o si la petición falla.
     *
     * @param string $bot_id ID del bot de Recall.ai del que se quiere la transcripción.
     *
     * @return array|null Array de utterances [{speaker, words: [{text, ...}]}] o null.
     */
    public function get_transcript(string $bot_id): ?array
    {
        /* Sin config activa no es posible autenticar la petición a Recall. */
        $config = $this->get_config();
        if (!$config) {
            return null;
        }

        try {
            /* Paso 1: traer el bot completo, incluye el array recordings[] con media_shortcuts. */
            $bot_response = Http::withHeaders([
                'Authorization' => 'Token ' . $config->recall_api_key,
            ])->get(self::API_BASE . '/bot/' . $bot_id . '/');

            if ($bot_response->failed()) {
                Log::channel('daily')->warning('[RECALL] Error al obtener bot.', [
                    'bot_id' => $bot_id,
                    'status' => $bot_response->status(),
                ]);
                return null;
            }

            $recordings = $bot_response->json('recordings') ?? [];
            if (empty($recordings)) {
                Log::channel('daily')->warning('[RECALL] Bot sin recordings.', [
                    'bot_id' => $bot_id,
                ]);
                return null;
            }

            /* Usar la última recording (por si hubo Start/Stop Recording múltiples). */
            $recording    = end($recordings);
            $download_url = $recording['media_shortcuts']['transcript']['data']['download_url'] ?? null;

            if (!$download_url) {
                Log::channel('daily')->warning('[RECALL] Recording sin transcript disponible.', [
                    'bot_id' => $bot_id,
                ]);
                return null;
            }

            /* Paso 2: descargar el JSON de utterances desde la URL pre-firmada (sin auth). */
            $transcript_response = Http::get($download_url);

            if ($transcript_response->failed()) {
                Log::channel('daily')->warning('[RECALL] Error al descargar transcript desde download_url.', [
                    'bot_id' => $bot_id,
                    'status' => $transcript_response->status(),
                ]);
                return null;
            }

            return $transcript_response->json();
        } catch (\Throwable $e) {
            Log::channel('daily')->error('[RECALL] Excepción al obtener transcripción.', [
                'bot_id' => $bot_id,
                'error'  => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Convierte el array de utterances de Recall en texto plano legible.
     *
     * Formato resultante: "Speaker: texto\nSpeaker: texto\n..."
     * Las utterances con texto vacío son ignoradas.
     *
     * @param array $utterances Array de utterances devuelto por Recall.ai.
     *
     * @return string Transcripción formateada como texto plano.
     */
    public function format_transcript(array $utterances): string
    {
        /* Acumular líneas de la transcripción formateada. */
        $lines = [];

        foreach ($utterances as $utterance) {
            /* Nombre del speaker tal como lo identifica Recall (ej: "Tommy", "Invitado"). */
            $speaker = $utterance['speaker'] ?? 'Desconocido';

            /* Las palabras vienen en el array 'words', unirlas en una sola cadena. */
            $words = $utterance['words'] ?? [];
            $text  = implode(' ', array_column($words, 'text'));

            if (trim($text) !== '') {
                $lines[] = $speaker . ': ' . trim($text);
            }
        }

        return implode("\n", $lines);
    }
}


<?php

namespace App\Services;

use App\Models\RecursosPregunta;
use App\Models\RecursosVideo;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * El borrador de respuesta con IA para una pregunta del centro de recursos (panel → "Proponer
 * respuesta"), misión centro-de-recursos-clientes (13/10/2026).
 *
 * Arma el prompt con lo que dice EL VIDEO (título, para qué sirve, cuándo no conviene, transcripción y
 * los títulos de los relacionados) y la pregunta, y le pide a Claude una respuesta corta en
 * rioplatense, con voseo y sin inventar: si la respuesta no está en el video, que lo diga y nombre el
 * relacionado que la cubra. La llamada es la misma de `CloserFollowupService::call_claude()`
 * (`services.anthropic`, mismos headers y la misma configuración de TLS).
 *
 * Es un BORRADOR: se guarda en `recursos_preguntas.borrador` y no lo ve ningún cliente hasta que un
 * admin lo pasa a `respuesta`. Sin clave o con error devuelve el motivo y no guarda nada; el panel
 * deja escribir a mano.
 */
class RecursosBorradorService
{
    /** Endpoint de la API de Anthropic para mensajes. */
    const ANTHROPIC_API_URL = 'https://api.anthropic.com/v1/messages';

    /** Tope de la transcripción que entra en el prompt (caracteres): un video de 3 minutos son ~3.000. */
    const MAX_TRANSCRIPCION = 20000;

    /**
     * Propone una respuesta y la guarda en `borrador`.
     *
     * @param RecursosPregunta $pregunta
     *
     * @return array{ok: bool, borrador?: string, error?: string, motivo?: string}
     */
    public function proponer(RecursosPregunta $pregunta): array
    {
        $api_key = (string) config('services.anthropic.api_key');

        if (trim($api_key) === '') {
            return [
                'ok'     => false,
                'error'  => 'sin_clave_ia',
                'motivo' => 'El admin no tiene ANTHROPIC_API_KEY en su .env: escribí la respuesta a mano.',
            ];
        }

        $video = RecursosVideo::find($pregunta->recursos_video_id);

        if (! $video instanceof RecursosVideo) {
            return ['ok' => false, 'error' => 'ia_no_disponible', 'motivo' => 'La pregunta no tiene un video que exista.'];
        }

        $texto = $this->llamar($api_key, $this->prompt($pregunta, $video));

        if ($texto['texto'] === null) {
            return ['ok' => false, 'error' => 'ia_no_disponible', 'motivo' => $texto['motivo']];
        }

        $pregunta->borrador = $texto['texto'];
        $pregunta->save();

        return ['ok' => true, 'borrador' => $texto['texto']];
    }

    /**
     * El prompt: instrucciones, lo que dice el video y la pregunta.
     *
     * Público para poder mirarlo en los tests sin llamar a nadie.
     *
     * @param RecursosPregunta $pregunta
     * @param RecursosVideo    $video
     *
     * @return string
     */
    public function prompt(RecursosPregunta $pregunta, RecursosVideo $video): string
    {
        $relacionados = [];
        $codigos      = array_values(array_filter((array) $video->relacionados, 'is_string'));

        if ($codigos !== []) {
            foreach (RecursosVideo::whereIn('codigo', $codigos)->where('activo', true)->orderBy('orden')->get(['codigo', 'titulo']) as $relacionado) {
                $relacionados[] = '- ' . $relacionado->codigo . ' · ' . $relacionado->titulo;
            }
        }

        $transcripcion = (string) $video->transcripcion;

        if (mb_strlen($transcripcion, 'UTF-8') > self::MAX_TRANSCRIPCION) {
            $transcripcion = mb_substr($transcripcion, 0, self::MAX_TRANSCRIPCION, 'UTF-8') . ' […]';
        }

        return implode("\n", [
            'Sos parte del equipo de ComercioCity, el sistema de gestión para comercios. Un cliente dejó una pregunta debajo de un video tutorial del centro de recursos y tenés que proponer la respuesta que le va a dar el equipo.',
            '',
            'Cómo tiene que ser la respuesta:',
            '- En español rioplatense, con voseo ("tocá", "fijate", "podés"), cálida y concreta.',
            '- Corta: de dos a cinco oraciones. Sin saludo con nombre y sin firma.',
            '- Usá SOLO lo que dice el video (abajo). No inventes menús, botones, pasos ni funciones que el video no nombre.',
            '- Si la respuesta no está en el video, decilo con honestidad. Si alguno de los videos relacionados la cubre, nombralo por su título para que lo vea. Si no, decí que el equipo lo revisa y le responde.',
            '- Devolvé solo el texto de la respuesta, sin comillas ni encabezados.',
            '',
            '=== EL VIDEO ===',
            'Título: ' . $video->titulo,
            'Para qué sirve y cuándo usarlo: ' . (string) $video->para_que,
            'Cuándo no conviene, y qué usar en su lugar: ' . (string) $video->cuando_no,
            'Lo que dice el video: ' . $transcripcion,
            'Videos relacionados: ' . ($relacionados === [] ? 'ninguno' : "\n" . implode("\n", $relacionados)),
            '',
            '=== LA PREGUNTA DEL CLIENTE ===',
            (string) $pregunta->texto,
            '=== FIN DE LA PREGUNTA ===',
        ]);
    }

    /**
     * La llamada a Anthropic, calcada de `CloserFollowupService::call_claude()`.
     *
     * @param string $api_key
     * @param string $prompt
     *
     * @return array{texto: string|null, motivo: string}
     */
    protected function llamar(string $api_key, string $prompt): array
    {
        $http = Http::withHeaders([
            'x-api-key'         => $api_key,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ])->timeout((int) config('recursos.borrador.timeout', 40));

        /* Configuración TLS según entorno (WAMP en Windows puede requerir cacert explícito). */
        $verify_ssl = (bool) config('services.anthropic.verify_ssl', true);
        $ca_bundle  = config('services.anthropic.ca_bundle');

        if (! $verify_ssl) {
            $http = $http->withoutVerifying();
        } elseif (is_string($ca_bundle) && $ca_bundle !== '' && is_file($ca_bundle)) {
            $http = $http->withOptions(['verify' => $ca_bundle]);
        }

        try {
            $response = $http->post(self::ANTHROPIC_API_URL, [
                'model'      => (string) config('services.anthropic.model', 'claude-sonnet-4-20250514'),
                'max_tokens' => (int) config('recursos.borrador.max_tokens', 700),
                'messages'   => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);
        } catch (\Throwable $e) {
            Log::channel('daily')->warning('[RECURSOS_BORRADOR] Excepción al llamar a Claude.', ['error' => $e->getMessage()]);

            return ['texto' => null, 'motivo' => 'No se pudo hablar con la IA (' . mb_substr($e->getMessage(), 0, 160, 'UTF-8') . ').'];
        }

        if ($response->failed()) {
            Log::channel('daily')->warning('[RECURSOS_BORRADOR] Error en la API de Claude.', [
                'status' => $response->status(),
                'body'   => substr((string) $response->body(), 0, 500),
            ]);

            return ['texto' => null, 'motivo' => 'La IA respondió HTTP ' . $response->status() . '.'];
        }

        $body = $response->json();
        $text = '';

        if (is_array($body) && isset($body['content']) && is_array($body['content'])) {
            foreach ($body['content'] as $block) {
                if (is_array($block) && isset($block['text']) && is_string($block['text'])) {
                    $text .= $block['text'];
                }
            }
        }

        $text = trim($text);

        if ($text === '') {
            return ['texto' => null, 'motivo' => 'La IA no devolvió texto.'];
        }

        return ['texto' => $text, 'motivo' => ''];
    }
}

<?php

namespace App\Jobs;

use App\Models\ClientAssistantMessage;
use App\Services\AsistenteReaccionService;
use App\Services\AsistenteWhatsappService;
use App\Services\ClientEmpresaApiUrlResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Le reenvía al `empresa-api` del cliente la reacción del dueño a un mensaje de su asistente.
 *
 * Es `POST api/admin-sync/asistente/reacciones` con la `api_key` del cliente (contrato en
 * `AsistenteReaccionService`). Del otro lado se valora la respuesta (👎/😡 = no me gusta) para que la
 * revisión diaria la analice.
 *
 * 🔴 **Nunca le escribe nada al dueño por WhatsApp**: una reacción no es un turno del asistente. Este job
 * sólo habla con el `empresa-api`.
 *
 * El trato de códigos copia el de `AsistenteAvisosService` (y lo extiende a los códigos del contrato):
 *   - 2xx: se registra `aplicada`/`motivo` y se termina.
 *   - 404 (empresa viejo, sin la ruta), 401 (clave), 403 (sin `agentes_ia`), 409 (dueño no resuelto),
 *     422 (cuerpo rechazado): se registra y se TERMINA, sin reintento. Reintentar no los arregla.
 *   - 5xx o caída de red: se reintenta con `release()` hasta agotar `$tries` (3 intentos).
 */
class ReenviarReaccionAlAsistenteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Intentos totales, el primero incluido.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * Espera, en segundos, antes del segundo y del tercer intento.
     *
     * @var array<int, int>
     */
    public $backoff = [10, 60];

    /**
     * Id de la fila saliente del asistente que se reaccionó.
     *
     * @var int
     */
    public $client_assistant_message_id;

    /**
     * Emoji de la reacción tal cual llegó ('' = el dueño la quitó).
     *
     * @var string
     */
    public $emoji;

    /**
     * wamid del mensaje reaccionado.
     *
     * @var string
     */
    public $target_whatsapp_message_id;

    /**
     * wamid del evento de reacción (sólo para el log del otro lado).
     *
     * @var string
     */
    public $reaction_message_id;

    /**
     * @param int    $client_assistant_message_id Fila saliente reaccionada.
     * @param string $emoji                       Emoji ('' = quitó la reacción).
     * @param string $target_whatsapp_message_id  wamid reaccionado.
     * @param string $reaction_message_id         wamid del evento de reacción.
     */
    public function __construct(int $client_assistant_message_id, string $emoji, string $target_whatsapp_message_id, string $reaction_message_id = '')
    {
        $this->client_assistant_message_id = $client_assistant_message_id;
        $this->emoji                       = $emoji;
        $this->target_whatsapp_message_id  = $target_whatsapp_message_id;
        $this->reaction_message_id         = $reaction_message_id;

        /* 🔴 Conexión Y cola, las dos, como el resto del canal. Sin `onConnection()` el job usa la conexión
           por defecto de admin-api, que es `sync`: el POST correría dentro del webhook de Kapso y
           `release()` sobre un SyncJob no reencola, o sea que los reintentos del 5xx no ocurrirían. */
        $this->onConnection(AsistenteWhatsappService::CONEXION_DE_COLA);
        $this->onQueue(AsistenteWhatsappService::COLA_DEL_ASISTENTE);
    }

    /**
     * Hace el POST y decide si termina o se reintenta.
     *
     * @param ClientEmpresaApiUrlResolver $resolver Arma la URL del empresa-api del cliente.
     *
     * @return void
     */
    public function handle(ClientEmpresaApiUrlResolver $resolver): void
    {
        $fila   = ClientAssistantMessage::query()->find($this->client_assistant_message_id);
        $client = $fila ? $fila->client : null;

        if ($fila === null || $client === null) {
            Log::channel('daily')->info('AsistenteReacciones: la fila o el cliente ya no existen; se descarta.', [
                'client_assistant_message_id' => $this->client_assistant_message_id,
            ]);

            return;
        }

        // Sin clave no se llama: el empresa-api contestaría 401 igual (patrón de EnviarMensajeAlAsistenteJob).
        $api_key = trim((string) $client->api_key);
        if ($api_key === '') {
            Log::channel('daily')->warning('AsistenteReacciones: el cliente no tiene clave para su empresa-api; no se reenvía.', [
                'client_id' => $client->id,
            ]);

            return;
        }

        $url = $resolver->admin_sync_url($client, AsistenteReaccionService::RUTA_REACCIONES);
        if ($url === '') {
            Log::channel('daily')->warning('AsistenteReacciones: no se pudo resolver la URL del empresa-api del cliente.', [
                'client_id' => $client->id,
            ]);

            return;
        }

        // Cuerpo del contrato: emoji siempre (aunque sea ''), ids opcionales sólo si existen.
        $cuerpo = AsistenteReaccionService::armar_cuerpo(
            $this->target_whatsapp_message_id,
            $this->emoji,
            $fila->ai_message_id !== null ? (int) $fila->ai_message_id : null,
            $this->reaction_message_id
        );

        try {
            $respuesta = Http::withHeaders([
                    'X-Admin-Api-Key' => $api_key,
                    'Accept'          => 'application/json',
                ])
                ->timeout((int) config('services.client_api.timeout', 15))
                ->post($url, $cuerpo);
        } catch (\Throwable $exception) {
            // Caída de red o timeout: transitorio.
            $this->reintentar('AsistenteReacciones: no se pudo llamar al empresa-api.', [
                'client_id' => $client->id,
                'error'     => $exception->getMessage(),
            ]);

            return;
        }

        $status = (int) $respuesta->status();

        if ($respuesta->successful()) {
            Log::channel('daily')->info('AsistenteReacciones: reacción reenviada al sistema del cliente.', [
                'client_id' => $client->id,
                'status'    => $status,
                'aplicada'  => $respuesta->json('aplicada'),
                'motivo'    => $respuesta->json('motivo'),
            ]);

            return;
        }

        // Respuestas definitivas: log y fin, sin reintento.
        if (in_array($status, [404, 401, 403, 409, 422], true)) {
            Log::channel('daily')->info('AsistenteReacciones: el sistema del cliente no tomó la reacción; no se reintenta.', [
                'client_id' => $client->id,
                'status'    => $status,
            ]);

            return;
        }

        // Cualquier otro (5xx y similares): transitorio.
        $this->reintentar('AsistenteReacciones: el sistema del cliente falló; se reintenta.', [
            'client_id' => $client->id,
            'status'    => $status,
        ]);
    }

    /**
     * Registra el fallo transitorio y vuelve a encolar el job con la espera del intento que sigue.
     *
     * Con el último intento ya consumido no se reencola: queda sólo el log.
     *
     * @param string               $mensaje Texto del log.
     * @param array<string, mixed> $contexto Datos del log.
     *
     * @return void
     */
    private function reintentar(string $mensaje, array $contexto): void
    {
        $intento = max(1, (int) $this->attempts());

        if ($intento >= $this->tries) {
            Log::channel('daily')->warning($mensaje . ' Se agotaron los intentos.', $contexto + ['intento' => $intento]);

            return;
        }

        Log::channel('daily')->warning($mensaje, $contexto + ['intento' => $intento]);

        // Espera del intento siguiente: backoff[0] tras el 1°, backoff[1] tras el 2°.
        $espera = $this->backoff[min($intento - 1, count($this->backoff) - 1)];
        $this->release($espera);
    }
}

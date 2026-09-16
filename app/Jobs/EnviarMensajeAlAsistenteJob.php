<?php

namespace App\Jobs;

use App\Models\Client;
use App\Models\ClientAssistantMessage;
use App\Services\AsistenteWhatsappService;
use App\Services\ClientEmpresaApiUrlResolver;
use App\Services\WhatsappSendService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Lleva el mensaje del dueño hasta el asistente de su sistema y le trae la respuesta.
 *
 * Es el único lugar de este canal que sale a la red, y hace las dos mitades del viaje:
 *
 *   1. **La ida**: `POST api/admin-sync/asistente/mensajes` al `empresa-api` de ese cliente, con
 *      la `api_key` del cliente en el header. Del otro lado se crea el mensaje del usuario y el
 *      del asistente en `pendiente`, se despacha el job que lo responde y se contesta **202** con
 *      los dos identificadores. O sea: el `empresa-api` tampoco se queda esperando.
 *   2. **La vuelta**: `GET .../mensajes/{id}` cada tantos segundos hasta que el estado deja de ser
 *      `pendiente`, y ahí el texto sale por WhatsApp.
 *
 * 🔴 **La espera se hace con `release()`, no durmiendo.** El asistente tiene un presupuesto de 150
 * segundos y un job con `timeout` 240 del lado del cliente: tres minutos de `sleep()` acá serían
 * tres minutos con un worker del admin tomado, y este admin tiene UN worker que además corre
 * deployments e instalaciones. `release($delay)` devuelve el job a la cola con fecha y libera el
 * worker; cuando le toca, vuelve a entrar por `handle()` y sigue donde estaba.
 *
 * 🔴 **Y la degradación es la parte que más importa.** Las cuatro rutas `api/admin-sync/asistente/*`
 * son nuevas y los 40+ clientes corren versiones distintas de `master`: durante semanas, la
 * mayoría va a contestar **404**. Eso no es un error del sistema, es la versión vieja, y por eso
 * tiene estado propio (`degradado`), aviso acotado a uno por cliente por día, un texto honesto
 * para el dueño y **cero reintentos** — reintentar contra un endpoint que no existe es quemar cola
 * para siempre.
 */
class EnviarMensajeAlAsistenteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Ruta del endpoint del asistente en el `empresa-api` del cliente.
     */
    const RUTA_MENSAJES = 'api/admin-sync/asistente/mensajes';

    /**
     * Esperas entre una consulta y la siguiente, en segundos.
     *
     * Arranca corto y se va estirando (3, 5, 8, 13, 21, 34, 55) porque la mayoría de las
     * respuestas del asistente llegan en los primeros segundos —una pregunta sin herramientas se
     * contesta casi de una— y no tiene sentido hacer esperar diez segundos a quien ya tiene la
     * respuesta lista. Las esperas largas del final son para el caso caro: el asistente que usa
     * varias herramientas de lectura antes de contestar.
     *
     * 🔴 **La suma da exactamente 180**, y ese es el tope de polling. No es un número redondo
     * elegido de arriba: del otro lado el job del asistente tiene `timeout` 240 y un presupuesto
     * interno de 150 segundos, así que a los 180 o ya contestó, o falló y el estado lo dice, o se
     * colgó de una forma que esperar más no arregla. El último valor es 41 y no 89 justamente para
     * que cierre en 180 y no en 228.
     */
    const ESPERAS_DE_POLLING = [3, 5, 8, 13, 21, 34, 55, 41];

    /**
     * Un solo intento del lado de Laravel.
     *
     * 🔴 Y por eso hace falta `retryUntil()` (ver abajo). Con `$tries = 1` a secas, el worker mata
     * el job en el SEGUNDO ingreso —el primero que llega por un `release()`— con
     * `MaxAttemptsExceededException`, porque compara `attempts()` contra este número. La ventana
     * de `retryUntil()` corta ese chequeo antes de que se aplique el tope de intentos, que es el
     * mecanismo que Laravel tiene justamente para un job que se auto-reencola.
     *
     * @var int
     */
    public $tries = 1;

    /**
     * Techo de cada ingreso al worker. Lo único que pasa adentro es una llamada HTTP corta.
     *
     * @var int
     */
    public $timeout = 60;

    /**
     * Fila de `client_assistant_messages` que se está tramitando.
     *
     * @var int
     */
    private $mensaje_id;

    /**
     * Cuántas esperas de `ESPERAS_DE_POLLING` se consumieron ya.
     *
     * En la cola de verdad la cuenta la lleva el worker (`attempts()` sobrevive al `release()`
     * porque la conexión de base de datos reencola la fila con el contador intacto). Esta
     * propiedad es el respaldo para el otro camino: una corrida directa, sin job de cola detrás,
     * donde `attempts()` devuelve siempre 1. Se usa el mayor de los dos, así los dos caminos
     * avanzan igual.
     *
     * @var int
     */
    private $esperas_usadas = 0;

    /**
     * @param int $mensaje_id Fila entrante de `client_assistant_messages`.
     */
    public function __construct(int $mensaje_id)
    {
        $this->mensaje_id = $mensaje_id;
    }

    /**
     * Momento hasta el cual el worker deja que este job se siga reencolando.
     *
     * Laravel lo resuelve UNA vez, al despachar, y lo guarda en el payload; los `release()`
     * posteriores reencolan ese mismo payload, así que la ventana no se renueva sola. 600 y no
     * 180: tiene que entrar el polling entero (180 s) más las llamadas HTTP y la espera en cola de
     * cada ingreso, y este es el techo de último recurso, no el que gobierna la lógica. El tope
     * que manda es `ESPERAS_DE_POLLING`, que se aplica en código y se puede probar.
     *
     * @return \DateTimeInterface
     */
    public function retryUntil()
    {
        return now()->addSeconds(600);
    }

    /**
     * Manda el mensaje al asistente del cliente, o consulta si la respuesta ya está.
     *
     * @param AsistenteWhatsappService   $asistente Servicio del canal (filas, textos, avisos).
     * @param ClientEmpresaApiUrlResolver $urls     Resolución de la URL del `empresa-api`.
     * @param WhatsappSendService        $sender    Envío a Kapso/Meta.
     *
     * @return void
     */
    public function handle(
        AsistenteWhatsappService $asistente,
        ClientEmpresaApiUrlResolver $urls,
        WhatsappSendService $sender
    ): void {
        $fila = ClientAssistantMessage::find($this->mensaje_id);
        if ($fila === null) {
            return;
        }

        /* Estados finales: la fila ya se resolvió (o se dio por perdida) en un ingreso anterior.
         * Puede pasar con un `release()` que llegó tarde y un reintento manual en el medio. */
        if (in_array((string) $fila->estado, [
            ClientAssistantMessage::ESTADO_RESPONDIDO,
            ClientAssistantMessage::ESTADO_DEGRADADO,
            ClientAssistantMessage::ESTADO_ERROR,
        ], true)) {
            return;
        }

        $client = Client::find((int) $fila->client_id);
        if ($client === null) {
            $this->cerrar_con_error($asistente, $fila, null, $sender, 'El cliente ya no existe.');

            return;
        }

        /* El interruptor se vuelve a mirar acá y no solo en el webhook: entre que el mensaje entró
         * y que el job corre pueden haber pasado minutos, y apagar la casilla tiene que frenar lo
         * que está en vuelo. Se corta en silencio —sin texto de disculpa— porque apagar el canal
         * es una decisión deliberada, no una falla. */
        if (! (bool) $client->asistente_whatsapp_activo) {
            $fila->estado = ClientAssistantMessage::ESTADO_ERROR;
            $fila->error  = 'El canal del asistente se apagó para este cliente antes de mandar el mensaje.';
            $fila->save();

            return;
        }

        try {
            if ($fila->ai_message_id === null) {
                $this->enviar($asistente, $urls, $sender, $fila, $client);

                return;
            }

            $this->consultar($asistente, $urls, $sender, $fila, $client);
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('AsistenteWhatsapp: excepción tramitando el mensaje.', [
                'assistant_message_id' => $fila->id,
                'client_id'            => $client->id,
                'error'                => $exception->getMessage(),
            ]);

            $this->cerrar_con_error($asistente, $fila, $client, $sender, $exception->getMessage());
        }
    }

    /**
     * Red de último recurso: el worker dio el job por muerto (se venció `retryUntil()`).
     *
     * Sin esto la fila quedaría para siempre en `enviado` y el dueño sin ninguna respuesta, que es
     * el peor de los finales posibles: ni contestó el asistente ni avisó nadie.
     *
     * @param \Throwable|null $exception Motivo con el que el worker lo dio por muerto.
     *
     * @return void
     */
    public function failed($exception = null): void
    {
        $fila = ClientAssistantMessage::find($this->mensaje_id);
        if ($fila === null) {
            return;
        }

        if (in_array((string) $fila->estado, [
            ClientAssistantMessage::ESTADO_RESPONDIDO,
            ClientAssistantMessage::ESTADO_DEGRADADO,
            ClientAssistantMessage::ESTADO_ERROR,
        ], true)) {
            return;
        }

        $client = Client::find((int) $fila->client_id);

        $this->cerrar_con_error(
            app(AsistenteWhatsappService::class),
            $fila,
            $client,
            app(WhatsappSendService::class),
            $exception !== null ? $exception->getMessage() : 'El job se dio por muerto sin resolver.'
        );
    }

    /**
     * La ida: le pasa el mensaje del dueño al `empresa-api` del cliente.
     *
     * @param AsistenteWhatsappService    $asistente
     * @param ClientEmpresaApiUrlResolver $urls
     * @param WhatsappSendService         $sender
     * @param ClientAssistantMessage      $fila
     * @param Client                      $client
     *
     * @return void
     */
    private function enviar(
        AsistenteWhatsappService $asistente,
        ClientEmpresaApiUrlResolver $urls,
        WhatsappSendService $sender,
        ClientAssistantMessage $fila,
        Client $client
    ): void {
        /* 🔴 Sin `api_key` no se manda NADA, ni siquiera un intento a ver qué pasa. Este canal
         * escribe plata y stock del otro lado: un endpoint que acepta sin clave es exactamente lo
         * que no puede existir, y pegarle sin ella solo sirve para convertir un problema de
         * configuración en un 401 que hay que ir a interpretar. */
        $api_key = trim((string) $client->api_key);
        if ($api_key === '') {
            $this->cerrar_con_error(
                $asistente,
                $fila,
                $client,
                $sender,
                'El cliente no tiene cargada la api_key: no se puede autenticar contra su sistema.'
            );

            return;
        }

        $url = $urls->admin_sync_url($client, self::RUTA_MENSAJES);
        if ($url === '') {
            $this->cerrar_con_error(
                $asistente,
                $fila,
                $client,
                $sender,
                'No se pudo resolver la URL del empresa-api de este cliente.'
            );

            return;
        }

        $cuerpo = [
            'texto'               => (string) ($fila->texto !== null ? $fila->texto : ''),
            'tipo'                => $this->tipo_del_contrato((string) $fila->tipo),
            'whatsapp_message_id' => (string) $fila->whatsapp_message_id,
        ];

        /* Solo viaja si la dedujo de una CITA. Sin este campo, la conversación la elige el
         * `empresa-api` con su corte por tiempo, que es el reparto que fija el plan: el admin sabe
         * de `wamid`, el cliente sabe de `last_message_at`. */
        if ($fila->ai_conversation_id !== null) {
            $cuerpo['ai_conversation_id'] = (int) $fila->ai_conversation_id;
        }

        try {
            $respuesta = Http::withHeaders([
                    'X-Admin-Api-Key' => $api_key,
                    'Accept'          => 'application/json',
                ])
                ->timeout((int) config('services.client_api.timeout', 15))
                ->asMultipart()
                ->post($url, $this->como_multipart($cuerpo));
        } catch (ConnectionException $exception) {
            /* El sistema del cliente no atendió. Es el caso transitorio por excelencia (el shared
             * hosting devuelve esto cuando está saturado), así que se reintenta dentro del mismo
             * presupuesto de 180 s en vez de darlo por perdido de una. */
            $this->reintentar_o_rendirse(
                $asistente,
                $fila,
                $client,
                $sender,
                'No respondió el sistema del cliente: ' . $exception->getMessage()
            );

            return;
        }

        $status = (int) $respuesta->status();

        /* 404: el cliente todavía no tiene la ruta. NO es un error y NO se reintenta. */
        if ($status === 404) {
            $this->degradar_sin_endpoint($asistente, $fila, $client, $sender);

            return;
        }

        /* 401 / 403: la clave no coincide, o al dueño le falta la extensión `asistente_ia`. Las
         * dos se arreglan con una configuración, ninguna con esperar. */
        if ($status === 401 || $status === 403) {
            $this->cerrar_con_error(
                $asistente,
                $fila,
                $client,
                $sender,
                $status === 401
                    ? 'El sistema del cliente rechazó la clave (401). Revisar clients.api_key contra su ADMIN_API_INBOUND_KEY.'
                    : 'El sistema del cliente rechazó el pedido (403). Al dueño le falta la extensión asistente_ia.'
            );

            return;
        }

        /* 5xx: el sistema del cliente se rompió. Puede ser pasajero; entra al reintento acotado. */
        if ($status >= 500) {
            $this->reintentar_o_rendirse(
                $asistente,
                $fila,
                $client,
                $sender,
                'El sistema del cliente respondió ' . $status . '.'
            );

            return;
        }

        $datos = (array) $respuesta->json();

        if ($status !== 202 || empty($datos['ai_message_id'])) {
            /* Cualquier otra cosa (un 422 de validación, un 200 con el cuerpo cambiado) es un
             * desacuerdo de contrato: no se arregla esperando y hay que ir a mirarlo. */
            $this->cerrar_con_error(
                $asistente,
                $fila,
                $client,
                $sender,
                'Respuesta inesperada del sistema del cliente (' . $status . '): '
                    . mb_strimwidth((string) $respuesta->body(), 0, 300, '…')
            );

            return;
        }

        $fila->ai_message_id      = (int) $datos['ai_message_id'];
        $fila->ai_conversation_id = isset($datos['ai_conversation_id'])
            ? (int) $datos['ai_conversation_id']
            : $fila->ai_conversation_id;
        $fila->estado             = ClientAssistantMessage::ESTADO_ENVIADO;
        $fila->error              = null;
        $fila->save();

        Log::channel('daily')->info('AsistenteWhatsapp: mensaje aceptado por el sistema del cliente.', [
            'assistant_message_id' => $fila->id,
            'client_id'            => $client->id,
            'ai_conversation_id'   => $fila->ai_conversation_id,
            'ai_message_id'        => $fila->ai_message_id,
        ]);

        $this->esperar_y_volver();
    }

    /**
     * La vuelta: pregunta si el asistente ya terminó y, si terminó, le manda el texto al dueño.
     *
     * @param AsistenteWhatsappService    $asistente
     * @param ClientEmpresaApiUrlResolver $urls
     * @param WhatsappSendService         $sender
     * @param ClientAssistantMessage      $fila
     * @param Client                      $client
     *
     * @return void
     */
    private function consultar(
        AsistenteWhatsappService $asistente,
        ClientEmpresaApiUrlResolver $urls,
        WhatsappSendService $sender,
        ClientAssistantMessage $fila,
        Client $client
    ): void {
        $url = $urls->admin_sync_url($client, self::RUTA_MENSAJES . '/' . (int) $fila->ai_message_id);
        if ($url === '') {
            $this->cerrar_con_error(
                $asistente,
                $fila,
                $client,
                $sender,
                'No se pudo resolver la URL del empresa-api de este cliente.'
            );

            return;
        }

        try {
            $respuesta = Http::withHeaders([
                    'X-Admin-Api-Key' => trim((string) $client->api_key),
                    'Accept'          => 'application/json',
                ])
                ->timeout((int) config('services.client_api.timeout', 15))
                ->get($url);
        } catch (ConnectionException $exception) {
            $this->reintentar_o_rendirse(
                $asistente,
                $fila,
                $client,
                $sender,
                'No respondió el sistema del cliente al consultar la respuesta: ' . $exception->getMessage()
            );

            return;
        }

        $status = (int) $respuesta->status();

        if ($status >= 500) {
            $this->reintentar_o_rendirse(
                $asistente,
                $fila,
                $client,
                $sender,
                'El sistema del cliente respondió ' . $status . ' al consultar la respuesta.'
            );

            return;
        }

        if ($status !== 200) {
            /* Un 404 acá NO es "no tiene el endpoint" —la ida ya devolvió 202, así que lo tiene—:
             * es el mensaje que se perdió del otro lado. Esperar no lo va a traer de vuelta. */
            $this->cerrar_con_error(
                $asistente,
                $fila,
                $client,
                $sender,
                'El sistema del cliente respondió ' . $status . ' al consultar el mensaje #'
                    . (int) $fila->ai_message_id . '.'
            );

            return;
        }

        $datos  = (array) $respuesta->json();
        $estado = trim((string) ($datos['estado'] ?? ''));

        if ($estado === 'pendiente') {
            $this->esperar_y_volver($asistente, $fila, $client, $sender);

            return;
        }

        if ($estado === 'error') {
            $this->cerrar_con_error(
                $asistente,
                $fila,
                $client,
                $sender,
                'El asistente del cliente no pudo responder: '
                    . trim((string) ($datos['error_mensaje'] ?? 'sin detalle'))
            );

            return;
        }

        $contenido = trim((string) ($datos['contenido'] ?? ''));
        if ($contenido === '') {
            $this->cerrar_con_error(
                $asistente,
                $fila,
                $client,
                $sender,
                'El asistente del cliente devolvió una respuesta vacía.'
            );

            return;
        }

        if (isset($datos['ai_conversation_id'])) {
            $fila->ai_conversation_id = (int) $datos['ai_conversation_id'];
        }

        $whatsapp_message_id = $sender->send_text(
            (string) $fila->telefono,
            $contenido,
            'Asistente por WhatsApp - cliente #' . $client->id
        );

        $fila->estado = ClientAssistantMessage::ESTADO_RESPONDIDO;
        $fila->error  = $whatsapp_message_id === null ? (string) $sender->last_send_error : null;
        $fila->save();

        /* 🔴 La fila saliente se deja SIEMPRE, con wamid o sin él. Con wamid es lo que hace andar
         * la cita del próximo turno; sin wamid es la única constancia de una respuesta que el
         * asistente produjo y que el dueño nunca vio. */
        $asistente->registrar_saliente(
            $fila,
            $contenido,
            $whatsapp_message_id,
            $whatsapp_message_id !== null
                ? ClientAssistantMessage::ESTADO_RESPONDIDO
                : ClientAssistantMessage::ESTADO_ERROR,
            $whatsapp_message_id === null ? (string) $sender->last_send_error : null
        );

        Log::channel('daily')->info('AsistenteWhatsapp: respuesta del asistente entregada.', [
            'assistant_message_id' => $fila->id,
            'client_id'            => $client->id,
            'entregada'            => $whatsapp_message_id !== null,
        ]);
    }

    /**
     * Reencola el job para la próxima consulta, o se rinde si se acabó el presupuesto.
     *
     * Los cuatro parámetros son opcionales porque este método se usa en dos momentos: justo
     * después de la ida —donde todavía no hay nada que cerrar si se acabara el presupuesto, cosa
     * que además no puede pasar porque recién arranca— y en cada consulta.
     *
     * @param AsistenteWhatsappService|null $asistente
     * @param ClientAssistantMessage|null   $fila
     * @param Client|null                   $client
     * @param WhatsappSendService|null      $sender
     *
     * @return void
     */
    private function esperar_y_volver(
        ?AsistenteWhatsappService $asistente = null,
        ?ClientAssistantMessage $fila = null,
        ?Client $client = null,
        ?WhatsappSendService $sender = null
    ): void {
        $espera = $this->proxima_espera();

        if ($espera === null) {
            if ($asistente === null || $fila === null || $sender === null) {
                return;
            }

            $this->cerrar_con_error(
                $asistente,
                $fila,
                $client,
                $sender,
                'El asistente del cliente no contestó en '
                    . array_sum(self::ESPERAS_DE_POLLING) . ' segundos.'
            );

            return;
        }

        $this->esperas_usadas = $this->indice_de_espera() + 1;

        $this->release($espera);
    }

    /**
     * Un reintento más por un fallo transitorio, o el texto de disculpa si ya no quedan.
     *
     * Comparte presupuesto con el polling a propósito: el dueño está esperando una respuesta y no
     * le importa si los tres minutos se fueron en reintentos de conexión o en esperar al modelo.
     * Tres minutos es lo que se banca una conversación de WhatsApp.
     *
     * @param AsistenteWhatsappService $asistente
     * @param ClientAssistantMessage   $fila
     * @param Client|null              $client
     * @param WhatsappSendService      $sender
     * @param string                   $detalle   Qué falló, para la fila y el aviso.
     *
     * @return void
     */
    private function reintentar_o_rendirse(
        AsistenteWhatsappService $asistente,
        ClientAssistantMessage $fila,
        ?Client $client,
        WhatsappSendService $sender,
        string $detalle
    ): void {
        $espera = $this->proxima_espera();

        if ($espera === null) {
            $this->cerrar_con_error($asistente, $fila, $client, $sender, $detalle);

            return;
        }

        Log::channel('daily')->warning('AsistenteWhatsapp: fallo transitorio, se reintenta.', [
            'assistant_message_id' => $fila->id,
            'client_id'            => $client !== null ? $client->id : null,
            'espera_segundos'      => $espera,
            'detalle'              => $detalle,
        ]);

        $this->esperas_usadas = $this->indice_de_espera() + 1;

        $this->release($espera);
    }

    /**
     * Cierra el mensaje del dueño con el texto honesto de "tu sistema todavía no tiene esto".
     *
     * @param AsistenteWhatsappService $asistente
     * @param ClientAssistantMessage   $fila
     * @param Client                   $client
     * @param WhatsappSendService      $sender
     *
     * @return void
     */
    private function degradar_sin_endpoint(
        AsistenteWhatsappService $asistente,
        ClientAssistantMessage $fila,
        Client $client,
        WhatsappSendService $sender
    ): void {
        $fila->estado = ClientAssistantMessage::ESTADO_DEGRADADO;
        $fila->error  = 'El empresa-api de este cliente no tiene la ruta ' . self::RUTA_MENSAJES . ' (404).';
        $fila->save();

        $whatsapp_message_id = $sender->send_text(
            (string) $fila->telefono,
            AsistenteWhatsappService::TEXTO_SIN_ENDPOINT,
            'Asistente por WhatsApp sin endpoint - cliente #' . $client->id
        );

        $asistente->registrar_saliente(
            $fila,
            AsistenteWhatsappService::TEXTO_SIN_ENDPOINT,
            $whatsapp_message_id,
            ClientAssistantMessage::ESTADO_DEGRADADO
        );

        $asistente->avisar_cliente_sin_endpoint($client);

        Log::channel('daily')->warning('AsistenteWhatsapp: el sistema del cliente no tiene el endpoint.', [
            'assistant_message_id' => $fila->id,
            'client_id'            => $client->id,
        ]);
    }

    /**
     * Cierra el mensaje del dueño con el texto de disculpa y avisa a los admins.
     *
     * @param AsistenteWhatsappService $asistente
     * @param ClientAssistantMessage   $fila
     * @param Client|null              $client
     * @param WhatsappSendService      $sender
     * @param string                   $detalle   Qué falló.
     *
     * @return void
     */
    private function cerrar_con_error(
        AsistenteWhatsappService $asistente,
        ClientAssistantMessage $fila,
        ?Client $client,
        WhatsappSendService $sender,
        string $detalle
    ): void {
        $fila->estado = ClientAssistantMessage::ESTADO_ERROR;
        $fila->error  = mb_strimwidth($detalle, 0, 1000, '…');
        $fila->save();

        $whatsapp_message_id = $sender->send_text(
            (string) $fila->telefono,
            AsistenteWhatsappService::TEXTO_DE_DISCULPA,
            'Asistente por WhatsApp con error - cliente #' . ($client !== null ? $client->id : '?')
        );

        $asistente->registrar_saliente(
            $fila,
            AsistenteWhatsappService::TEXTO_DE_DISCULPA,
            $whatsapp_message_id,
            ClientAssistantMessage::ESTADO_ERROR,
            $fila->error
        );

        $nombre = $client !== null ? (string) $client->name : 'cliente desconocido';

        $asistente->avisar_a_los_admins(
            'Asistente por WhatsApp - ' . $nombre . ' (mensaje #' . $fila->id . ')',
            $detalle
        );

        Log::channel('daily')->error('AsistenteWhatsapp: el mensaje del dueño quedó sin respuesta.', [
            'assistant_message_id' => $fila->id,
            'client_id'            => $client !== null ? $client->id : null,
            'detalle'              => $detalle,
        ]);
    }

    /**
     * Segundos hasta la próxima consulta, o null si se acabó el presupuesto de 180 s.
     *
     * @return int|null
     */
    private function proxima_espera(): ?int
    {
        $indice = $this->indice_de_espera();

        if (! array_key_exists($indice, self::ESPERAS_DE_POLLING)) {
            return null;
        }

        return (int) self::ESPERAS_DE_POLLING[$indice];
    }

    /**
     * Posición dentro de `ESPERAS_DE_POLLING` que corresponde a este ingreso.
     *
     * `attempts()` es la cuenta del worker y arranca en 1 (el ingreso donde se hizo la ida), así
     * que la posición es uno menos. Cuando no hay job de cola detrás —una corrida directa, que es
     * como se prueba esto— `attempts()` devuelve 1 fijo y la cuenta la lleva `$esperas_usadas`.
     * Se toma el mayor de los dos para que los dos caminos avancen igual.
     *
     * @return int
     */
    private function indice_de_espera(): int
    {
        return max(((int) $this->attempts()) - 1, $this->esperas_usadas);
    }

    /**
     * Traduce el tipo normalizado del webhook al del contrato con el `empresa-api`.
     *
     * El webhook maneja más tipos de los que el contrato conoce (`video`, `document`, `sticker`…).
     * Todo lo que no es audio ni imagen viaja como `texto`, que es lo único que el asistente puede
     * hacer con eso: leer el caption o la transcripción que igual llegó en `texto`.
     *
     * @param string $tipo Tipo normalizado del webhook.
     *
     * @return string texto | audio | imagen
     */
    private function tipo_del_contrato(string $tipo): string
    {
        switch (strtolower(trim($tipo))) {
            case 'audio':
            case 'ptt':
            case 'voice':
                return 'audio';
            case 'image':
                return 'imagen';
            default:
                return 'texto';
        }
    }

    /**
     * Arma el cuerpo con la forma que espera `asMultipart()`.
     *
     * El endpoint es multipart porque el contrato contempla `imagenes[]`, y un POST multipart con
     * `Http::asMultipart()` necesita la lista de `['name' => ..., 'contents' => ...]`, no el array
     * asociativo que toma `->post()` en modo JSON.
     *
     * @param array<string, mixed> $cuerpo Campos del contrato.
     *
     * @return array<int, array<string, mixed>>
     */
    private function como_multipart(array $cuerpo): array
    {
        $partes = [];
        foreach ($cuerpo as $nombre => $valor) {
            $partes[] = [
                'name'     => $nombre,
                'contents' => (string) $valor,
            ];
        }

        return $partes;
    }
}

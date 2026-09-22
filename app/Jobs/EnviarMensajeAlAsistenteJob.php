<?php

namespace App\Jobs;

use App\Models\Client;
use App\Models\ClientAssistantMessage;
use App\Services\AsistenteFotoSalienteService;
use App\Services\AsistenteImagenesService;
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
 *      `pendiente`, y ahí el texto sale por WhatsApp — seguido de las fotos que la respuesta traiga
 *      en `adjuntos`, una por mensaje y por link (ver `mandar_fotos_de_la_respuesta()`).
 *
 * 🔴 **La espera se hace con `release()`, no durmiendo.** El asistente tiene un presupuesto de 150
 * segundos y un job con `timeout` 240 del lado del cliente: tres minutos de `sleep()` acá serían
 * tres minutos con un worker del admin tomado, y este admin tiene UN worker que además corre
 * deployments e instalaciones. `release($delay)` devuelve el job a la cola con fecha y libera el
 * worker; cuando le toca, vuelve a entrar por `handle()` y sigue donde estaba. (Lo único que se
 * hace durmiendo son las pausas de segundos entre el texto y cada foto de la respuesta, que existen
 * por el 409 de Kapso: ver `mandar_fotos_de_la_respuesta()`.)
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
     * Tope de fotos que se mandan por respuesta del asistente.
     *
     * Es el mismo tope que el `empresa-api` aplica al guardar `adjuntos` (`AdjuntosIaHelper::MAX_ADJUNTOS`),
     * repetido de este lado a propósito: el que decide cuántos mensajes de WhatsApp salen es el
     * que los manda, y un API que por un bug devolviera cincuenta adjuntos no puede convertirse en
     * cincuenta mensajes seguidos al dueño.
     */
    const MAXIMO_DE_FOTOS_POR_RESPUESTA = 6;

    /**
     * Conteo de fotos de un turno, en cero.
     *
     * `enviadas` y `fallidas` se leyeron siempre; `por_link` y `convertidas` son por dónde salió
     * cada una de las enviadas, y existen porque el log mentía: un envío por link se cuenta como
     * exitoso apenas Meta contesta, y Meta valida el archivo recién después. Ver el bloque donde se
     * escribe la línea del log.
     */
    const CUENTA_DE_FOTOS_EN_CERO = [
        'enviadas'    => 0,
        'fallidas'    => 0,
        'por_link'    => 0,
        'convertidas' => 0,
    ];

    /**
     * Presupuesto de tiempo para TODAS las fotos de un turno, en segundos.
     *
     * 🔴 **Es un presupuesto acumulado y no un techo por foto, y esa es toda la diferencia.** Este
     * ingreso al worker tiene `$timeout = 60` y ya gastó lo suyo antes de llegar acá: el GET al
     * `empresa-api` que trajo la respuesta y el `send_text()` del texto. Lo que queda es esto.
     *
     * Con un techo por foto la cuenta no cerraba nunca: seis fotos × 20 s de descarga eran 120 s
     * sobre un tope de 60, y hasta UNA sola foto se pasaba —1,2 s de pausa + 20 de descarga + 30 de
     * subida + 1,5 + 30 del reintento ≈ 82 s—. Y la consecuencia no era solamente perder la foto:
     * si el worker mata el job acá, la línea `respuesta del asistente entregada` **nunca se
     * escribe**, porque está después del bucle. O sea que los contadores desaparecen justo en el
     * escenario donde más se los quiere leer.
     *
     * Ahora antes de cada foto —y antes de cada reintento— se pregunta si queda presupuesto para el
     * peor caso de esa llamada; si no queda, se corta el bucle, las que faltan cuentan como
     * fallidas con su motivo, y el log sale igual.
     */
    const SEGUNDOS_PARA_TODAS_LAS_FOTOS = 35;

    /**
     * Techo de cada llamada a Kapso al mandar una foto por media_id, en segundos.
     *
     * Son dos llamadas (la subida a `/media` y el mensaje), así que el peor caso de una foto por
     * ese camino es la pausa del 409 (1,2 s) + la descarga (`AsistenteFotoSalienteService::SEGUNDOS_DE_DESCARGA`,
     * 8 s) + 10 + 10 ≈ 30 s. Ese es el número que usa el presupuesto para decidir si arranca.
     */
    const SEGUNDOS_POR_ENVIO_DE_FOTO = 10;

    /**
     * Pausa antes de cada foto, en microsegundos.
     *
     * 🔴 No es precaución teórica. Kapso devuelve **409 "otro mensaje en vuelo para esta
     * conversación"** cuando dos envíos salen pegados (lead #440, 22/7/2026; la misma regla vive
     * en `LeadSuggestionSendService::enviar_partes()` y en `SupportWhatsappOpenerService`). Acá el
     * texto y las fotos salen uno atrás del otro por la misma conversación, que es exactamente ese
     * caso: sin la pausa, la primera foto se perdería casi siempre y el log diría "fallida" sin que
     * nadie entendiera por qué. 1200 ms es lo que aquella vez alcanzó para que Kapso soltara el
     * bloqueo.
     */
    const PAUSA_ANTES_DE_CADA_FOTO_US = 1200000;

    /**
     * Espera antes del segundo intento de una foto que falló por algo transitorio, en microsegundos.
     */
    const ESPERA_ANTES_DE_REINTENTAR_FOTO_US = 1500000;

    /**
     * Intentos por foto cuando el fallo es transitorio (409 / 429 / 5xx).
     *
     * Dos y no tres como en el opener de soporte: este job tiene `$timeout` 60 y hasta seis fotos,
     * y con tres intentos más el backoff largo (3500 ms) el peor caso se acerca al techo. La foto
     * es un extra, y el segundo intento existe por el 409, que se suelta en un segundo. El sender
     * además ya reintenta a nivel HTTP (`retry(2, 500)`), así que son cuatro pedidos por foto.
     */
    const INTENTOS_POR_FOTO = 2;

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
     * Metadata de las fotos que trajo el mensaje (URL firmada, mime, id de Meta), sin los bytes.
     *
     * Viaja en el payload del job y no en una columna: una URL firmada de Kapso escrita en la base
     * es una credencial guardada para siempre. Los bytes se bajan recién en la ida, que es el único
     * momento en que hacen falta. Laravel reencola el mismo payload en cada `release()`, así que
     * esto sigue disponible si el job vuelve a entrar — aunque después de la ida ya no se use.
     *
     * @var array<int, array<string, mixed>>
     */
    private $imagenes;

    /**
     * @param int                              $mensaje_id Fila entrante de `client_assistant_messages`.
     * @param array<int, array<string, mixed>> $imagenes   Metadata de las fotos, si el mensaje traía.
     */
    public function __construct(int $mensaje_id, array $imagenes = [])
    {
        $this->mensaje_id = $mensaje_id;
        $this->imagenes   = $imagenes;
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
     * @param AsistenteWhatsappService    $asistente Servicio del canal (filas, textos, avisos).
     * @param ClientEmpresaApiUrlResolver $urls      Resolución de la URL del `empresa-api`.
     * @param WhatsappSendService         $sender    Envío a Kapso/Meta.
     * @param AsistenteImagenesService    $imagenes  Descarga y validación de las fotos del dueño.
     * @param AsistenteFotoSalienteService $fotos    Cómo tiene que salir cada foto de la respuesta.
     *
     * @return void
     */
    public function handle(
        AsistenteWhatsappService $asistente,
        ClientEmpresaApiUrlResolver $urls,
        WhatsappSendService $sender,
        AsistenteImagenesService $imagenes,
        AsistenteFotoSalienteService $fotos
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
                $this->enviar($asistente, $urls, $sender, $imagenes, $fila, $client);

                return;
            }

            $this->consultar($asistente, $urls, $sender, $fotos, $fila, $client);
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
     * @param AsistenteImagenesService    $imagenes
     * @param ClientAssistantMessage      $fila
     * @param Client                      $client
     *
     * @return void
     */
    private function enviar(
        AsistenteWhatsappService $asistente,
        ClientEmpresaApiUrlResolver $urls,
        WhatsappSendService $sender,
        AsistenteImagenesService $imagenes,
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

        $partes                  = $this->como_multipart($cuerpo);
        $preparadas_con_descarte = false;

        /*
         * Las fotos: se bajan de Kapso recién acá y se pegan al mismo multipart.
         *
         * 🔴 Una descarga que falla NO voltea el mensaje. El dueño escribió algo y espera respuesta;
         * perder el mensaje entero por un adjunto que no se pudo bajar es peor que perder el
         * adjunto. Lo que sí pasa es que el asistente se entera, porque la nota se le pega al texto
         * y con eso puede pedir la foto de nuevo en vez de contestar como si nunca hubiera existido.
         */
        if ($this->imagenes !== []) {
            $preparadas = $imagenes->preparar($this->imagenes, (int) $fila->id);

            $faltantes = count($this->imagenes) - count($preparadas['partes']);
            if ($faltantes > 0) {
                $nota = $imagenes->nota_para_el_asistente($faltantes);

                foreach ($partes as $indice => $parte) {
                    if ($parte['name'] !== 'texto') {
                        continue;
                    }

                    $texto = trim((string) $parte['contents']);
                    $partes[$indice]['contents'] = $texto !== '' ? ($texto . "\n" . $nota) : $nota;
                }
            }

            if ($preparadas['descartes'] !== []) {
                /* Queda escrito en la fila, que es donde se va a mirar cuando el dueño diga "te
                 * mandé la factura y no la viste". El texto es el mismo que ya se le explicó al
                 * asistente, en criollo y sin la URL de nada. */
                $preparadas_con_descarte = true;
                $fila->error = mb_strimwidth(implode(' ', $preparadas['descartes']), 0, 1000, '…');
                $fila->save();
            }

            $partes = array_merge($partes, $preparadas['partes']);
        }

        try {
            $respuesta = Http::withHeaders([
                    'X-Admin-Api-Key' => $api_key,
                    'Accept'          => 'application/json',
                ])
                ->timeout((int) config('services.client_api.timeout', 15))
                ->asMultipart()
                ->post($url, $partes);
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

        /* 🔴 409: el cliente SÍ tiene la ruta, pero no pudo decidir de qué dueño hablamos — una base
         * compartida sin USER_ID en el .env de ese frente. Es distinto del 404 a propósito: si se
         * tratara como "no tiene el endpoint", le diríamos al dueño que actualice un sistema que ya
         * está actualizado, y el problema real —una variable sin cargar— no lo vería nadie. Se
         * arregla con una configuración, así que no se reintenta. */
        if ($status === 409) {
            $this->cerrar_con_error(
                $asistente,
                $fila,
                $client,
                $sender,
                'El sistema del cliente no pudo resolver el dueño (409). '
                . 'Si la base la comparten varios comercios, falta USER_ID en el .env de ese frente.'
            );

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

        /* 🔴 El `error` se limpia solo si no quedó una nota de descarte de fotos escrita unas líneas
         * más arriba. La columna lleva las dos cosas —lo que falló y lo que se descartó—, y este es
         * el único punto donde se pisan: "el mensaje salió bien" no borra "la foto no viajó", que es
         * justamente lo que hay que poder leer cuando el dueño dice que mandó la factura y nadie la
         * vio. */
        if ($preparadas_con_descarte === false) {
            $fila->error = null;
        }

        $fila->save();

        Log::channel('daily')->info('AsistenteWhatsapp: mensaje aceptado por el sistema del cliente.', [
            'assistant_message_id' => $fila->id,
            'client_id'            => $client->id,
            'ai_conversation_id'   => $fila->ai_conversation_id,
            'ai_message_id'        => $fila->ai_message_id,
        ]);

        /* Van los cuatro parámetros y no ninguno: si los reintentos transitorios de la ida ya se
         * comieron el presupuesto, esta llamada tiene que poder CERRAR el mensaje con la disculpa.
         * Sin ellos volvería en silencio y la fila quedaría en `enviado` para siempre, con el dueño
         * esperando una respuesta que nadie iba a ir a buscar. */
        $this->esperar_y_volver($asistente, $fila, $client, $sender);
    }

    /**
     * La vuelta: pregunta si el asistente ya terminó y, si terminó, le manda el texto al dueño —
     * y después, las fotos que la respuesta traiga adjuntas.
     *
     * @param AsistenteWhatsappService     $asistente
     * @param ClientEmpresaApiUrlResolver  $urls
     * @param WhatsappSendService          $sender
     * @param AsistenteFotoSalienteService $fotos     Cómo tiene que salir cada foto de la respuesta.
     * @param ClientAssistantMessage       $fila
     * @param Client                       $client
     *
     * @return void
     */
    private function consultar(
        AsistenteWhatsappService $asistente,
        ClientEmpresaApiUrlResolver $urls,
        WhatsappSendService $sender,
        AsistenteFotoSalienteService $fotos,
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

        /* Las fotos van DESPUÉS de que el texto quedó guardado y registrado, y solo si el texto
         * salió. El orden en el WhatsApp del dueño es ese mismo: primero la respuesta, debajo cada
         * foto con su epígrafe. Y lo que pase acá no toca ni el estado de la fila ni la fila
         * saliente del texto, que ya quedaron escritos: una foto que no sale es una línea de log,
         * no un turno perdido. */
        $cuenta_de_fotos = self::CUENTA_DE_FOTOS_EN_CERO;
        if ($whatsapp_message_id !== null) {
            $cuenta_de_fotos = $this->mandar_fotos_de_la_respuesta($sender, $fotos, $fila, $client, $datos['adjuntos'] ?? []);
        }

        /* 🔴 `por_link` y `convertidas` no son decoración: hasta el 22/9/2026 este log decía
         * `imagenes_enviadas: 1` para una foto que Meta descartó después, y la única forma de
         * enterarse era que el dueño avisara. Un envío por link sigue siendo optimista —Meta valida
         * asincrónico—, así que el próximo que lea esta línea tiene que poder distinguir de una cuál
         * de los dos caminos tomó cada foto: lo que salió por `convertidas` pasó por un rechazo
         * sincrónico y ese conteo es real. Las que no son ninguno de los dos (`enviadas` menos
         * `por_link` menos `convertidas`) son las que se bajaron y ya venían en jpeg o png. */
        Log::channel('daily')->info('AsistenteWhatsapp: respuesta del asistente entregada.', [
            'assistant_message_id' => $fila->id,
            'client_id'            => $client->id,
            'entregada'            => $whatsapp_message_id !== null,
            'imagenes_enviadas'    => $cuenta_de_fotos['enviadas'],
            'imagenes_fallidas'    => $cuenta_de_fotos['fallidas'],
            'imagenes_por_link'    => $cuenta_de_fotos['por_link'],
            'imagenes_convertidas' => $cuenta_de_fotos['convertidas'],
        ]);
    }

    /**
     * Manda por WhatsApp las fotos que el asistente adjuntó a su respuesta, una por mensaje.
     *
     * `adjuntos` es la clave opcional del contrato con el `empresa-api` (§2 de la misión
     * `asistente-omnisciente`): `[{tipo: 'imagen', url: 'https://…', texto: 'epígrafe'}]`. Un API
     * viejo no la manda, y acá no pasa nada. Cada ítem se valida por su cuenta —tipo `imagen` y
     * `url` `http(s)`— y lo que no valida se saltea sin error, porque un adjunto raro no puede
     * voltear a los que están bien. El `texto` viaja como caption; sin texto, la foto va sola.
     *
     * 🔴 Un fallo acá se loguea y nada más. No cambia el estado de la fila, no toca la fila
     * saliente del texto y no le manda ninguna disculpa al dueño: el texto ya salió y la foto es
     * un extra. Vale también para una excepción, y por eso cada envío va en su propio `try`: sin
     * él, un `Throwable` subiría hasta el `catch` de `handle()`, que cerraría con error —y con la
     * disculpa— un turno que el dueño ya recibió bien. El motivo queda en el log con la URL, que
     * es lo que hace falta para ver si Meta no pudo bajarla (un link caído, una imagen de más de
     * 5 MB). Una foto que falla no frena a la siguiente: cada una es independiente, con su propio
     * epígrafe, a diferencia de las partes de un texto partido.
     *
     * ⚠️ Las pausas de acá (`pausar()`) son lo ÚNICO que este job hace durmiendo, y son de
     * segundos, no de minutos: a lo sumo ~1,2 s por foto más 1,5 s por reintento transitorio. La
     * regla de "esperar con `release()`" es para los tres minutos del polling; un `release()` por
     * foto haría que cada una llegara hasta un minuto después de la anterior, que es lo que tarda
     * el cron en volver a levantar el worker.
     *
     * @param WhatsappSendService          $sender   Envío a Kapso/Meta.
     * @param AsistenteFotoSalienteService $fotos    Decide el camino de cada foto y convierte.
     * @param ClientAssistantMessage       $fila     Mensaje del dueño que se está respondiendo.
     * @param Client                       $client   Cliente dueño del hilo.
     * @param mixed                        $adjuntos Lo que vino en `adjuntos`, tal cual llegó.
     *
     * `protected` y no `private` porque el conteo que devuelve **es** lo que hay que poder medir:
     * termina en una línea de log y desde afuera del job no se ve de ninguna otra forma. Una prueba
     * que sólo mirara los envíos del espía no distinguiría "se descartó y se contó" de "se descartó
     * en silencio", que es justamente la diferencia que importa acá.
     *
     * @return array{enviadas: int, fallidas: int, por_link: int, convertidas: int}
     */
    protected function mandar_fotos_de_la_respuesta(
        WhatsappSendService $sender,
        AsistenteFotoSalienteService $fotos,
        ClientAssistantMessage $fila,
        Client $client,
        $adjuntos
    ): array {
        $cuenta = self::CUENTA_DE_FOTOS_EN_CERO;

        if (! is_array($adjuntos) || $adjuntos === []) {
            return $cuenta;
        }

        /* Cuándo se acaba el presupuesto de este turno para fotos. Ver SEGUNDOS_PARA_TODAS_LAS_FOTOS. */
        $vence = $this->ahora() + self::SEGUNDOS_PARA_TODAS_LAS_FOTOS;

        /* Se cuentan los intentos y no las posiciones: seis adjuntos válidos después de tres
         * inválidos tienen que salir los seis. */
        $intentadas = 0;

        foreach ($adjuntos as $adjunto) {
            if ($intentadas >= self::MAXIMO_DE_FOTOS_POR_RESPUESTA) {
                break;
            }

            /* Los `is_string` no son paranoia: un `(string)` sobre un array tira "Array to string
             * conversion", que Laravel convierte en excepción, y eso es justo lo que acá no puede
             * pasar por un adjunto malformado. */
            if (! is_array($adjunto)) {
                $cuenta['fallidas']++;

                Log::channel('daily')->warning('AsistenteWhatsapp: se descartó un adjunto que no es un objeto.', [
                    'assistant_message_id' => $fila->id,
                    'client_id'            => $client->id,
                    'recibido'             => gettype($adjunto),
                ]);

                continue;
            }

            $tipo = isset($adjunto['tipo']) && is_string($adjunto['tipo']) ? trim($adjunto['tipo']) : '';
            $url  = isset($adjunto['url']) && is_string($adjunto['url']) ? trim($adjunto['url']) : '';

            /* 🔴 **Un descarte por forma se CUENTA y se LOGUEA, no se saltea en silencio.** Es la
             * misma clase de error que esta corrección vino a arreglar: si el `continue` no tocara
             * el conteo, un adjunto con `tipo` "foto" o "Imagen" —cualquier cosa que no sea
             * `imagen` exacto— desaparecería y la línea final del turno diría
             * `imagenes_enviadas: 0, imagenes_fallidas: 0`, o sea que salió todo bien.
             *
             * Hoy no se dispara porque el `empresa-api` filtra más duro de este lado (su
             * `es_url_absoluta()` compara con `strpos(...) === 0`, sensible a mayúsculas, y acá la
             * expresión lleva `i`). Se va a disparar el día que alguien sume un `TIPO_*` nuevo en
             * `AdjuntosIaHelper` sin enseñárselo al admin — que es exactamente el caso
             * `manual_tasks` vs `tareas` que este proyecto ya tuvo: la clave mal puesta no rompe
             * nada, el array llega vacío y el envío informa éxito. Por eso el log lleva el `tipo`
             * recibido: para que el próximo lo lea en vez de deducirlo. */
            if ($tipo !== 'imagen' || ! preg_match('#^https?://#i', $url)) {
                $cuenta['fallidas']++;

                Log::channel('daily')->warning('AsistenteWhatsapp: se descartó un adjunto que no tiene la forma esperada.', [
                    'assistant_message_id' => $fila->id,
                    'client_id'            => $client->id,
                    'tipo_recibido'        => mb_strimwidth($tipo, 0, 40, '…'),
                    'tipo_esperado'        => 'imagen',
                    /* El host y nada más, con el mismo criterio que el resto de este bloque: no se
                     * pasean URLs del negocio por el log. Vacío si ni siquiera parsea. */
                    'origen'               => parse_url($url, PHP_URL_HOST),
                    'url_absoluta'         => (bool) preg_match('#^https?://#i', $url),
                ]);

                continue;
            }

            $texto = isset($adjunto['texto']) && is_scalar($adjunto['texto'])
                ? trim((string) $adjunto['texto'])
                : '';

            $intentadas++;

            /* 🔴 El presupuesto se mira ANTES de arrancar la foto, contra el peor caso de una foto
             * entera. Preguntar después no sirve de nada: para entonces el tiempo ya se gastó y el
             * riesgo es que el worker mate el ingreso con el log del final sin escribir. */
            if (! $this->queda_presupuesto($vence, $this->peor_caso_de_una_foto())) {
                $cuenta['fallidas']++;

                Log::channel('daily')->warning('AsistenteWhatsapp: una foto no se intentó por falta de tiempo en el turno.', [
                    'assistant_message_id' => $fila->id,
                    'client_id'            => $client->id,
                    'origen'               => parse_url($url, PHP_URL_HOST),
                    'archivo'              => basename((string) parse_url($url, PHP_URL_PATH)),
                    'ya_enviadas'          => $cuenta['enviadas'],
                ]);

                continue;
            }

            /* Antes de CADA foto, incluida la primera: el texto acaba de salir por esta misma
             * conversación y Kapso todavía puede tenerla tomada (ver PAUSA_ANTES_DE_CADA_FOTO_US). */
            $this->pausar(self::PAUSA_ANTES_DE_CADA_FOTO_US);

            $resultado = $this->mandar_una_foto($sender, $fotos, $fila, $client, $url, $texto !== '' ? $texto : null, $vence);

            if ($resultado['wamid'] !== null) {
                $cuenta['enviadas']++;

                if ($resultado['por_link']) {
                    $cuenta['por_link']++;
                }

                if ($resultado['convertida']) {
                    $cuenta['convertidas']++;
                }

                continue;
            }

            $cuenta['fallidas']++;

            Log::channel('daily')->warning('AsistenteWhatsapp: una foto de la respuesta no salió.', [
                'assistant_message_id' => $fila->id,
                'client_id'            => $client->id,
                /* Por dónde iba cuando falló: un fallo por link y uno por media_id se arreglan en
                 * lugares distintos —el primero es de Meta yendo a buscar el archivo, el segundo es
                 * la descarga del hosting del cliente o la conversión. */
                'camino'               => $resultado['por_link'] ? 'link' : 'media_id',
                /*
                 * 🔴 EL ORIGEN DE LA FOTO, NO LA URL ENTERA. Es una URL del catálogo de un cliente
                 * real y el log diario del admin lo leen personas y lo rota el hosting. El mismo
                 * criterio con el que la corrección del contrato del 21/9 sacó los adjuntos del
                 * broadcast: no se pasean URLs del negocio por canales que no las necesitan. Para
                 * diagnosticar alcanza con de dónde salió y qué archivo era: si hace falta la URL
                 * completa, está en la respuesta del `empresa-api` de ese cliente.
                 */
                'origen'               => parse_url($url, PHP_URL_HOST),
                'archivo'              => basename((string) parse_url($url, PHP_URL_PATH)),
                'motivo'               => $resultado['motivo'],
            ]);
        }

        return $cuenta;
    }

    /**
     * Un envío de foto: elige el camino y reintenta una vez si el fallo fue transitorio.
     *
     * 🔴 **El camino lo decide el formato, y por qué no hay uno solo está escrito en
     * {@see AsistenteFotoSalienteService::va_por_link()}.** En dos líneas: por link es más barato
     * pero Meta valida el archivo *después* de contestar que sí, y descarta el webp —que es el 100 %
     * de las fotos del catálogo— sin decir nada; convertir todas siempre arregla eso pero le cuesta
     * dos viajes de red y una pasada de GD a cada foto que ya era jpeg y Meta iba a bajar sola.
     *
     * Transitorio lo decide `WhatsappSendService::last_send_was_transient()` (409 / 429 / 5xx), y el
     * caso central es el 409 de Kapso por la conversación tomada. Un rechazo definitivo —un link
     * que Meta no pudo bajar, un número inválido, la configuración apagada— no se reintenta:
     * esperar no lo arregla. Una excepción tampoco: el sender ya atrapa las suyas, así que una que
     * llegue hasta acá es algo que no se entiende, y se reporta tal cual.
     *
     * @param WhatsappSendService          $sender  Envío a Kapso/Meta.
     * @param AsistenteFotoSalienteService $fotos   Decide el camino y convierte.
     * @param ClientAssistantMessage       $fila    Mensaje del dueño que se está respondiendo.
     * @param Client                       $client  Cliente dueño del hilo.
     * @param string                       $url     URL pública de la foto.
     * @param string|null                  $caption Epígrafe, o null para mandarla sola.
     * @param float                        $vence   Momento en que se acaba el presupuesto del turno.
     *
     * @return array{wamid: string|null, motivo: string|null, por_link: bool, convertida: bool}
     */
    private function mandar_una_foto(
        WhatsappSendService $sender,
        AsistenteFotoSalienteService $fotos,
        ClientAssistantMessage $fila,
        Client $client,
        string $url,
        ?string $caption,
        float $vence
    ): array {
        $telefono = (string) $fila->telefono;
        $contexto = 'Asistente por WhatsApp - foto - cliente #' . $client->id;

        if ($fotos->va_por_link($url)) {
            $resultado = $this->con_reintento_transitorio($sender, $vence, function () use ($sender, $telefono, $url, $caption, $contexto) {
                return $sender->send_image_by_link($telefono, $url, $caption, $contexto);
            });

            return [
                'wamid'      => $resultado['wamid'],
                'motivo'     => $resultado['motivo'],
                'por_link'   => true,
                'convertida' => false,
            ];
        }

        /* 🔴 Bajar y convertir va FUERA del bucle de reintentos, y no es un detalle: el reintento
         * existe por el 409 de Kapso —la conversación tomada—, que no tiene nada que ver con los
         * bytes. Adentro del bucle, cada 409 volvería a pedirle el archivo al hosting del cliente y
         * a pasarlo por GD para llegar exactamente al mismo JPEG. */
        try {
            $preparada = $fotos->preparar($url);
        } catch (\Throwable $exception) {
            /* Una foto no puede voltear el turno ni acá: el texto ya salió. */
            return [
                'wamid'      => null,
                'motivo'     => $exception->getMessage(),
                'por_link'   => false,
                'convertida' => false,
            ];
        }

        if ($preparada['binario'] === null) {
            return [
                'wamid'      => null,
                'motivo'     => $preparada['motivo'],
                'por_link'   => false,
                'convertida' => false,
            ];
        }

        $binario = (string) $preparada['binario'];
        $mime    = (string) $preparada['mime'];
        $nombre  = (string) $preparada['nombre'];

        $resultado = $this->con_reintento_transitorio($sender, $vence, function () use ($sender, $telefono, $binario, $mime, $nombre, $caption, $contexto) {
            return $sender->send_image_by_bytes(
                $telefono,
                $binario,
                $mime,
                $nombre,
                $caption,
                $contexto,
                true,
                self::SEGUNDOS_POR_ENVIO_DE_FOTO
            );
        });

        return [
            'wamid'      => $resultado['wamid'],
            'motivo'     => $resultado['motivo'],
            'por_link'   => false,
            'convertida' => (bool) $preparada['convertida'],
        ];
    }

    /**
     * Corre un envío y lo repite una vez si el fallo fue de los que se sueltan solos.
     *
     * @param WhatsappSendService $sender Para leer el motivo y el status del fallo.
     * @param float               $vence  Momento en que se acaba el presupuesto del turno.
     * @param callable            $envio  Devuelve el wamid o null, igual que los métodos del sender.
     *
     * @return array{wamid: string|null, motivo: string|null}
     */
    private function con_reintento_transitorio(WhatsappSendService $sender, float $vence, callable $envio): array
    {
        $motivo = null;

        for ($intento = 1; $intento <= self::INTENTOS_POR_FOTO; $intento++) {
            try {
                $wamid = $envio();
            } catch (\Throwable $exception) {
                return ['wamid' => null, 'motivo' => $exception->getMessage()];
            }

            if ($wamid !== null) {
                return ['wamid' => $wamid, 'motivo' => null];
            }

            $motivo = (string) $sender->last_send_error;

            if ($intento < self::INTENTOS_POR_FOTO && $sender->last_send_was_transient()) {
                /* El reintento también pide permiso: es otra espera más otro envío, y si el
                 * presupuesto no le alcanza, insistir es lo que mata el ingreso. */
                $segundos_del_reintento = (self::ESPERA_ANTES_DE_REINTENTAR_FOTO_US / 1000000)
                    + self::SEGUNDOS_POR_ENVIO_DE_FOTO;

                if (! $this->queda_presupuesto($vence, $segundos_del_reintento)) {
                    $motivo = $motivo . ' (no quedó tiempo en el turno para reintentar)';

                    break;
                }

                $this->pausar(self::ESPERA_ANTES_DE_REINTENTAR_FOTO_US);

                continue;
            }

            break;
        }

        return ['wamid' => null, 'motivo' => $motivo];
    }

    /**
     * El peor caso, en segundos, de mandar una foto entera por el camino largo.
     *
     * La pausa del 409, la descarga del hosting del cliente, la subida a `/media` y el mensaje.
     *
     * @return float
     */
    private function peor_caso_de_una_foto(): float
    {
        return (self::PAUSA_ANTES_DE_CADA_FOTO_US / 1000000)
            + AsistenteFotoSalienteService::SEGUNDOS_DE_DESCARGA
            + (self::SEGUNDOS_POR_ENVIO_DE_FOTO * 2);
    }

    /**
     * Indica si todavía entra algo que va a tardar `$segundos` antes de que venza el presupuesto.
     *
     * @param float $vence    Momento en que se acaba.
     * @param float $segundos Cuánto puede tardar lo que se quiere hacer.
     *
     * @return bool
     */
    private function queda_presupuesto(float $vence, float $segundos): bool
    {
        return ($this->ahora() + $segundos) <= $vence;
    }

    /**
     * El reloj. Separado para que un test lo pueda mover sin esperar.
     *
     * @return float Segundos con decimales, como `microtime(true)`.
     */
    protected function ahora(): float
    {
        return microtime(true);
    }

    /**
     * Espera entre un envío y el siguiente. Separado para que un test lo pueda anular.
     *
     * Mismo mecanismo que `SupportWhatsappOpenerService::pausar()`: las pruebas del job corren
     * `handle()` derecho y una subclase anónima lo pisa, así una prueba con seis fotos no duerme
     * siete segundos por nada.
     *
     * @param int $microsegundos Cuánto esperar.
     *
     * @return void
     */
    protected function pausar(int $microsegundos): void
    {
        usleep($microsegundos);
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
     * El endpoint es multipart porque el contrato contempla `imagenes[]`. Laravel sabe convertir un
     * array asociativo a partes solo (`PendingRequest::parseMultipartBodyFormat()`), así que esto
     * NO existe para eso: existe por el **cast a string**. `ai_conversation_id` es un entero, y
     * Guzzle exige que el `contents` de una parte sea string o recurso — un entero le hace tirar
     * `InvalidArgumentException` justo en el único campo que viaja cuando el dueño citó un mensaje,
     * o sea en el camino que menos se prueba a mano.
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

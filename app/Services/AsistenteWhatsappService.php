<?php

namespace App\Services;

use App\Jobs\EnviarMensajeAlAsistenteJob;
use App\Models\Client;
use App\Models\ClientAssistantMessage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * El admin como intermediario entre WhatsApp y el asistente de IA del sistema del cliente.
 *
 * Lo que el dueño de un negocio le escribe al número de ComercioCity entra por el webhook de
 * Kapso y termina en el chat del asistente de SU propio `empresa-api` — el mismo del botón
 * flotante, la misma conversación, las mismas herramientas de carga. El admin no entiende nada
 * de lo que se está hablando: pone el mensaje del lado del cliente, espera la respuesta y la
 * devuelve por WhatsApp.
 *
 * 🔴 **Acá adentro no se hace ni una llamada HTTP.** El webhook de Kapso tiene que contestar 200
 * rápido o Meta lo reintenta, y el asistente puede tardar hasta 150 segundos en pensar. Todo lo
 * que sale a la red vive en `EnviarMensajeAlAsistenteJob`, que se devuelve a la cola con
 * `release()` mientras espera en vez de quedarse durmiendo con un worker tomado.
 *
 * Lo único que este servicio resuelve de la conversación es **la cita**: el `wamid` que el dueño
 * citó al responder. Es lo único que el `empresa-api` no puede resolver —nunca habló con Meta y
 * no conoce ningún `wamid`—, y es el mecanismo que Lucas eligió para separar conversaciones donde
 * no hay ningún botón de "nueva conversación". El corte por tiempo (6 hs sin hablar) lo decide el
 * `empresa-api`, que es el que tiene el `last_message_at` de verdad.
 */
class AsistenteWhatsappService
{
    /**
     * Texto que recibe el dueño cuando su sistema todavía no tiene el endpoint del asistente.
     *
     * 🔴 Dice la verdad y no pide disculpas genéricas. Es el caso MÁS FRECUENTE durante semanas:
     * las rutas `api/admin-sync/asistente/*` son nuevas y los 40+ clientes corren versiones
     * distintas. Un "hubo un error, probá más tarde" haría que el dueño reintente para siempre
     * sobre algo que no va a andar hasta que lo actualicen; esto le dice qué pasa y por dónde
     * seguir usando el asistente mientras tanto.
     */
    const TEXTO_SIN_ENDPOINT = 'Todavía no tenés habilitado el asistente por WhatsApp en tu sistema: '
        . 'te falta la actualización que lo trae. Ya quedó avisado el equipo de ComercioCity. '
        . 'Mientras tanto podés hablar con el asistente desde el sistema, con el botón del chat.';

    /**
     * Texto que recibe el dueño cuando el mensaje se cayó por cualquier otro motivo.
     *
     * No se le cuenta el detalle técnico: el 401 por la clave que falta, el 500 de su servidor o
     * los tres minutos de polling agotados son todos, desde su silla, lo mismo. El detalle queda
     * en la fila y en el aviso a los admins, que son los que pueden hacer algo.
     */
    const TEXTO_DE_DISCULPA = 'Uf, no pude contestarte esta vez. Ya quedó avisado el equipo de '
        . 'ComercioCity. Probá de nuevo en un rato, o escribime desde el chat del sistema.';

    /**
     * Prefijo de la clave de caché que limita el aviso del 404 a uno por cliente y por día.
     */
    const CACHE_AVISO_404 = 'asistente_whatsapp_aviso_404';

    /**
     * Conexión de cola por la que sale el job, explícita.
     *
     * 🔴 **Explícita y no la default, y esto es lo único que hace que el canal funcione.**
     * `QUEUE_CONNECTION` cae a `sync` cuando no está seteada, y en `sync` el job corre INLINE
     * adentro del webhook: el POST al cliente saldría dentro del request de Kapso, y peor, el
     * polling no existiría —`release()` sobre un `SyncJob` no reencola nada—, así que el dueño
     * nunca recibiría la respuesta y nada lo denunciaría. Es la misma clase de error que en este
     * repo ya dejó tres demos mudas con `RunDemoSetupJob`, y está escrita con todas las letras en
     * `ClaudeDemoOpsController`.
     *
     * ⚠️ Precondición de infraestructura: al job lo corre el worker
     * `queue:work database --stop-when-empty` que el scheduler dispara cada minuto. Si ese cron no
     * corre, este canal no hace NADA visible: la fila queda en `recibido` y el job dormido en
     * `jobs`. Esa fila en `recibido` es justamente la señal para mirar.
     */
    const CONEXION_DE_COLA = 'database';

    /**
     * Recibe un mensaje del dueño y lo deja encaminado hacia el asistente de su sistema.
     *
     * Deja la fila entrante SIEMPRE, incluso cuando después todo falle: es lo que permite
     * diagnosticar un mensaje que el dueño mandó y nunca le volvió. Sin esta tabla ese mensaje es
     * invisible — no abre ticket, no deja `SupportMessage`, y el `empresa-api` puede ni haberse
     * enterado.
     *
     * @param array<string, mixed> $parsed Resultado de `WhatsappWebhookController::parse_inbound_message()`.
     * @param Client               $client Cliente cuyo dueño escribió.
     *
     * @return ClientAssistantMessage La fila entrante ya persistida.
     */
    public function recibir(array $parsed, Client $client): ClientAssistantMessage
    {
        $reply_to = isset($parsed['reply_to_message_id']) ? trim((string) $parsed['reply_to_message_id']) : '';

        $fila                               = new ClientAssistantMessage();
        $fila->client_id                    = (int) $client->id;
        $fila->telefono                     = (string) $parsed['from'];
        $fila->direccion                    = ClientAssistantMessage::DIRECCION_ENTRANTE;
        $fila->whatsapp_message_id          = (string) $parsed['message_id'];
        $fila->reply_to_whatsapp_message_id = $reply_to !== '' ? $reply_to : null;
        $fila->tipo                         = substr((string) ($parsed['type'] ?? 'text'), 0, 20);
        $fila->texto                        = $parsed['body'];
        $fila->estado                       = ClientAssistantMessage::ESTADO_RECIBIDO;

        /* La cita se resuelve ACÁ y no en el job a propósito: es una consulta a una tabla propia,
         * no sale a la red, y dejarla resuelta en la fila hace que el hilo se pueda leer sin
         * reconstruir nada. Null significa "no hay cita que resolver" —el dueño no citó, o citó
         * algo que no es de este canal—, y en ese caso la conversación la decide el `empresa-api`
         * con su corte por tiempo. */
        $fila->ai_conversation_id = ClientAssistantMessage::conversacion_por_cita(
            (int) $client->id,
            $fila->reply_to_whatsapp_message_id
        );

        $fila->save();

        Log::channel('daily')->info('AsistenteWhatsapp: mensaje del dueño recibido.', [
            'client_id'           => $client->id,
            'assistant_message_id' => $fila->id,
            'tipo'                => $fila->tipo,
            'cito'                => $fila->reply_to_whatsapp_message_id !== null,
            'ai_conversation_id'  => $fila->ai_conversation_id,
        ]);

        /*
         * Las fotos viajan en el DESPACHO y no en una columna, y es a propósito.
         *
         * Lo que se manda acá es la metadata de Kapso (URL firmada, mime, id), no los bytes: los
         * bytes los baja el job, que es el único que puede salir a la red sin hacerle esperar el 200
         * a Meta. Guardar esa metadata en `client_assistant_messages` sería dejar una URL firmada
         * escrita en la base para siempre, que es justo lo que no se quiere; y guardar los bytes
         * sería quedarse con la factura de un tercero en un storage que no le corresponde.
         *
         * El payload del job sobrevive a los `release()` del polling —Laravel reencola el mismo—,
         * así que la metadata sigue ahí si el job vuelve a entrar. Lo que la fila sí guarda es que
         * el mensaje era una foto (`tipo`) y qué se descartó (`error`), que es lo que hace falta
         * para diagnosticar.
         */
        EnviarMensajeAlAsistenteJob::dispatch((int) $fila->id, $this->imagenes_del_mensaje($parsed))
            ->onConnection(self::CONEXION_DE_COLA);

        return $fila;
    }

    /**
     * La metadata de las fotos que trae un mensaje entrante, si trae alguna.
     *
     * Hoy devuelve a lo sumo UNA: WhatsApp manda un adjunto por mensaje y el webhook deja uno solo
     * en `inbound_media`. Igual se devuelve una lista y no un valor suelto, porque el contrato con
     * el `empresa-api` es `imagenes[]` y porque así el tope de tres tiene dónde aplicarse — el día
     * que Kapso agrupe un envío múltiple, o que alguien vuelva a correr el job a mano, no hay nada
     * que cambiar acá.
     *
     * Solo fotos: un audio ya llega transcripto en el texto y un PDF o un video no son algo que el
     * asistente pueda mirar, así que ni se intentan bajar.
     *
     * @param array<string, mixed> $parsed Resultado de `parse_inbound_message()`.
     *
     * @return array<int, array<string, mixed>>
     */
    private function imagenes_del_mensaje(array $parsed): array
    {
        if (strtolower((string) ($parsed['type'] ?? '')) !== 'image') {
            return [];
        }

        if (empty($parsed['inbound_media']) || ! is_array($parsed['inbound_media'])) {
            return [];
        }

        return [$parsed['inbound_media']];
    }

    /**
     * Deja la fila del mensaje que el asistente le devolvió al dueño.
     *
     * 🔴 Esta fila es la que hace funcionar la cita del PRÓXIMO turno: guarda el `wamid` con el
     * que salió la respuesta junto al `ai_conversation_id` de la que salió. Sin ella, citar la
     * respuesta del asistente no llevaría a ningún lado y cada mensaje del dueño abriría una
     * conversación nueva o caería en la última por tiempo.
     *
     * La fila se deja aunque Meta haya rechazado el envío (`$whatsapp_message_id` null): sin ella
     * no queda rastro de que hubo una respuesta que no llegó, que es justo lo que después hay que
     * poder ver.
     *
     * @param ClientAssistantMessage $entrante            Mensaje del dueño que se está respondiendo.
     * @param string                 $texto               Texto que salió (o que se intentó mandar).
     * @param string|null            $whatsapp_message_id wamid devuelto por Meta, o null si falló.
     * @param string                 $estado              Estado con el que nace la fila saliente.
     * @param string|null            $error               Detalle del fallo, si lo hubo.
     *
     * @return ClientAssistantMessage
     */
    public function registrar_saliente(
        ClientAssistantMessage $entrante,
        string $texto,
        ?string $whatsapp_message_id,
        string $estado,
        ?string $error = null
    ): ClientAssistantMessage {
        $fila = $this->registrar_saliente_de(
            (int) $entrante->client_id,
            (string) $entrante->telefono,
            $texto,
            $whatsapp_message_id,
            $entrante->ai_conversation_id,
            $estado,
            $error
        );

        $fila->ai_message_id = $entrante->ai_message_id;
        $fila->save();

        return $fila;
    }

    /**
     * Deja una fila saliente que NO responde a ningún mensaje del dueño.
     *
     * El caso es el informe de la mañana: lo manda el admin por su cuenta, sin que nadie haya
     * escrito antes, así que no hay fila entrante de la cual sacar el teléfono ni la conversación.
     *
     * 🔴 **Y esa fila es lo que hace que al informe se le pueda preguntar algo**, que es la mitad
     * del pedido de Lucas: *"para abrirlos desde el celular y poder preguntarles cosas"*. Sin ella,
     * el dueño responde citando el informe —*"¿por qué bajó la caja?"*— y la cita no resuelve
     * ninguna conversación: la pregunta cae en el hilo genérico de WhatsApp y el asistente contesta
     * sin el informe delante. Con ella, la cita lo manda a la conversación del informe, que del
     * lado del cliente ya nace con el contexto armado.
     *
     * @param int         $client_id           Cliente dueño del hilo.
     * @param string      $telefono            E.164 del dueño.
     * @param string      $texto               Lo que salió (o lo que se intentó mandar).
     * @param string|null $whatsapp_message_id wamid de Meta, o null si el envío falló.
     * @param int|null    $ai_conversation_id  Conversación del `empresa-api` a la que lleva la cita.
     * @param string      $estado              Estado con el que nace la fila.
     * @param string|null $error               Detalle del fallo, si lo hubo.
     *
     * @return ClientAssistantMessage
     */
    public function registrar_saliente_de(
        int $client_id,
        string $telefono,
        string $texto,
        ?string $whatsapp_message_id,
        ?int $ai_conversation_id,
        string $estado,
        ?string $error = null
    ): ClientAssistantMessage {
        $fila                      = new ClientAssistantMessage();
        $fila->client_id           = $client_id;
        $fila->telefono            = $telefono;
        $fila->direccion           = ClientAssistantMessage::DIRECCION_SALIENTE;
        $fila->whatsapp_message_id = $whatsapp_message_id;
        $fila->ai_conversation_id  = $ai_conversation_id;
        $fila->tipo                = 'text';
        $fila->texto               = $texto;
        $fila->estado              = $estado;
        $fila->error               = $error;
        $fila->save();

        return $fila;
    }

    /**
     * Avisa a los admins que el sistema de un cliente todavía no tiene el endpoint del asistente.
     *
     * 🔴 Una vez por cliente y por día, y ese techo no es cosmético. Con el canal prendido sobre
     * un cliente sin actualizar, CADA mensaje que ese dueño mande da 404: un dueño conversador
     * puede generar cincuenta avisos en una tarde, y cincuenta avisos son cero avisos. El aviso
     * sirve para acordarse de actualizarlo, y para eso alcanza y sobra con uno por día.
     *
     * Ojo con la lectura del resultado: el aviso lo manda `SystemErrorWhatsappService`, que tiene
     * ADEMÁS su propio throttle global de 10 minutos. O sea que este `true` significa "no estaba
     * avisado hoy", no "el WhatsApp salió".
     *
     * @param Client $client Cliente sin el endpoint.
     *
     * @return bool True si este llamado fue el primero del día para ese cliente.
     */
    public function avisar_cliente_sin_endpoint(Client $client): bool
    {
        $clave = self::CACHE_AVISO_404 . ':' . $client->id . ':' . now()->toDateString();

        /* `add()` es atómico: devuelve false si la clave ya estaba. Con dos mensajes del mismo
         * dueño llegando a la vez, uno solo avisa. */
        if (! Cache::add($clave, 1, now()->addDay())) {
            return false;
        }

        $nombre = trim((string) ($client->company_name ?? '')) !== ''
            ? (string) $client->company_name
            : (string) $client->name;

        $this->avisar_a_los_admins(
            'Asistente por WhatsApp - ' . $nombre . ' (cliente #' . $client->id . ')',
            'El dueño le escribió al asistente pero su sistema todavía no tiene las rutas '
                . 'api/admin-sync/asistente/*. Hay que actualizarlo, o apagarle la casilla '
                . '"Habla con su asistente por WhatsApp" en su ficha.'
        );

        return true;
    }

    /**
     * Avisa a los admins de un fallo del canal.
     *
     * Va adentro de su propio try: el aviso es lo ÚLTIMO que pasa en cada camino de error, y que
     * falle no puede voltear el trabajo que ya quedó persistido ni impedir que el dueño reciba su
     * texto de disculpa.
     *
     * @param string $contexto Descripción corta de dónde pasó.
     * @param string $detalle  Qué pasó.
     *
     * @return void
     */
    public function avisar_a_los_admins(string $contexto, string $detalle): void
    {
        try {
            app(SystemErrorWhatsappService::class)->notify_send_error($contexto, $detalle);
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('AsistenteWhatsapp: no se pudo avisar a los admins.', [
                'contexto' => $contexto,
                'detalle'  => $detalle,
                'error'    => $exception->getMessage(),
            ]);
        }
    }
}

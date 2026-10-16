<?php

namespace App\Services;

use App\Jobs\EnviarMensajeAlAsistenteJob;
use App\Models\Client;
use App\Models\ClientAssistantMessage;
use App\Models\WhatsappConfig;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
     * Texto que recibe el dueño cuando manda algo que el asistente no puede leer: un PDF, un video,
     * un sticker… sin nada escrito (misión asistente-envios-a-clientes, 16/10/2026).
     *
     * 🔴 No es una disculpa ni un error, y por eso no es `TEXTO_DE_DISCULPA`: no falló nada y no hay
     * nadie a quien avisar. Le dice qué SÍ puede mandar, que es lo único que le sirve. Lo manda el
     * admin, sin pasar por el sistema del cliente: el contrato no tiene cómo llevar ese mensaje. Ver
     * `no_se_puede_leer()`.
     */
    const TEXTO_NO_SE_PUEDE_LEER = 'Ese tipo de mensaje todavía no lo puedo leer: escribime, '
        . 'mandame una nota de voz o una foto.';

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
     * ⚠️ Precondición de infraestructura: al job lo corre un worker de la cola `asistente` (ver
     * `COLA_DEL_ASISTENTE`, justo abajo). Si no corre ninguno, este canal no hace NADA visible: la
     * fila queda en `recibido` y el job dormido en `jobs`. Esa fila en `recibido` es justamente la
     * señal para mirar.
     */
    const CONEXION_DE_COLA = 'database';

    /**
     * Cola propia del canal, adentro de la conexión `database` (misión asistente-envios-a-clientes,
     * 16/10/2026).
     *
     * 🔴 **Por qué una cola propia y no `default`.** Hasta el 16/10/2026 el job salía por `default`,
     * que la consumía un solo worker: el `queue:work database --stop-when-empty` que el scheduler
     * lanza una vez por minuto. Cada ingreso del job —la ida y cada `release()` del polling— esperaba
     * al tick siguiente, y ese mismo worker además corre deployments (30 min), actualizaciones de
     * demo y demo setups: medido en la prueba del agente personal del 16/10, cada respuesta llegaba
     * 60 a 150 segundos después de que el sistema del cliente ya la tenía lista. En su propia cola el
     * canal no compite con nadie y lo atienden dos cosas:
     *
     *   1. **El worker permanente**: el programa de supervisor `api_admin_queue_asistente` del VPS
     *      del admin (`queue:work database --queue=asistente --sleep=1 ...`). Levanta cada ingreso en
     *      un segundo. Se instala al desplegar, con el ok de Lucas; NO lo instala el código.
     *   2. **La red**: un `queue:work database --queue=asistente --stop-when-empty` que el scheduler
     *      lanza cada minuto (`App\Console\Kernel`). Si el programa de supervisor no está —un admin
     *      recién desplegado sin el programa, o el programa caído—, el canal sigue andando con la
     *      demora de antes, no se queda mudo.
     *
     * El worker de `default` NO mira esta cola: si alguien la cambia acá sin la red del Kernel y sin
     * el programa de supervisor, el canal se queda sin consumidor y nada lo avisa.
     *
     * `release()` conserva la cola, pero ojo con el porqué: en Laravel 8.83 `DatabaseJob::release()`
     * reencola con `$this->queue`, que NO es la columna `queue` de la fila sino la cola que el worker
     * pidió en el `pop()` (el worker recorre su `--queue` de a una y le pasa al job la que lo
     * encontró). Los dos workers del canal piden `--queue=asistente`, así que da lo mismo y el
     * polling entero vive en `asistente` sin volver a pedirlo. Un worker que levantara este job desde
     * otra cola lo reencolaría en esa otra.
     */
    const COLA_DEL_ASISTENTE = 'asistente';

    /**
     * Segundos que espera una foto sin epígrafe si la configuración no dice otra cosa.
     *
     * Treinta es lo que pidió Lucas: el tiempo de sacar la foto, apretar el micrófono y decir
     * "cargame este artículo". Se ajusta con `ASISTENTE_WHATSAPP_ESPERA_FOTO` sin tocar código.
     */
    const SEGUNDOS_DE_ESPERA_DE_FOTO_POR_DEFECTO = 30;

    /**
     * Cuánto vive una foto en espera, en minutos.
     *
     * 🔴 Es el techo que impide que una foto cuyo job se perdió —un worker caído, un deploy en el
     * medio— se pegue a un mensaje de horas después. El dueño que manda "cuánto vendí ayer" a la
     * tarde no está hablando de la factura que fotografió a la mañana, y mandarlas juntas haría
     * que el asistente conteste sobre algo que nadie le preguntó. Se mide contra la fila que CIERRA
     * la espera (el mensaje nuevo, o la última foto de la ráfaga), no contra el reloj del job: así
     * una cola atrasada no deja huérfana a una foto que su ráfaga sí tenía que llevarse.
     */
    const MINUTOS_DE_VIGENCIA_DE_LA_ESPERA = 10;

    /**
     * Intentos de la transacción que reclama las fotos en espera.
     *
     * El webhook y el job de la última foto pueden reclamar las mismas filas al mismo tiempo, y
     * con `lockForUpdate` sobre dos lecturas por índice MySQL puede elegir a uno de los dos como
     * víctima de un deadlock. `DB::transaction()` reintenta justamente ese caso.
     */
    const INTENTOS_DEL_RECLAMO = 3;

    /**
     * Margen, en segundos, que se le da al temporizador de una foto más allá de su espera.
     *
     * Una foto más nueva "sostiene" la ráfaga —las anteriores le ceden el cierre— solo mientras su
     * propio temporizador todavía puede llegar: su espera más este margen, que cubre la demora
     * normal del worker en levantar un job vencido. Pasado eso, se la da por perdida y el
     * temporizador que esté corriendo cierra la ráfaga entera. Ver `cerrar_espera_vencida()`.
     */
    const SEGUNDOS_DE_MARGEN_DEL_TEMPORIZADOR = 60;

    /**
     * Recibe un mensaje del dueño y lo deja encaminado hacia el asistente de su sistema.
     *
     * Deja la fila entrante SIEMPRE, incluso cuando después todo falle: es lo que permite
     * diagnosticar un mensaje que el dueño mandó y nunca le volvió. Sin esta tabla ese mensaje es
     * invisible — no abre ticket, no deja `SupportMessage`, y el `empresa-api` puede ni haberse
     * enterado.
     *
     * **La foto sin epígrafe espera su instrucción** (misión asistente-espera-foto, 27/9/2026). El
     * dueño manda la foto de una factura o de un artículo y enseguida un audio diciendo qué hacer
     * con ella. Si cada uno fuera su propio turno, la foto sola dispararía uno caro (un turno con
     * foto arranca escalado) y el audio otro donde el modelo YA NO VE la foto: del lado del
     * `empresa-api` solo viajan al modelo las imágenes del último mensaje del dueño. Por eso acá se
     * bifurca:
     *
     *   - **Foto sin epígrafe**: la fila nace `esperando`, con la metadata cifrada en
     *     `media_en_espera`, y el job sale con `delay`. No se le manda nada al cliente todavía.
     *     Otra foto sin epígrafe hace lo mismo, y la ventana se reinicia con ella (factura de dos
     *     páginas): la ráfaga la cierra el job de la ÚLTIMA foto.
     *   - **Cualquier otra cosa** (texto, audio, foto con epígrafe, documento): se reclaman las
     *     fotos que ese dueño tenía esperando y viajan en el job de este mensaje, antes que las
     *     propias. Un solo POST con la instrucción y las fotos, que el contrato con el `empresa-api`
     *     ya soporta (`imagenes[]` no mira el `tipo`).
     *
     * ⚠️ El `delay` depende de quién atienda la cola `asistente` (ver `COLA_DEL_ASISTENTE`). Con el
     * worker permanente de supervisor (`api_admin_queue_asistente`, que se instala en el VPS del admin
     * al desplegar la misión asistente-envios-a-clientes) la foto sale a los ~30 s. Sin él la atiende
     * solo la red del Kernel —el `queue:work database --queue=asistente --stop-when-empty` de cada
     * minuto— y la foto sale al primer tick después de los 30 s, que es lento pero igual correcto.
     * (Hasta el 16/10/2026 este comentario decía que ya existía un worker de supervisor
     * `api_admin_queue` desde el 21/9: no existía. La migración del admin al VPS dejó en supervisor
     * solo el scheduler, `api_admin_schedule`, y el worker de cola lo siguió lanzando el Kernel.)
     *
     * **El mensaje que el asistente no puede leer** (misión asistente-envios-a-clientes, 16/10/2026):
     * un documento, un video, un sticker, una ubicación… que llega SIN texto y sin fotos. El contrato
     * con el `empresa-api` no tiene con qué representarlo —un `texto` vacío sin `imagenes[]` es un
     * 422, y el 422 terminaba en el «Uf, no pude contestarte» y en un aviso al equipo por algo que no
     * es ninguna falla—. Ver `no_se_puede_leer()`. Se decide ACÁ, y no recién en el job, por dos
     * cosas que solo este punto ve:
     *
     *   - **El archivo de audio.** WhatsApp Web manda un audio adjuntado como `document` con mime
     *     `audio/*`, y el mime solo viene en el `inbound_media` del webhook, que no llega al job (el
     *     job lleva fotos, nada más). Ese documento se guarda como `tipo = audio` sin texto, y así
     *     viaja: del otro lado es exactamente un audio que Kapso no transcribió, y el `empresa-api`
     *     ya tiene su respuesta fija para eso. Con epígrafe no cambia nada: el epígrafe es lo que hay
     *     para contestar y viaja como siempre.
     *   - **La foto como archivo.** El dueño que manda la factura como Documento (para que WhatsApp
     *     no la comprima) llega como `document` con mime `image/*`. Es una foto y se trata como
     *     foto en todo (revisión independiente de la misión, 16/10/2026): `imagenes_del_mensaje()` la
     *     incluye con la misma descarga y los mismos límites, la fila se guarda como `tipo = image`,
     *     sin epígrafe espera su instrucción igual que cualquier foto y con epígrafe viaja como texto
     *     más foto. Antes de esto recibía «mandame una foto», que era justo lo que había mandado.
     *   - **La espera de las fotos.** Un mensaje que no se puede leer NO reclama las fotos que el
     *     dueño tenía esperando: no trae ninguna instrucción, y llevárselas haría que salieran sin la
     *     que el dueño todavía está por mandar. Siguen esperando su audio, o su temporizador.
     *
     * El texto fijo lo manda el job (acá no se sale a la red), por la cola `asistente`: con el worker
     * permanente sale en un segundo. La cita no cambia: la fila resuelve su conversación por cita como
     * cualquier otra, y la respuesta fija queda como saliente de esa misma conversación (o de ninguna,
     * si el dueño no citó), así que citarla después lleva a donde el dueño estaba.
     *
     * @param array<string, mixed> $parsed Resultado de `WhatsappWebhookController::parse_inbound_message()`.
     * @param Client               $client Cliente cuyo dueño escribió.
     *
     * @return ClientAssistantMessage La fila entrante ya persistida.
     */
    public function recibir(array $parsed, Client $client): ClientAssistantMessage
    {
        $reply_to = isset($parsed['reply_to_message_id']) ? trim((string) $parsed['reply_to_message_id']) : '';

        $imagenes           = $this->imagenes_del_mensaje($parsed);
        $segundos           = $this->segundos_de_espera_de_foto();
        $espera             = $segundos > 0 && $this->es_foto_sin_epigrafe($parsed, $imagenes);
        $documento_de_audio = $this->es_documento_de_audio_sin_epigrafe($parsed);
        $documento_de_foto  = $this->es_documento_de_imagen($parsed);

        /* El documento de audio y el de foto se guardan como lo que son para el asistente: un audio
         * sin transcribir y una foto. Va ANTES de mirar si se puede leer, porque las dos cosas se
         * pueden leer (el audio tiene su respuesta fija del otro lado; la foto viaja en `imagenes[]`),
         * y la foto con `image` toma el mismo `tipo` del contrato (`imagen`) que una foto común. */
        if ($documento_de_audio) {
            $tipo = 'audio';
        } elseif ($documento_de_foto) {
            $tipo = 'image';
        } else {
            $tipo = substr((string) ($parsed['type'] ?? 'text'), 0, 20);
        }

        /* `$espera` y esto se excluyen solos: la foto que espera trae una foto, y un mensaje con fotos
         * siempre se puede leer. */
        $no_se_puede_leer = $this->no_se_puede_leer($parsed['body'] ?? null, $tipo, $imagenes);

        $fila                               = new ClientAssistantMessage();
        $fila->client_id                    = (int) $client->id;
        $fila->telefono                     = (string) $parsed['from'];
        $fila->phone_number_id              = $this->phone_number_id_del_payload($parsed);
        $fila->direccion                    = ClientAssistantMessage::DIRECCION_ENTRANTE;
        $fila->whatsapp_message_id          = (string) $parsed['message_id'];
        $fila->reply_to_whatsapp_message_id = $reply_to !== '' ? $reply_to : null;
        $fila->tipo                         = $tipo;
        $fila->texto                        = $parsed['body'];
        $fila->estado                       = $espera
            ? ClientAssistantMessage::ESTADO_ESPERANDO
            : ClientAssistantMessage::ESTADO_RECIBIDO;

        /* La cita se resuelve ACÁ y no en el job a propósito: es una consulta a una tabla propia,
         * no sale a la red, y dejarla resuelta en la fila hace que el hilo se pueda leer sin
         * reconstruir nada. Null significa "no hay cita que resolver" —el dueño no citó, o citó
         * algo que no es de este canal—, y en ese caso la conversación la decide el `empresa-api`
         * con su corte por tiempo. */
        $fila->ai_conversation_id = ClientAssistantMessage::conversacion_por_cita(
            (int) $client->id,
            $fila->reply_to_whatsapp_message_id
        );

        /* 🔴 La metadata de la foto que espera va CIFRADA (cast `encrypted:array` del modelo) y
         * vive ahí solo hasta que alguien la reclama: el reclamo la pone en null en el mismo
         * movimiento en que la saca. Mientras espera, el job no la lleva en el payload —la tabla
         * `jobs` la guardaría en claro durante toda la espera—. */
        if ($espera) {
            $fila->media_en_espera = $imagenes;
        }

        $fila->save();

        Log::channel('daily')->info('AsistenteWhatsapp: mensaje del dueño recibido.', [
            'client_id'           => $client->id,
            'assistant_message_id' => $fila->id,
            'tipo'                => $fila->tipo,
            'cito'                => $fila->reply_to_whatsapp_message_id !== null,
            'ai_conversation_id'  => $fila->ai_conversation_id,
            'espera_instruccion'  => $espera,
            'documento_de_audio'  => $documento_de_audio,
            'documento_de_foto'   => $documento_de_foto,
            'no_se_puede_leer'    => $no_se_puede_leer,
        ]);

        if ($no_se_puede_leer) {
            /* Sin pasar por el reclamo de las fotos en espera: ver el comentario del método. El job
             * vuelve a mirar `no_se_puede_leer()` con lo que quedó en la fila y contesta el texto fijo
             * sin hablar con el sistema del cliente. */
            EnviarMensajeAlAsistenteJob::dispatch((int) $fila->id)
                ->onConnection(self::CONEXION_DE_COLA)
                ->onQueue(self::COLA_DEL_ASISTENTE);

            return $fila;
        }

        if ($espera) {
            /* El tercer parámetro es la espera, y no es decorativo: `retryUntil()` la suma a su
             * ventana, que Laravel calcula al DESPACHAR y no cuando el job por fin corre. */
            EnviarMensajeAlAsistenteJob::dispatch((int) $fila->id, [], $segundos)
                ->onConnection(self::CONEXION_DE_COLA)
                ->onQueue(self::COLA_DEL_ASISTENTE)
                ->delay(now()->addSeconds($segundos));

            return $fila;
        }

        /*
         * Las fotos viajan en el DESPACHO y no en una columna, y es a propósito.
         *
         * Lo que se manda acá es la metadata de Kapso (URL firmada, mime, id), no los bytes: los
         * bytes los baja el job, que es el único que puede salir a la red sin hacerle esperar el 200
         * a Meta. Guardar esa metadata en `client_assistant_messages` sería dejar una URL firmada
         * escrita en la base para siempre, que es justo lo que no se quiere; y guardar los bytes
         * sería quedarse con la factura de un tercero en un storage que no le corresponde. (La foto
         * que ESPERA es la única excepción, y va cifrada y por minutos: ver arriba.)
         *
         * El payload del job sobrevive a los `release()` del polling —Laravel reencola el mismo—,
         * así que la metadata sigue ahí si el job vuelve a entrar. Lo que la fila sí guarda es que
         * el mensaje era una foto (`tipo`) y qué se descartó (`error`), que es lo que hace falta
         * para diagnosticar.
         *
         * Las fotos que el dueño tenía esperando van ANTES que las propias: es el orden en que las
         * mandó, y el asistente lee la primera como la principal (el encabezado de la factura).
         *
         * El despacho lo hace el reclamo, adentro de su transacción: ver `reclamar_fotos_en_espera()`.
         */
        $this->reclamar_fotos_en_espera($fila, $imagenes);

        return $fila;
    }

    /**
     * Segundos que espera una foto sin epígrafe antes de salir sola. Cero apaga la espera.
     *
     * Acotado a la vigencia de una foto en espera: una espera más larga que la vigencia haría que un
     * texto que llega a tiempo desde la silla del dueño ya no pudiera llevarse la foto.
     *
     * @return int
     */
    public function segundos_de_espera_de_foto(): int
    {
        $crudo = config(
            'services.asistente_whatsapp.segundos_de_espera_de_foto',
            self::SEGUNDOS_DE_ESPERA_DE_FOTO_POR_DEFECTO
        );

        /* Un valor que no es número (la variable escrita vacía en el `.env`) cae al default y no a
         * cero: apagar la espera tiene que ser una decisión, no un typo. */
        $segundos = is_numeric($crudo) ? (int) $crudo : self::SEGUNDOS_DE_ESPERA_DE_FOTO_POR_DEFECTO;

        if ($segundos < 0) {
            return 0;
        }

        return min($segundos, self::MINUTOS_DE_VIGENCIA_DE_LA_ESPERA * 60);
    }

    /**
     * Se lleva las fotos que el dueño tenía esperando y despacha el job del mensaje con ellas.
     *
     * Lo llama `recibir()` con la fila del mensaje que cierra ya guardada. Reclama las fotos del
     * MISMO dueño (`client_id` + `telefono`), anteriores a este mensaje y dentro de la vigencia; las
     * pasa a `agrupado` en esta fila, les borra `media_en_espera` y despacha el job de este mensaje
     * con su metadata en orden de llegada, antes que las propias. Una foto que llegó DESPUÉS de
     * este mensaje no se toca: esa espera sus segundos y sale sola (el caso "audio primero, foto
     * después", que el plan deja afuera a propósito).
     *
     * 🔴 Transacción con `lockForUpdate`, porque el job de la última foto puede estar reclamando las
     * mismas filas en el mismo instante (se le venció la espera justo cuando llegó el audio). El que
     * toma el lock primero se las lleva; el otro las relee después del commit, ya en `agrupado` o
     * en `recibido`, y no las encuentra. Sin el lock, la misma foto podía viajar dos veces.
     *
     * 🔴 **Y el despacho va ADENTRO de esa transacción.** La conexión `database` de la cola no
     * declara `connection` (usa la de siempre) y tiene `after_commit => false`, así que el insert en
     * `jobs` es parte de la misma transacción que el reclamo: o quedan las dos cosas o ninguna. Si
     * el despacho quedara afuera, un corte entre el commit y el insert dejaba las fotos `agrupado`,
     * sin metadata y sin ningún job que las lleve; y un deadlock que reintenta la transacción
     * despacharía una vez por intento. Si alguien cambia la cola a otra conexión, esto deja de ser
     * atómico sin que nada lo avise.
     *
     * Si el reclamo falla, la transacción se deshace entera —el job que alcanzó a encolar también—,
     * y el mensaje sale igual por el despacho de repliegue, sin las fotos. Las fotos siguen
     * esperando y salen solas cuando se les venza la espera: nada se pierde, solo el agrupamiento.
     * Ese despacho de repliegue es el único que queda, porque el de adentro se deshizo.
     *
     * @param ClientAssistantMessage           $fila             Fila del mensaje que cierra, ya persistida.
     * @param array<int, array<string, mixed>> $imagenes_propias Fotos que trae el propio mensaje.
     *
     * @return void
     */
    public function reclamar_fotos_en_espera(ClientAssistantMessage $fila, array $imagenes_propias = []): void
    {
        $despachar = function (array $reclamadas) use ($fila, $imagenes_propias) {
            EnviarMensajeAlAsistenteJob::dispatch((int) $fila->id, $this->fotos_que_viajan($reclamadas, $imagenes_propias))
                ->onConnection(self::CONEXION_DE_COLA)
                ->onQueue(self::COLA_DEL_ASISTENTE);
        };

        $desde = ($fila->created_at !== null ? $fila->created_at->copy() : now())
            ->subMinutes(self::MINUTOS_DE_VIGENCIA_DE_LA_ESPERA);

        $en_espera = function () use ($fila, $desde) {
            return ClientAssistantMessage::query()
                ->enEsperaDelDueno((int) $fila->client_id, (string) $fila->telefono)
                ->where('id', '<', (int) $fila->id)
                ->where('created_at', '>=', $desde);
        };

        /* Casi ningún mensaje tiene fotos esperando: se mira primero sin lock, para no tomar locks
         * de rango en cada texto del dueño. La lectura que vale es la de adentro de la transacción. */
        if (! $en_espera()->exists()) {
            $despachar([]);

            return;
        }

        try {
            DB::transaction(function () use ($fila, $en_espera, $despachar) {
                $fotos      = $en_espera()->orderBy('id')->lockForUpdate()->get();
                $reclamadas = [];

                if ($fotos->isNotEmpty()) {
                    $reclamadas = $this->agrupar_fotos_en($fila, $fotos);

                    if ($fila->isDirty()) {
                        $fila->save();
                    }

                    Log::channel('daily')->info('AsistenteWhatsapp: el mensaje se llevó fotos que esperaban su instrucción.', [
                        'client_id'            => $fila->client_id,
                        'assistant_message_id' => $fila->id,
                        'fotos_agrupadas'      => $fotos->count(),
                    ]);
                }

                /* Lo último de la transacción, a propósito: si algo falla antes, no se encoló nada. */
                $despachar($reclamadas);
            }, self::INTENTOS_DEL_RECLAMO);
        } catch (\Throwable $exception) {
            Log::channel('daily')->warning('AsistenteWhatsapp: no se pudieron reclamar las fotos en espera; salen solas.', [
                'client_id'            => $fila->client_id,
                'assistant_message_id' => $fila->id,
                'error'                => $exception->getMessage(),
            ]);

            $despachar([]);
        }
    }

    /**
     * Arma la lista de fotos del job de un mensaje que se llevó fotos en espera.
     *
     * 🔴 **Las fotos PROPIAS del mensaje que cierra entran siempre.** El tope es de
     * `AsistenteImagenesService::MAXIMO_DE_IMAGENES` por POST y `preparar()` se queda con las
     * primeras de la lista. Si la lista fuera solo el orden de llegada —reclamadas primero—, con
     * tres fotos esperando la que quedaría afuera sería justo la foto CON epígrafe, que es la que
     * trae la instrucción ("cargá esta compra"). Así que los lugares se reparten: las propias
     * primero, y lo que sobra hasta el tope, con las reclamadas más viejas.
     *
     * El orden en el POST sigue siendo el de llegada. La lista sale con las que entran —las
     * reclamadas elegidas y después las propias, que son las más nuevas— y al final las que quedan
     * afuera, para que `preparar()` se quede con las primeras y la nota del tope las cuente.
     *
     * @param array<int, array<string, mixed>> $reclamadas Fotos que esperaban, en orden de llegada.
     * @param array<int, array<string, mixed>> $propias    Fotos del mensaje que cierra.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fotos_que_viajan(array $reclamadas, array $propias): array
    {
        $lugares = max(0, AsistenteImagenesService::MAXIMO_DE_IMAGENES - count($propias));

        return array_merge(
            array_slice($reclamadas, 0, $lugares),
            $propias,
            array_slice($reclamadas, $lugares)
        );
    }

    /**
     * Cierra la ráfaga de fotos cuando se le venció la espera a una, sin que llegara instrucción.
     *
     * Lo llama `EnviarMensajeAlAsistenteJob` cuando su fila todavía está `esperando`. Devuelve null
     * —y no toca nada— en los dos casos en que no le corresponde cerrar:
     *
     *   - su foto ya no está esperando: la reclamó un mensaje, que la lleva en su propio job;
     *   - hay una foto MÁS NUEVA del mismo dueño esperando cuyo temporizador todavía puede llegar:
     *     la ráfaga sigue abierta y la cierra el job de esa última foto, que se lleva a esta. Es lo
     *     que hace que la ventana se reinicie con cada foto.
     *
     * 🔴 "Todavía puede llegar" es la mitad del arreglo de las fotos huérfanas. Una foto más nueva
     * sostiene la ráfaga solo mientras no pasó su propio vencimiento (su espera más
     * `SEGUNDOS_DE_MARGEN_DEL_TEMPORIZADOR`). Si ya pasó, su temporizador se perdió o viene muy
     * atrasado, y ceder el cierre a él sería dejar a todas esperando para siempre: este
     * temporizador cierra la ráfaga ENTERA, incluidas las más nuevas, que quedan `agrupado` en esta
     * fila. Su temporizador, si alguna vez llega, sale en silencio por la guarda de estado final.
     *
     * Si le corresponde, pasa las otras fotos a `agrupado` en esta, vuelve la propia a `recibido`,
     * borra la metadata de todas y despacha —en la misma transacción— el job común que las lleva en
     * orden de llegada; devuelve esa misma lista para el log. Es exactamente lo que pasaba
     * antes con una foto sola: el POST sale con las fotos y el texto vacío. Acá no se aplica
     * `fotos_que_viajan()`: ninguna de estas fotos trae la instrucción, así que con más de tres
     * viajan las primeras, que en una factura son las del encabezado.
     *
     * 🔴 Las lecturas salen de UNA consulta con `lockForUpdate` sobre las fotos en espera del
     * dueño, la misma forma que la del webhook: si el webhook y este job leyeran en órdenes
     * distintos (primero la propia por id, después las demás por índice), MySQL tendría con qué
     * armar un deadlock entre los dos.
     *
     * La vigencia se mide contra ESTA foto y no contra el reloj: con la cola atrasada, las fotos de
     * una misma ráfaga tienen que seguir encontrándose entre sí aunque ya hayan pasado diez minutos
     * desde la primera.
     *
     * @param ClientAssistantMessage $fila Foto cuya espera venció.
     *
     * @return array{imagenes: array<int, array<string, mixed>>, agrupadas: int}|null
     */
    public function cerrar_espera_vencida(ClientAssistantMessage $fila): ?array
    {
        $creada = $fila->created_at !== null ? $fila->created_at->copy() : now();
        $desde  = $creada->copy()->subMinutes(self::MINUTOS_DE_VIGENCIA_DE_LA_ESPERA);
        $hasta  = $creada->copy()->addMinutes(self::MINUTOS_DE_VIGENCIA_DE_LA_ESPERA);

        /* Una foto creada DESPUÉS de este límite todavía tiene su temporizador en camino. */
        $limite_de_sosten = now()->subSeconds(
            $this->segundos_de_espera_de_foto() + self::SEGUNDOS_DE_MARGEN_DEL_TEMPORIZADOR
        );

        return DB::transaction(function () use ($fila, $desde, $hasta, $limite_de_sosten) {
            $en_espera = ClientAssistantMessage::query()
                ->enEsperaDelDueno((int) $fila->client_id, (string) $fila->telefono)
                ->where('created_at', '>=', $desde)
                ->where('created_at', '<=', $hasta)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $id_propio = (int) $fila->id;

            $propia = $en_espera->first(function ($foto) use ($id_propio) {
                return (int) $foto->id === $id_propio;
            });

            if ($propia === null) {
                return null;
            }

            $la_sostiene_una_mas_nueva = $en_espera->contains(function ($foto) use ($id_propio, $limite_de_sosten) {
                return (int) $foto->id > $id_propio
                    && $foto->created_at !== null
                    && $foto->created_at->greaterThan($limite_de_sosten);
            });

            if ($la_sostiene_una_mas_nueva) {
                return null;
            }

            /* Todas las que esperan, en orden de llegada: las anteriores y, si las hay, las más
             * nuevas cuyo temporizador ya no va a llegar. La propia va en su lugar de la fila. */
            $imagenes = $this->agrupar_fotos_en($propia, $en_espera);

            $propia->estado          = ClientAssistantMessage::ESTADO_RECIBIDO;
            $propia->media_en_espera = null;
            $propia->save();

            /* 🔴 El job común se despacha ADENTRO de la transacción, por lo mismo que en
             * `reclamar_fotos_en_espera()`: el insert en `jobs` va en la misma transacción que el
             * reclamo. Si algo falla, se deshacen juntos —las fotos siguen esperando, con su
             * metadata— y el temporizador cierra con error; y un reintento por deadlock no deja un
             * job por intento. Va con las fotos en el payload porque `release()` reencola el payload
             * ORIGINAL: ver `EnviarMensajeAlAsistenteJob::cerrar_la_espera()`. */
            EnviarMensajeAlAsistenteJob::dispatch($id_propio, $imagenes)
                ->onConnection(self::CONEXION_DE_COLA)
                ->onQueue(self::COLA_DEL_ASISTENTE);

            return [
                'imagenes'  => $imagenes,
                'agrupadas' => $en_espera->count() - 1,
            ];
        }, self::INTENTOS_DEL_RECLAMO);
    }

    /**
     * Cierra con error las fotos que el dueño tenía esperando ANTES de una foto que se cerró con
     * error sin viajar.
     *
     * 🔴 Es la otra mitad del arreglo de las fotos huérfanas. Las fotos anteriores de una ráfaga le
     * cedieron el cierre a la última; si la última termina en error —el canal se apagó en el medio,
     * el cliente desapareció, el reclamo agotó sus reintentos, el worker la dio por muerta—, nadie
     * más las iba a cerrar y quedaban `esperando` para siempre, con la URL firmada guardada. Se
     * cierran todas de una, con un update: SIN una disculpa por cada una. La que ya manda la
     * disculpa (si corresponde) es la fila que se cerró.
     *
     * Va en su propio `try`: se llama desde los caminos que cierran con error, y un fallo acá no
     * puede voltear el cierre que ya quedó escrito.
     *
     * @param ClientAssistantMessage $fila   Foto que se cerró con error.
     * @param string                 $motivo Qué pasó, para el `error` de cada foto.
     *
     * @return int Cuántas fotos se cerraron.
     */
    public function cerrar_fotos_anteriores_en_espera(ClientAssistantMessage $fila, string $motivo): int
    {
        try {
            $cerradas = ClientAssistantMessage::query()
                ->enEsperaDelDueno((int) $fila->client_id, (string) $fila->telefono)
                ->where('id', '<', (int) $fila->id)
                ->update([
                    'estado'          => ClientAssistantMessage::ESTADO_ERROR,
                    'media_en_espera' => null,
                    'error'           => mb_strimwidth($motivo, 0, 1000, '…'),
                ]);
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('AsistenteWhatsapp: no se pudieron cerrar las fotos que esperaban.', [
                'assistant_message_id' => $fila->id,
                'error'                => $exception->getMessage(),
            ]);

            return 0;
        }

        if ($cerradas > 0) {
            Log::channel('daily')->warning('AsistenteWhatsapp: se cerraron con error fotos que esperaban su instrucción.', [
                'client_id'            => $fila->client_id,
                'assistant_message_id' => $fila->id,
                'fotos_cerradas'       => $cerradas,
            ]);
        }

        return (int) $cerradas;
    }

    /**
     * Pasa un grupo de fotos en espera a `agrupado` en la fila que las cierra.
     *
     * Devuelve la metadata de todas, en el orden en que vienen, y deja a cada una sin
     * `media_en_espera`. El grupo puede traer adentro a la propia fila que cierra (el temporizador
     * que cierra la ráfaga la pasa en su lugar de llegada): su metadata se toma en ese lugar, pero
     * no se la agrupa en sí misma —su estado lo decide quien llama—.
     *
     * Si la fila que cierra no trae conversación deducida de una cita y alguna foto sí —el dueño
     * mandó la foto citando una respuesta vieja del asistente y después el audio sin citar—, la
     * fila que cierra se queda con la de la foto más reciente: la cita era la intención de seguir
     * esa conversación, y el audio es la segunda mitad del mismo pedido. Quien llama es el que
     * guarda la fila que cierra.
     *
     * Se llama siempre adentro de la transacción del reclamo.
     *
     * @param ClientAssistantMessage                         $cierre Fila con la que viajan las fotos.
     * @param iterable<int, ClientAssistantMessage>          $fotos  Fotos en espera, en orden de llegada.
     *
     * @return array<int, array<string, mixed>>
     */
    private function agrupar_fotos_en(ClientAssistantMessage $cierre, $fotos): array
    {
        $imagenes     = [];
        $conversacion = null;

        foreach ($fotos as $foto) {
            $imagenes = array_merge($imagenes, $this->media_en_espera_de($foto));

            if ((int) $foto->id === (int) $cierre->id) {
                continue;
            }

            if ($foto->ai_conversation_id !== null) {
                $conversacion = (int) $foto->ai_conversation_id;
            }

            $foto->estado          = ClientAssistantMessage::ESTADO_AGRUPADO;
            $foto->agrupado_en_id  = (int) $cierre->id;
            $foto->media_en_espera = null;
            $foto->save();
        }

        if ($cierre->ai_conversation_id === null && $conversacion !== null) {
            $cierre->ai_conversation_id = $conversacion;
        }

        return $imagenes;
    }

    /**
     * La metadata guardada de una foto en espera, como lista de adjuntos.
     *
     * Si no se puede descifrar —la `APP_KEY` cambió mientras la foto esperaba— no voltea el
     * reclamo: la foto se da por perdida, queda en el log, y el resto de la ráfaga sigue su viaje.
     *
     * @param ClientAssistantMessage $foto Fila en espera.
     *
     * @return array<int, array<string, mixed>>
     */
    private function media_en_espera_de(ClientAssistantMessage $foto): array
    {
        try {
            $media = $foto->media_en_espera;
        } catch (\Throwable $exception) {
            /* Sin el valor crudo: aunque esté cifrado, es la URL firmada de un adjunto. */
            Log::channel('daily')->warning('AsistenteWhatsapp: no se pudo leer la foto en espera.', [
                'assistant_message_id' => $foto->id,
                'error'                => $exception->getMessage(),
            ]);

            return [];
        }

        if (! is_array($media)) {
            return [];
        }

        $lista = [];
        foreach ($media as $adjunto) {
            if (is_array($adjunto)) {
                $lista[] = $adjunto;
            }
        }

        return $lista;
    }

    /**
     * Si el mensaje es una foto que llegó sola, sin nada escrito.
     *
     * El epígrafe manda: una foto con epígrafe YA trae su instrucción ("esto es la compra de
     * Distribuidora Norte") y sale en el acto, como siempre. Sin epígrafe, el `body` que deja el
     * webhook es null (`extract_media_caption_body()` no inventa ningún placeholder), y esa es la
     * foto que se queda esperando.
     *
     * @param array<string, mixed>              $parsed   Resultado de `parse_inbound_message()`.
     * @param array<int, array<string, mixed>> $imagenes Lo que devolvió `imagenes_del_mensaje()`.
     *
     * @return bool
     */
    private function es_foto_sin_epigrafe(array $parsed, array $imagenes): bool
    {
        if ($imagenes === []) {
            return false;
        }

        return trim((string) ($parsed['body'] ?? '')) === '';
    }

    /**
     * Si el mensaje es un archivo de audio que llegó como DOCUMENTO, sin nada escrito.
     *
     * Es el caso S19 de la prueba del agente personal (16/10/2026): un audio adjuntado desde WhatsApp
     * Web (Adjuntar → Audio) no sale como nota de voz sino como `document` con mime `audio/ogg`, y
     * Kapso no lo transcribe. El webhook lo deja como `document` sin `body`, y hasta el 16/10 viajaba
     * como un `texto` vacío: 422 del `empresa-api` y «Uf, no pude contestarte».
     *
     * El mime se lee de `inbound_media`, que es donde lo deja el webhook (`mime_type` del nodo del
     * documento, o el que trae `kapso.content`). Se compara solo el principio —`audio/`— porque Meta
     * puede mandarlo con parámetros (`audio/ogg; codecs=opus`).
     *
     * Con epígrafe devuelve false a propósito: el epígrafe es lo que el dueño escribió y el asistente
     * lo contesta como siempre; marcarlo como audio haría que el `empresa-api` tomara el epígrafe por
     * la transcripción de un audio que nadie transcribió.
     *
     * @param array<string, mixed> $parsed Resultado de `parse_inbound_message()`.
     *
     * @return bool
     */
    private function es_documento_de_audio_sin_epigrafe(array $parsed): bool
    {
        if (strtolower(trim((string) ($parsed['type'] ?? ''))) !== 'document') {
            return false;
        }

        if (trim((string) ($parsed['body'] ?? '')) !== '') {
            return false;
        }

        if (empty($parsed['inbound_media']) || ! is_array($parsed['inbound_media'])) {
            return false;
        }

        $mime = strtolower(trim((string) ($parsed['inbound_media']['mime'] ?? '')));

        return strpos($mime, 'audio/') === 0;
    }

    /**
     * Si el mensaje es una foto que llegó como DOCUMENTO: `document` con mime `image/*`.
     *
     * Es el dueño que manda la factura con Adjuntar → Documento para que WhatsApp no la comprima, que
     * es justamente lo que conviene para escanearla. Con o sin epígrafe: el epígrafe es la
     * instrucción, igual que en una foto común, y no cambia que el adjunto sea una foto.
     *
     * El mime se lee de `inbound_media`, donde lo deja el webhook, y se compara solo el principio
     * (`image/`). Qué imágenes se aceptan de verdad (jpeg, png, webp) y hasta qué tamaño lo sigue
     * decidiendo `AsistenteImagenesService::preparar()`, igual que para una `image`: un HEIC adjunto
     * como documento se descarta con la misma nota que un HEIC mandado como foto. La descarga
     * (`WhatsappInboundMediaService::descargar_binario()`) solo mira `url` y `whatsapp_media_id`, que
     * el webhook llena igual para el nodo `document`.
     *
     * @param array<string, mixed> $parsed Resultado de `parse_inbound_message()`.
     *
     * @return bool
     */
    private function es_documento_de_imagen(array $parsed): bool
    {
        if (strtolower(trim((string) ($parsed['type'] ?? ''))) !== 'document') {
            return false;
        }

        if (empty($parsed['inbound_media']) || ! is_array($parsed['inbound_media'])) {
            return false;
        }

        $mime = strtolower(trim((string) ($parsed['inbound_media']['mime'] ?? '')));

        return strpos($mime, 'image/') === 0;
    }

    /**
     * Si un mensaje del dueño es de los que el asistente no puede leer: sin texto, sin fotos y sin audio.
     *
     * 🔴 **Una sola regla para las dos puntas.** La usa `recibir()` para no reclamar fotos en espera y
     * despachar el job sin ellas, y la vuelve a usar `EnviarMensajeAlAsistenteJob` antes de la ida,
     * con lo que quedó en la fila, para contestar el texto fijo en vez de mandarle al `empresa-api`
     * un mensaje vacío que contesta 422. Dos copias de esta regla serían dos reglas el día que alguien
     * toque una sola.
     *
     * Lo que SÍ se puede leer, y por qué cada cosa:
     *
     *   - **Algo escrito** (texto, epígrafe, la transcripción de Kapso, el contenido que deja Kapso de
     *     una ubicación o un botón): es lo que el asistente contesta.
     *   - **Fotos** (las propias o las que el mensaje se llevó de la espera): el contrato acepta
     *     `imagenes[]` sin texto, es el caso normal de la factura.
     *   - **Audio** (`audio`, `ptt`, `voice`): aunque no venga transcripto, el `empresa-api` tiene su
     *     respuesta fija («No me llegó lo que dijiste en el audio…»). Incluye el documento de audio,
     *     que `recibir()` ya guardó como `audio`.
     *   - **Una foto (`image`) sin metadata**: no entra en esta regla a propósito. Es una falla —Kapso
     *     no mandó ni URL ni id, o la metadata de la espera no se pudo descifrar— y no un tipo de
     *     mensaje que no se sepa leer: decirle al dueño que «ese tipo de mensaje» no se lee sería
     *     mentirle sobre una foto. Sigue su camino de siempre y, si falla, avisa al equipo.
     *   - **Un texto (`text`) sin texto**: tampoco entra, por lo mismo (revisión independiente de la
     *     misión, 16/10/2026). Un mensaje de texto llega CON texto; si llega vacío es que el parser no
     *     lo encontró —Kapso cambió la forma del payload, una ruta que dejó de existir— y eso es una
     *     falla que alguien tiene que ver. Contestarle «ese tipo de mensaje no lo puedo leer» la
     *     taparía: sigue su camino de siempre (422 → disculpa + aviso al equipo).
     *
     * Todo lo demás —un PDF, un video, un sticker, un contacto, un documento cualquiera— sin nada
     * escrito no tiene con qué viajar.
     *
     * @param string|null                      $texto    Lo que escribió el dueño (`body` o `texto` de la fila).
     * @param string                           $tipo     Tipo normalizado (el de la fila).
     * @param array<int, array<string, mixed>> $imagenes Fotos que viajarían con el mensaje.
     *
     * @return bool
     */
    public function no_se_puede_leer(?string $texto, string $tipo, array $imagenes): bool
    {
        if (trim((string) $texto) !== '') {
            return false;
        }

        if ($imagenes !== []) {
            return false;
        }

        return ! in_array(strtolower(trim($tipo)), ['audio', 'ptt', 'voice', 'image', 'text'], true);
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
     * asistente pueda mirar, así que ni se intentan bajar. "Foto" es una `image` o un `document`
     * con mime `image/*` (la factura mandada como archivo para que no se comprima; ver
     * `es_documento_de_imagen()`): los dos se bajan y se validan igual.
     *
     * @param array<string, mixed> $parsed Resultado de `parse_inbound_message()`.
     *
     * @return array<int, array<string, mixed>>
     */
    private function imagenes_del_mensaje(array $parsed): array
    {
        if (strtolower((string) ($parsed['type'] ?? '')) !== 'image' && ! $this->es_documento_de_imagen($parsed)) {
            return [];
        }

        if (empty($parsed['inbound_media']) || ! is_array($parsed['inbound_media'])) {
            return [];
        }

        return [$parsed['inbound_media']];
    }

    /**
     * Por qué número de WhatsApp entró este mensaje, tal como lo resolvió el webhook.
     *
     * 🔴 **Puede no venir, y eso no es un error.** El campo lo agrega el webhook a partir del
     * payload de Kapso (misión whatsapp-dos-numeros, 13/10/2026) y hay dos caminos por los que
     * llega vacío: un payload de Kapso que no lo trae —ahí el webhook cae al número principal, que
     * es la compatibilidad hacia atrás— y cualquier llamador que arme el `$parsed` a mano. En los
     * dos casos la fila queda con la columna en null, que es exactamente lo que significa: *no se
     * sabe por qué número entró*. Inventarle el número principal sería escribir un dato falso en la
     * única tabla que después se usa para diagnosticar.
     *
     * @param array<string, mixed> $parsed Resultado de `WhatsappWebhookController::parse_inbound_message()`.
     *
     * @return string|null
     */
    private function phone_number_id_del_payload(array $parsed): ?string
    {
        $phone_number_id = isset($parsed['phone_number_id']) ? trim((string) $parsed['phone_number_id']) : '';

        return $phone_number_id !== '' ? $phone_number_id : null;
    }

    /**
     * Por qué número se le habla al dueño a partir de un mensaje suyo: por el mismo por el que entró.
     *
     * 🔴 Se decide mirando la FILA y no «cuál es el número del asistente hoy». El día del corte hay
     * mensajes en vuelo que entraron por el número de leads y clientes cuando el del asistente todavía
     * no estaba listo; contestarlos por el número nuevo —donde ese dueño nunca escribió— sería texto
     * libre fuera de ventana, que Meta rechaza. Lo mismo para el dueño que el webhook sigue atendiendo
     * por el principal porque el número del asistente no lo reconoce.
     *
     * «Entró por el asistente» = la fila guarda el `phone_number_id` de la fila de propósito
     * `asistente`. Sin dato (filas anteriores a la columna, o un payload que no traía el campo) o con
     * cualquier otro número → `principal`, que es lo que este canal hacía antes de tener número propio.
     * Y aunque la fila diga `asistente`, `WhatsappSendService::paraProposito()` cae solo al principal
     * si ese número ya no está listo.
     *
     * Vivía como método privado de `EnviarMensajeAlAsistenteJob` y se mudó acá, tal cual, en la misión
     * asistente-aviso-escaneo-factura (16/10/2026): el aviso de que terminó el escaneo de una factura
     * (`AsistenteAvisosService`) sale por el número por el que el dueño mandó la foto, con esta misma
     * regla. Dos copias de una regla de ruteo son dos reglas el día que alguien toque una sola.
     *
     * @param ClientAssistantMessage $fila Fila ENTRANTE del dueño.
     *
     * @return string Uno de los WhatsappConfig::PROPOSITO_*.
     */
    public static function proposito_de_salida(ClientAssistantMessage $fila): string
    {
        $phone_number_id = trim((string) $fila->phone_number_id);
        if ($phone_number_id === '') {
            return WhatsappConfig::PROPOSITO_PRINCIPAL;
        }

        $numero = WhatsappConfig::porPhoneNumberId($phone_number_id);

        return $numero !== null && $numero->proposito === WhatsappConfig::PROPOSITO_ASISTENTE
            ? WhatsappConfig::PROPOSITO_ASISTENTE
            : WhatsappConfig::PROPOSITO_PRINCIPAL;
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
     * @param string|null            $phone_number_id     Número por el que salió. Opcional y con
     *        default null para no tocar a ningún llamador viejo; sin él la fila cae al número por el
     *        que ENTRÓ el mensaje del dueño, que es lo que pasa siempre en el canal del asistente
     *        (se responde por donde escribió). Se pasa explícito cuando el llamador sabe por qué
     *        número salió de verdad, que es lo único que sirve el día que los dos no coincidan.
     *
     * @return ClientAssistantMessage
     */
    public function registrar_saliente(
        ClientAssistantMessage $entrante,
        string $texto,
        ?string $whatsapp_message_id,
        string $estado,
        ?string $error = null,
        ?string $phone_number_id = null
    ): ClientAssistantMessage {
        $fila = $this->registrar_saliente_de(
            (int) $entrante->client_id,
            (string) $entrante->telefono,
            $texto,
            $whatsapp_message_id,
            $entrante->ai_conversation_id,
            $estado,
            $error,
            $phone_number_id !== null ? $phone_number_id : $entrante->phone_number_id
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
     * @param string|null $phone_number_id     Número de WhatsApp por el que salió, o null si no se
     *        sabe. Opcional y último a propósito: la firma vieja sigue andando igual.
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
        ?string $error = null,
        ?string $phone_number_id = null
    ): ClientAssistantMessage {
        $fila                      = new ClientAssistantMessage();
        $fila->client_id           = $client_id;
        $fila->telefono            = $telefono;
        $fila->phone_number_id     = $phone_number_id;
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

<?php

namespace App\Services;

use App\Jobs\EnviarMensajeAlAsistenteJob;
use App\Models\Client;
use App\Models\ClientAssistantMessage;
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
     * ⚠️ El `delay` depende del worker permanente de supervisor (`api_admin_queue`, desde el
     * 21/9/2026): con él la foto sale a los ~30 s. Con el worker viejo del cron sale al primer tick
     * después de los 30 s, que es lento pero igual correcto.
     *
     * @param array<string, mixed> $parsed Resultado de `WhatsappWebhookController::parse_inbound_message()`.
     * @param Client               $client Cliente cuyo dueño escribió.
     *
     * @return ClientAssistantMessage La fila entrante ya persistida.
     */
    public function recibir(array $parsed, Client $client): ClientAssistantMessage
    {
        $reply_to = isset($parsed['reply_to_message_id']) ? trim((string) $parsed['reply_to_message_id']) : '';

        $imagenes = $this->imagenes_del_mensaje($parsed);
        $segundos = $this->segundos_de_espera_de_foto();
        $espera   = $segundos > 0 && $this->es_foto_sin_epigrafe($parsed, $imagenes);

        $fila                               = new ClientAssistantMessage();
        $fila->client_id                    = (int) $client->id;
        $fila->telefono                     = (string) $parsed['from'];
        $fila->direccion                    = ClientAssistantMessage::DIRECCION_ENTRANTE;
        $fila->whatsapp_message_id          = (string) $parsed['message_id'];
        $fila->reply_to_whatsapp_message_id = $reply_to !== '' ? $reply_to : null;
        $fila->tipo                         = substr((string) ($parsed['type'] ?? 'text'), 0, 20);
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
        ]);

        if ($espera) {
            /* El tercer parámetro es la espera, y no es decorativo: `retryUntil()` la suma a su
             * ventana, que Laravel calcula al DESPACHAR y no cuando el job por fin corre. */
            EnviarMensajeAlAsistenteJob::dispatch((int) $fila->id, [], $segundos)
                ->onConnection(self::CONEXION_DE_COLA)
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
         */
        $reclamadas = $this->reclamar_fotos_en_espera($fila);

        EnviarMensajeAlAsistenteJob::dispatch((int) $fila->id, array_merge($reclamadas, $imagenes))
            ->onConnection(self::CONEXION_DE_COLA);

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
     * Se lleva las fotos que el dueño tenía esperando, para que viajen con este mensaje.
     *
     * Lo llama `recibir()` con la fila del mensaje que cierra ya guardada. Reclama las fotos del
     * MISMO dueño (`client_id` + `telefono`), anteriores a este mensaje y dentro de la vigencia; las
     * pasa a `agrupado` en esta fila, les borra `media_en_espera` y devuelve su metadata en orden de
     * llegada. Una foto que llegó DESPUÉS de este mensaje no se toca: esa espera sus segundos y sale
     * sola (el caso "audio primero, foto después", que el plan deja afuera a propósito).
     *
     * 🔴 Transacción con `lockForUpdate`, porque el job de la última foto puede estar reclamando las
     * mismas filas en el mismo instante (se le venció la espera justo cuando llegó el audio). El que
     * toma el lock primero se las lleva; el otro las relee después del commit, ya en `agrupado` o
     * en `recibido`, y no las encuentra. Sin el lock, la misma foto podía viajar dos veces.
     *
     * Si el reclamo falla, este mensaje sale igual sin las fotos, y las fotos salen solas cuando se
     * les venza la espera: nada se pierde, solo se pierde el agrupamiento.
     *
     * @param ClientAssistantMessage $fila Fila del mensaje que cierra la espera, ya persistida.
     *
     * @return array<int, array<string, mixed>> Metadata de las fotos reclamadas, en orden de llegada.
     */
    public function reclamar_fotos_en_espera(ClientAssistantMessage $fila): array
    {
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
            return [];
        }

        try {
            return DB::transaction(function () use ($fila, $en_espera) {
                $fotos = $en_espera()->orderBy('id')->lockForUpdate()->get();

                if ($fotos->isEmpty()) {
                    return [];
                }

                $imagenes = $this->agrupar_fotos_en($fila, $fotos);

                if ($fila->isDirty()) {
                    $fila->save();
                }

                Log::channel('daily')->info('AsistenteWhatsapp: el mensaje se llevó fotos que esperaban su instrucción.', [
                    'client_id'            => $fila->client_id,
                    'assistant_message_id' => $fila->id,
                    'fotos_agrupadas'      => $fotos->count(),
                ]);

                return $imagenes;
            }, self::INTENTOS_DEL_RECLAMO);
        } catch (\Throwable $exception) {
            Log::channel('daily')->warning('AsistenteWhatsapp: no se pudieron reclamar las fotos en espera; salen solas.', [
                'client_id'            => $fila->client_id,
                'assistant_message_id' => $fila->id,
                'error'                => $exception->getMessage(),
            ]);

            return [];
        }
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
     * borra la metadata de todas y la devuelve en orden de llegada. Es exactamente lo que pasaba
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

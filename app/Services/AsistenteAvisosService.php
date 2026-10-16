<?php

namespace App\Services;

use App\Helpers\WhatsappNormalizer;
use App\Models\Client;
use App\Models\ClientAssistantMessage;
use App\Models\WhatsappConfig;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Los avisos del asistente por WhatsApp: "terminé de leer tu factura, revisala acá".
 *
 * Misión asistente-aviso-escaneo-factura (16/10/2026). Lucas lo pidió textual: cuando el dueño le
 * manda una factura al asistente, que *"le avise al usuario por whatsapp cuando termine de
 * escanearse para que revise el escaneo desde el sistema"*, con el link que abre la compra y la
 * revisión. El escaneo corre en el `empresa-api` del cliente y tarda unos segundos; la respuesta del
 * turno ya salió hace rato. O sea que alguien tiene que volver a hablarle al dueño DESPUÉS, sin que
 * él haya escrito nada.
 *
 * 🔴 **El aviso sale por CONSULTA del admin, no por empuje del cliente** (decisión de Lucas, Fase 2).
 * Cada minuto este servicio le pregunta `avisos-pendientes` a los clientes cuyo dueño le escribió al
 * asistente en las últimas 24 hs —sin eso no hay escaneo posible por WhatsApp ni ventana de Meta para
 * contestarle—, y manda lo que haya. Es el mismo molde que los informes de la mañana
 * (`AsistenteInformesService`), sin claves nuevas en ningún lado: el `empresa-api` no necesita saber
 * cómo llegar al admin.
 *
 * 🔴 **Solo dentro de la ventana de 24 hs, y nunca por plantilla.** El aviso es la continuación de
 * una conversación que el dueño acaba de tener; si la ventana del número por el que se le habla está
 * cerrada, el aviso se deja pendiente (el `empresa-api` lo vence a las 24 hs) y no se le manda una
 * plantilla que nadie pidió.
 *
 * 🔴 **Y no se manda dos veces.** El aviso se RECLAMA en la cache antes de mandarlo (`Cache::add`,
 * atómico) y el reclamo se suelta solo si el envío falla. Después de mandarlo se le avisa al
 * `empresa-api` (`avisos/avisado`); si esa marca falla, el reclamo guarda con qué link y qué wamid
 * salió, y la vuelta siguiente reintenta SOLO la marca, sin volver a escribirle al dueño.
 */
class AsistenteAvisosService
{
    /**
     * Ruta de los avisos que el `empresa-api` tiene listos para mandar.
     */
    const RUTA_PENDIENTES = 'api/admin-sync/asistente/avisos-pendientes';

    /**
     * Ruta que marca un aviso como mandado. El id viaja en el cuerpo, no en la ruta: es un string
     * con `:` adentro (`"escaneo_factura:15"`).
     */
    const RUTA_AVISADO = 'api/admin-sync/asistente/avisos/avisado';

    /**
     * Horas hacia atrás en las que el dueño tuvo que haberle escrito al asistente para que valga la
     * pena consultarle. Son las 24 hs de la ventana de Meta: fuera de ellas no se le puede mandar
     * texto libre, y el `empresa-api` tampoco ofrece avisos más viejos que eso.
     */
    const HORAS_DE_ACTIVIDAD = 24;

    /**
     * Minutos sin volver a consultar a un cliente cuyo sistema no tiene la ruta (404).
     *
     * Treinta minutos: es un cliente con un `empresa-api` anterior a esta misión, y consultarlo cada
     * minuto serían 1.440 pedidos al pedo por día contra un shared hosting que bloquea por ráfaga.
     *
     * 🔴 Eran seis horas hasta el chequeo independiente del 16/10/2026, y no alcanzaba con bajarlas:
     * la pausa SOBREVIVÍA AL UPGRADE. Un cliente actualizado a media mañana ya le prometía al dueño
     * "te aviso por acá" y el admin no le preguntaba hasta la tarde. Ahora, además del techo corto,
     * la pausa se levanta sola en cuanto el sistema del cliente dice que tiene la función: el 202 de
     * `POST asistente/mensajes` trae `admite_avisos: true` y el job llama a `levantar_pausa()`.
     */
    const MINUTOS_DE_PAUSA_SIN_LA_FUNCION = 30;

    /**
     * Minutos sin volver a consultar a un cliente que respondió con error (401/403/409/5xx/timeout).
     *
     * Corto a propósito: puede ser pasajero, y un aviso que se demora diez minutos todavía sirve.
     */
    const MINUTOS_DE_PAUSA_POR_ERROR = 10;

    /**
     * Días que dura el reclamo de un aviso. Mucho más que las 24 hs que el `empresa-api` lo ofrece,
     * así que mientras exista el aviso, el reclamo también.
     */
    const DIAS_DEL_RECLAMO = 7;

    /**
     * Minutos hacia atrás en los que un mensaje del dueño sin contestar frena el aviso.
     *
     * El job se rinde a los 180 s y la espera de la foto dura minutos, así que media hora cubre
     * cualquier turno vivo de sobra; una fila más vieja trabada en un estado no final es un turno
     * muerto, y no puede dejar al dueño sin su aviso para siempre.
     */
    const MINUTOS_DE_TURNO_EN_CURSO = 30;

    /**
     * Estados de una fila entrante cuyo turno todavía no terminó: el dueño espera la respuesta.
     *
     * Son los NO finales de `EnviarMensajeAlAsistenteJob::es_estado_final()`. `agrupado` no está a
     * propósito: es final (la foto viajó pegada al mensaje de otra fila, y el turno lo sigue esa otra).
     */
    const ESTADOS_DE_TURNO_EN_CURSO = [
        ClientAssistantMessage::ESTADO_RECIBIDO,
        ClientAssistantMessage::ESTADO_ENVIADO,
        ClientAssistantMessage::ESTADO_ESPERANDO,
    ];

    /**
     * Cuántas veces seguidas puede rechazar Meta un mismo aviso antes de darlo por perdido.
     *
     * 🔴 Sin tope, un rechazo persistente (un número que ya no existe, un destino bloqueado) se
     * reintentaba cada minuto durante las 24 hs que el `empresa-api` ofrece el aviso: ~1.440 intentos
     * y, con la notificación de fallo de `send_text()`, ~144 WhatsApps de error a los admins (el
     * throttle global es de 10 min). Tres intentos cubren un rechazo pasajero; el cuarto no cambia nada.
     */
    const INTENTOS_MAXIMOS = 3;

    /**
     * Horas que un aviso `fallido` queda sin reintentarse: las mismas 24 hs en que el `empresa-api`
     * lo ofrece, así que no vuelve a intentarse nunca.
     */
    const HORAS_DEL_FALLIDO = 24;

    /**
     * Prefijo del contador de intentos fallidos de un aviso: `asistente_aviso_intentos:{client_id}:{id}`.
     *
     * Vive en una clave hermana del reclamo y no adentro de él a propósito: entre un intento y el
     * siguiente el reclamo se SUELTA (para que `Cache::add()` siga siendo el reclamo atómico), y el
     * contador tiene que sobrevivir a eso.
     */
    const CACHE_INTENTOS = 'asistente_aviso_intentos';

    /**
     * Prefijo de la clave de pausa por cliente: `asistente_avisos:pausa:{client_id}`.
     */
    const CACHE_PAUSA = 'asistente_avisos:pausa';

    /**
     * Prefijo de la clave del reclamo de un aviso: `asistente_aviso:{client_id}:{id}`.
     */
    const CACHE_RECLAMO = 'asistente_aviso';

    /**
     * Estados del reclamo guardado en la cache.
     *
     * - `reclamado`: alguien lo está mandando ahora mismo (o murió en el medio: el reclamo vence solo).
     * - `enviado`: el WhatsApp salió y falta que el `empresa-api` lo marque.
     * - `marcado`: salió y quedó marcado (o el `empresa-api` ya no lo reconoce): no hay nada más que hacer.
     * - `fallido`: Meta lo rechazó INTENTOS_MAXIMOS veces: no se reintenta más (vence a las 24 hs).
     */
    const RECLAMO_RECLAMADO = 'reclamado';
    const RECLAMO_ENVIADO   = 'enviado';
    const RECLAMO_MARCADO   = 'marcado';
    const RECLAMO_FALLIDO   = 'fallido';

    /**
     * Resolución de la URL del `empresa-api` y del SPA de cada cliente.
     *
     * @var ClientEmpresaApiUrlResolver
     */
    private $urls;

    /**
     * Envío a Kapso/Meta.
     *
     * @var WhatsappSendService
     */
    private $sender;

    /**
     * Dueño de la fila saliente del hilo y de la regla del número de salida.
     *
     * @var AsistenteWhatsappService
     */
    private $asistente;

    /**
     * @param ClientEmpresaApiUrlResolver $urls      Resolución de URLs del cliente.
     * @param WhatsappSendService         $sender    Envío a Kapso/Meta.
     * @param AsistenteWhatsappService    $asistente Fila saliente y número de salida.
     */
    public function __construct(
        ClientEmpresaApiUrlResolver $urls,
        WhatsappSendService $sender,
        AsistenteWhatsappService $asistente
    ) {
        $this->urls      = $urls;
        $this->sender    = $sender;
        $this->asistente = $asistente;
    }

    /**
     * Si a este cliente se le mandan avisos por WhatsApp: el ÚNICO criterio, compartido por las dos
     * puntas.
     *
     * 🔴 Lo usan el job, para decidir si le anuncia `avisos = 1` al `empresa-api` (la promesa "te aviso
     * por acá"), y el barrido, para decidir a quién consultar. Tienen que coincidir siempre: un
     * cliente que recibe la promesa y nunca se consulta es un dueño esperando un aviso que no llega
     * (chequeo independiente del 16/10/2026: el job no miraba `is_active` y el barrido sí).
     *
     * El criterio es activo + canal prendido + clave:
     *   - `asistente_whatsapp_activo`: el interruptor del canal.
     *   - `api_key`: sin ella no hay cómo consultarle nada a su sistema.
     *   - `is_active`: se mantiene porque es la puerta de ENTRADA del canal. Los dos caminos del
     *     webhook que le rutean un mensaje al asistente buscan al dueño solo entre clientes activos
     *     (`WhatsappWebhookController::find_client_by_phone()` y `find_dueno_por_telefono_exacto()`),
     *     así que un cliente inactivo no puede mandarle una factura al asistente; el único que llega
     *     al job es el que se desactivó con un mensaje en vuelo, y a ése no se le promete nada.
     *
     * @param Client $client
     *
     * @return bool
     */
    public static function cliente_recibe_avisos(Client $client): bool
    {
        return (bool) $client->is_active
            && (bool) $client->asistente_whatsapp_activo
            && trim((string) $client->api_key) !== '';
    }

    /**
     * Manda los avisos pendientes de todos los clientes que pueden tenerlos.
     *
     * 🔴 El filtro es lo que hace barato correr esto cada minuto: los clientes que pasan
     * `cliente_recibe_avisos()` **y con algún mensaje entrante del dueño al asistente en las últimas 24 hs**.
     * Sin ese mensaje no puede haber un escaneo pedido por WhatsApp ni ventana para contestarle, así
     * que preguntarle a su `empresa-api` sería un pedido HTTP para nada. En la práctica son unos pocos
     * clientes por minuto, no cuarenta.
     *
     * Cada cliente va en su propio try: el que falla no puede dejar sin aviso a los que vienen después.
     *
     * @return array<int, array<string, mixed>> Una fila por cliente, para que el comando la imprima.
     */
    public function enviar_a_todos(): array
    {
        $desde = now()->subHours(self::HORAS_DE_ACTIVIDAD);

        $clientes = Client::query()
            ->where('is_active', true)
            ->where('asistente_whatsapp_activo', true)
            ->whereNotNull('api_key')
            ->where('api_key', '!=', '')
            ->whereExists(function ($query) use ($desde) {
                $query->select(DB::raw(1))
                    ->from('client_assistant_messages')
                    ->whereColumn('client_assistant_messages.client_id', 'clients.id')
                    ->where('client_assistant_messages.direccion', ClientAssistantMessage::DIRECCION_ENTRANTE)
                    ->where('client_assistant_messages.created_at', '>=', $desde);
            })
            ->orderBy('id')
            ->get()
            /* La consulta de arriba es solo el prefiltro barato en SQL; el que decide es el criterio
             * compartido con el job, para que las dos puntas no puedan desalinearse. */
            ->filter(function (Client $client) {
                return self::cliente_recibe_avisos($client);
            });

        $resultados = [];

        foreach ($clientes as $client) {
            try {
                $resultados[] = $this->enviar_a($client);
            } catch (\Throwable $exception) {
                Log::channel('daily')->error('AsistenteAvisos: excepción mandando los avisos de un cliente.', [
                    'client_id' => $client->id,
                    'error'     => $exception->getMessage(),
                ]);

                $resultados[] = $this->resultado($client, 'error', 0, 0, $exception->getMessage());
            }
        }

        return $resultados;
    }

    /**
     * Manda los avisos pendientes de un cliente.
     *
     * @param Client $client Cliente con el canal prendido.
     *
     * @return array{client_id: int, nombre: string, estado: string, avisos: int, enviados: int, detalle: string}
     */
    public function enviar_a(Client $client): array
    {
        if (trim((string) $client->api_key) === '') {
            return $this->resultado($client, 'sin_api_key', 0, 0, 'El cliente no tiene cargada la api_key.');
        }

        $pausa = Cache::get($this->clave_de_pausa($client));
        if ($pausa !== null) {
            return $this->resultado($client, 'en_pausa', 0, 0, 'En pausa hasta la próxima consulta: ' . (string) $pausa);
        }

        /* La última entrante del dueño en las 24 hs. Es el destino de repuesto de un aviso cuya
         * conversación no tiene ninguna fila acá, y sin ella no hay a quién hablarle: ni se consulta. */
        $ultima_del_cliente = $this->ultima_entrante_del_cliente($client);
        if ($ultima_del_cliente === null) {
            return $this->resultado($client, 'sin_actividad', 0, 0, 'Nadie le escribió al asistente en las últimas 24 hs.');
        }

        $spa_url    = $this->urls->resolve_spa_url($client);
        $pendientes = $this->traer_avisos($client, $spa_url);

        if ($pendientes['estado'] !== 'ok') {
            return $this->resultado($client, $pendientes['estado'], 0, 0, $pendientes['detalle']);
        }

        $avisos = $pendientes['avisos'];
        if ($avisos === []) {
            return $this->resultado($client, 'sin_avisos', 0, 0, 'No hay avisos pendientes.');
        }

        /* La ventana se cachea por request adentro del servicio: una instancia nueva por cliente para
         * que lo medido de uno no se le pegue al siguiente en una corrida larga. */
        $ventana = app(WhatsappSessionWindowService::class);

        $enviados = 0;
        $estados  = [];
        foreach ($avisos as $aviso) {
            $estado = $this->avisar($client, $aviso, $ultima_del_cliente, $ventana, $spa_url);

            $estados[] = $aviso['id'] . '=' . $estado;
            if ($estado === 'enviado') {
                $enviados++;
            }
        }

        Log::channel('daily')->info('AsistenteAvisos: avisos del cliente procesados.', [
            'client_id' => $client->id,
            'avisos'    => count($avisos),
            'enviados'  => $enviados,
            'estados'   => $estados,
        ]);

        return $this->resultado(
            $client,
            $enviados > 0 ? 'enviado' : 'pendiente',
            count($avisos),
            $enviados,
            implode(', ', $estados)
        );
    }

    /**
     * Manda UN aviso, si se puede, y lo marca del lado del cliente.
     *
     * @param Client                       $client             Cliente dueño del aviso.
     * @param array<string, mixed>         $aviso              Aviso normalizado por `traer_avisos()`.
     * @param ClientAssistantMessage       $ultima_del_cliente Destino de repuesto.
     * @param WhatsappSessionWindowService $ventana            Ventana de 24 hs de Meta.
     * @param string                       $spa_url            SPA del cliente, para armar el link.
     *
     * @return string enviado | remarcado | ya_avisado | en_curso | turno_en_curso | ventana_cerrada | error | fallido
     */
    private function avisar(
        Client $client,
        array $aviso,
        ClientAssistantMessage $ultima_del_cliente,
        WhatsappSessionWindowService $ventana,
        string $spa_url
    ): string {
        $clave = $this->clave_de_reclamo($client, (string) $aviso['id']);

        /* Ya hay un reclamo: o alguien lo está mandando, o ya salió. En ningún caso se vuelve a
         * escribirle al dueño. Si salió y el `empresa-api` lo sigue ofreciendo, es que la marca
         * falló: se reintenta SOLO la marca, con el link y el wamid con los que salió. */
        $previo = Cache::get($clave);
        if ($previo !== null) {
            return $this->resolver_reclamo_previo($client, $aviso, $clave, $previo);
        }

        $destino = $this->destino_del_aviso($client, $aviso, $ultima_del_cliente);

        /* El número de salida es el del mensaje del dueño (la regla compartida con el job), y la
         * ventana se mide sobre el número por el que DE VERDAD va a salir: si la fila dice
         * `asistente` pero ese número ya no está listo, `paraProposito()` cae al principal, y la
         * ventana se tiene que medir ahí. */
        $proposito = AsistenteWhatsappService::proposito_de_salida($destino);
        $efectivo  = $proposito === WhatsappConfig::PROPOSITO_ASISTENTE && WhatsappConfig::canalListo(WhatsappConfig::PROPOSITO_ASISTENTE) === null
            ? WhatsappConfig::PROPOSITO_PRINCIPAL
            : $proposito;

        $telefono = (string) $destino->telefono;

        /* 🔴 El aviso no puede llegar ANTES que la respuesta del turno (chequeo independiente del
         * 16/10/2026). El escaneo tarda unos 17 s y la respuesta del asistente puede tardar más: sin
         * esto el dueño recibiría "terminé de leer tu factura" y recién después "creé la compra y la
         * estoy leyendo". Mientras ese teléfono tenga un mensaje sin contestar, el aviso queda para
         * la vuelta siguiente (sin reclamo: no salió nada). */
        if ($this->tiene_un_turno_en_curso($client, $telefono)) {
            return 'turno_en_curso';
        }

        if (! $this->ventana_abierta($client, $telefono, $efectivo, $ventana)) {
            return 'ventana_cerrada';
        }

        /* `add()` es atómico: con dos corridas pisándose, una sola manda. */
        if (! Cache::add($clave, ['estado' => self::RECLAMO_RECLAMADO], now()->addDays(self::DIAS_DEL_RECLAMO))) {
            return 'en_curso';
        }

        $link    = $this->link_del_aviso($aviso, $spa_url);
        $mensaje = (string) $aviso['texto'] . ($link !== '' ? "\n\n" . $link : '');

        /* 🔴 `skip_failure_notification = true`: un aviso rechazado NO le manda un WhatsApp de error
         * a los admins por cada intento. Ese parámetro se documentó para la notificación de fallo misma
         * (evitar la recursión), pero hace exactamente lo que acá hace falta, y lo usan también los
         * envíos de fotos del asistente. El fallo queda igual en `last_send_error`, en el log de cada
         * intento y, al agotar los intentos, en un log de error único (`registrar_fallo()`). */
        try {
            $whatsapp_message_id = $this->sender
                ->paraProposito($proposito)
                ->send_text(
                    $telefono,
                    $mensaje,
                    'Aviso del asistente - cliente #' . $client->id,
                    true
                );
        } catch (\Throwable $exception) {
            /* El aviso no salió: cuenta como intento y, si quedan, se suelta para la vuelta siguiente. */
            $this->registrar_fallo($client, (string) $aviso['id'], $clave, $exception->getMessage());

            throw $exception;
        }

        if ($whatsapp_message_id === null) {
            return $this->registrar_fallo($client, (string) $aviso['id'], $clave, (string) $this->sender->last_send_error);
        }

        /* Desde acá el aviso YA salió: el reclamo pasa a guardar con qué salió, para que una marca
         * fallida se pueda reintentar sin mandarle el mensaje dos veces al dueño. */
        Cache::put(
            $clave,
            ['estado' => self::RECLAMO_ENVIADO, 'url' => $link, 'whatsapp_message_id' => $whatsapp_message_id],
            now()->addDays(self::DIAS_DEL_RECLAMO)
        );

        /*
         * 🔴 La fila saliente con la conversación del escaneo: es lo que hace que el dueño pueda
         * responder CITANDO el aviso ("¿y qué trae?") y caer en la conversación donde mandó la
         * factura, con el escaneo delante. Sin conversación (un aviso que no la trae) la cita no
         * resuelve nada y la pregunta entra como conversación común, que es la degradación correcta.
         *
         * Va en su propio try: el WhatsApp ya salió, y una fila que no se pudo escribir no puede
         * impedir que el aviso quede marcado del lado del cliente.
         */
        try {
            $this->asistente->registrar_saliente_de(
                (int) $client->id,
                $telefono,
                $mensaje,
                $whatsapp_message_id,
                $aviso['ai_conversation_id'],
                ClientAssistantMessage::ESTADO_RESPONDIDO,
                null,
                $this->sender->phone_number_id_de_destino()
            );
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('AsistenteAvisos: el aviso salió pero no se pudo dejar la fila saliente.', [
                'client_id' => $client->id,
                'aviso_id'  => $aviso['id'],
                'error'     => $exception->getMessage(),
            ]);
        }

        $this->marcar_y_cerrar_el_reclamo($client, (string) $aviso['id'], $clave, $link, $whatsapp_message_id);

        return 'enviado';
    }

    /**
     * Anota un intento fallido del aviso: suelta el reclamo para reintentar, o lo da por `fallido`.
     *
     * @param Client $client   Cliente.
     * @param string $aviso_id Id del aviso.
     * @param string $clave    Clave del reclamo.
     * @param string $detalle  Por qué falló.
     *
     * @return string error (se reintenta la vuelta siguiente) | fallido (no se reintenta más)
     */
    private function registrar_fallo(Client $client, string $aviso_id, string $clave, string $detalle): string
    {
        $clave_de_intentos = self::CACHE_INTENTOS . ':' . (int) $client->id . ':' . $aviso_id;

        /* `add()` primero para que el contador nazca con vencimiento; `increment()` sobre una clave
         * inexistente la crearía sin él en algunos drivers. */
        Cache::add($clave_de_intentos, 0, now()->addHours(self::HORAS_DEL_FALLIDO));
        $intentos = (int) Cache::increment($clave_de_intentos);

        if ($intentos < self::INTENTOS_MAXIMOS) {
            Cache::forget($clave);

            Log::channel('daily')->warning('AsistenteAvisos: Meta rechazó el aviso, se reintenta la vuelta siguiente.', [
                'client_id' => $client->id,
                'aviso_id'  => $aviso_id,
                'intento'   => $intentos,
                'error'     => $detalle,
            ]);

            return 'error';
        }

        /* El reclamo queda tomado como `fallido` y ya no se suelta: esta es la única vez que se llega
         * acá por este aviso, así que el log de error sale una sola vez. */
        Cache::put(
            $clave,
            ['estado' => self::RECLAMO_FALLIDO, 'intentos' => $intentos],
            now()->addHours(self::HORAS_DEL_FALLIDO)
        );

        Log::channel('daily')->error('AsistenteAvisos: Meta rechazó el aviso ' . $intentos . ' veces; no se reintenta más.', [
            'client_id' => $client->id,
            'aviso_id'  => $aviso_id,
            'error'     => $detalle,
        ]);

        return 'fallido';
    }

    /**
     * Qué hacer con un aviso que ya tiene reclamo.
     *
     * @param Client               $client Cliente.
     * @param array<string, mixed> $aviso  Aviso que el `empresa-api` sigue ofreciendo.
     * @param string               $clave  Clave del reclamo.
     * @param mixed                $previo Lo guardado en el reclamo.
     *
     * @return string remarcado | ya_avisado | fallido | en_curso
     */
    private function resolver_reclamo_previo(Client $client, array $aviso, string $clave, $previo): string
    {
        $estado = is_array($previo) && isset($previo['estado']) ? (string) $previo['estado'] : '';

        if ($estado === self::RECLAMO_ENVIADO) {
            $marcado = $this->marcar_y_cerrar_el_reclamo(
                $client,
                (string) $aviso['id'],
                $clave,
                isset($previo['url']) ? (string) $previo['url'] : '',
                isset($previo['whatsapp_message_id']) ? (string) $previo['whatsapp_message_id'] : ''
            );

            return $marcado ? 'remarcado' : 'ya_avisado';
        }

        if ($estado === self::RECLAMO_MARCADO) {
            return 'ya_avisado';
        }

        if ($estado === self::RECLAMO_FALLIDO) {
            return 'fallido';
        }

        return 'en_curso';
    }

    /**
     * Marca el aviso del lado del cliente y, si quedó, cierra el reclamo.
     *
     * @param Client $client              Cliente.
     * @param string $aviso_id            Id del aviso (string del contrato).
     * @param string $clave               Clave del reclamo.
     * @param string $link                Link con el que salió (vacío si salió sin link).
     * @param string $whatsapp_message_id wamid con el que salió.
     *
     * @return bool True si el `empresa-api` ya no lo va a volver a ofrecer.
     */
    private function marcar_y_cerrar_el_reclamo(Client $client, string $aviso_id, string $clave, string $link, string $whatsapp_message_id): bool
    {
        if (! $this->marcar_avisado($client, $aviso_id, $link, $whatsapp_message_id)) {
            return false;
        }

        Cache::put($clave, ['estado' => self::RECLAMO_MARCADO], now()->addDays(self::DIAS_DEL_RECLAMO));

        return true;
    }

    /**
     * Le pide al `empresa-api` del cliente los avisos pendientes.
     *
     * Las respuestas que no son 200 dejan al cliente en pausa (ver las constantes): 404 treinta
     * minutos —es un sistema sin la función, no un error, y no se le avisa a nadie—, y cualquier otra cosa
     * diez minutos con su log. En ningún caso se le escribe al dueño: él no pidió nada que falte.
     *
     * @param Client $client  Cliente.
     * @param string $spa_url SPA del cliente, para que el `empresa-api` arme la `url`. Vacía no viaja.
     *
     * @return array{estado: string, avisos: array<int, array<string, mixed>>, detalle: string}
     */
    private function traer_avisos(Client $client, string $spa_url): array
    {
        $vacio = [];

        $url = $this->urls->admin_sync_url($client, self::RUTA_PENDIENTES);
        if ($url === '') {
            return ['estado' => 'sin_url', 'avisos' => $vacio, 'detalle' => 'No se pudo resolver la URL del empresa-api.'];
        }

        $query = $spa_url !== '' ? ['spa_url' => $spa_url] : [];

        try {
            $respuesta = Http::withHeaders([
                    'X-Admin-Api-Key' => trim((string) $client->api_key),
                    'Accept'          => 'application/json',
                ])
                ->timeout((int) config('services.client_api.timeout', 15))
                ->get($url, $query);
        } catch (ConnectionException $exception) {
            return $this->pausar_por_error($client, 'No respondió el sistema del cliente: ' . $exception->getMessage());
        }

        $status = (int) $respuesta->status();

        /* 404: el cliente todavía no tiene la ruta. Es la versión vieja, no una falla: pausa más larga
         * que la de un error, sin avisar a los admins ni al dueño (a él no le falta nada que haya
         * pedido). La levanta el job apenas el cliente anuncia `admite_avisos`. */
        if ($status === 404) {
            Cache::put(
                $this->clave_de_pausa($client),
                'sin la ruta de avisos (404)',
                now()->addMinutes(self::MINUTOS_DE_PAUSA_SIN_LA_FUNCION)
            );

            return ['estado' => 'degradado', 'avisos' => $vacio, 'detalle' => 'El sistema del cliente todavía no tiene la ruta de avisos.'];
        }

        if ($status !== 200) {
            return $this->pausar_por_error($client, 'El sistema del cliente respondió ' . $status . '.');
        }

        $datos = (array) $respuesta->json();
        $lista = isset($datos['avisos']) && is_array($datos['avisos']) ? $datos['avisos'] : [];

        $avisos = [];
        foreach ($lista as $fila) {
            if (! is_array($fila)) {
                continue;
            }

            $id    = isset($fila['id']) ? trim((string) $fila['id']) : '';
            $texto = isset($fila['texto']) ? trim((string) $fila['texto']) : '';

            /* Sin id no se puede reclamar ni marcar, y sin texto no hay nada que decirle al dueño. */
            if ($id === '' || $texto === '') {
                continue;
            }

            $avisos[] = [
                'id'                 => $id,
                'tipo'               => isset($fila['tipo']) ? trim((string) $fila['tipo']) : '',
                'ai_conversation_id' => isset($fila['ai_conversation_id']) && $fila['ai_conversation_id'] !== null && (int) $fila['ai_conversation_id'] > 0
                    ? (int) $fila['ai_conversation_id']
                    : null,
                'texto'              => $texto,
                'ruta'               => isset($fila['ruta']) ? trim((string) $fila['ruta']) : '',
                'url'                => isset($fila['url']) ? trim((string) $fila['url']) : '',
            ];
        }

        return ['estado' => 'ok', 'avisos' => $avisos, 'detalle' => ''];
    }

    /**
     * Deja al cliente en pausa corta por un error y lo registra.
     *
     * @param Client $client  Cliente.
     * @param string $detalle Qué pasó.
     *
     * @return array{estado: string, avisos: array<int, array<string, mixed>>, detalle: string}
     */
    private function pausar_por_error(Client $client, string $detalle): array
    {
        Cache::put(
            $this->clave_de_pausa($client),
            $detalle,
            now()->addMinutes(self::MINUTOS_DE_PAUSA_POR_ERROR)
        );

        Log::channel('daily')->warning('AsistenteAvisos: no se pudieron traer los avisos de un cliente.', [
            'client_id' => $client->id,
            'detalle'   => $detalle,
        ]);

        return ['estado' => 'error', 'avisos' => [], 'detalle' => $detalle];
    }

    /**
     * El link que abre la revisión del escaneo en el teléfono del dueño.
     *
     * Dos fuentes, en orden: la `url` que armó el `empresa-api` (que ya usó la `spa_url` que le
     * mandamos), y si no vino, el SPA que conoce el admin más la `ruta` relativa. Sin ninguna de las
     * dos, el aviso sale sin link: el dueño igual se entera de que su factura está leída.
     *
     * Una `url` que no es http/https no se usa: es un link que el dueño no puede abrir.
     *
     * @param array<string, mixed> $aviso   Aviso normalizado.
     * @param string               $spa_url SPA del cliente según el admin (sin barra final).
     *
     * @return string Link absoluto, o vacío.
     */
    private function link_del_aviso(array $aviso, string $spa_url): string
    {
        $url = (string) $aviso['url'];
        if ($url !== '' && preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        $ruta = (string) $aviso['ruta'];
        if ($ruta === '' || $spa_url === '') {
            return '';
        }

        return rtrim($spa_url, '/') . '/' . ltrim($ruta, '/');
    }

    /**
     * A qué fila del dueño se le contesta el aviso.
     *
     * La última ENTRANTE de la conversación del escaneo: es quien mandó la factura (puede ser un
     * empleado, no el dueño de la ficha) y su `phone_number_id` dice por qué número escribió. Sin
     * conversación, o sin ninguna fila de esa conversación acá, la última entrante del cliente en las
     * 24 hs.
     *
     * @param Client                 $client             Cliente.
     * @param array<string, mixed>   $aviso              Aviso normalizado.
     * @param ClientAssistantMessage $ultima_del_cliente Repuesto.
     *
     * @return ClientAssistantMessage
     */
    private function destino_del_aviso(Client $client, array $aviso, ClientAssistantMessage $ultima_del_cliente): ClientAssistantMessage
    {
        if ($aviso['ai_conversation_id'] === null) {
            return $ultima_del_cliente;
        }

        $de_la_conversacion = ClientAssistantMessage::query()
            ->where('client_id', (int) $client->id)
            ->where('direccion', ClientAssistantMessage::DIRECCION_ENTRANTE)
            ->where('ai_conversation_id', (int) $aviso['ai_conversation_id'])
            ->whereNotNull('telefono')
            ->where('telefono', '!=', '')
            ->orderBy('id', 'desc')
            ->first();

        return $de_la_conversacion !== null ? $de_la_conversacion : $ultima_del_cliente;
    }

    /**
     * Si ese teléfono del cliente tiene un mensaje al asistente que todavía no se contestó.
     *
     * Se compara el `telefono` tal cual: las dos filas salen de la misma tabla, que lo guarda ya
     * normalizado por el webhook.
     *
     * @param Client $client   Cliente.
     * @param string $telefono Teléfono del destino del aviso.
     *
     * @return bool
     */
    private function tiene_un_turno_en_curso(Client $client, string $telefono): bool
    {
        return ClientAssistantMessage::query()
            ->where('client_id', (int) $client->id)
            ->where('direccion', ClientAssistantMessage::DIRECCION_ENTRANTE)
            ->where('telefono', $telefono)
            ->whereIn('estado', self::ESTADOS_DE_TURNO_EN_CURSO)
            ->where('created_at', '>=', now()->subMinutes(self::MINUTOS_DE_TURNO_EN_CURSO))
            ->exists();
    }

    /**
     * La última fila entrante del cliente en las últimas 24 hs, o null.
     *
     * @param Client $client Cliente.
     *
     * @return ClientAssistantMessage|null
     */
    private function ultima_entrante_del_cliente(Client $client): ?ClientAssistantMessage
    {
        return ClientAssistantMessage::query()
            ->where('client_id', (int) $client->id)
            ->where('direccion', ClientAssistantMessage::DIRECCION_ENTRANTE)
            ->where('created_at', '>=', now()->subHours(self::HORAS_DE_ACTIVIDAD))
            ->whereNotNull('telefono')
            ->where('telefono', '!=', '')
            ->orderBy('id', 'desc')
            ->first();
    }

    /**
     * Si la ventana de 24 hs de Meta está abierta para ese teléfono, en el número por el que sale.
     *
     * Primero, la medición de siempre: `WhatsappSessionWindowService` con el propósito efectivo. Para
     * el número del asistente eso ya cuenta los entrantes del hilo del asistente por ese número.
     *
     * 🔴 **Y para el principal hace falta un complemento, sin el cual el aviso no saldría nunca hoy.**
     * Con propósito `principal`, el servicio de la ventana mira soporte, leads e implementación, y NO
     * `client_assistant_messages`. Pero mientras el número propio del asistente no esté listo (al
     * 16/10/2026 su alta está frenada en Meta), el dueño le escribe al asistente por el número
     * principal: esos mensajes abren la ventana del principal para Meta, y solo están en esta tabla.
     * Sin contarlos, un dueño que acaba de mandar su factura tendría la ventana "cerrada" y el aviso
     * se quedaría pendiente hasta vencer. Se cuentan las entrantes de ESTE cliente, de ese mismo
     * teléfono (E.164 exacto, como el servicio de la ventana), que NO entraron por el número del
     * asistente — la misma partición que usa `proposito_de_salida()`. No se toca el servicio de la
     * ventana porque tiene otros consumidores (la bandeja de soporte, los programados, los leads).
     *
     * @param Client                       $client    Cliente.
     * @param string                       $telefono  Teléfono del destino.
     * @param string                       $efectivo  Propósito por el que va a salir de verdad.
     * @param WhatsappSessionWindowService $ventana   Ventana de 24 hs.
     *
     * @return bool
     */
    private function ventana_abierta(Client $client, string $telefono, string $efectivo, WhatsappSessionWindowService $ventana): bool
    {
        if ($ventana->is_open($telefono, $efectivo)) {
            return true;
        }

        if ($efectivo !== WhatsappConfig::PROPOSITO_PRINCIPAL) {
            return false;
        }

        $normalizado = WhatsappNormalizer::normalize($telefono);
        if ($normalizado === '') {
            return false;
        }

        $numero_del_asistente = WhatsappConfig::porProposito(WhatsappConfig::PROPOSITO_ASISTENTE);
        $id_del_asistente     = $numero_del_asistente !== null ? trim((string) $numero_del_asistente->phone_number_id) : '';

        $filas = ClientAssistantMessage::query()
            ->where('client_id', (int) $client->id)
            ->where('direccion', ClientAssistantMessage::DIRECCION_ENTRANTE)
            ->where('created_at', '>=', now()->subHours(WhatsappSessionWindowService::WINDOW_HOURS))
            ->orderBy('id', 'desc')
            ->get(['telefono', 'phone_number_id']);

        foreach ($filas as $fila) {
            if (WhatsappNormalizer::normalize((string) $fila->telefono) !== $normalizado) {
                continue;
            }

            $entro_por = trim((string) $fila->phone_number_id);
            if ($id_del_asistente !== '' && $entro_por === $id_del_asistente) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * Le dice al `empresa-api` que el aviso ya se mandó.
     *
     * Un 404 cuenta como hecho: el `empresa-api` ya no reconoce ese aviso (vencido, borrado, de otro
     * dueño), así que reintentar la marca no lleva a nada y el reclamo se cierra igual.
     *
     * @param Client $client              Cliente.
     * @param string $aviso_id            Id del contrato.
     * @param string $link                Link con el que salió, o vacío.
     * @param string $whatsapp_message_id wamid con el que salió, o vacío.
     *
     * @return bool True si ya no hay que volver a marcarlo.
     */
    private function marcar_avisado(Client $client, string $aviso_id, string $link, string $whatsapp_message_id): bool
    {
        $url = $this->urls->admin_sync_url($client, self::RUTA_AVISADO);
        if ($url === '') {
            return false;
        }

        $cuerpo = ['id' => $aviso_id];
        if ($link !== '') {
            $cuerpo['url'] = $link;
        }
        if ($whatsapp_message_id !== '') {
            $cuerpo['whatsapp_message_id'] = $whatsapp_message_id;
        }

        try {
            $respuesta = Http::withHeaders([
                    'X-Admin-Api-Key' => trim((string) $client->api_key),
                    'Accept'          => 'application/json',
                ])
                ->timeout((int) config('services.client_api.timeout', 15))
                ->post($url, $cuerpo);
        } catch (\Throwable $exception) {
            Log::channel('daily')->warning('AsistenteAvisos: no se pudo marcar el aviso como mandado.', [
                'client_id' => $client->id,
                'aviso_id'  => $aviso_id,
                'error'     => $exception->getMessage(),
            ]);

            return false;
        }

        $status = (int) $respuesta->status();

        if ($status === 404) {
            Log::channel('daily')->info('AsistenteAvisos: el sistema del cliente ya no reconoce el aviso; se da por cerrado.', [
                'client_id' => $client->id,
                'aviso_id'  => $aviso_id,
            ]);

            return true;
        }

        if (! $respuesta->successful()) {
            Log::channel('daily')->warning('AsistenteAvisos: el cliente rechazó la marca del aviso.', [
                'client_id' => $client->id,
                'aviso_id'  => $aviso_id,
                'status'    => $status,
            ]);

            return false;
        }

        return true;
    }

    /**
     * Clave de pausa de un cliente.
     *
     * @param Client $client
     *
     * @return string
     */
    private function clave_de_pausa(Client $client): string
    {
        return self::clave_de_pausa_de((int) $client->id);
    }

    /**
     * Clave de pausa de un cliente, por id. Pública para que las pruebas y el job miren la misma.
     *
     * @param int $client_id
     *
     * @return string
     */
    public static function clave_de_pausa_de(int $client_id): string
    {
        return self::CACHE_PAUSA . ':' . $client_id;
    }

    /**
     * Saca al cliente de la pausa: la próxima vuelta del barrido lo consulta.
     *
     * La llama `EnviarMensajeAlAsistenteJob` cuando el 202 del mensaje trae `admite_avisos: true`,
     * o sea cuando el sistema del cliente acaba de decir que ya tiene `avisos-pendientes`. Sin esto,
     * la pausa de un 404 de antes del upgrade seguiría corriendo después del upgrade, justo cuando el
     * `empresa-api` ya le prometió al dueño el aviso.
     *
     * Levantar una pausa por ERROR también está bien: un sistema que acaba de contestar un 202 está
     * atendiendo.
     *
     * @param int $client_id Cliente.
     *
     * @return void
     */
    public static function levantar_pausa(int $client_id): void
    {
        Cache::forget(self::clave_de_pausa_de($client_id));
    }

    /**
     * Clave del reclamo de un aviso.
     *
     * @param Client $client
     * @param string $aviso_id
     *
     * @return string
     */
    public function clave_de_reclamo(Client $client, string $aviso_id): string
    {
        return self::CACHE_RECLAMO . ':' . (int) $client->id . ':' . $aviso_id;
    }

    /**
     * Arma la fila del resultado que imprime el comando.
     *
     * @param Client $client
     * @param string $estado
     * @param int    $avisos
     * @param int    $enviados
     * @param string $detalle
     *
     * @return array{client_id: int, nombre: string, estado: string, avisos: int, enviados: int, detalle: string}
     */
    private function resultado(Client $client, string $estado, int $avisos, int $enviados, string $detalle): array
    {
        return [
            'client_id' => (int) $client->id,
            'nombre'    => (string) $client->name,
            'estado'    => $estado,
            'avisos'    => $avisos,
            'enviados'  => $enviados,
            'detalle'   => $detalle,
        ];
    }
}

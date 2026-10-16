<?php

namespace App\Services;

use App\Helpers\WhatsappNormalizer;
use App\Models\Client;
use App\Models\ClientAssistantMessage;
use App\Models\ClientTemplate;
use App\Models\WhatsappConfig;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Los recordatorios de la Agenda por WhatsApp: «recordame el viernes a las 10 que llame a Juan».
 *
 * Misión cruzada recordatorios-whatsapp (16/10/2026). Hasta acá `proponer_tarea` guardaba la hora
 * como texto adelante del detalle («10:00 · Llamar a…») y el asistente le decía al dueño que no iba
 * a recibir ningún aviso. Ahora el `empresa-api` deriva la hora de ese prefijo, guarda qué tareas
 * las confirmó el dueño desde el asistente y expone cuáles vencieron; este servicio le pregunta cada
 * cinco minutos a cada cliente y le manda al dueño lo que haya.
 *
 * 🔴 **Sale por CONSULTA del admin, igual que los avisos** (`AsistenteAvisosService`): el
 * `empresa-api` no necesita saber cómo llegar al admin. Pero a diferencia de los avisos, **no se
 * filtra por actividad reciente del dueño**: el recordatorio sale justamente cuando él NO está
 * hablando con el asistente, que es el caso normal. Se consulta a todos los clientes con el canal
 * prendido, y el 404 de un sistema viejo los deja una hora en pausa para que eso sea barato.
 *
 * 🔴 **Por eso la ventana de 24 hs está cerrada casi siempre, y sin plantilla aprobada no sale
 * nada.** Ventana abierta → texto libre con el texto que armó el `empresa-api`. Cerrada → la
 * plantilla `cc_recordatorio_asistente` con el detalle y la hora como única variable. Cerrada y sin
 * plantilla → no se manda: el recordatorio sigue pendiente y el `empresa-api` lo vence solo a las
 * 3 hs. Para que el asistente no le prometa al dueño un aviso que no va a salir, cada consulta le
 * dice al `empresa-api` si hay plantilla (`plantilla=1|0`), y él ajusta lo que le dice al modelo.
 *
 * 🔴 **Y no se manda dos veces.** Mismo esquema que los avisos, copiado y no compartido a propósito
 * (el comportamiento de los avisos no puede cambiar por esta misión): el recordatorio se RECLAMA en
 * la cache antes de mandarlo (`Cache::add`, atómico), el reclamo se suelta solo si el envío falla y,
 * con el WhatsApp ya afuera, guarda el wamid para que una marca fallida se reintente SOLA, sin
 * volver a escribirle al dueño. El id del recordatorio trae la fecha (`recordatorio:{tarea}-{alta}:
 * {Y-m-d}`), así que la recurrente de mañana es otro reclamo.
 */
class AsistenteRecordatoriosService
{
    /**
     * Ruta de los recordatorios que el `empresa-api` tiene vencidos y sin avisar.
     */
    const RUTA_PENDIENTES = 'api/admin-sync/asistente/recordatorios-pendientes';

    /**
     * Ruta que marca un recordatorio como mandado. El id viaja en el CUERPO, no en la ruta: es un
     * string con `:` adentro (`"recordatorio:{pending_id}-{created_at unix}:{Y-m-d}"`).
     */
    const RUTA_AVISADO = 'api/admin-sync/asistente/recordatorios/avisado';

    /**
     * Minutos sin volver a consultar a un cliente cuyo sistema no tiene la ruta (404).
     *
     * Una hora, el doble de largo que la de los avisos, y por el mismo motivo multiplicado: acá no
     * hay filtro de actividad, así que se consulta a TODOS los clientes con el canal prendido cada
     * cinco minutos. Con un `empresa-api` anterior a esta misión eso serían 288 pedidos por día y por
     * cliente contra un shared hosting que bloquea por ráfaga. El costo es que un cliente recién
     * actualizado tarda hasta una hora en empezar a recibir recordatorios; mientras tanto no se
     * pierde ninguno que valga la pena, porque el `empresa-api` los ofrece durante 3 hs.
     */
    const MINUTOS_DE_PAUSA_SIN_LA_FUNCION = 60;

    /**
     * Minutos sin volver a consultar a un cliente que respondió con error (401/403/409/5xx/timeout).
     *
     * Corto a propósito: puede ser pasajero, y un recordatorio que se demora diez minutos todavía
     * sirve.
     */
    const MINUTOS_DE_PAUSA_POR_ERROR = 10;

    /**
     * Días que dura el reclamo de un recordatorio ya mandado. Mucho más que las 3 hs que el
     * `empresa-api` lo ofrece, así que mientras exista el recordatorio, el reclamo también.
     */
    const DIAS_DEL_RECLAMO = 7;

    /**
     * Minutos que dura el reclamo mientras el recordatorio se está MANDANDO (estado `reclamado`).
     *
     * 🔴 Corto a propósito, por la misma razón que en los avisos: si el proceso muere entre el
     * `Cache::add()` y el `put()`/`forget()` que lo cierra (OOM, un reinicio), con los siete días del
     * reclamo largo el recordatorio no saldría nunca. Diez minutos son muchísimo más que un envío y
     * menos que las 3 hs en que el `empresa-api` lo sigue ofreciendo.
     */
    const MINUTOS_DEL_RECLAMO_EN_CURSO = 10;

    /**
     * Minutos hacia atrás en los que un mensaje del dueño sin contestar frena el recordatorio.
     *
     * Los mismos treinta minutos que los avisos: cubren de sobra cualquier turno vivo, y una fila más
     * vieja trabada en un estado no final es un turno muerto que no puede dejar al dueño sin su
     * recordatorio.
     */
    const MINUTOS_DE_TURNO_EN_CURSO = 30;

    /**
     * Estados de una fila entrante cuyo turno todavía no terminó: el dueño espera la respuesta.
     *
     * Los mismos que los avisos (los NO finales de `EnviarMensajeAlAsistenteJob::es_estado_final()`).
     */
    const ESTADOS_DE_TURNO_EN_CURSO = [
        ClientAssistantMessage::ESTADO_RECIBIDO,
        ClientAssistantMessage::ESTADO_ENVIADO,
        ClientAssistantMessage::ESTADO_ESPERANDO,
    ];

    /**
     * Cuántas veces seguidas puede rechazar Meta un mismo recordatorio antes de darlo por perdido.
     *
     * 🔴 Sin tope, un rechazo persistente se reintentaría cada cinco minutos durante las 3 hs en que
     * el `empresa-api` lo ofrece. Y la plantilla no tiene cómo pedirle a `send_template()` que no
     * avise a los admins de cada fallo (el texto sí: ver `enviar_uno()`), así que el tope es además
     * lo que acota esas notificaciones a tres por recordatorio.
     */
    const INTENTOS_MAXIMOS = 3;

    /**
     * Horas que un recordatorio `fallido` queda sin reintentarse. Más que las 3 hs en que el
     * `empresa-api` lo ofrece, así que no vuelve a intentarse nunca.
     */
    const HORAS_DEL_FALLIDO = 24;

    /**
     * Largo máximo (exclusivo) de la variable de la plantilla.
     *
     * El mismo tope que `SupportWhatsappOpenerService::TEMPLATE_VARIABLE_MAX_LENGTH`, repetido y no
     * importado por la misma razón que en `AsistenteInformesService::LARGO_MAXIMO_DE_VARIABLE`. Se
     * usa como tope EXCLUSIVO, igual que allá y que el opener: el saneo del opener corta a
     * exactamente 600 caracteres, y el opener mismo trata 600 como "demasiado largo".
     */
    const LARGO_MAXIMO_DE_VARIABLE = 600;

    /**
     * Prefijo del contador de intentos fallidos: `asistente_recordatorio_intentos:{client_id}:{id}`.
     *
     * En una clave hermana del reclamo, como en los avisos: entre un intento y el siguiente el
     * reclamo se SUELTA, y el contador tiene que sobrevivir a eso.
     */
    const CACHE_INTENTOS = 'asistente_recordatorio_intentos';

    /**
     * Prefijo de la clave de pausa por cliente: `asistente_recordatorios:pausa:{client_id}`.
     *
     * Propia y no la de los avisos: un cliente puede tener una ruta y no la otra (son dos misiones
     * distintas del `empresa-api`), y la pausa del 404 de una no puede callar a la otra.
     */
    const CACHE_PAUSA = 'asistente_recordatorios:pausa';

    /**
     * Prefijo de la clave del reclamo de un recordatorio: `asistente_recordatorio:{client_id}:{id}`.
     */
    const CACHE_RECLAMO = 'asistente_recordatorio';

    /**
     * Estados del reclamo guardado en la cache. Los mismos cuatro de los avisos:
     *
     * - `reclamado`: alguien lo está mandando ahora mismo (o murió en el medio: vence a los 10 min).
     * - `enviado`: el WhatsApp salió y falta que el `empresa-api` lo marque.
     * - `marcado`: salió y quedó marcado (o el `empresa-api` ya no lo reconoce): no hay nada más que hacer.
     * - `fallido`: Meta lo rechazó INTENTOS_MAXIMOS veces: no se reintenta más (vence a las 24 hs).
     */
    const RECLAMO_RECLAMADO = 'reclamado';
    const RECLAMO_ENVIADO   = 'enviado';
    const RECLAMO_MARCADO   = 'marcado';
    const RECLAMO_FALLIDO   = 'fallido';

    /**
     * Resolución de la URL del `empresa-api` de cada cliente.
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
     * Dueño de la fila saliente del hilo.
     *
     * @var AsistenteWhatsappService
     */
    private $asistente;

    /**
     * Saneo de variables de plantilla: el del opener, que es el que ya está probado contra Meta.
     *
     * @var SupportWhatsappOpenerService
     */
    private $opener;

    /**
     * @param ClientEmpresaApiUrlResolver  $urls      Resolución de URLs del cliente.
     * @param WhatsappSendService          $sender    Envío a Kapso/Meta.
     * @param AsistenteWhatsappService     $asistente Fila saliente del hilo.
     * @param SupportWhatsappOpenerService $opener    Dueño del saneo de variables de plantilla.
     */
    public function __construct(
        ClientEmpresaApiUrlResolver $urls,
        WhatsappSendService $sender,
        AsistenteWhatsappService $asistente,
        SupportWhatsappOpenerService $opener
    ) {
        $this->urls      = $urls;
        $this->sender    = $sender;
        $this->asistente = $asistente;
        $this->opener    = $opener;
    }

    /**
     * Si a este cliente se le consultan los recordatorios.
     *
     * El criterio de los avisos (`AsistenteAvisosService::cliente_recibe_avisos()`: activo + canal
     * prendido + clave) más el teléfono de la ficha, que acá es el ÚNICO destino posible: el
     * recordatorio no contesta ningún mensaje, así que no hay una fila entrante de la que sacar a
     * quién hablarle. Sin teléfono no tiene sentido preguntarle nada a su sistema.
     *
     * @param Client $client
     *
     * @return bool
     */
    public static function cliente_recibe_recordatorios(Client $client): bool
    {
        return AsistenteAvisosService::cliente_recibe_avisos($client)
            && trim((string) $client->phone) !== '';
    }

    /**
     * La plantilla con la que salen los recordatorios fuera de ventana, o null si no hay una que sirva.
     *
     * 🔴 Las dos cosas, igual que los informes: el nombre cargado en `AdminSetting` Y la fila activa en
     * `client_templates`. Es también lo que decide el `plantilla=1|0` de la consulta, o sea lo que el
     * asistente le promete al dueño: si se anunciara con solo el nombre cargado, el dueño escucharía
     * «te llega un recordatorio» y el envío cortaría acá por la fila que falta.
     *
     * @return ClientTemplate|null
     */
    public static function plantilla_configurada(): ?ClientTemplate
    {
        $nombre = AsistenteWhatsappSettings::plantilla_de_recordatorios();
        if ($nombre === '') {
            return null;
        }

        return ClientTemplate::query()
            ->where('template_name', $nombre)
            ->where('activa', true)
            ->first();
    }

    /**
     * Manda los recordatorios vencidos de todos los clientes que pueden tenerlos.
     *
     * Cada cliente va en su propio try: el que falla no puede dejar sin recordatorio a los que vienen
     * después.
     *
     * @return array<int, array<string, mixed>> Una fila por cliente, para que el comando la imprima.
     */
    public function enviar_a_todos(): array
    {
        $clientes = Client::query()
            ->where('is_active', true)
            ->where('asistente_whatsapp_activo', true)
            ->whereNotNull('api_key')
            ->where('api_key', '!=', '')
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->orderBy('id')
            ->get()
            /* La consulta de arriba es el prefiltro barato en SQL; el que decide es el criterio
             * escrito una sola vez, que además recorta los espacios. */
            ->filter(function (Client $client) {
                return self::cliente_recibe_recordatorios($client);
            });

        /* Se resuelve una vez por corrida: es la misma para todos los clientes. */
        $plantilla = self::plantilla_configurada();

        $resultados = [];

        foreach ($clientes as $client) {
            try {
                $resultados[] = $this->enviar_a($client, $plantilla);
            } catch (\Throwable $exception) {
                Log::channel('daily')->error('AsistenteRecordatorios: excepción mandando los recordatorios de un cliente.', [
                    'client_id' => $client->id,
                    'error'     => $exception->getMessage(),
                ]);

                $resultados[] = $this->resultado($client, 'error', 0, 0, $exception->getMessage());
            }
        }

        return $resultados;
    }

    /**
     * Manda los recordatorios vencidos de un cliente.
     *
     * @param Client              $client    Cliente con el canal prendido.
     * @param ClientTemplate|null $plantilla Plantilla para la ventana cerrada. Si no viene, se resuelve
     *                                       acá: el barrido la resuelve una vez y la presta, y si no
     *                                       hay ninguna se vuelve a mirar por cliente (una consulta
     *                                       barata, y el `--client` del comando no pasa ninguna).
     *
     * @return array{client_id: int, nombre: string, estado: string, recordatorios: int, enviados: int, detalle: string}
     */
    public function enviar_a(Client $client, ?ClientTemplate $plantilla = null): array
    {
        if ($plantilla === null) {
            $plantilla = self::plantilla_configurada();
        }

        $telefono = trim((string) $client->phone);
        if ($telefono === '') {
            return $this->resultado($client, 'sin_telefono', 0, 0, 'El cliente no tiene teléfono cargado en su ficha.');
        }

        if (trim((string) $client->api_key) === '') {
            return $this->resultado($client, 'sin_api_key', 0, 0, 'El cliente no tiene cargada la api_key.');
        }

        $pausa = Cache::get($this->clave_de_pausa($client));
        if ($pausa !== null) {
            return $this->resultado($client, 'en_pausa', 0, 0, 'En pausa hasta la próxima consulta: ' . (string) $pausa);
        }

        $pendientes = $this->traer_recordatorios($client, $plantilla !== null);

        if ($pendientes['estado'] !== 'ok') {
            return $this->resultado($client, $pendientes['estado'], 0, 0, $pendientes['detalle']);
        }

        $recordatorios = $pendientes['recordatorios'];
        if ($recordatorios === []) {
            return $this->resultado($client, 'sin_recordatorios', 0, 0, 'No hay recordatorios vencidos.');
        }

        /*
         * 🔴 **La ventana se mide sobre el número POR EL QUE SALE, y es la MISMA elección para medir y
         * para mandar** (ver el comentario grande de `AsistenteInformesService::enviar_a()`).
         * `propositoEfectivoDelAsistente()` devuelve `asistente` solo cuando ese número está listo, y
         * `paraProposito()` hace la misma elección; acá se le pasa el efectivo derecho para que no
         * puedan separarse.
         *
         * Se mide una sola vez por cliente: el destino es siempre el mismo teléfono, el de la ficha.
         * Una instancia nueva del servicio de la ventana por cliente, para que lo cacheado de uno no
         * se le pegue al siguiente en una corrida larga.
         */
        $efectivo = WhatsappConfig::propositoEfectivoDelAsistente();
        $abierta  = $this->ventana_abierta($client, $telefono, $efectivo, app(WhatsappSessionWindowService::class));

        $enviados = 0;
        $estados  = [];
        foreach ($recordatorios as $recordatorio) {
            $estado = $this->recordar($client, $recordatorio, $telefono, $efectivo, $abierta, $plantilla);

            $estados[] = $recordatorio['id'] . '=' . $estado;
            if ($estado === 'enviado_texto' || $estado === 'enviado_plantilla') {
                $enviados++;
            }
        }

        Log::channel('daily')->info('AsistenteRecordatorios: recordatorios del cliente procesados.', [
            'client_id'     => $client->id,
            'recordatorios' => count($recordatorios),
            'enviados'      => $enviados,
            'estados'       => $estados,
        ]);

        return $this->resultado(
            $client,
            $enviados > 0 ? 'enviado' : 'pendiente',
            count($recordatorios),
            $enviados,
            implode(', ', $estados)
        );
    }

    /**
     * Manda UN recordatorio, si se puede, y lo marca del lado del cliente.
     *
     * @param Client               $client       Cliente dueño del recordatorio.
     * @param array<string, mixed> $recordatorio Recordatorio normalizado por `traer_recordatorios()`.
     * @param string               $telefono     Teléfono de la ficha (el destino).
     * @param string               $efectivo     Propósito por el que sale de verdad.
     * @param bool                 $abierta      Si la ventana de 24 hs de ese número está abierta.
     * @param ClientTemplate|null  $plantilla    Plantilla para la ventana cerrada, o null.
     *
     * @return string enviado_texto | enviado_plantilla | remarcado | ya_avisado | en_curso
     *                | turno_en_curso | sin_plantilla | error | fallido
     */
    private function recordar(
        Client $client,
        array $recordatorio,
        string $telefono,
        string $efectivo,
        bool $abierta,
        ?ClientTemplate $plantilla
    ): string {
        $clave = $this->clave_de_reclamo($client, (string) $recordatorio['id']);

        /* Ya hay un reclamo: o alguien lo está mandando, o ya salió. En ningún caso se le vuelve a
         * escribir al dueño. Si salió y el `empresa-api` lo sigue ofreciendo, es que la marca falló:
         * se reintenta SOLO la marca, con el wamid con el que salió. */
        $previo = Cache::get($clave);
        if ($previo !== null) {
            return $this->resolver_reclamo_previo($client, (string) $recordatorio['id'], $clave, $previo);
        }

        /* 🔴 El recordatorio no se mete en medio de un turno: si el dueño le acaba de escribir al
         * asistente y la respuesta todavía no salió, el recordatorio llegaría antes que ella. Queda
         * para la vuelta siguiente (sin reclamo: no salió nada). */
        if ($this->tiene_un_turno_en_curso($client, $telefono)) {
            return 'turno_en_curso';
        }

        if ($abierta) {
            $mensaje = (string) $recordatorio['texto'];
        } else {
            /* 🔴 Ventana cerrada y sin plantilla: NO se manda texto libre (Meta lo rechaza y el dueño
             * no ve nada). Sigue pendiente; si la ventana se abre antes de que el `empresa-api` lo
             * venza, sale como texto. */
            if ($plantilla === null) {
                return 'sin_plantilla';
            }

            $mensaje = $this->armar_variable_de_plantilla($recordatorio);

            /* Variable vacía = Meta la descarta con `(#131008) Required parameter is missing` (ver
             * `WhatsappSendService::send_template()`). Sin nada que poner adentro, no se manda. */
            if ($mensaje === '') {
                return 'sin_plantilla';
            }
        }

        /* `add()` es atómico: con dos corridas pisándose, una sola manda. */
        if (! Cache::add($clave, ['estado' => self::RECLAMO_RECLAMADO], now()->addMinutes(self::MINUTOS_DEL_RECLAMO_EN_CURSO))) {
            return 'en_curso';
        }

        $contexto = 'Recordatorio del asistente - cliente #' . $client->id;

        try {
            $this->sender->paraProposito($efectivo);

            if ($abierta) {
                /* 🔴 `skip_failure_notification = true`, como los avisos: un recordatorio rechazado
                 * no le manda un WhatsApp de error a los admins por cada intento. Queda en
                 * `last_send_error`, en el log de cada intento y, al agotarlos, en un log único. */
                $whatsapp_message_id = $this->sender->send_text($telefono, $mensaje, $contexto, true);
            } else {
                $whatsapp_message_id = $this->sender->send_template(
                    $telefono,
                    (string) $plantilla->template_name,
                    [$mensaje],
                    $this->idioma_de($plantilla),
                    $contexto
                );
            }
        } catch (\Throwable $exception) {
            /* No salió: cuenta como intento y, si quedan, se suelta para la vuelta siguiente. */
            $this->registrar_fallo($client, (string) $recordatorio['id'], $clave, $exception->getMessage());

            throw $exception;
        }

        if ($whatsapp_message_id === null) {
            return $this->registrar_fallo($client, (string) $recordatorio['id'], $clave, (string) $this->sender->last_send_error);
        }

        /* Desde acá el recordatorio YA salió: el reclamo pasa a guardar con qué salió, para que una
         * marca fallida se pueda reintentar sin mandárselo dos veces al dueño. */
        Cache::put(
            $clave,
            ['estado' => self::RECLAMO_ENVIADO, 'whatsapp_message_id' => $whatsapp_message_id],
            now()->addDays(self::DIAS_DEL_RECLAMO)
        );

        /*
         * 🔴 La fila saliente es lo que le da contexto al «listo, ya lo hice» del dueño: responder
         * citando el recordatorio lo lleva a la conversación que mandó el `empresa-api`, si mandó
         * una. Con plantilla, la fila guarda la variable ya renderizada y no la plantilla cruda (el
         * criterio de los informes: quien relee la fila tiene que ver lo que le llegó).
         *
         * En su propio try: el WhatsApp ya salió, y una fila que no se pudo escribir no puede impedir
         * que el recordatorio quede marcado del lado del cliente.
         */
        try {
            $this->asistente->registrar_saliente_de(
                (int) $client->id,
                $telefono,
                $mensaje,
                $whatsapp_message_id,
                $recordatorio['ai_conversation_id'],
                ClientAssistantMessage::ESTADO_RESPONDIDO,
                null,
                $this->sender->phone_number_id_de_destino()
            );
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('AsistenteRecordatorios: el recordatorio salió pero no se pudo dejar la fila saliente.', [
                'client_id'       => $client->id,
                'recordatorio_id' => $recordatorio['id'],
                'error'           => $exception->getMessage(),
            ]);
        }

        $this->marcar_y_cerrar_el_reclamo($client, (string) $recordatorio['id'], $clave, $whatsapp_message_id);

        return $abierta ? 'enviado_texto' : 'enviado_plantilla';
    }

    /**
     * La variable de la plantilla: el detalle de la tarea y cuándo, en una sola línea.
     *
     * «Llamar a Juan por el presupuesto (hoy 10:00)». El detalle llega SIN el prefijo de la hora (lo
     * saca el `empresa-api`), así que la hora se agrega una sola vez, al final. Si el detalle no
     * entra, se recorta EL DETALLE y no la hora: un recordatorio sin hora no dice nada.
     *
     * Sin detalle (un `empresa-api` que no lo mandó) se usa el texto entero, aplanado: es preferible
     * a una variable que diga solo «(hoy 10:00)».
     *
     * @param array<string, mixed> $recordatorio Recordatorio normalizado.
     *
     * @return string Cadena vacía si no quedó nada que mandar.
     */
    public function armar_variable_de_plantilla(array $recordatorio): string
    {
        $detalle = $this->opener->sanitize_template_variable((string) $recordatorio['detalle']);
        if ($detalle === '') {
            $detalle = $this->opener->sanitize_template_variable((string) $recordatorio['texto']);
        }

        if ($detalle === '') {
            return '';
        }

        $cuando = $this->cuando($recordatorio);
        $sufijo = $cuando !== '' ? ' (' . $cuando . ')' : '';

        $lugar = self::LARGO_MAXIMO_DE_VARIABLE - 1 - mb_strlen($sufijo);
        if (mb_strlen($detalle) > $lugar) {
            $detalle = rtrim(mb_substr($detalle, 0, $lugar - 1)) . '…';
        }

        return $this->opener->sanitize_template_variable($detalle . $sufijo);
    }

    /**
     * Cuándo es el recordatorio, como se lee: «hoy 10:00», o «17/10 10:00» si no es de hoy.
     *
     * El `empresa-api` solo ofrece los de hoy, así que el segundo caso es una defensa (un reloj
     * corrido entre los dos sistemas a la medianoche), no un camino normal: decir «hoy» de un
     * recordatorio de ayer sería mentirle al dueño.
     *
     * @param array<string, mixed> $recordatorio
     *
     * @return string Vacío si no vino la hora.
     */
    private function cuando(array $recordatorio): string
    {
        $hora = (string) $recordatorio['hora'];
        if ($hora === '') {
            return '';
        }

        $fecha = (string) $recordatorio['fecha'];
        if ($fecha === '' || $fecha === now()->toDateString()) {
            return 'hoy ' . $hora;
        }

        $partes = explode('-', $fecha);

        return (int) $partes[2] . '/' . (int) $partes[1] . ' ' . $hora;
    }

    /**
     * Idioma con el que sale la plantilla: el de su fila en `client_templates`, que es la que se dio
     * de alta contra Meta; si la fila no lo tiene, el de la configuración.
     *
     * @param ClientTemplate $plantilla
     *
     * @return string
     */
    private function idioma_de(ClientTemplate $plantilla): string
    {
        $idioma = trim((string) $plantilla->language_code);

        return $idioma !== '' ? $idioma : AsistenteWhatsappSettings::idioma_de_la_plantilla_de_recordatorios();
    }

    /**
     * Anota un intento fallido: suelta el reclamo para reintentar, o lo da por `fallido`.
     *
     * Copia de `AsistenteAvisosService::registrar_fallo()`, con sus propias claves.
     *
     * @param Client $client          Cliente.
     * @param string $recordatorio_id Id del recordatorio.
     * @param string $clave           Clave del reclamo.
     * @param string $detalle         Por qué falló.
     *
     * @return string error (se reintenta la vuelta siguiente) | fallido (no se reintenta más)
     */
    private function registrar_fallo(Client $client, string $recordatorio_id, string $clave, string $detalle): string
    {
        $clave_de_intentos = self::CACHE_INTENTOS . ':' . (int) $client->id . ':' . $recordatorio_id;

        /* `add()` primero para que el contador nazca con vencimiento; `increment()` sobre una clave
         * inexistente la crearía sin él en algunos drivers. */
        Cache::add($clave_de_intentos, 0, now()->addHours(self::HORAS_DEL_FALLIDO));
        $intentos = (int) Cache::increment($clave_de_intentos);

        if ($intentos < self::INTENTOS_MAXIMOS) {
            Cache::forget($clave);

            Log::channel('daily')->warning('AsistenteRecordatorios: Meta rechazó el recordatorio, se reintenta la vuelta siguiente.', [
                'client_id'       => $client->id,
                'recordatorio_id' => $recordatorio_id,
                'intento'         => $intentos,
                'error'           => $detalle,
            ]);

            return 'error';
        }

        /* El reclamo queda tomado como `fallido` y ya no se suelta: el log de error sale una vez. */
        Cache::put(
            $clave,
            ['estado' => self::RECLAMO_FALLIDO, 'intentos' => $intentos],
            now()->addHours(self::HORAS_DEL_FALLIDO)
        );

        Log::channel('daily')->error('AsistenteRecordatorios: Meta rechazó el recordatorio ' . $intentos . ' veces; no se reintenta más.', [
            'client_id'       => $client->id,
            'recordatorio_id' => $recordatorio_id,
            'error'           => $detalle,
        ]);

        return 'fallido';
    }

    /**
     * Qué hacer con un recordatorio que ya tiene reclamo.
     *
     * @param Client $client          Cliente.
     * @param string $recordatorio_id Id del recordatorio que el `empresa-api` sigue ofreciendo.
     * @param string $clave           Clave del reclamo.
     * @param mixed  $previo          Lo guardado en el reclamo.
     *
     * @return string remarcado | ya_avisado | fallido | en_curso
     */
    private function resolver_reclamo_previo(Client $client, string $recordatorio_id, string $clave, $previo): string
    {
        $estado = is_array($previo) && isset($previo['estado']) ? (string) $previo['estado'] : '';

        if ($estado === self::RECLAMO_ENVIADO) {
            $marcado = $this->marcar_y_cerrar_el_reclamo(
                $client,
                $recordatorio_id,
                $clave,
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
     * Marca el recordatorio del lado del cliente y, si quedó, cierra el reclamo.
     *
     * @param Client $client              Cliente.
     * @param string $recordatorio_id     Id del recordatorio (string del contrato).
     * @param string $clave               Clave del reclamo.
     * @param string $whatsapp_message_id wamid con el que salió.
     *
     * @return bool True si el `empresa-api` ya no lo va a volver a ofrecer.
     */
    private function marcar_y_cerrar_el_reclamo(Client $client, string $recordatorio_id, string $clave, string $whatsapp_message_id): bool
    {
        if (! $this->marcar_avisado($client, $recordatorio_id, $whatsapp_message_id)) {
            return false;
        }

        Cache::put($clave, ['estado' => self::RECLAMO_MARCADO], now()->addDays(self::DIAS_DEL_RECLAMO));

        return true;
    }

    /**
     * Le pide al `empresa-api` del cliente los recordatorios vencidos sin avisar.
     *
     * `plantilla=1|0` viaja SIEMPRE: es el anuncio con el que el `empresa-api` decide qué le dice el
     * asistente al dueño cuando agenda algo con hora (ver el docblock de la clase). Lo cachea dos
     * horas, así que con una consulta cada cinco minutos el anuncio no se cae nunca mientras el
     * admin esté vivo; y si el admin deja de consultar, se cae solo y el asistente deja de prometer.
     *
     * Las respuestas que no son 200 dejan al cliente en pausa: 404 una hora —es un sistema sin la
     * función, no un error, y no se le avisa a nadie—, y cualquier otra cosa diez minutos con su log.
     *
     * @param Client $client        Cliente.
     * @param bool   $con_plantilla Si hay plantilla para la ventana cerrada.
     *
     * @return array{estado: string, recordatorios: array<int, array<string, mixed>>, detalle: string}
     */
    private function traer_recordatorios(Client $client, bool $con_plantilla): array
    {
        $vacio = [];

        $url = $this->urls->admin_sync_url($client, self::RUTA_PENDIENTES);
        if ($url === '') {
            return ['estado' => 'sin_url', 'recordatorios' => $vacio, 'detalle' => 'No se pudo resolver la URL del empresa-api.'];
        }

        try {
            $respuesta = Http::withHeaders([
                    'X-Admin-Api-Key' => trim((string) $client->api_key),
                    'Accept'          => 'application/json',
                ])
                ->timeout((int) config('services.client_api.timeout', 15))
                ->get($url, ['plantilla' => $con_plantilla ? '1' : '0']);
        } catch (ConnectionException $exception) {
            return $this->pausar_por_error($client, 'No respondió el sistema del cliente: ' . $exception->getMessage());
        }

        $status = (int) $respuesta->status();

        /* 404: el cliente todavía no tiene la ruta. Es la versión vieja, no una falla: pausa larga,
         * sin avisar a los admins ni al dueño (a él no le falta nada que el asistente le haya
         * prometido: sin consulta no hay anuncio, y sin anuncio el asistente no promete el aviso). */
        if ($status === 404) {
            Cache::put(
                $this->clave_de_pausa($client),
                'sin la ruta de recordatorios (404)',
                now()->addMinutes(self::MINUTOS_DE_PAUSA_SIN_LA_FUNCION)
            );

            return ['estado' => 'degradado', 'recordatorios' => $vacio, 'detalle' => 'El sistema del cliente todavía no tiene la ruta de recordatorios.'];
        }

        if ($status !== 200) {
            return $this->pausar_por_error($client, 'El sistema del cliente respondió ' . $status . '.');
        }

        $datos = (array) $respuesta->json();
        $lista = isset($datos['recordatorios']) && is_array($datos['recordatorios']) ? $datos['recordatorios'] : [];

        $recordatorios = [];
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

            $hora  = isset($fila['hora']) ? trim((string) $fila['hora']) : '';
            $fecha = isset($fila['fecha']) ? trim((string) $fila['fecha']) : '';

            $recordatorios[] = [
                'id'                 => $id,
                'tarea_id'           => isset($fila['tarea_id']) ? (int) $fila['tarea_id'] : 0,
                /* Solo con la forma del contrato: una hora o una fecha raras no se le dicen al dueño. */
                'fecha'              => preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) === 1 ? $fecha : '',
                'hora'               => preg_match('/^\d{2}:\d{2}$/', $hora) === 1 ? $hora : '',
                'detalle'            => isset($fila['detalle']) ? trim((string) $fila['detalle']) : '',
                'texto'              => $texto,
                'ai_conversation_id' => isset($fila['ai_conversation_id']) && $fila['ai_conversation_id'] !== null && (int) $fila['ai_conversation_id'] > 0
                    ? (int) $fila['ai_conversation_id']
                    : null,
                'ruta'               => isset($fila['ruta']) ? trim((string) $fila['ruta']) : '',
            ];
        }

        return ['estado' => 'ok', 'recordatorios' => $recordatorios, 'detalle' => ''];
    }

    /**
     * Deja al cliente en pausa corta por un error y lo registra.
     *
     * @param Client $client  Cliente.
     * @param string $detalle Qué pasó.
     *
     * @return array{estado: string, recordatorios: array<int, array<string, mixed>>, detalle: string}
     */
    private function pausar_por_error(Client $client, string $detalle): array
    {
        Cache::put(
            $this->clave_de_pausa($client),
            $detalle,
            now()->addMinutes(self::MINUTOS_DE_PAUSA_POR_ERROR)
        );

        Log::channel('daily')->warning('AsistenteRecordatorios: no se pudieron traer los recordatorios de un cliente.', [
            'client_id' => $client->id,
            'detalle'   => $detalle,
        ]);

        return ['estado' => 'error', 'recordatorios' => [], 'detalle' => $detalle];
    }

    /**
     * Si ese teléfono del cliente tiene un mensaje al asistente que todavía no se contestó.
     *
     * La misma regla que los avisos, con una diferencia: allá el teléfono sale de la misma tabla y se
     * compara tal cual; acá sale de la FICHA del cliente, que se carga a mano y puede tener otro
     * formato que el que guarda el webhook. Por eso se comparan los dos normalizados.
     *
     * @param Client $client   Cliente.
     * @param string $telefono Teléfono de la ficha.
     *
     * @return bool
     */
    private function tiene_un_turno_en_curso(Client $client, string $telefono): bool
    {
        $normalizado = WhatsappNormalizer::normalize($telefono);
        if ($normalizado === '') {
            return false;
        }

        $filas = ClientAssistantMessage::query()
            ->where('client_id', (int) $client->id)
            ->where('direccion', ClientAssistantMessage::DIRECCION_ENTRANTE)
            ->whereIn('estado', self::ESTADOS_DE_TURNO_EN_CURSO)
            ->where('created_at', '>=', now()->subMinutes(self::MINUTOS_DE_TURNO_EN_CURSO))
            ->get(['telefono']);

        foreach ($filas as $fila) {
            if (WhatsappNormalizer::normalize((string) $fila->telefono) === $normalizado) {
                return true;
            }
        }

        return false;
    }

    /**
     * Si la ventana de 24 hs de Meta está abierta para ese teléfono, en el número por el que sale.
     *
     * La medición de siempre (`WhatsappSessionWindowService` con el propósito efectivo) más el mismo
     * complemento que los avisos (ver `AsistenteAvisosService::ventana_abierta()`): con propósito
     * `principal`, el servicio de la ventana NO mira `client_assistant_messages`, pero mientras el
     * número del asistente no esté listo el dueño le escribe al asistente por el principal, y esos
     * mensajes abren la ventana del principal para Meta. Sin contarlos, un dueño que estuvo hablando
     * con el asistente esta mañana recibiría el recordatorio de la tarde por plantilla —o no lo
     * recibiría, sin plantilla— pudiendo recibirlo como texto. Es además lo que el `empresa-api` le
     * promete al dueño cuando no hay plantilla: «si ese día estuvo hablando con vos, le llega».
     *
     * Copiado y no llamado: en los avisos es privado, y su comportamiento no se toca en esta misión.
     *
     * @param Client                       $client   Cliente.
     * @param string                       $telefono Teléfono del destino.
     * @param string                       $efectivo Propósito por el que va a salir de verdad.
     * @param WhatsappSessionWindowService $ventana  Ventana de 24 hs.
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
     * Le dice al `empresa-api` que el recordatorio ya se mandó.
     *
     * Un 404 cuenta como hecho: el `empresa-api` ya no reconoce ese recordatorio (la tarea se borró,
     * se completó o es de otro dueño), así que reintentar la marca no lleva a nada y el reclamo se
     * cierra igual.
     *
     * @param Client $client              Cliente.
     * @param string $recordatorio_id     Id del contrato.
     * @param string $whatsapp_message_id wamid con el que salió, o vacío.
     *
     * @return bool True si ya no hay que volver a marcarlo.
     */
    private function marcar_avisado(Client $client, string $recordatorio_id, string $whatsapp_message_id): bool
    {
        $url = $this->urls->admin_sync_url($client, self::RUTA_AVISADO);
        if ($url === '') {
            return false;
        }

        $cuerpo = ['id' => $recordatorio_id];
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
            Log::channel('daily')->warning('AsistenteRecordatorios: no se pudo marcar el recordatorio como mandado.', [
                'client_id'       => $client->id,
                'recordatorio_id' => $recordatorio_id,
                'error'           => $exception->getMessage(),
            ]);

            return false;
        }

        $status = (int) $respuesta->status();

        if ($status === 404) {
            Log::channel('daily')->info('AsistenteRecordatorios: el sistema del cliente ya no reconoce el recordatorio; se da por cerrado.', [
                'client_id'       => $client->id,
                'recordatorio_id' => $recordatorio_id,
            ]);

            return true;
        }

        if (! $respuesta->successful()) {
            Log::channel('daily')->warning('AsistenteRecordatorios: el cliente rechazó la marca del recordatorio.', [
                'client_id'       => $client->id,
                'recordatorio_id' => $recordatorio_id,
                'status'          => $status,
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
     * Clave de pausa de un cliente, por id. Pública para que las pruebas miren la misma.
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
     * Clave del reclamo de un recordatorio.
     *
     * @param Client $client
     * @param string $recordatorio_id
     *
     * @return string
     */
    public function clave_de_reclamo(Client $client, string $recordatorio_id): string
    {
        return self::CACHE_RECLAMO . ':' . (int) $client->id . ':' . $recordatorio_id;
    }

    /**
     * Arma la fila del resultado que imprime el comando.
     *
     * @param Client $client
     * @param string $estado
     * @param int    $recordatorios
     * @param int    $enviados
     * @param string $detalle
     *
     * @return array{client_id: int, nombre: string, estado: string, recordatorios: int, enviados: int, detalle: string}
     */
    private function resultado(Client $client, string $estado, int $recordatorios, int $enviados, string $detalle): array
    {
        return [
            'client_id'     => (int) $client->id,
            'nombre'        => (string) $client->name,
            'estado'        => $estado,
            'recordatorios' => $recordatorios,
            'enviados'      => $enviados,
            'detalle'       => $detalle,
        ];
    }
}

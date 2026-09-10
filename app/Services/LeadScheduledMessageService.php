<?php

namespace App\Services;

use App\Helpers\AppTime;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\LeadScheduledMessage;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Programar el envío de un mensaje de WhatsApp a un lead, y despacharlo cuando llega la hora.
 *
 * Acá viven TODOS los frenos: los del momento de programar (¿se le puede escribir a este lead?,
 * ¿esa fecha admite texto libre?) y los del momento de enviar (¿sigue siendo cierto todo eso, un
 * rato o dos días después?). El controlador solo traduce el resultado a JSON y el comando solo
 * llama a `despachar_vencidos()`: si un freno se escribiera afuera, existiría en una sola de las
 * dos puertas.
 *
 * 🔴 La ventana de 24 hs de Meta NO se recalcula acá. La resuelve
 * {@see WhatsappSessionWindowService}, que es el mismo servicio que usa el envío de texto libre y
 * mira los tres canales (leads, soporte e implementación) porque la ventana es por par de números
 * y no por canal. Reimplementar el criterio con `lead_messages` a mano —que es la tentación
 * obvia— daría "ventana cerrada" para un lead que escribió por soporte hace diez minutos.
 *
 * Los métodos que pueden frenar devuelven un array `['ok', 'status', 'message', 'ventana',
 * 'scheduled']` en vez de tirar una excepción: los frenos no son errores de programa, son el
 * estado del mundo (el lead ya es cliente, la ventana se cerró), y quien llama tiene que poder
 * contestarlos con el 422 exacto que la SPA sabe leer sin envolver todo en try/catch.
 */
class LeadScheduledMessageService
{
    /**
     * Tope de caracteres del texto.
     *
     * Es el límite del cuerpo de un mensaje de WhatsApp, el mismo que ya usa
     * `ClaudeLeadsOutboundController::MAX_CARACTERES_TEXTO`. Sin esto, el texto viaja igual a Meta
     * para que lo rechace con un error opaco — y encima acá el rechazo llegaría horas después de
     * que el operador se fue del panel, así que se corta al programar.
     */
    const MAX_CARACTERES_TEXTO = 4096;

    /**
     * Cuántos días para adelante se puede programar.
     *
     * No es un número mágico con vocación de configuración: es una guarda contra el dedazo (un año
     * en vez de un día) sobre algo que va a salir solo, sin nadie mirando. Y a más de un mes vista
     * casi nada de lo que justificaba el mensaje sigue siendo cierto.
     */
    const MAX_DIAS_A_FUTURO = 30;

    /**
     * Margen de segundos que se le perdona a una fecha "en el pasado".
     *
     * El reloj del navegador y el del servidor no son el mismo, y el viaje de la request tampoco es
     * gratis: sin este margen, programar "para dentro de un minuto" desde una máquina atrasada
     * unos segundos rebota con un 422 incomprensible.
     */
    const MARGEN_PASADO_SEGUNDOS = 60;

    /**
     * Resolución de la ventana de 24 hs de Meta.
     *
     * @var WhatsappSessionWindowService
     */
    private $ventana;

    /**
     * Envío saliente por Kapso/Meta.
     *
     * @var WhatsappSendService
     */
    private $sender;

    /**
     * @param WhatsappSessionWindowService|null $ventana Resolución de la ventana de Meta.
     * @param WhatsappSendService|null          $sender  Envío saliente.
     */
    public function __construct(
        WhatsappSessionWindowService $ventana = null,
        WhatsappSendService $sender = null
    ) {
        /* Se resuelven del contenedor y no con `new` a secas para que los tests puedan sustituir
           el sender con `$this->app->instance()`, como ya hacen los del resto de los envíos. */
        $this->ventana = $ventana !== null ? $ventana : app(WhatsappSessionWindowService::class);
        $this->sender  = $sender !== null ? $sender : app(WhatsappSendService::class);
    }

    /**
     * Programa un mensaje nuevo para un lead.
     *
     * 🔴 Los siete frenos corren TODOS antes de escribir la fila. Un programado que nace inválido
     * es peor que un 422: nadie lo mira hasta que sale (o hasta que no sale), y para entonces el
     * operador ya dio por hecho que el lead lo recibió.
     *
     * @param Lead     $lead     Lead destinatario, ya resuelto por el llamador.
     * @param array    $datos    scheduled_send_at, mode, content, template_name, template_language,
     *                           template_variables, cancel_if_lead_replies.
     * @param int|null $admin_id Admin que lo programa. Hereda al LeadMessage cuando salga.
     *
     * @return array{ok: bool, status: int, message: string|null, ventana: array, scheduled: LeadScheduledMessage|null}
     */
    public function programar(Lead $lead, array $datos, $admin_id = null): array
    {
        $validacion = $this->validar($lead, $datos);
        if (! $validacion['ok']) {
            return $validacion;
        }

        $limpio = $validacion['datos'];

        $programado                           = new LeadScheduledMessage();
        $programado->lead_id                  = (int) $lead->id;
        $programado->scheduled_send_at        = $limpio['scheduled_send_at'];
        $programado->mode                     = $limpio['mode'];
        $programado->content                  = $limpio['content'];
        $programado->template_name            = $limpio['template_name'];
        $programado->template_language        = $limpio['template_language'];
        $programado->template_variables       = $limpio['template_variables'];
        $programado->cancel_if_lead_replies   = $limpio['cancel_if_lead_replies'];
        $programado->baseline_lead_message_id = $this->ultimo_mensaje_del_lead($lead);
        $programado->status                   = LeadScheduledMessage::STATUS_PENDIENTE;
        $programado->created_by_admin_id      = $admin_id !== null ? (int) $admin_id : null;
        $programado->save();

        return $this->exito($programado, $validacion['ventana']);
    }

    /**
     * Cambia el texto y/o la fecha de un programado que todavía no salió.
     *
     * Pasa por los mismos frenos que `programar()` y por el mismo camino: editar es volver a
     * decidir si ese mensaje se puede mandar en esa fecha, y esa decisión es la misma o hay dos.
     *
     * 🔴 El `baseline_lead_message_id` SE RECALCULA. Editar es un acto deliberado del operador
     * sobre la conversación tal como está ahora: si el lead escribió entre programar y editar, el
     * operador lo tuvo delante al editar, así que ese mensaje ya no puede contar como "el lead
     * respondió después". Dejar el baseline viejo cancelaría el envío por una respuesta que el
     * operador ya leyó y decidió ignorar.
     *
     * @param LeadScheduledMessage $programado Programado en estado `pendiente`.
     * @param array                $datos      Mismos campos que `programar()`.
     *
     * @return array{ok: bool, status: int, message: string|null, ventana: array, scheduled: LeadScheduledMessage|null}
     */
    public function editar(LeadScheduledMessage $programado, array $datos): array
    {
        if ((string) $programado->status !== LeadScheduledMessage::STATUS_PENDIENTE) {
            return $this->freno(
                'Ese mensaje programado ya no está pendiente (' . (string) $programado->status
                    . '): no se puede editar. Programá uno nuevo.',
                422
            );
        }

        $lead = $programado->lead;
        if ($lead === null) {
            return $this->freno('El lead de ese mensaje programado ya no existe.', 404);
        }

        $validacion = $this->validar($lead, $datos);
        if (! $validacion['ok']) {
            return $validacion;
        }

        $limpio = $validacion['datos'];

        $programado->scheduled_send_at        = $limpio['scheduled_send_at'];
        $programado->mode                     = $limpio['mode'];
        $programado->content                  = $limpio['content'];
        $programado->template_name            = $limpio['template_name'];
        $programado->template_language        = $limpio['template_language'];
        $programado->template_variables       = $limpio['template_variables'];
        $programado->cancel_if_lead_replies   = $limpio['cancel_if_lead_replies'];
        $programado->baseline_lead_message_id = $this->ultimo_mensaje_del_lead($lead);
        $programado->save();

        return $this->exito($programado, $validacion['ventana']);
    }

    /**
     * Cancela un programado que todavía no salió.
     *
     * @param LeadScheduledMessage $programado Programado a cancelar.
     * @param string               $motivo     Uno de los CANCELED_* del modelo.
     *
     * @return array{ok: bool, status: int, message: string|null, ventana: array, scheduled: LeadScheduledMessage|null}
     */
    public function cancelar(LeadScheduledMessage $programado, string $motivo = LeadScheduledMessage::CANCELED_MANUAL): array
    {
        if ((string) $programado->status === LeadScheduledMessage::STATUS_ENVIADO) {
            return $this->freno(
                'Ese mensaje ya salió por WhatsApp: no se puede cancelar. Está en la conversación como '
                    . 'un mensaje enviado más.',
                422
            );
        }

        $programado->status          = LeadScheduledMessage::STATUS_CANCELADO;
        $programado->canceled_reason = $motivo;
        $programado->save();

        return $this->exito($programado, null);
    }

    /**
     * Despacha todos los programados cuya hora ya se cumplió.
     *
     * Es lo único que hace el comando `leads:send-scheduled-messages`, que corre cada minuto.
     *
     * @return array{despachados: int, enviados: int, cancelados: int, errores: int}
     */
    public function despachar_vencidos(): array
    {
        $resumen = [
            'despachados' => 0,
            'enviados'    => 0,
            'cancelados'  => 0,
            'errores'     => 0,
        ];

        /* orderBy('scheduled_send_at') y no por id: si una corrida se atrasó y hay varios vencidos
           del mismo lead, salen en el orden en que el operador quiso que salieran. */
        $vencidos = LeadScheduledMessage::query()
            ->vencidos()
            ->orderBy('scheduled_send_at')
            ->orderBy('id')
            ->get();

        foreach ($vencidos as $programado) {
            $resultado = $this->despachar_uno($programado);
            $resumen['despachados']++;

            if ($resultado === LeadScheduledMessage::STATUS_ENVIADO) {
                $resumen['enviados']++;
            } elseif ($resultado === LeadScheduledMessage::STATUS_CANCELADO) {
                $resumen['cancelados']++;
            } elseif ($resultado === LeadScheduledMessage::STATUS_ERROR) {
                $resumen['errores']++;
            }
        }

        return $resumen;
    }

    /**
     * Despacha UN programado: revalida los frenos del momento del envío y, si pasan todos, manda.
     *
     * 🔴 Va entero adentro de un lock por id, y no es paranoia. El comando corre cada minuto y no
     * tiene `withoutOverlapping()`: si una corrida se atrasa (Meta lento, muchos vencidos juntos),
     * la siguiente arranca con la anterior todavía en vuelo, lee el mismo `pendiente` y manda el
     * mismo WhatsApp por segunda vez. El daño no se deshace: son dos mensajes reales a la misma
     * persona. Mismo patrón que `ClaudeLeadsOutboundController`, y por el mismo motivo.
     *
     * Lock y no una transacción con `lockForUpdate()`: eso dejaría una transacción de MySQL abierta
     * durante la llamada HTTP a Meta, que entre timeout y reintentos puede tardar decenas de
     * segundos.
     *
     * @param LeadScheduledMessage $programado
     *
     * @return string|null Estado en el que quedó, o null si no se pudo tomar el lock (otra corrida
     *                     lo tiene) y no se hizo nada.
     */
    public function despachar_uno(LeadScheduledMessage $programado)
    {
        $lock = Cache::lock('lead-scheduled-message-' . (int) $programado->id, $this->segundos_de_lock());

        if (! $lock->get()) {
            /* Otra corrida lo está mandando ahora mismo. No se toca nada: es exactamente el caso
               que el lock existe para atrapar. */
            return null;
        }

        try {
            /* 🔴 Se relee de la base ADENTRO del lock. Entre el SELECT de `despachar_vencidos()` y
               este punto la corrida anterior pudo haberlo mandado ya: sin esta relectura el lock
               no sirve de nada, porque el objeto en memoria seguiría diciendo `pendiente`. */
            $fresco = LeadScheduledMessage::query()->find($programado->id);
            if ($fresco === null || (string) $fresco->status !== LeadScheduledMessage::STATUS_PENDIENTE) {
                return null;
            }

            return $this->enviar_ahora($fresco);
        } finally {
            $lock->release();
        }
    }

    /**
     * El envío propiamente dicho, ya con el lock tomado y la fila releída.
     *
     * @param LeadScheduledMessage $programado
     *
     * @return string Estado en el que quedó.
     */
    private function enviar_ahora(LeadScheduledMessage $programado): string
    {
        $lead = $programado->lead;

        if ($lead === null) {
            return $this->marcar_cancelado($programado, LeadScheduledMessage::CANCELED_MANUAL);
        }

        /* Freno 3, revalidado: el lead pasó a ser cliente entre programar y enviar. A un cliente no
           se le manda un mensaje comercial; si hay que escribirle, va por soporte. */
        if ((string) $lead->status === 'cerrado_ganado' || $lead->promoted_client_id !== null) {
            return $this->marcar_cancelado($programado, LeadScheduledMessage::CANCELED_LEAD_PROMOVIDO);
        }

        /* Freno 4, revalidado: alguien marcó a mano que a este lead ya no le llega nada. La marca
           la puso una persona mirando la conversación y un envío automático no está en posición de
           contradecirla — igual que en `claude/*`, no hay parámetro que la saltee. */
        if ($lead->no_recibe_mensajes_at !== null) {
            return $this->marcar_cancelado($programado, LeadScheduledMessage::CANCELED_LEAD_NO_RECIBE);
        }

        $phone = trim((string) ($lead->phone ?? ''));
        if ($phone === '') {
            return $this->marcar_cancelado($programado, LeadScheduledMessage::CANCELED_SIN_TELEFONO);
        }

        /* El check de Lucas, apagado por defecto (decisión del 10/9/2026). Prendido, si el lead
           escribió después de que se programó el mensaje, el mensaje se descarta.

           🔴 Se compara por `id` y NO por `created_at`. `created_at` es un timestamp sin fracción
           de segundo: un mensaje del lead que entra en el mismo segundo en que se programó da el
           resultado al revés y el programado sale igual (o se cancela de más). Está documentado en
           el informe 20260902-mensaje-libre-a-lead.md y ya costó una vez. */
        if ($programado->cancel_if_lead_replies) {
            if ($this->lead_escribio_despues($programado)) {
                return $this->marcar_cancelado($programado, LeadScheduledMessage::CANCELED_LEAD_RESPONDIO);
            }
        }

        /* 🔴 La ventana se revalida AHORA, no alcanza con la que se validó al programar. Entre las
           dos cosas puede haber pasado cualquier cosa: el cron caído, el servidor apagado, la
           corrida atrasada, o simplemente que el mensaje se programó para el filo de las 24 hs.
           Mandar texto libre con la ventana cerrada es un mensaje que Meta rechaza y que el lead
           nunca ve — o sea, una fila diciendo "enviado" sobre algo que no le llegó a nadie. */
        if ((string) $programado->mode === LeadScheduledMessage::MODE_TEXTO_LIBRE) {
            $estado_ventana = $this->ventana->window_state($phone);

            if (empty($estado_ventana['open'])) {
                return $this->marcar_error(
                    $programado,
                    'La ventana de 24 hs de Meta se cerró antes de que saliera el mensaje: un texto libre '
                        . 'no habría llegado. Reprogramalo con una plantilla aprobada.'
                );
            }
        }

        $contexto = 'Mensaje programado del panel - Lead #' . (int) $lead->id
            . (! empty($lead->contact_name) ? ' (' . $lead->contact_name . ')' : '');

        try {
            if ((string) $programado->mode === LeadScheduledMessage::MODE_PLANTILLA) {
                $variables = $programado->template_variables;

                $whatsapp_message_id = $this->sender->send_template(
                    $phone,
                    (string) $programado->template_name,
                    is_array($variables) ? $variables : [],
                    (string) ($programado->template_language !== null && $programado->template_language !== ''
                        ? $programado->template_language
                        : 'es_AR'),
                    $contexto
                );
            } else {
                $whatsapp_message_id = $this->sender->send_text($phone, (string) $programado->content, $contexto);
            }
        } catch (\Throwable $e) {
            Log::channel('daily')->error('LeadScheduledMessageService: excepción al enviar el mensaje programado.', [
                'lead_scheduled_message_id' => (int) $programado->id,
                'lead_id'                   => (int) $lead->id,
                'error'                     => $e->getMessage(),
            ]);

            (new LeadConversationErrorLogger())->log(
                (int) $lead->id,
                'No se pudo enviar el mensaje programado por WhatsApp',
                $e->getMessage()
            );

            return $this->marcar_error($programado, $e->getMessage());
        }

        /* 🔴 `send_text()`/`send_template()` no tiran excepción: devuelven null y dejan el motivo en
           `last_send_error`. O sea que el camino de fallo REAL —el que se ve en producción cuando
           Meta rechaza— es éste y no el catch de arriba. Un `null` tratado como éxito dejaría el
           programado en `enviado` sobre algo que nadie recibió, que es el peor estado posible: el
           operador no vuelve a mirar un mensaje que dice que salió.

           Se marca `error` y NO se crea LeadMessage: nada llegó al lead, así que una fila
           `enviado` en el hilo sería mentira, y además el agente de IA la leería como algo que ya
           se le dijo. El motivo sí queda visible en la conversación, por el logger de errores. */
        if ($whatsapp_message_id === null || trim((string) $whatsapp_message_id) === '') {
            $motivo = trim((string) ($this->sender->last_send_error ?? ''));
            if ($motivo === '') {
                $motivo = 'WhatsApp no confirmó el envío y no dejó motivo.';
            }

            (new LeadConversationErrorLogger())->log(
                (int) $lead->id,
                'No se pudo enviar el mensaje programado por WhatsApp',
                $motivo
            );

            return $this->marcar_error($programado, $motivo);
        }

        /* 🔴 GARANTÍA: el mensaje ya SALIÓ. De acá para abajo la fila del LeadMessage se escribe sí
           o sí — un envío que llegó al lead y no quedó registrado deja al agente de IA sin saber
           que se lo dijimos, y al operador viendo un hilo incompleto. Por eso el LeadMessage se
           crea ANTES de tocar el programado: si algo revienta en el medio, lo que sobrevive es el
           registro de lo que efectivamente pasó. */
        try {
            $mensaje = LeadMessage::create([
                'lead_id'               => (int) $lead->id,
                'sender'                => 'setter',
                'content'               => (string) $programado->content,
                'status'                => 'enviado',
                'whatsapp_message_id'   => $whatsapp_message_id,
                'sent_at'               => AppTime::now(),
                'is_followup'           => false,
                'requiere_verificacion' => false,
                /* El admin que lo programó es el autor del mensaje: la burbuja del hilo tiene que
                   decir quién lo mandó, igual que en un envío directo. */
                'sent_by_admin_id'      => $programado->created_by_admin_id,
            ]);
        } catch (\Throwable $e) {
            /* Estado más peligroso posible: salió y no quedó fila. No se puede arreglar solo, pero
               sí se puede dejar el wamid escrito para poder reconstruirlo a mano. */
            Log::channel('daily')->critical(
                'LeadScheduledMessageService: el mensaje SALIÓ por WhatsApp y no se pudo registrar en la conversación.',
                [
                    'lead_scheduled_message_id' => (int) $programado->id,
                    'lead_id'                   => (int) $lead->id,
                    'whatsapp_message_id'       => $whatsapp_message_id,
                    'error'                     => $e->getMessage(),
                ]
            );

            throw $e;
        }

        $programado->status               = LeadScheduledMessage::STATUS_ENVIADO;
        $programado->sent_lead_message_id = (int) $mensaje->id;
        $programado->error_text           = null;
        $programado->save();

        /* Mismo aviso que el resto de los envíos: las conversaciones abiertas en otros navegadores
           tienen que ver aparecer el mensaje sin recargar. */
        LeadBroadcastService::emit_conversation_updated((int) $lead->id, (int) $mensaje->id);

        return LeadScheduledMessage::STATUS_ENVIADO;
    }

    /**
     * ¿Escribió el lead después de que se programó (o se editó) este mensaje?
     *
     * Sin baseline —el lead nunca había escrito— cualquier mensaje suyo cuenta.
     *
     * @param LeadScheduledMessage $programado
     *
     * @return bool
     */
    private function lead_escribio_despues(LeadScheduledMessage $programado): bool
    {
        $query = LeadMessage::query()
            ->where('lead_id', (int) $programado->lead_id)
            ->where('sender', 'lead');

        if ($programado->baseline_lead_message_id !== null) {
            $query->where('id', '>', (int) $programado->baseline_lead_message_id);
        }

        return $query->exists();
    }

    /**
     * Id del último mensaje del lead, que es el baseline contra el que se mide si respondió.
     *
     * @param Lead $lead
     *
     * @return int|null
     */
    private function ultimo_mensaje_del_lead(Lead $lead)
    {
        $ultimo = LeadMessage::query()
            ->where('lead_id', (int) $lead->id)
            ->where('sender', 'lead')
            ->orderByDesc('id')
            ->first();

        return $ultimo !== null ? (int) $ultimo->id : null;
    }

    /**
     * Los siete frenos, en orden, sobre los datos crudos de la request.
     *
     * @param Lead  $lead
     * @param array $datos
     *
     * @return array Con `ok` en false trae el freno que cortó; con `ok` en true trae `datos` ya
     *               normalizados y `ventana` (el estado real de la ventana de Meta).
     */
    private function validar(Lead $lead, array $datos): array
    {
        /* Freno 1: el texto. Va primero porque es el único que depende solo de lo que se escribió,
           y porque un texto vacío o gigante no mejora por más que el lead esté disponible. */
        $content = trim((string) (isset($datos['content']) ? $datos['content'] : ''));
        if ($content === '') {
            return $this->freno('El mensaje no puede estar vacío.', 422);
        }

        /* mb_strlen y no strlen: el tope de WhatsApp es de caracteres, no de bytes, y con acentos y
           emojis strlen corta textos legítimos. */
        if (mb_strlen($content) > self::MAX_CARACTERES_TEXTO) {
            return $this->freno(
                'El mensaje no puede pasar de ' . self::MAX_CARACTERES_TEXTO
                    . ' caracteres, que es el tope de un mensaje de WhatsApp.',
                422
            );
        }

        /* Freno 2: sin teléfono no hay a dónde mandar. (El lead inexistente lo corta el controlador
           con un 404 antes de llegar acá: no se puede validar nada sobre un lead que no existe.) */
        $phone = trim((string) ($lead->phone ?? ''));
        if ($phone === '') {
            return $this->freno(
                'El lead #' . (int) $lead->id . ' no tiene teléfono cargado: no hay a dónde mandarle nada.',
                422
            );
        }

        /* Freno 3: ya es cliente. Mismo criterio, palabra por palabra, que el envío de texto libre
           de `claude/*`: a un cliente se le responde desde soporte, que tiene su propio hilo. */
        if ((string) $lead->status === 'cerrado_ganado' || $lead->promoted_client_id !== null) {
            return $this->freno(
                'El lead #' . (int) $lead->id . ' ya es cliente: no se le programa un mensaje comercial. '
                    . 'Si hay que escribirle, va por el hilo de soporte.',
                422
            );
        }

        /* Freno 4: la marca de "ya no recibe mensajes". La pone una PERSONA mirando la
           conversación, porque el código de error de Meta que distinguiría un número muerto de un
           fallo reintentable nunca se capturó (ver la migración 2026_09_02_150000). No hay
           parámetro que lo saltee: para eso está el botón del panel, que la desmarca. */
        if ($lead->no_recibe_mensajes_at !== null) {
            $motivo = trim((string) ($lead->no_recibe_mensajes_motivo ?? ''));

            return $this->freno(
                'El lead #' . (int) $lead->id . ' está marcado como que ya no recibe mensajes'
                    . ($motivo !== '' ? ' (' . $motivo . ')' : '') . ': no se le programa nada. La marca la '
                    . 'puso una persona desde el panel y sólo se saca desde ahí.',
                422
            );
        }

        /* Freno 5: la fecha. */
        $cuando = $this->parsear_fecha(isset($datos['scheduled_send_at']) ? $datos['scheduled_send_at'] : null);
        if ($cuando === null) {
            return $this->freno(
                'La fecha y hora de envío es obligatoria y tiene que venir en formato ISO 8601 con zona horaria.',
                422
            );
        }

        $ahora = AppTime::now();

        if ($cuando->lt($ahora->copy()->subSeconds(self::MARGEN_PASADO_SEGUNDOS))) {
            return $this->freno('No se puede programar un mensaje para una fecha que ya pasó.', 422);
        }

        if ($cuando->gt($ahora->copy()->addDays(self::MAX_DIAS_A_FUTURO))) {
            return $this->freno(
                'No se puede programar un mensaje a más de ' . self::MAX_DIAS_A_FUTURO . ' días vista.',
                422
            );
        }

        $mode = trim((string) (isset($datos['mode']) ? $datos['mode'] : ''));
        if (! in_array($mode, LeadScheduledMessage::MODES, true)) {
            return $this->freno(
                'El modo tiene que ser "' . LeadScheduledMessage::MODE_TEXTO_LIBRE . '" o "'
                    . LeadScheduledMessage::MODE_PLANTILLA . '".',
                422
            );
        }

        /* 🔴 El estado real de la ventana, resuelto por el servicio que ya la resuelve para todo el
           resto del sistema. La SPA calcula lo mismo del lado del cliente para decidir qué
           formulario mostrar, pero con otra fuente (solo `lead_messages`), así que puede ofrecer
           plantilla donde acá habría entrado texto libre — nunca al revés. La autoridad es ésta. */
        $estado_ventana = $this->ventana->window_state($phone);

        /* Freno 6: texto libre fuera de la ventana. El techo es `expires_at`; con la ventana ya
           cerrada (`expires_at` en null) CUALQUIER fecha futura exige plantilla, porque nada de lo
           que hagamos nosotros la vuelve a abrir: la abre el lead escribiendo. */
        if ($mode === LeadScheduledMessage::MODE_TEXTO_LIBRE) {
            $expira = $this->expira_en($estado_ventana);

            if ($expira === null) {
                return $this->freno(
                    'La ventana de 24 hs de Meta está cerrada para este lead: un mensaje de texto libre no '
                        . 'saldría. Programalo con una plantilla aprobada.',
                    422,
                    $estado_ventana
                );
            }

            if (! $cuando->lt($expira)) {
                return $this->freno(
                    'Para esa fecha la ventana de 24 hs de Meta ya va a estar cerrada (vence el '
                        . $expira->format('d/m/Y H:i') . '): un mensaje de texto libre no saldría. '
                        . 'Programalo con una plantilla aprobada o elegí una hora anterior.',
                    422,
                    $estado_ventana
                );
            }
        }

        $template_name      = trim((string) (isset($datos['template_name']) ? $datos['template_name'] : ''));
        $template_language  = trim((string) (isset($datos['template_language']) ? $datos['template_language'] : ''));
        $template_variables = isset($datos['template_variables']) && is_array($datos['template_variables'])
            ? array_values($datos['template_variables'])
            : null;

        /* Freno 7: una plantilla sin nombre no se puede mandar, y sin el body ya renderizado el
           hilo quedaría mostrando el texto con los `{{1}}` sin reemplazar. El content vacío ya lo
           cortó el freno 1; acá se exige lo que la plantilla agrega. */
        if ($mode === LeadScheduledMessage::MODE_PLANTILLA) {
            if ($template_name === '') {
                return $this->freno('Elegí la plantilla aprobada que se va a enviar.', 422, $estado_ventana);
            }
        }

        return [
            'ok'      => true,
            'status'  => 200,
            'message' => null,
            'ventana' => $estado_ventana,
            'datos'   => [
                'scheduled_send_at'      => $cuando,
                'mode'                   => $mode,
                'content'                => $content,
                'template_name'          => $mode === LeadScheduledMessage::MODE_PLANTILLA ? $template_name : null,
                'template_language'      => $mode === LeadScheduledMessage::MODE_PLANTILLA
                    ? ($template_language !== '' ? $template_language : 'es_AR')
                    : null,
                'template_variables'     => $mode === LeadScheduledMessage::MODE_PLANTILLA ? $template_variables : null,
                'cancel_if_lead_replies' => ! empty($datos['cancel_if_lead_replies']),
            ],
        ];
    }

    /**
     * Momento en que vence la ventana, según el estado que devolvió el servicio.
     *
     * @param array $estado_ventana Salida de `WhatsappSessionWindowService::window_state()`.
     *
     * @return Carbon|null Null si la ventana está cerrada.
     */
    private function expira_en(array $estado_ventana)
    {
        if (empty($estado_ventana['open']) || empty($estado_ventana['expires_at'])) {
            return null;
        }

        try {
            return Carbon::parse($estado_ventana['expires_at'])->setTimezone(config('app.timezone'));
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Parsea la fecha que mandó la SPA (ISO 8601 con offset) al timezone de la app.
     *
     * Se pasa al timezone de la app y no se deja en el offset original para que la comparación
     * contra `AppTime::now()` y el guardado en MySQL hablen del mismo huso: sin eso, un navegador
     * en otra zona guarda una hora corrida y el mensaje sale cuando no es.
     *
     * @param mixed $valor
     *
     * @return Carbon|null
     */
    private function parsear_fecha($valor)
    {
        $texto = trim((string) $valor);
        if ($texto === '') {
            return null;
        }

        try {
            return Carbon::parse($texto)->setTimezone(config('app.timezone'));
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Deja el programado cancelado con su motivo.
     *
     * @param LeadScheduledMessage $programado
     * @param string               $motivo
     *
     * @return string
     */
    private function marcar_cancelado(LeadScheduledMessage $programado, string $motivo): string
    {
        $programado->status          = LeadScheduledMessage::STATUS_CANCELADO;
        $programado->canceled_reason = $motivo;
        $programado->save();

        return LeadScheduledMessage::STATUS_CANCELADO;
    }

    /**
     * Deja el programado en error con el motivo escrito y visible en la conversación.
     *
     * @param LeadScheduledMessage $programado
     * @param string               $motivo
     *
     * @return string
     */
    private function marcar_error(LeadScheduledMessage $programado, string $motivo): string
    {
        $programado->status     = LeadScheduledMessage::STATUS_ERROR;
        $programado->error_text = $motivo;
        $programado->save();

        return LeadScheduledMessage::STATUS_ERROR;
    }

    /**
     * Resultado de un freno.
     *
     * @param string     $mensaje
     * @param int        $status
     * @param array|null $estado_ventana
     *
     * @return array
     */
    private function freno(string $mensaje, int $status, array $estado_ventana = null): array
    {
        return [
            'ok'        => false,
            'status'    => $status,
            'message'   => $mensaje,
            'ventana'   => $estado_ventana,
            'scheduled' => null,
        ];
    }

    /**
     * Resultado exitoso.
     *
     * @param LeadScheduledMessage $programado
     * @param array|null           $estado_ventana
     *
     * @return array
     */
    private function exito(LeadScheduledMessage $programado, array $estado_ventana = null): array
    {
        return [
            'ok'        => true,
            'status'    => 200,
            'message'   => null,
            'ventana'   => $estado_ventana,
            'scheduled' => $programado,
        ];
    }

    /**
     * Cuántos segundos dura el lock del despacho, derivado del peor caso real del envío.
     *
     * Mismo cálculo que `ClaudeLeadsOutboundController::segundos_de_lock()`: el envío usa
     * `KapsoHttpClient` con `services.client_api.timeout` y le encadena
     * `retry(services.client_api.retries, 500)`, así que el peor caso es
     * `timeout * intentos + las esperas entre reintentos`, más un margen. Se calcula y no se
     * escribe fijo para que subir el timeout por entorno no deje el lock corto sin que nada lo
     * denuncie — un lock que vence en vuelo no protege nada.
     *
     * @return int
     */
    private function segundos_de_lock(): int
    {
        $timeout  = (int) config('services.client_api.timeout', 15);
        $intentos = (int) config('services.client_api.retries', 2);

        if ($timeout < 1) {
            $timeout = 15;
        }

        if ($intentos < 1) {
            $intentos = 1;
        }

        /* Las esperas entre reintentos son de 500 ms cada una: se redondean a 1 s por intento. */
        return ($timeout * $intentos) + $intentos + 15;
    }
}

<?php

namespace App\Services;

use App\Helpers\AppTime;
use App\Models\Lead;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Decide por qué canal sale un recordatorio de demo y lo envía (misión
 * recordatorios-demo-con-conversacion, 16/10/2026, pedido de Lucas): *"que los recordatorios se
 * envíen teniendo en cuenta la conversación que veníamos teniendo con el lead; en caso de que la
 * ventana de 24 hs esté cerrada, ahí sí enviar la plantilla ya armada"*.
 *
 * La regla, que es la misma para los dos comandos (`SendMorningDemoReminder` y `SendDemoReminders`):
 *
 *  - **Ventana de 24 hs de Meta abierta:** texto libre. Lo redacta la IA retomando el chat
 *    (`LeadReminderComposer`). Si la IA está apagada, falla, o devuelve algo que no pasa la
 *    validación, sale lo de siempre: el texto fijo si el comando tiene uno o la plantilla si no.
 *  - **Ventana cerrada:** la plantilla ya armada. Meta no deja texto libre fuera de la ventana.
 *
 * 🔴 EL RESPALDO ES SIEMPRE "LO QUE SALÍA ANTES". Cualquier falla de la IA (sin clave, 402 por saldo
 * en DeepSeek, timeout, texto inválido) degrada al mensaje de antes de esta misión, así que el
 * recordatorio nunca se pierde por culpa de la IA. Y el motivo del respaldo queda en el log: el modo
 * de falla de esta familia es "cae al respaldo en silencio", y tiene que poder verse en producción.
 *
 * 🔴 LA VENTANA DE LA BASE NO ES LA DE META. `WhatsappSessionWindowService` la calcula con los
 * mensajes del lead que figuran en nuestra base, y puede decir "abierta" cuando Meta ya la cerró.
 * Por eso, si Meta rechaza el texto libre de forma DEFINITIVA (un 4xx), la cadena sigue con el
 * respaldo siguiente en vez de insistir: IA → texto fijo → plantilla. Solo un error TRANSITORIO
 * (409, 429, 5xx) se deja para reintentar en el próximo tick, y únicamente donde el comando lo pide
 * (`reintentar_si_es_transitorio`), porque reintentar a ciegas repite la llamada a la IA en cada
 * tick.
 *
 * 🔴 CONTROLES HUMANOS POR LEAD QUE SÍ SE RESPETAN. `claude_auto_reply = false` ("Claude no genera
 * ni envía nada para este lead") y `requiere_intervencion_humana` (una persona está llevando el
 * caso) apagan la IA para ese lead: ninguno está prendido por defecto, así que respetarlos no
 * cuesta nada, y el escenario que evitan es concreto (un lead que se quejó y recibe un recordatorio
 * alegre que "retoma" esa charla). Ese lead recibe lo de siempre.
 *
 * 🔴 EL ESCUDO DE VERIFICACIÓN DEL LEAD (`requiere_verificacion_mensajes`) NO GOBIERNA ESTE SERVICIO
 * —decisión que le queda a Lucas—. El modelo `Lead` lo prende solo cuando el lead entra al tramo de
 * la demo (`demo_agendada` está en `ESTADOS_VENTANA_VERIFICACION_MENSAJES`) mientras el interruptor
 * global de Cuenta esté prendido, que es su default. Los recordatorios operan justo sobre leads en
 * `demo_agendada`, así que condicionar la IA a ese escudo la dejaría apagada para casi todos. Es
 * cierto que los recordatorios de texto fijo nunca pasaron por la verificación y estos son de texto
 * libre; lo acota el validador de `LeadReminderComposer` (sin links, números, plazos ni plata) y lo
 * frena el interruptor `LeadDemoSettings::get_recordatorio_ia_activo()`. Para que un lead
 * supervisado no reciba texto de la IA sin revisar, es agregar el chequeo en `redactar_con_ia()`.
 *
 * 🔴 PRESUPUESTO DE IA POR CORRIDA. Los dos comandos corren en el scheduler sin `runInBackground()`, y
 * cada lead suma una llamada de hasta ~25 s. Pasado `PRESUPUESTO_DE_IA_SEGUNDOS` de espera acumulada
 * en esta corrida, el resto de los leads sale por el respaldo: el peor tick (las 09:00, con varios
 * leads) no puede demorar a los comandos que vienen después.
 */
class LeadReminderService
{
    /** Salió un texto libre redactado por la IA. */
    const VIA_IA = 'ia';

    /** Salió el texto libre fijo del comando (el de antes de la misión). */
    const VIA_TEXTO_FIJO = 'texto_fijo';

    /** Salió la plantilla de Meta. */
    const VIA_PLANTILLA = 'plantilla';

    /**
     * Segundos de espera a la IA, acumulados en una corrida del comando, a partir de los cuales el
     * resto de los leads ya no la consulta.
     */
    const PRESUPUESTO_DE_IA_SEGUNDOS = 60;

    /**
     * Servicio de envío saliente vía Kapso/Meta. Lo pasa el comando para que sea el mismo que ya
     * inyectan los tests de los comandos.
     *
     * @var WhatsappSendService
     */
    private $whatsapp_send_service;

    /**
     * Redactor con IA. Se crea recién cuando hace falta.
     *
     * @var LeadReminderComposer|null
     */
    private $composer;

    /**
     * Resolutor de la ventana de 24 hs de Meta.
     *
     * @var WhatsappSessionWindowService
     */
    private $ventana;

    /**
     * Segundos que ya se esperaron a la IA en esta corrida.
     *
     * @var float
     */
    private $segundos_de_ia = 0.0;

    /**
     * @param WhatsappSendService               $whatsapp_send_service Servicio de envío.
     * @param LeadReminderComposer|null         $composer              Inyección opcional (tests).
     * @param WhatsappSessionWindowService|null $ventana               Inyección opcional (tests).
     */
    public function __construct(
        WhatsappSendService $whatsapp_send_service,
        ?LeadReminderComposer $composer = null,
        ?WhatsappSessionWindowService $ventana = null
    ) {
        $this->whatsapp_send_service = $whatsapp_send_service;
        $this->composer              = $composer;
        $this->ventana               = $ventana ?? new WhatsappSessionWindowService();
    }

    /**
     * Envía el recordatorio del lead por el canal que corresponde.
     *
     * @param Lead   $lead     Lead al que se le recuerda la demo.
     * @param string $tipo     LeadReminderComposer::TIPO_MANANA | TIPO_PRE_DEMO.
     * @param array  $respaldo Lo que salía antes de la misión:
     *                         `texto` (?string): texto libre fijo, o null si el comando solo usaba plantilla;
     *                         `reintentar_si_es_transitorio` (bool, opcional): si un error transitorio del
     *                         texto libre corta la cadena (el comando lo reintenta en el próximo tick) en
     *                         vez de seguir con el respaldo siguiente;
     *                         `plantilla` (array): `nombre`, `variables` (array), `idioma` (string),
     *                         `contenido` (string: el texto ya renderizado de la plantilla, para el hilo) y
     *                         `solo_antes_de` (?Carbon: pasado ese momento la plantilla ya no sale, porque su
     *                         texto —"en unos minutos ya tenés disponible"— dejó de ser cierto).
     * @param string $contexto Etiqueta para los logs de envío ("Recordatorio demo - Lead #12 (...)").
     *
     * @return array{enviado: bool, via: string|null, contenido: string, whatsapp_message_id: string|null,
     *         motivo: string|null, ventana_abierta: bool, proveedor: string|null, modelo: string|null,
     *         reintentable: bool}
     *         `contenido` es lo que REALMENTE se mandó (o se intentó mandar): lo que el comando tiene
     *         que guardar en el hilo. `motivo` explica por qué NO fue la IA (null si fue la IA).
     *         `via` es null si no se intentó ningún envío (lead sin teléfono, o plantilla vencida).
     *         `reintentable` es true solo si no salió nada por un error transitorio y el comando pidió
     *         reintentar.
     */
    public function enviar(Lead $lead, string $tipo, array $respaldo, string $contexto): array
    {
        $plantilla  = $respaldo['plantilla'];
        $reintentar = ! empty($respaldo['reintentar_si_es_transitorio']);
        $resultado  = [
            'enviado'             => false,
            'via'                 => null,
            'contenido'           => (string) $plantilla['contenido'],
            'whatsapp_message_id' => null,
            'motivo'              => null,
            'ventana_abierta'     => false,
            'proveedor'           => null,
            'modelo'              => null,
            'reintentable'        => false,
        ];

        $phone = trim((string) $lead->phone);
        if ($phone === '') {
            $resultado['motivo'] = 'sin_telefono';

            return $resultado;
        }

        $ventana_abierta              = $this->ventana->is_open($phone);
        $resultado['ventana_abierta'] = $ventana_abierta;

        if ($ventana_abierta) {
            $ia = $this->redactar_con_ia($lead, $tipo);

            if ($ia['ok']) {
                /* El cuarto parámetro calla el aviso a los admins: si Meta rechaza este texto se
                 * prueba el respaldo de abajo, y el aviso lo da, si hace falta, ese último envío. */
                $id = $this->whatsapp_send_service->send_text($phone, $ia['texto'], $contexto . ' [IA]', true);

                if ($id !== null) {
                    $resultado['enviado']             = true;
                    $resultado['via']                 = self::VIA_IA;
                    $resultado['contenido']           = $ia['texto'];
                    $resultado['whatsapp_message_id'] = $id;
                    $resultado['proveedor']           = $ia['proveedor'];
                    $resultado['modelo']              = $ia['modelo'];

                    return $this->registrar($lead, $tipo, $resultado);
                }

                $resultado['motivo'] = 'envio_ia_rechazado';

                if ($reintentar && $this->fallo_transitorio()) {
                    $resultado['via']          = self::VIA_IA;
                    $resultado['contenido']    = $ia['texto'];
                    $resultado['reintentable'] = true;

                    return $this->registrar($lead, $tipo, $resultado);
                }
            } else {
                $resultado['motivo'] = $ia['motivo'];
            }

            /* Respaldo 1: el texto libre fijo del comando. */
            if ($respaldo['texto'] !== null) {
                $id = $this->whatsapp_send_service->send_text($phone, (string) $respaldo['texto'], $contexto, true);

                if ($id !== null) {
                    $resultado['enviado']             = true;
                    $resultado['via']                 = self::VIA_TEXTO_FIJO;
                    $resultado['contenido']           = (string) $respaldo['texto'];
                    $resultado['whatsapp_message_id'] = $id;

                    return $this->registrar($lead, $tipo, $resultado);
                }

                if ($reintentar && $this->fallo_transitorio()) {
                    $resultado['via']          = self::VIA_TEXTO_FIJO;
                    $resultado['contenido']    = (string) $respaldo['texto'];
                    $resultado['reintentable'] = true;

                    return $this->registrar($lead, $tipo, $resultado);
                }

                /* Rechazo definitivo de Meta: la ventana de la base estaba mal. Sigue la plantilla. */
                $resultado['motivo'] = 'envio_texto_rechazado';
            }
        } else {
            $resultado['motivo'] = 'ventana_cerrada';
        }

        /* La plantilla dice "en unos minutos ya tenés disponible el acceso": pasado el inicio de la
         * demo deja de ser cierto, y es mejor no mandar nada que mandar eso. */
        $limite = isset($plantilla['solo_antes_de']) ? $plantilla['solo_antes_de'] : null;
        if ($limite instanceof Carbon && AppTime::now()->gte($limite)) {
            $resultado['motivo'] = 'plantilla_vencida';

            return $this->registrar($lead, $tipo, $resultado);
        }

        /* Respaldo 2 (o único camino con la ventana cerrada): la plantilla ya armada. */
        $id = $this->whatsapp_send_service->send_template(
            $phone,
            (string) $plantilla['nombre'],
            $plantilla['variables'],
            isset($plantilla['idioma']) ? (string) $plantilla['idioma'] : 'es_AR',
            $contexto
        );

        $resultado['enviado']             = $id !== null;
        $resultado['via']                 = self::VIA_PLANTILLA;
        $resultado['whatsapp_message_id'] = $id;

        return $this->registrar($lead, $tipo, $resultado);
    }

    /**
     * ¿El último envío de texto falló por algo que tiene sentido reintentar más tarde?
     *
     * Sí si `WhatsappSendService` lo declara transitorio (409 de Kapso con otro mensaje en vuelo, 429 y
     * 5xx) o si NO hubo respuesta (sin código HTTP: red caída, timeout). Esto último reproduce lo que
     * hacía `SendDemoReminders` antes de esta misión —un texto que no salía se reintentaba en el
     * próximo tick— y evita perder el recordatorio por una caída de cinco minutos. Lo que NO es
     * transitorio es un rechazo de Meta con código (un 400: su ventana estaba cerrada aunque la
     * nuestra figure abierta): ahí insistir con texto libre no sirve y se pasa a la plantilla.
     *
     * @return bool
     */
    private function fallo_transitorio(): bool
    {
        return $this->whatsapp_send_service->last_send_was_transient()
            || $this->whatsapp_send_service->last_send_status_code === null;
    }

    /**
     * Pide el texto a la IA, o devuelve por qué no se la usa.
     *
     * @param Lead   $lead
     * @param string $tipo
     *
     * @return array{ok: bool, texto: string|null, motivo: string|null, proveedor: string|null, modelo: string|null}
     */
    private function redactar_con_ia(Lead $lead, string $tipo): array
    {
        if (! LeadDemoSettings::get_recordatorio_ia_activo()) {
            return $this->sin_ia('ia_apagada');
        }

        /* Controles humanos por lead: ver el docblock de la clase. `=== false` y no `! valor`: un
         * lead sin el campo cargado (null) no es un lead con la IA apagada. */
        if ($lead->claude_auto_reply === false) {
            return $this->sin_ia('claude_apagado_para_el_lead');
        }
        if ((bool) $lead->requiere_intervencion_humana) {
            return $this->sin_ia('requiere_intervencion_humana');
        }

        if ($this->segundos_de_ia >= self::PRESUPUESTO_DE_IA_SEGUNDOS) {
            return $this->sin_ia('presupuesto_de_ia_agotado');
        }

        if ($this->composer === null) {
            $this->composer = new LeadReminderComposer();
        }

        $inicio    = microtime(true);
        $resultado = $this->composer->compose($lead, $tipo);
        $this->segundos_de_ia += microtime(true) - $inicio;

        return $resultado;
    }

    /**
     * @param string $motivo
     *
     * @return array{ok: bool, texto: string|null, motivo: string|null, proveedor: string|null, modelo: string|null}
     */
    private function sin_ia(string $motivo): array
    {
        return ['ok' => false, 'texto' => null, 'motivo' => $motivo, 'proveedor' => null, 'modelo' => null];
    }

    /**
     * Deja el rastro del envío en el log (con la vía y el motivo del respaldo) y devuelve el resultado.
     *
     * @param Lead                 $lead
     * @param string               $tipo
     * @param array<string, mixed> $resultado
     *
     * @return array<string, mixed>
     */
    private function registrar(Lead $lead, string $tipo, array $resultado): array
    {
        $datos = [
            'lead_id'         => $lead->id,
            'tipo'            => $tipo,
            'via'             => $resultado['via'],
            'enviado'         => $resultado['enviado'],
            'ventana_abierta' => $resultado['ventana_abierta'],
            'motivo_respaldo' => $resultado['motivo'],
            'reintentable'    => $resultado['reintentable'],
            'proveedor'       => $resultado['proveedor'],
            'modelo'          => $resultado['modelo'],
        ];

        if ($resultado['enviado']) {
            Log::info('LeadReminderService: recordatorio enviado.', $datos);
        } elseif ($resultado['motivo'] === 'plantilla_vencida' && ! $resultado['ventana_abierta']) {
            /* Se repite en cada tick hasta el fin de la demo (unas catorce veces por lead) y no es un
             * problema: la ventana está cerrada y la plantilla ya no es válida. Solo debug. */
            Log::debug('LeadReminderService: sin nada para mandar (ventana cerrada y plantilla vencida).', $datos);
        } else {
            Log::warning('LeadReminderService: el recordatorio no salió.', $datos);
        }

        return $resultado;
    }
}

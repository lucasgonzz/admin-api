<?php

namespace App\Services;

use App\Models\Lead;
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
 *    validación, sale lo de siempre: el texto fijo si el comando tiene uno (la dinámica nueva) o la
 *    plantilla si no.
 *  - **Ventana cerrada:** la plantilla ya armada. Meta no deja texto libre fuera de la ventana.
 *
 * 🔴 EL RESPALDO ES SIEMPRE "LO QUE SALÍA ANTES". Cualquier falla de la IA (sin clave, 402 por saldo
 * en DeepSeek, timeout, texto inválido) degrada al mensaje de antes de esta misión, así que el
 * recordatorio nunca se pierde por culpa de la IA. Y el motivo del respaldo queda en el log: el modo
 * de falla de esta familia es "cae al respaldo en silencio", y tiene que poder verse en producción.
 *
 * 🔴 EL ESCUDO DE VERIFICACIÓN DEL LEAD (`requiere_verificacion_mensajes`) NO GOBIERNA ESTE SERVICIO,
 * y no es un descuido. El modelo `Lead` lo prende solo cuando el lead entra al tramo de la demo
 * (`demo_agendada` está en `ESTADOS_VENTANA_VERIFICACION_MENSAJES`) mientras el interruptor global
 * de Cuenta esté prendido, que es su default. Los recordatorios operan justo sobre leads en
 * `demo_agendada`, así que condicionar la IA a ese escudo la dejaría apagada para casi todos y la
 * función pedida por Lucas no haría nada. Además los recordatorios NUNCA pasaron por la
 * verificación (salen a la hora; ni siquiera la plantilla espera aprobación), y el texto de la IA
 * queda acotado por el validador de `LeadReminderComposer` (sin links, horas ni días inventados).
 * El freno es `LeadDemoSettings::get_recordatorio_ia_activo()`. Si Lucas decide que un lead
 * supervisado no debe recibir texto de la IA sin revisar, es agregar el chequeo acá.
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
     *                         `plantilla` (array): `nombre`, `variables` (array), `idioma` (string),
     *                         `contenido` (string: el texto ya renderizado de la plantilla, para el hilo).
     * @param string $contexto Etiqueta para los logs de envío ("Recordatorio demo - Lead #12 (...)").
     *
     * @return array{enviado: bool, via: string|null, contenido: string, whatsapp_message_id: string|null,
     *         motivo: string|null, ventana_abierta: bool, proveedor: string|null, modelo: string|null}
     *         `contenido` es lo que REALMENTE se mandó (o se intentó mandar): lo que el comando tiene
     *         que guardar en el hilo. `motivo` explica por qué NO fue la IA (null si fue la IA).
     *         `via` es null solo si el lead no tiene teléfono.
     */
    public function enviar(Lead $lead, string $tipo, array $respaldo, string $contexto): array
    {
        $plantilla = $respaldo['plantilla'];
        $resultado = [
            'enviado'             => false,
            'via'                 => null,
            'contenido'           => (string) $plantilla['contenido'],
            'whatsapp_message_id' => null,
            'motivo'              => null,
            'ventana_abierta'     => false,
            'proveedor'           => null,
            'modelo'              => null,
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
            } else {
                $resultado['motivo'] = $ia['motivo'];
            }

            /* Respaldo 1: el texto libre fijo del comando (la dinámica nueva). */
            if ($respaldo['texto'] !== null) {
                $id = $this->whatsapp_send_service->send_text($phone, (string) $respaldo['texto'], $contexto, true);

                $resultado['enviado']             = $id !== null;
                $resultado['via']                 = self::VIA_TEXTO_FIJO;
                $resultado['contenido']           = (string) $respaldo['texto'];
                $resultado['whatsapp_message_id'] = $id;

                return $this->registrar($lead, $tipo, $resultado);
            }
        } else {
            $resultado['motivo'] = 'ventana_cerrada';
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

        if ($this->composer === null) {
            $this->composer = new LeadReminderComposer();
        }

        return $this->composer->compose($lead, $tipo);
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
            'proveedor'       => $resultado['proveedor'],
            'modelo'          => $resultado['modelo'],
        ];

        if ($resultado['enviado']) {
            Log::info('LeadReminderService: recordatorio enviado.', $datos);
        } else {
            Log::warning('LeadReminderService: el recordatorio no salió.', $datos);
        }

        return $resultado;
    }
}

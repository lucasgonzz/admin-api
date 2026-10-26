<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\Client;
use App\Models\Implementation;
use App\Models\ImplementationStage;
use App\Models\SupportCodexCase;
use App\Models\SupportTicket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Manda un HITO de la implementación (presentación, link del formulario, pedido de archivos, entrega...)
 * por el TICKET DE SOPORTE del contacto, por el número de Kapso del admin, en vez de por WhatsApp Web
 * (misión `hitos-de-implementacion-por-soporte`, 26/10/2026).
 *
 * 🔴 POR QUÉ EXISTE. Hasta acá `/implementar` mandaba los hitos desde el teléfono de Lucas (WhatsApp Web)
 * y después los REGISTRABA con `POST claude/implementations/{id}/actions`. La conversación quedaba partida:
 * lo que el cliente contestaba entraba al ticket de soporte (con el circuito de Codex encendido, el cliente
 * en implementación manual cae a soporte) y lo que se le había mandado vivía en el teléfono de Lucas, donde
 * Martín no lo ve. Mandándolo por el ticket, la conversación entera queda donde la lee Martín.
 *
 * 🔴 EL ENVÍO NO SE REESCRIBE: es `SupportWhatsappOpenerService::open()`, el mismo del botón «Nueva
 * conversación por WhatsApp» de la bandeja. Reusa el ticket abierto del contacto (o abre uno), manda texto
 * libre con la ventana de 24 h abierta y la plantilla de apertura (`cc_soporte_apertura`) con el texto
 * adentro si está cerrada. Este servicio agrega lo que ese botón no necesita porque tiene una persona
 * mirando: los frenos previos, la idempotencia por `operation_id` y el registro en la etapa.
 *
 * Lo que guarda (sin migraciones):
 *  - `implementation_stages.data['whatsapp_ops'][operation_id]`: la operación —huella del contenido,
 *    estado y resultado—, en la etapa del hito. Sin el texto ni el teléfono: solo sus huellas.
 *  - `implementation_stages.data['actions'][]`: la acción, con el mismo formato que `actions`
 *    (así el checklist del panel se tilda igual) más `canal: whatsapp`, el ticket y el mensaje.
 *
 * 🔴 NO pasa el caso de Martín a `manual_review`. Este envío no es «Lucas tomó la atención» (eso lo hace
 * `SupportMessageController` cuando Lucas responde desde la bandeja): cuando el cliente conteste, Martín
 * lo atiende como siempre. Y si Martín ESTÁ atendiendo ese ticket (reserva vigente), no se manda nada.
 */
class ImplementationSupportWhatsappService
{
    /** Clave del `data` de la etapa donde viven las operaciones de este envío. */
    const CLAVE_DE_OPERACIONES = 'whatsapp_ops';

    /** Canal con el que queda la acción en `data['actions']`: distinto de `whatsapp_web` a propósito. */
    const CANAL = 'whatsapp';

    /** Estado de una operación reservada cuyo envío todavía no terminó (o cuyo proceso murió en el medio). */
    const ESTADO_ENVIANDO = 'sending';

    /** El cliente recibió el mensaje entero. */
    const ESTADO_ENVIADO = 'sent';

    /** No salió nada. */
    const ESTADO_FALLIDO = 'failed';

    /** Ventana abierta y texto partido con el separador: salieron las primeras partes y no las demás. */
    const ESTADO_PARCIAL = 'partial';

    /** El envío tiró una excepción que no dice si salió o no: no se reintenta, se mira el ticket. */
    const ESTADO_INCIERTO = 'unknown';

    /** Largo máximo del error que se guarda en la operación. */
    const TOPE_DEL_ERROR = 500;

    /**
     * El que manda (reusa o abre el ticket y decide texto libre o plantilla).
     *
     * @var SupportWhatsappOpenerService
     */
    private $opener;

    /**
     * La ventana de 24 h de Meta.
     *
     * @var WhatsappSessionWindowService
     */
    private $ventanas;

    /**
     * Los contactos válidos de cada cliente.
     *
     * @var ClientPhoneDirectory
     */
    private $directorio;

    /**
     * @param SupportWhatsappOpenerService $opener     El envío de la bandeja de soporte.
     * @param WhatsappSessionWindowService $ventanas   El estado de la ventana de 24 h.
     * @param ClientPhoneDirectory         $directorio Los contactos del cliente.
     */
    public function __construct(
        SupportWhatsappOpenerService $opener,
        WhatsappSessionWindowService $ventanas,
        ClientPhoneDirectory $directorio
    ) {
        $this->opener     = $opener;
        $this->ventanas   = $ventanas;
        $this->directorio = $directorio;
    }

    /**
     * El contacto al que va el mensaje: el `telefono` pedido (que tiene que ser un contacto del cliente) o,
     * si no vino, el teléfono del dueño (`clients.phone`).
     *
     * 🔴 El teléfono tiene que estar en la ficha (`ClientPhoneDirectory::resolve_for_client`, el mismo
     * criterio que el modal de la bandeja y que el webhook): si no, la respuesta del cliente no la reconoce
     * el webhook y cae al pipeline de leads, que es justo el hilo partido que este envío viene a evitar.
     *
     * @param Client      $client El cliente de la implementación.
     * @param string|null $pedido `telefono` tal como llegó, o null.
     *
     * @return array{contacto: array<string, mixed>|null, motivo: string|null} `motivo` es
     *         `telefono_ajeno` (el pedido no es del cliente) o `sin_contacto` (no vino y el dueño no tiene
     *         un teléfono válido).
     */
    public function resolver_contacto(Client $client, $pedido)
    {
        $pedido = trim((string) $pedido);

        if ($pedido !== '') {
            $contacto = $this->directorio->resolve_for_client($client, $pedido);

            return ['contacto' => $contacto, 'motivo' => $contacto === null ? 'telefono_ajeno' : null];
        }

        $del_dueno = trim((string) $client->phone);
        $contacto  = $del_dueno === '' ? null : $this->directorio->resolve_for_client($client, $del_dueno);

        return ['contacto' => $contacto, 'motivo' => $contacto === null ? 'sin_contacto' : null];
    }

    /**
     * Lo que pasaría si se mandara ahora: qué ticket se usaría, cómo está la ventana, si sale como texto
     * libre o por plantilla, lo que recibiría el cliente y qué lo impide.
     *
     * Es lo que devuelve el dry-run y lo que mira el real antes de mandar, así los dos deciden con la misma
     * cuenta. Los impedimentos son dos, y ninguno manda nada:
     *  - `texto_largo_para_plantilla`: la ventana está cerrada y el texto, ya aplanado como lo deja la
     *    plantilla, pasa de `TEMPLATE_VARIABLE_MAX_LENGTH`. La plantilla lo cortaría con «…» y el cliente
     *    recibiría medio mensaje (por eso el opener lo corta en silencio y acá se frena antes).
     *  - `martin_atendiendo`: el ticket que se reusaría tiene un caso de Codex con reserva vigente. Mandar
     *    en el medio le cambiaría la conversación debajo a Martín mientras redacta.
     *
     * 🔴 El ticket se busca con `SupportWhatsappOpenerService::find_reusable_ticket()`, el MISMO criterio con
     * el que `open()` decide reusar: si acá se mirara otro ticket, el freno de Martín miraría el equivocado.
     *
     * @param Client               $client   El cliente.
     * @param array<string, mixed> $contacto Fila de `ClientPhoneDirectory` (phone, label, client_employee_id).
     * @param string               $texto    El texto del hito.
     * @param Admin                $admin    El admin que figura como remitente.
     *
     * @return array<string, mixed>
     */
    public function plan(Client $client, array $contacto, $texto, Admin $admin)
    {
        $telefono    = (string) $contacto['phone'];
        $empleado_id = isset($contacto['client_employee_id']) ? $contacto['client_employee_id'] : null;

        $ticket  = $this->opener->find_reusable_ticket($client, $telefono, $empleado_id);
        $ventana = $this->ventanas->window_state($telefono);
        $abierta = (bool) $ventana['open'];

        /* Con la ventana cerrada `open()` pega las partes con un renglón en blanco y descarta los guiones, y
           `sanitize_template_variable()` aplana los saltos y los espacios repetidos: el largo que cuenta es
           el de ESE texto, sin el corte a 600 que hace el propio saneo (por eso no se mide su resultado). */
        $partes   = (new SeparadorDeMensajesManuales())->partir((string) $texto);
        $unido    = count($partes) > 1 ? implode("\n\n", $partes) : trim((string) $texto);
        $aplanado = trim((string) preg_replace('/\s+/u', ' ', $unido));
        $largo    = mb_strlen($aplanado);

        $texto_largo = ! $abierta && $largo > SupportWhatsappOpenerService::TEMPLATE_VARIABLE_MAX_LENGTH;

        $caso   = $ticket === null ? null : SupportCodexCase::where('support_ticket_id', $ticket->id)->first();
        $martin = $caso !== null && $caso->lease_expires_at !== null && $caso->lease_expires_at->isFuture();

        $remitente = trim((string) $admin->name) !== '' ? trim((string) $admin->name) : 'Soporte';
        $contacto_nombre = (string) $contacto['label'];

        $impedimentos = [];
        if ($texto_largo) {
            $impedimentos[] = 'texto_largo_para_plantilla';
        }
        if ($martin) {
            $impedimentos[] = 'martin_atendiendo';
        }

        return [
            'ticket'                 => $ticket,
            'reusaria_ticket'        => $ticket !== null,
            'ventana'                => $ventana,
            'ventana_abierta'        => $abierta,
            'uso_plantilla'          => ! $abierta,
            'plantilla'              => $abierta ? null : $this->opener->resolve_template_name(),
            'largo_para_plantilla'   => $largo,
            'tope_para_plantilla'    => SupportWhatsappOpenerService::TEMPLATE_VARIABLE_MAX_LENGTH,
            'partes'                 => $abierta ? count($partes) : 1,
            'martin_atendiendo'      => $martin,
            'reserva_de_martin_hasta' => $martin ? $caso->lease_expires_at->toIso8601String() : null,
            'contacto_nombre'        => $contacto_nombre,
            'contacto_rol'           => $empleado_id !== null ? 'employee' : 'owner',
            'remitente'              => $remitente,
            /* Lo que recibiría el cliente: con plantilla, el envoltorio de saludo y firma que arma el opener. */
            'vista_previa'           => $abierta ? $unido : $this->opener->build_opening_body($contacto_nombre, $remitente, $unido),
            'impedimentos'           => $impedimentos,
        ];
    }

    /**
     * La huella del CONTENIDO de una operación: el texto, la acción, la etapa y el destinatario ya
     * normalizado. La misma `operation_id` con otra huella es otro mensaje (409), no un reintento.
     *
     * @param string $texto    El texto del hito.
     * @param string $accion   La acción.
     * @param int    $etapa    La etapa donde se registra.
     * @param string $telefono El teléfono E.164 del contacto.
     *
     * @return string sha1.
     */
    public function huella($texto, $accion, $etapa, $telefono)
    {
        return sha1((string) json_encode([trim((string) $texto), (string) $accion, (int) $etapa, (string) $telefono], JSON_UNESCAPED_UNICODE));
    }

    /**
     * Busca una operación por su `operation_id` en TODAS las etapas de la implementación.
     *
     * En todas y no solo en la del pedido: la misma clave mandada con otra `etapa` tiene que dar 409 (otro
     * contenido), no un segundo envío porque se buscó en el lugar equivocado.
     *
     * @param Implementation $implementation La implementación.
     * @param string         $operation_id   La clave.
     * @param bool           $bloquear       true = `lockForUpdate()` de las etapas (adentro de una transacción).
     *
     * @return array{etapa: int, operacion: array<string, mixed>}|null
     */
    public function buscar_operacion(Implementation $implementation, $operation_id, $bloquear = false)
    {
        $consulta = ImplementationStage::where('implementation_id', $implementation->id)->orderBy('stage_number');

        if ($bloquear) {
            $consulta->lockForUpdate();
        }

        foreach ($consulta->get() as $registro) {
            $datos       = is_array($registro->data) ? $registro->data : [];
            $operaciones = isset($datos[self::CLAVE_DE_OPERACIONES]) && is_array($datos[self::CLAVE_DE_OPERACIONES])
                ? $datos[self::CLAVE_DE_OPERACIONES]
                : [];

            if (isset($operaciones[$operation_id]) && is_array($operaciones[$operation_id])) {
                return ['etapa' => (int) $registro->stage_number, 'operacion' => $operaciones[$operation_id]];
            }
        }

        return null;
    }

    /**
     * Reserva la operación ANTES de mandar: la escribe en `sending` en la etapa, en una transacción con la
     * fila de la implementación bloqueada, y recién ahí se manda.
     *
     * 🔴 La búsqueda y la reserva son UNA operación (con la implementación bloqueada): sin eso, dos llamadas
     * simultáneas con la misma clave —un reintento por timeout mientras la primera sigue mandando— pasarían
     * las dos el chequeo y el cliente recibiría el mensaje dos veces. Si el proceso muere después de
     * reservar, la operación queda en `sending` y la misma clave contesta 409 `envio_en_curso` para siempre:
     * es a propósito (no se sabe si salió), y se resuelve mirando el ticket.
     *
     * @param Implementation $implementation La implementación.
     * @param int            $etapa          La etapa donde se guarda.
     * @param string         $operation_id   La clave.
     * @param string         $huella         La huella del contenido.
     * @param string         $accion         La acción (para leer la operación sin cruzarla con nada).
     *
     * @return array{reservada: bool, existente: array<string, mixed>|null, sin_etapa: bool}
     */
    public function reservar(Implementation $implementation, $etapa, $operation_id, $huella, $accion)
    {
        return DB::transaction(function () use ($implementation, $etapa, $operation_id, $huella, $accion) {
            Implementation::where('id', $implementation->id)->lockForUpdate()->first();

            $previa = $this->buscar_operacion($implementation, $operation_id, true);
            if ($previa !== null) {
                return ['reservada' => false, 'existente' => $previa, 'sin_etapa' => false];
            }

            $registro = ImplementationStage::where('implementation_id', $implementation->id)
                ->where('stage_number', $etapa)
                ->lockForUpdate()
                ->first();

            if ($registro === null) {
                return ['reservada' => false, 'existente' => null, 'sin_etapa' => true];
            }

            $datos       = is_array($registro->data) ? $registro->data : [];
            $operaciones = isset($datos[self::CLAVE_DE_OPERACIONES]) && is_array($datos[self::CLAVE_DE_OPERACIONES])
                ? $datos[self::CLAVE_DE_OPERACIONES]
                : [];

            $operaciones[$operation_id] = [
                'huella'     => $huella,
                'accion'     => (string) $accion,
                'etapa'      => (int) $etapa,
                'estado'     => self::ESTADO_ENVIANDO,
                'reservada_at' => now()->toISOString(),
            ];

            $datos[self::CLAVE_DE_OPERACIONES] = $operaciones;
            $registro->data = $datos;
            $registro->save();

            return ['reservada' => true, 'existente' => null, 'sin_etapa' => false];
        });
    }

    /**
     * Manda el texto por el ticket de soporte del contacto (`SupportWhatsappOpenerService::open()`) y
     * traduce su resultado al estado de la operación.
     *
     * El estado sale del `delivery` del opener: `sent` → `sent`, `failed` → `failed` y `partial` (ventana
     * abierta, texto partido con el separador, salieron las primeras partes) → `partial`, que NO es un
     * `failed`: el cliente ya recibió algo. Una excepción que no sea la del teléfono (que se frena antes, así
     * que acá no debería llegar) queda `unknown`: el opener pudo haber mandado antes de tirar (el aviso a la
     * bandeja va DESPUÉS del POST a Kapso), y decir `failed` invitaría a mandarlo otra vez.
     *
     * @param Client               $client   El cliente.
     * @param array<string, mixed> $contacto El contacto (de `resolver_contacto`).
     * @param string               $texto    El texto del hito.
     * @param Admin                $admin    El remitente.
     *
     * @return array<string, mixed> estado, enviado, uso_plantilla, ventana_abierta, ticket_id, message_id,
     *                              whatsapp_message_id, reusado, error (y sent_parts/total_parts si se partió).
     */
    public function enviar(Client $client, array $contacto, $texto, Admin $admin)
    {
        try {
            $resultado = $this->opener->open($client, (string) $contacto['phone'], (string) $texto, $admin, '');
        } catch (\InvalidArgumentException $e) {
            return $this->resultado_sin_envio(self::ESTADO_FALLIDO, $e->getMessage());
        } catch (\Throwable $e) {
            Log::channel('daily')->error('ImplementationSupportWhatsappService: el envío tiró una excepción; queda incierto.', [
                'client_id' => (int) $client->id,
                'error'     => $e->getMessage(),
            ]);

            return $this->resultado_sin_envio(self::ESTADO_INCIERTO, 'El envío tiró una excepción y no se sabe si salió: ' . $e->getMessage());
        }

        $whatsapp = $resultado['whatsapp'];
        $entrega  = isset($whatsapp['delivery']) ? (string) $whatsapp['delivery'] : self::ESTADO_FALLIDO;
        $estado   = in_array($entrega, [self::ESTADO_ENVIADO, self::ESTADO_PARCIAL], true) ? $entrega : self::ESTADO_FALLIDO;

        $salida = [
            'estado'              => $estado,
            'enviado'             => $estado === self::ESTADO_ENVIADO,
            'uso_plantilla'       => (bool) $whatsapp['used_template'],
            'ventana_abierta'     => (bool) $whatsapp['window_open'],
            'ticket_id'           => (int) $resultado['ticket']->id,
            'message_id'          => (int) $resultado['message']->id,
            'whatsapp_message_id' => isset($whatsapp['message_id']) ? $whatsapp['message_id'] : null,
            'reusado'             => (bool) $resultado['reused'],
            'error'               => $this->recortar_error(isset($whatsapp['error']) ? $whatsapp['error'] : null),
        ];

        if (isset($whatsapp['sent_parts'])) {
            $salida['partes_enviadas'] = (int) $whatsapp['sent_parts'];
            $salida['partes_totales']  = isset($whatsapp['total_parts']) ? (int) $whatsapp['total_parts'] : null;
        }

        return $salida;
    }

    /**
     * Cierra la operación con su resultado y, si el cliente recibió el mensaje (`sent` o `partial`), suma la
     * acción a `data['actions']` de la etapa. Todo en una transacción con la etapa bloqueada.
     *
     * La acción tiene el mismo formato que la de `POST claude/implementations/{id}/actions` —`action`,
     * `stage`, `at`, `canal`, `origen: claude`, `texto_hash`, `telefono_hash`— más `support_ticket_id`,
     * `support_message_id` y `operation_id`. 🔴 Un `failed` o un `unknown` NO registran la acción: tildaría
     * en el checklist del panel un mensaje que el cliente no recibió.
     *
     * @param Implementation       $implementation La implementación.
     * @param int                  $etapa          La etapa de la operación.
     * @param string               $operation_id   La clave.
     * @param string               $accion         La acción.
     * @param string               $texto          El texto (solo para su huella).
     * @param string               $telefono       El teléfono E.164 (solo para su huella).
     * @param array<string, mixed> $resultado      Lo que devolvió `enviar()`.
     *
     * @return array<string, mixed> La operación tal como quedó guardada.
     */
    public function registrar(Implementation $implementation, $etapa, $operation_id, $accion, $texto, $telefono, array $resultado)
    {
        return DB::transaction(function () use ($implementation, $etapa, $operation_id, $accion, $texto, $telefono, $resultado) {
            $registro = ImplementationStage::where('implementation_id', $implementation->id)
                ->where('stage_number', $etapa)
                ->lockForUpdate()
                ->first();

            /* La etapa existía al reservar; si alguien la borró en el medio no hay dónde anotar. El mensaje ya
               salió (o no), así que no se tira: se loguea y se devuelve el resultado igual. */
            if ($registro === null) {
                Log::channel('daily')->warning('ImplementationSupportWhatsappService: la etapa desapareció antes de registrar el envío.', [
                    'implementation_id' => (int) $implementation->id,
                    'etapa'             => (int) $etapa,
                    'operation_id'      => (string) $operation_id,
                ]);

                return $resultado;
            }

            $datos       = is_array($registro->data) ? $registro->data : [];
            $operaciones = isset($datos[self::CLAVE_DE_OPERACIONES]) && is_array($datos[self::CLAVE_DE_OPERACIONES])
                ? $datos[self::CLAVE_DE_OPERACIONES]
                : [];
            $previa      = isset($operaciones[$operation_id]) && is_array($operaciones[$operation_id]) ? $operaciones[$operation_id] : [];
            $ahora       = now()->toISOString();

            $operacion = array_merge($previa, $resultado, ['terminada_at' => $ahora]);
            $operaciones[$operation_id] = $operacion;
            $datos[self::CLAVE_DE_OPERACIONES] = $operaciones;

            if (in_array($resultado['estado'], [self::ESTADO_ENVIADO, self::ESTADO_PARCIAL], true)) {
                $entradas = isset($datos['actions']) && is_array($datos['actions']) ? $datos['actions'] : [];

                $entradas[] = [
                    'action'             => (string) $accion,
                    'stage'              => (int) $etapa,
                    'at'                 => $ahora,
                    'canal'              => self::CANAL,
                    'origen'             => 'claude',
                    'texto_hash'         => sha1((string) $texto),
                    'telefono_hash'      => sha1((string) $telefono),
                    'support_ticket_id'  => $resultado['ticket_id'],
                    'support_message_id' => $resultado['message_id'],
                    'operation_id'       => (string) $operation_id,
                ];

                $datos['actions'] = $entradas;
            }

            $registro->data = $datos;
            $registro->save();

            return $operacion;
        });
    }

    /**
     * El resultado de un envío que no llegó a darle un ticket ni un mensaje.
     *
     * @param string      $estado `failed` o `unknown`.
     * @param string|null $error  El motivo.
     *
     * @return array<string, mixed>
     */
    private function resultado_sin_envio($estado, $error)
    {
        return [
            'estado'              => $estado,
            'enviado'             => false,
            'uso_plantilla'       => null,
            'ventana_abierta'     => null,
            'ticket_id'           => null,
            'message_id'          => null,
            'whatsapp_message_id' => null,
            'reusado'             => null,
            'error'               => $this->recortar_error($error),
        ];
    }

    /**
     * Recorta el error a `TOPE_DEL_ERROR` caracteres para guardarlo en el JSON de la etapa.
     *
     * @param mixed $error El error crudo.
     *
     * @return string|null
     */
    private function recortar_error($error)
    {
        $error = trim((string) $error);

        if ($error === '') {
            return null;
        }

        return mb_strlen($error) > self::TOPE_DEL_ERROR ? mb_substr($error, 0, self::TOPE_DEL_ERROR) . '…' : $error;
    }
}

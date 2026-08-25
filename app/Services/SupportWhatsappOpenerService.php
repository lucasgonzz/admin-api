<?php

namespace App\Services;

use App\Events\SupportMessageReceived;
use App\Models\Admin;
use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Abre una conversación de soporte por WhatsApp desde la bandeja del admin.
 *
 * Hasta ahora un ticket de WhatsApp solo podía nacer si el cliente escribía primero
 * (WhatsappWebhookController). Este servicio es el camino inverso: el operador elige un
 * contacto del cliente y escribe el primer mensaje.
 *
 * El envío se decide por la ventana de 24hs de Meta: adentro va texto libre, afuera va la
 * plantilla aprobada con el texto del operador metido como variable. El ticket queda con
 * source='whatsapp' y NUNCA se replica al empresa-api del cliente: los dos canales son
 * excluyentes, igual que en SupportMessageController::store().
 */
class SupportWhatsappOpenerService
{
    /**
     * Plantilla de apertura por defecto, si no hay override en admin_settings.
     */
    const DEFAULT_TEMPLATE_NAME = 'cc_soporte_apertura';

    /**
     * Idioma por defecto de la plantilla de apertura.
     */
    const DEFAULT_TEMPLATE_LANGUAGE = 'es_AR';

    /**
     * Clave de admin_settings para pisar el nombre de la plantilla sin deploy.
     */
    const KEY_TEMPLATE_NAME = 'support_whatsapp_template_name';

    /**
     * Clave de admin_settings para pisar el idioma de la plantilla sin deploy.
     */
    const KEY_TEMPLATE_LANGUAGE = 'support_whatsapp_template_language';

    /**
     * Largo máximo del texto del operador cuando viaja como variable de plantilla.
     * Meta corta los parámetros largos; 600 deja margen sobre el cuerpo de la plantilla.
     */
    const TEMPLATE_VARIABLE_MAX_LENGTH = 600;

    /**
     * En cuantos mensajes se puede partir como mucho una respuesta del agente.
     */
    const MAX_PARTES = 3;

    /**
     * Servicio de envío a Kapso/Meta.
     *
     * @var WhatsappSendService
     */
    private $sender;

    /**
     * Resolución de la ventana de 24hs.
     *
     * @var WhatsappSessionWindowService
     */
    private $window_service;

    /**
     * Contactos válidos de cada cliente.
     *
     * @var ClientPhoneDirectory
     */
    private $phone_directory;

    /**
     * @param WhatsappSendService          $sender          Envío a Kapso/Meta.
     * @param WhatsappSessionWindowService $window_service  Estado de la ventana de 24hs.
     * @param ClientPhoneDirectory         $phone_directory Contactos del cliente.
     */
    public function __construct(
        WhatsappSendService $sender,
        WhatsappSessionWindowService $window_service,
        ClientPhoneDirectory $phone_directory
    ) {
        $this->sender = $sender;
        $this->window_service = $window_service;
        $this->phone_directory = $phone_directory;
    }

    /**
     * Abre (o retoma) una conversación de WhatsApp con un contacto del cliente.
     *
     * @param Client $client         Cliente destino.
     * @param string $phone          Teléfono elegido por el operador, en cualquier formato.
     * @param string $operator_text  Texto que escribió el operador.
     * @param Admin  $admin          Operador que abre la conversación.
     * @param string $ticket_name    Título opcional del ticket.
     *
     * @return array{ticket: SupportTicket, message: SupportMessage, reused: bool, whatsapp: array<string, mixed>}
     *
     * @throws \InvalidArgumentException Si el teléfono no pertenece al cliente.
     */
    public function open(Client $client, string $phone, string $operator_text, Admin $admin, string $ticket_name = ''): array
    {
        $contact = $this->phone_directory->resolve_for_client($client, $phone);
        if ($contact === null) {
            throw new \InvalidArgumentException(
                'Ese número no está cargado en la ficha del cliente. Cargalo primero como teléfono del cliente o como empleado, si no el webhook no va a reconocer la respuesta y va a caer en el pipeline de leads.'
            );
        }

        $normalized_phone = (string) $contact['phone'];
        $client_employee_id = $contact['client_employee_id'];

        $window = $this->window_service->window_state($normalized_phone);
        $use_template = ! $window['open'];

        $contact_name = (string) $contact['label'];
        $admin_name = trim((string) ($admin->name ?? ''));
        if ($admin_name === '') {
            $admin_name = 'Soporte';
        }

        // Se persiste el texto CRUDO del operador. El body solo pasa a ser el texto completo
        // de la plantilla cuando el envío se confirma: si se guardara envuelto de entrada, un
        // envío fallido dejaría en la bandeja un mensaje con saludo y firma que el cliente
        // nunca recibió, y el botón de reintentar lo volvería a envolver sobre sí mismo.
        $operator_text = trim($operator_text);

        $reused = false;
        $ticket = null;
        $message = null;

        // La transacción cubre solo la escritura local. El POST a Kapso queda afuera a
        // propósito: tiene timeout de 15s y dos reintentos, y no se sostiene una transacción
        // abierta durante 45 segundos.
        DB::transaction(function () use (
            $client,
            $normalized_phone,
            $client_employee_id,
            $contact_name,
            $ticket_name,
            $admin,
            $operator_text,
            &$ticket,
            &$message,
            &$reused
        ) {
            // La búsqueda va adentro de la transacción y con lock: suelta, dos operadores
            // simultáneos (o un operador y un mensaje entrante) abrían dos hilos del mismo
            // contacto, y el webhook después engancha las respuestas en uno solo.
            $ticket = $this->find_reusable_ticket($client, $normalized_phone, $client_employee_id, true);
            $reused = $ticket !== null;

            if ($ticket === null) {
                $ticket = SupportTicket::create([
                    'client_id'          => $client->id,
                    'client_employee_id' => $client_employee_id,
                    'client_user_id'     => 0,
                    'client_user_name'   => $contact_name !== '' ? $contact_name : null,
                    // Al operador que la abre, igual que el alta del canal ERP. Asignarla al
                    // dueño por defecto (criterio de los tickets ENTRANTES) la sacaría de su
                    // bandeja apenas la crea: el filtro por defecto es "mine".
                    'assigned_admin_id'  => (int) $admin->id,
                    'name'               => trim($ticket_name) !== '' ? trim($ticket_name) : null,
                    'status'             => 'open',
                    'source'             => 'whatsapp',
                    'whatsapp_phone'     => $normalized_phone,
                    'opened_at'          => now(),
                ]);
            } elseif (trim($ticket_name) !== '' && empty($ticket->name)) {
                $ticket->name = trim($ticket_name);
                $ticket->save();
            }

            $message = SupportMessage::create([
                'support_ticket_id' => $ticket->id,
                'sender_type'       => 'admin',
                'sender_admin_id'   => (int) $admin->id,
                'kind'              => 'text',
                'body'              => $operator_text,
                'delivered_at'      => now(),
            ]);
        });

        $delivery = $this->deliver($normalized_phone, $message, $use_template, $contact_name, $admin_name, $operator_text);

        $message = SupportMessage::where('id', $message->id)->withAll()->first();
        event(new SupportMessageReceived((int) $message->id));

        return [
            'ticket'   => SupportTicket::where('id', $ticket->id)->withAll()->first(),
            'message'  => $message,
            'reused'   => $reused,
            'whatsapp' => array_merge($delivery, [
                'used_template' => $use_template,
                'window_open'   => (bool) $window['open'],
                'window'        => $window,
            ]),
        ];
    }

    /**
     * Entrega una respuesta del operador en un hilo de WhatsApp que ya existe.
     *
     * Antes de esta misión todo ticket de WhatsApp nacía de un mensaje entrante, así que la
     * ventana estaba siempre abierta y mandar texto libre alcanzaba. Con la apertura desde el
     * admin eso deja de ser cierto: se puede abrir una conversación con la ventana cerrada y
     * que el cliente no conteste. Sin esta rama, el segundo mensaje del operador lo rechaza
     * Meta y el operador ve "no recibido" sin ningún motivo.
     *
     * Los adjuntos no se pueden mandar por plantilla, así que con la ventana cerrada se
     * intenta igual y se deja el motivo escrito si falla.
     *
     * @param SupportTicket  $ticket  Ticket de WhatsApp con whatsapp_phone cargado.
     * @param SupportMessage $message Mensaje del operador ya persistido.
     * @param string         $admin_name Nombre del operador que responde.
     *
     * @return array{delivery: string, message_id: string|null, error: string|null, used_template: bool, window_open: bool, template_name: string|null}
     */
    public function deliver_follow_up(SupportTicket $ticket, SupportMessage $message, string $admin_name): array
    {
        $to = (string) $ticket->whatsapp_phone;
        $window = $this->window_service->window_state($to);

        $message->loadMissing('attachments');
        $has_attachments = $message->attachments !== null && count($message->attachments) > 0;

        // Ventana abierta, texto del agente y separadores: sale partido en varios mensajes.
        // Solo acá: una plantilla no puede llevar tres mensajes, y partir el texto de una
        // persona sería cambiarle lo que escribió sin que lo haya pedido.
        if ($window['open'] && ! $has_attachments && $message->ai_generated_at !== null) {
            $partes = $this->split_en_partes((string) ($message->body ?? ''));

            if (count($partes) > 1) {
                return $this->deliver_en_partes($ticket, $message, $partes);
            }
        }

        // Ventana abierta, o adjunto que la plantilla no puede transportar: camino de siempre.
        if ($window['open'] || $has_attachments) {
            $whatsapp_message_id = $this->sender->send_support_message($to, $message);

            if ($whatsapp_message_id !== null) {
                return $this->mark_sent($message, $whatsapp_message_id, false, (bool) $window['open'], null);
            }

            $error = $this->sender->last_send_error;
            if (! $window['open'] && $has_attachments) {
                $error = 'La ventana de 24hs está cerrada y un adjunto no se puede mandar por plantilla. '
                    . 'Esperá a que el cliente escriba, o mandale un texto primero. ' . (string) $error;
            }

            return $this->mark_failed($message, $error, false, (bool) $window['open'], null);
        }

        // Ventana cerrada y mensaje de texto: va por plantilla, con el texto adentro.
        // Las relaciones se cargan a mano: resolve_contact_display_name() las mira con
        // relationLoaded(), y el ticket llega acá desde un findOrFail() pelado.
        $ticket->loadMissing('client', 'client_employee');
        $contact_name = $ticket->resolve_contact_display_name();
        $operator_text = trim((string) ($message->body ?? ''));
        $template_name = $this->resolve_template_name();

        // Una plantilla no puede transportar un texto largo: Meta lo recorta. Antes se mandaba
        // truncado con puntos suspensivos, que en una respuesta de soporte con instrucciones
        // significa mandarle al cliente media explicación. Es preferible no mandar nada y
        // decir por qué: el operador espera a que el cliente escriba, o lo llama.
        if (mb_strlen($this->sanitize_template_variable($operator_text)) >= self::TEMPLATE_VARIABLE_MAX_LENGTH) {
            $this->sender->last_send_error = 'El mensaje es demasiado largo para mandarlo por plantilla ('
                . mb_strlen($operator_text) . ' caracteres, el tope es ' . self::TEMPLATE_VARIABLE_MAX_LENGTH
                . '). La ventana de 24hs está cerrada, así que hay que esperar a que el cliente escriba, o acortarlo.';

            return $this->mark_failed($message, $this->sender->last_send_error, true, false, $template_name);
        }

        $whatsapp_message_id = $this->sender->send_template(
            $to,
            $template_name,
            [
                $this->sanitize_template_variable($contact_name),
                $this->sanitize_template_variable($admin_name),
                $this->sanitize_template_variable($operator_text),
            ],
            $this->resolve_template_language(),
            'Respuesta de soporte a ' . $contact_name . ' con la ventana cerrada'
        );

        if ($whatsapp_message_id !== null) {
            // El texto previo al envoltorio va a su propia columna, NO a ai_original_body:
            // esa significa "el operador corrigió al agente" y el historial que se le manda a
            // Claude la lee así. Escribir ahí le diría que lo corrigieron cada vez que su
            // respuesta salió tal cual pero envuelta en la plantilla.
            if ($message->ai_generated_at !== null && trim((string) ($message->ai_body_before_template ?? '')) === '') {
                $message->ai_body_before_template = $operator_text;
            }

            // Recién ahora el body pasa a ser lo que el cliente de verdad recibió. Pisarlo
            // antes del envío dejaba dos cosas rotas: en la bandeja quedaba un mensaje con
            // saludo y firma que nunca salió, y el botón de reintentar volvía a envolver lo
            // ya envuelto —a los tres reintentos el corte a 600 se comía el texto original—.
            $message->body = $this->build_opening_body($contact_name, $admin_name, $operator_text);
            $message->save();

            return $this->mark_sent($message, $whatsapp_message_id, true, false, $template_name);
        }

        return $this->mark_failed($message, $this->sender->last_send_error, true, false, $template_name);
    }

    /**
     * Parte un texto en los mensajes que el agente quiso mandar por separado.
     *
     * El separador es una línea con tres guiones, igual que en el agente de leads. Se descartan
     * las partes vacías, así que un separador de más no genera un mensaje en blanco.
     *
     * @param string $body Texto completo del agente.
     *
     * @return array<int, string>
     */
    public function split_en_partes(string $body): array
    {
        $partes = preg_split('/\n\s*---\s*\n/', $body);
        if ($partes === false) {
            return [trim($body)];
        }

        $limpias = [];
        foreach ($partes as $parte) {
            $parte = trim($parte);
            if ($parte !== '') {
                $limpias[] = $parte;
            }
        }

        if (empty($limpias)) {
            return [trim($body)];
        }

        // Tope duro. El prompt le pide dos o tres, pero eso es una instruccion a un modelo, no
        // una garantia: ocho separadores serian ocho mensajes al cliente y ocho pausas de 1200ms
        // adentro del request del operador. Lo que sobra se pega a la ultima parte.
        if (count($limpias) > self::MAX_PARTES) {
            $sobrantes = array_splice($limpias, self::MAX_PARTES - 1);
            $limpias[] = implode("\n\n", $sobrantes);
        }

        return $limpias;
    }

    /**
     * Manda un texto partido en varios mensajes de WhatsApp, uno tras otro.
     *
     * 🔴 Las pausas y los reintentos de acá no son precaución teórica: son la solución a un
     * incidente real del agente de leads (lead #440, 22/7/2026). Kapso devuelve 409 "otro
     * mensaje en vuelo para esta conversación" si las partes salen una pegada a la otra, y
     * aquella vez una sugerencia de cuatro partes llegó hasta la tercera, falló la cuarta, y el
     * sistema lo registró como "no se envió nada" mientras el lead ya había recibido y contestado.
     *
     * De ahí las tres reglas: 1200ms entre parte y parte, hasta tres intentos por parte con
     * backoff cuando el fallo es transitorio, y cortar si una parte no sale —mandar la cuarta
     * cuando la tercera nunca llegó deja la conversación sin sentido—.
     *
     * La primera parte se queda en el mensaje que ya existe; las demás se persisten como
     * mensajes propios, para que el hilo muestre exactamente lo que recibió el cliente y cada
     * parte tenga su whatsapp_message_id, que es con lo que Meta correlaciona las entregas.
     *
     * @param SupportTicket      $ticket  Ticket de la conversación.
     * @param SupportMessage     $message Mensaje original, que pasa a ser la primera parte.
     * @param array<int, string> $partes  Partes ya limpias, dos o más.
     *
     * @return array<string, mixed>
     */
    private function deliver_en_partes(SupportTicket $ticket, SupportMessage $message, array $partes): array
    {
        $to = (string) $ticket->whatsapp_phone;
        $total = count($partes);

        // PRIMERO se persisten TODAS las partes, y recien despues se manda.
        //
        // El orden importa y la primera version de esto lo tenia al reves: mandaba, y escribia
        // la parte en el body al confirmarse. Con eso, un fallo en la parte 2 dejaba el body
        // pisado con la parte 1 y el texto de las partes 2..N no quedaba en NINGUN lado: ni en
        // la base, ni en el log, ni en la pantalla. Es justo lo que el incidente del lead #440
        // habia dejado resuelto del otro lado con `partial_send_pending`, y que aca se habia
        // copiado a medias: se copiaron las pausas y los reintentos, no la parte recuperable.
        //
        // Persistiendo antes, una parte que no salio queda en el hilo marcada como no entregada
        // y con el boton de reintentar que ya existe. El estado a medias pasa a ser visible y
        // reparable en vez de silencioso y perdido.
        $filas = [$message];
        $message->body = $partes[0];
        $message->remote_delivery_status = 'not_received';
        $message->whatsapp_message_id = null;
        $message->save();

        for ($indice = 1; $indice < $total; $indice++) {
            $filas[] = $this->persistir_parte($ticket, $message, $partes[$indice]);
        }

        $enviadas = 0;
        $error = null;
        $primer_id = null;

        foreach ($filas as $indice => $fila) {
            $whatsapp_message_id = $this->send_con_reintentos($to, (string) $fila->body);

            if ($whatsapp_message_id === null) {
                // Se corta aca: mandar la parte 4 cuando la 3 nunca llego deja la conversacion
                // sin sentido. Las que quedan ya estan en el hilo, esperando el reintento.
                $error = $this->sender->last_send_error;
                break;
            }

            $fila->whatsapp_message_id = $whatsapp_message_id;
            $fila->remote_delivery_status = null;
            $fila->save();

            if ($indice === 0) {
                $primer_id = $whatsapp_message_id;
            }

            $this->emitir_mensaje($fila);
            $enviadas++;

            // Pausa antes de la siguiente: le da tiempo a Kapso a soltar el bloqueo de la
            // conversacion, que es la causa de raiz del 409 "otro mensaje en vuelo".
            if ($indice < $total - 1) {
                $this->pausar(1200000);
            }
        }

        if ($enviadas === 0) {
            return $this->mark_failed($message, $error, false, true, null);
        }

        if ($enviadas < $total) {
            Log::channel('daily')->warning('SupportWhatsappOpenerService: envio parcial de un mensaje partido.', [
                'ticket_id'  => $ticket->id,
                'message_id' => $message->id,
                'enviadas'   => $enviadas,
                'total'      => $total,
                'error'      => $error,
            ]);

            return [
                'delivery'      => 'partial',
                'message_id'    => $primer_id,
                'error'         => 'Salieron ' . $enviadas . ' de ' . $total . ' mensajes. Los que faltan quedaron en el hilo, marcados como no enviados, para reintentarlos. Motivo: ' . (string) $error,
                'used_template' => false,
                'window_open'   => true,
                'template_name' => null,
                'sent_parts'    => $enviadas,
                'total_parts'   => $total,
            ];
        }

        return [
            'delivery'      => 'sent',
            'message_id'    => $primer_id,
            'error'         => null,
            'used_template' => false,
            'window_open'   => true,
            'template_name' => null,
            'sent_parts'    => $enviadas,
            'total_parts'   => $total,
        ];
    }

    /**
     * Espera entre parte y parte. Separado para que un test lo pueda anular.
     *
     * @param int $microsegundos Cuanto esperar.
     *
     * @return void
     */
    protected function pausar(int $microsegundos): void
    {
        usleep($microsegundos);
    }

    /**
     * Avisa a la pantalla que un mensaje cambio.
     *
     * @param SupportMessage $mensaje Mensaje ya guardado.
     *
     * @return void
     */
    private function emitir_mensaje(SupportMessage $mensaje): void
    {
        $cargado = SupportMessage::query()->where('id', $mensaje->id)->withAll()->first();
        if ($cargado !== null) {
            event(new SupportMessageReceived((int) $cargado->id));
        }
    }

    /**
     * Manda una parte, reintentando cuando el fallo es transitorio.
     *
     * Los intentos intermedios piden que NO se notifique el fallo a los admins: un reintento
     * que después sale bien no tiene por qué despertar a nadie.
     *
     * @param string $to    Teléfono destino.
     * @param string $parte Texto de la parte.
     *
     * @return string|null Id de Meta, o null si no salió tras los tres intentos.
     */
    private function send_con_reintentos(string $to, string $parte)
    {
        for ($intento = 1; $intento <= 3; $intento++) {
            $es_el_ultimo = $intento === 3;

            $whatsapp_message_id = $this->sender->send_text($to, $parte, null, ! $es_el_ultimo);
            if ($whatsapp_message_id !== null) {
                return $whatsapp_message_id;
            }

            if (! $es_el_ultimo && $this->sender->last_send_was_transient()) {
                usleep($intento === 1 ? 1500000 : 3500000);

                continue;
            }

            break;
        }

        return null;
    }

    /**
     * Crea una parte adicional como mensaje propio del hilo, todavia sin enviar.
     *
     * Nace marcada como no entregada a proposito: si el envio se corta antes de llegar a ella,
     * el operador la ve en el hilo con el boton de reintentar en vez de que el texto desaparezca.
     *
     * @param SupportTicket  $ticket   Ticket.
     * @param SupportMessage $original Mensaje de la primera parte, del que hereda la autoria.
     * @param string         $parte    Texto de esta parte.
     *
     * @return SupportMessage
     */
    private function persistir_parte(SupportTicket $ticket, SupportMessage $original, string $parte): SupportMessage
    {
        return SupportMessage::create([
            'support_ticket_id'      => $ticket->id,
            'sender_type'            => 'admin',
            'sender_admin_id'        => $original->sender_admin_id,
            'kind'                   => 'text',
            'body'                   => $parte,
            'delivered_at'           => now(),
            'remote_delivery_status' => 'not_received',
            'ai_generated_at'        => $original->ai_generated_at,
        ]);
    }

    /**
     * Estampa un envío exitoso en el mensaje.
     *
     * @param SupportMessage $message             Mensaje persistido.
     * @param string         $whatsapp_message_id Id devuelto por Meta.
     * @param bool           $used_template       Si salió por plantilla.
     * @param bool           $window_open         Estado de la ventana al momento de mandar.
     * @param string|null    $template_name       Plantilla usada, si hubo.
     *
     * @return array<string, mixed>
     */
    private function mark_sent(SupportMessage $message, string $whatsapp_message_id, bool $used_template, bool $window_open, $template_name): array
    {
        $message->whatsapp_message_id = $whatsapp_message_id;
        $message->remote_delivery_status = null;
        $message->save();

        return [
            'delivery'      => 'sent',
            'message_id'    => $whatsapp_message_id,
            'error'         => null,
            'used_template' => $used_template,
            'window_open'   => $window_open,
            'template_name' => $template_name,
        ];
    }

    /**
     * Estampa un envío fallido en el mensaje.
     *
     * @param SupportMessage $message       Mensaje persistido.
     * @param string|null    $error         Motivo del fallo.
     * @param bool           $used_template Si se intentó por plantilla.
     * @param bool           $window_open   Estado de la ventana al momento de mandar.
     * @param string|null    $template_name Plantilla intentada, si hubo.
     *
     * @return array<string, mixed>
     */
    private function mark_failed(SupportMessage $message, $error, bool $used_template, bool $window_open, $template_name): array
    {
        $message->remote_delivery_status = 'not_received';
        $message->save();

        Log::channel('daily')->warning('SupportWhatsappOpenerService: no se pudo entregar el mensaje.', [
            'message_id'    => $message->id,
            'used_template' => $used_template,
            'window_open'   => $window_open,
            'error'         => $error,
        ]);

        return [
            'delivery'      => 'failed',
            'message_id'    => null,
            'error'         => $error,
            'used_template' => $used_template,
            'window_open'   => $window_open,
            'template_name' => $template_name,
        ];
    }

    /**
     * Ticket de WhatsApp abierto que corresponde a ese contacto, si ya existe.
     *
     * La query es la misma que usa WhatsappWebhookController::handle_support_message() para
     * decidir si reusa o crea. Si acá se creara un hilo nuevo con otro criterio, el próximo
     * mensaje entrante del cliente iría al otro hilo y la conversación quedaría partida.
     *
     * @param Client   $client             Cliente dueño del ticket.
     * @param string   $normalized_phone   Teléfono en E.164.
     * @param int|null $client_employee_id Empleado, si el contacto es un empleado.
     * @param bool     $lock               Si hay que bloquear la fila hasta cerrar la transacción.
     *
     * @return SupportTicket|null
     */
    private function find_reusable_ticket(Client $client, string $normalized_phone, $client_employee_id, bool $lock = false)
    {
        $query = SupportTicket::where('client_id', $client->id)
            ->where('source', 'whatsapp')
            ->where('whatsapp_phone', $normalized_phone)
            ->where('status', 'open');

        if ($client_employee_id !== null) {
            $query->where('client_employee_id', $client_employee_id);
        } else {
            $query->whereNull('client_employee_id');
        }

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /**
     * Manda el mensaje por WhatsApp y estampa el resultado en el mensaje ya persistido.
     *
     * Si el envío falla el ticket NO se revierte: es el mismo estado 'not_received' que ya
     * produce SupportMessageController cuando falla una respuesta, así el operador ve el
     * mismo cartel y el mismo botón de reintento. Además, un rollback borraría un hilo que
     * puede haber salido igual (Meta responde mal y el mensaje llega lo mismo).
     *
     * @param string         $to            Teléfono destino en E.164.
     * @param SupportMessage $message       Mensaje ya guardado.
     * @param bool           $use_template  Si hay que usar plantilla en vez de texto libre.
     * @param string         $contact_name  Nombre del contacto, variable {{1}}.
     * @param string         $admin_name    Nombre del operador, variable {{2}}.
     * @param string         $operator_text Texto del operador, variable {{3}}.
     *
     * @return array{delivery: string, message_id: string|null, error: string|null, template_name: string|null}
     */
    private function deliver(string $to, SupportMessage $message, bool $use_template, string $contact_name, string $admin_name, string $operator_text): array
    {
        $template_name = $use_template ? $this->resolve_template_name() : null;

        if ($use_template) {
            $whatsapp_message_id = $this->sender->send_template(
                $to,
                $template_name,
                [
                    $this->sanitize_template_variable($contact_name),
                    $this->sanitize_template_variable($admin_name),
                    $this->sanitize_template_variable($operator_text),
                ],
                $this->resolve_template_language(),
                'Apertura de conversación de soporte con ' . $contact_name
            );
        } else {
            $whatsapp_message_id = $this->sender->send_text(
                $to,
                $operator_text,
                'Apertura de conversación de soporte con ' . $contact_name
            );
        }

        if ($whatsapp_message_id !== null) {
            if ($use_template) {
                // Confirmado: el body pasa a ser lo que el cliente realmente recibió.
                $message->body = $this->build_opening_body($contact_name, $admin_name, $operator_text);
            }
            $message->whatsapp_message_id = $whatsapp_message_id;
            $message->remote_delivery_status = null;
            $message->save();

            return [
                'delivery'      => 'sent',
                'message_id'    => $whatsapp_message_id,
                'error'         => null,
                'template_name' => $template_name,
            ];
        }

        $error = $this->sender->last_send_error;

        $message->remote_delivery_status = 'not_received';
        $message->save();

        Log::channel('daily')->warning('SupportWhatsappOpenerService: no se pudo entregar la apertura.', [
            'to'            => $to,
            'message_id'    => $message->id,
            'used_template' => $use_template,
            'template'      => $template_name,
            'error'         => $error,
        ]);

        return [
            'delivery'      => 'failed',
            'message_id'    => null,
            'error'         => $error,
            'template_name' => $template_name,
        ];
    }

    /**
     * Texto legible que se guarda cuando el mensaje sale por plantilla.
     *
     * El operador tiene que leer en la bandeja lo mismo que le llegó al cliente, no solo el
     * fragmento que escribió. Mismo criterio que ImplementationConversationService con la
     * plantilla de bienvenida.
     *
     * @param string $contact_name  Nombre del contacto.
     * @param string $admin_name    Nombre del operador.
     * @param string $operator_text Texto escrito por el operador.
     *
     * @return string
     */
    public function build_opening_body(string $contact_name, string $admin_name, string $operator_text): string
    {
        $lines = [];
        $lines[] = 'Hola ' . $contact_name . ', te escribe ' . $admin_name . ' del equipo de soporte de ComercioCity.';
        $lines[] = '';
        $lines[] = $this->sanitize_template_variable($operator_text);
        $lines[] = '';
        $lines[] = 'Respondé este mensaje y seguimos por acá.';

        return implode("\n", $lines);
    }

    /**
     * Adapta un texto para que Meta lo acepte como parámetro de plantilla.
     *
     * Meta rechaza el envío si un parámetro trae saltos de línea, tabs o cuatro o más
     * espacios seguidos. Un rechazo acá se ve como "el mensaje no salió y no sé por qué",
     * así que el saneo va antes del envío y no después.
     *
     * @param string $value Texto crudo.
     *
     * @return string
     */
    public function sanitize_template_variable(string $value): string
    {
        $flattened = preg_replace('/[\r\n\t]+/u', ' ', $value);
        if ($flattened === null) {
            $flattened = $value;
        }

        $collapsed = preg_replace('/\s{2,}/u', ' ', $flattened);
        if ($collapsed === null) {
            $collapsed = $flattened;
        }

        $collapsed = trim($collapsed);

        if (mb_strlen($collapsed) > self::TEMPLATE_VARIABLE_MAX_LENGTH) {
            $collapsed = rtrim(mb_substr($collapsed, 0, self::TEMPLATE_VARIABLE_MAX_LENGTH - 1)) . '…';
        }

        return $collapsed;
    }

    /**
     * Nombre de la plantilla de apertura, con override sin deploy.
     *
     * @return string
     */
    public function resolve_template_name(): string
    {
        $configured = trim((string) AdminSetting::get(self::KEY_TEMPLATE_NAME, ''));

        return $configured !== '' ? $configured : self::DEFAULT_TEMPLATE_NAME;
    }

    /**
     * Idioma de la plantilla de apertura, con override sin deploy.
     *
     * @return string
     */
    public function resolve_template_language(): string
    {
        $configured = trim((string) AdminSetting::get(self::KEY_TEMPLATE_LANGUAGE, ''));

        return $configured !== '' ? $configured : self::DEFAULT_TEMPLATE_LANGUAGE;
    }

}

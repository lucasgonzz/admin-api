<?php

namespace App\Services;

use App\Events\SupportMessageReceived;
use App\Helpers\WhatsappNormalizer;
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
     * Asignación inicial del ticket.
     *
     * @var SupportTicketAssignmentService
     */
    private $assignment_service;

    /**
     * @param WhatsappSendService            $sender             Envío a Kapso/Meta.
     * @param WhatsappSessionWindowService   $window_service     Estado de la ventana de 24hs.
     * @param ClientPhoneDirectory           $phone_directory    Contactos del cliente.
     * @param SupportTicketAssignmentService $assignment_service Asignación inicial.
     */
    public function __construct(
        WhatsappSendService $sender,
        WhatsappSessionWindowService $window_service,
        ClientPhoneDirectory $phone_directory,
        SupportTicketAssignmentService $assignment_service
    ) {
        $this->sender = $sender;
        $this->window_service = $window_service;
        $this->phone_directory = $phone_directory;
        $this->assignment_service = $assignment_service;
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

        // Se decide el canal ANTES de persistir: el body que se guarda tiene que ser el texto
        // que realmente le llega al cliente, y con plantilla ese texto es más largo.
        $window = $this->window_service->window_state($normalized_phone);
        $use_template = ! $window['open'];

        $contact_name = (string) $contact['label'];
        $admin_name = trim((string) ($admin->name ?? ''));
        if ($admin_name === '') {
            $admin_name = 'Soporte';
        }

        $operator_text = trim($operator_text);
        $body = $use_template
            ? $this->build_opening_body($contact_name, $admin_name, $operator_text)
            : $operator_text;

        $reused = false;
        $ticket = $this->find_reusable_ticket($client, $normalized_phone, $client_employee_id);
        if ($ticket !== null) {
            $reused = true;
        }

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
            $body,
            &$ticket,
            &$message
        ) {
            if ($ticket === null) {
                $assigned_admin_id = $this->assignment_service->resolve_assigned_admin_id($client);
                $ticket = SupportTicket::create([
                    'client_id'          => $client->id,
                    'client_employee_id' => $client_employee_id,
                    'client_user_id'     => 0,
                    'client_user_name'   => $contact_name !== '' ? $contact_name : null,
                    'assigned_admin_id'  => $assigned_admin_id !== null ? $assigned_admin_id : (int) $admin->id,
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
                'body'              => $body,
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
     * Ticket de WhatsApp abierto que corresponde a ese contacto, si ya existe.
     *
     * La query es la misma que usa WhatsappWebhookController::handle_support_message() para
     * decidir si reusa o crea. Si acá se creara un hilo nuevo con otro criterio, el próximo
     * mensaje entrante del cliente iría al otro hilo y la conversación quedaría partida.
     *
     * @param Client   $client             Cliente dueño del ticket.
     * @param string   $normalized_phone   Teléfono en E.164.
     * @param int|null $client_employee_id Empleado, si el contacto es un empleado.
     *
     * @return SupportTicket|null
     */
    private function find_reusable_ticket(Client $client, string $normalized_phone, $client_employee_id)
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
                $message->body,
                'Apertura de conversación de soporte con ' . $contact_name
            );
        }

        if ($whatsapp_message_id !== null) {
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

    /**
     * Normaliza un teléfono con el mismo criterio que el resto de la integración.
     *
     * @param string $phone Teléfono en cualquier formato.
     *
     * @return string
     */
    public function normalize_phone(string $phone): string
    {
        return WhatsappNormalizer::normalize($phone);
    }
}

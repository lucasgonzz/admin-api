<?php

namespace App\Services;

use App\Events\SupportMessageReceived;
use App\Models\Admin;
use App\Models\ClientTemplate;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use Illuminate\Support\Facades\Log;

/**
 * Manda una plantilla de cliente al teléfono de un ticket de soporte de WhatsApp.
 *
 * Es el camino que le queda al operador cuando la ventana de 24hs de Meta está cerrada y el texto
 * libre ya no sale, pero también sirve con la ventana abierta: una plantilla se puede mandar
 * siempre, así que este servicio no mira la ventana.
 *
 * 🔴 El body que queda en el hilo es el texto YA RENDERIZADO —los `{{n}}` reemplazados por lo que
 * cargó el operador—, no la plantilla cruda con los placeholders. Es el mismo criterio que
 * SupportWhatsappOpenerService::build_opening_body(): el operador tiene que poder releer en la
 * bandeja lo mismo que le llegó al cliente, y una fila con "{{1}}" adentro no le sirve a nadie
 * para saber qué se dijo.
 *
 * 🔴 Y el mensaje nace marcado como NO ENTREGADO. Recién cuando Meta confirma se le estampa el
 * whatsapp_message_id y se le limpia el estado. Un envío que falló no puede dejar en el hilo un
 * mensaje que se lee como si hubiera salido: queda a la vista con el cartel y el botón de
 * reintentar que ya existen, igual que cualquier otra respuesta que Meta rechaza. Tampoco se borra
 * la fila cuando falla, por el mismo motivo que el opener: Meta puede contestar mal y el mensaje
 * haber llegado lo mismo, y borrarlo dejaría al operador mandándolo dos veces.
 */
class SupportTemplateSendService
{
    /**
     * Envío a Kapso/Meta.
     *
     * @var WhatsappSendService
     */
    private $sender;

    /**
     * Se usa solo por su saneo de variables de plantilla, que es público y ya está probado.
     *
     * @var SupportWhatsappOpenerService
     */
    private $opener;

    /**
     * @param WhatsappSendService          $sender Envío a Kapso/Meta.
     * @param SupportWhatsappOpenerService $opener Dueño del saneo de variables de plantilla.
     */
    public function __construct(WhatsappSendService $sender, SupportWhatsappOpenerService $opener)
    {
        $this->sender = $sender;
        $this->opener = $opener;
    }

    /**
     * Manda la plantilla y deja el mensaje en el hilo del ticket.
     *
     * @param SupportTicket  $ticket    Ticket de WhatsApp destino.
     * @param ClientTemplate $template  Plantilla aprobada en Meta.
     * @param array          $variables Valores de las variables, en orden ({{1}}, {{2}}…).
     * @param Admin          $admin     Operador que la manda.
     *
     * @return array{message: SupportMessage, delivery: string, error: string|null}
     */
    public function enviar(SupportTicket $ticket, ClientTemplate $template, array $variables, Admin $admin): array
    {
        // Meta rechaza el envío entero si un parámetro trae saltos de línea, tabs o espacios de
        // más. El saneo es el del opener y no uno propio: si mañana Meta cambia lo que acepta, el
        // arreglo tiene que estar en un solo lugar.
        $variables_saneadas = array_map(function ($valor) {
            return $this->opener->sanitize_template_variable((string) $valor);
        }, array_values($variables));

        $body = $this->renderizar_body($template, $variables_saneadas);

        $message = SupportMessage::create([
            'support_ticket_id'      => $ticket->id,
            'sender_type'            => 'admin',
            'sender_admin_id'        => (int) $admin->id,
            'kind'                   => 'text',
            'body'                   => $body,
            'delivered_at'           => now(),
            /* Nace no entregado a propósito: recién se limpia si Meta confirma. */
            'remote_delivery_status' => 'not_received',
        ]);

        $whatsapp_message_id = $this->sender->send_template(
            (string) $ticket->whatsapp_phone,
            (string) $template->template_name,
            $variables_saneadas,
            (string) $template->language_code,
            'Plantilla de cliente al ticket #' . $ticket->id
        );

        $error = null;

        if ($whatsapp_message_id !== null) {
            $message->whatsapp_message_id = $whatsapp_message_id;
            $message->remote_delivery_status = null;
            $message->save();

            $delivery = 'sent';
        } else {
            $delivery = 'failed';
            $error = $this->sender->last_send_error;

            Log::channel('daily')->warning('SupportTemplateSendService: no se pudo entregar la plantilla de cliente.', [
                'ticket_id'     => $ticket->id,
                'message_id'    => $message->id,
                'template_name' => $template->template_name,
                'error'         => $error,
            ]);
        }

        // Se recarga con withAll() porque el SPA espera el mensaje completo, y se emite el evento
        // para que el hilo abierto lo pinte por Pusher sin que el operador tenga que recargar.
        $message = SupportMessage::where('id', $message->id)->withAll()->first();
        event(new SupportMessageReceived((int) $message->id));

        return [
            'message'  => $message,
            'delivery' => $delivery,
            'error'    => $error,
        ];
    }

    /**
     * Arma el texto legible que queda en el hilo, con los `{{n}}` ya reemplazados.
     *
     * El texto que le llega al cliente lo arma Meta con la plantilla aprobada, no nosotros: esto
     * es una reconstrucción para la bandeja. Por eso se hace sobre `body_template`, que es la copia
     * local de lo aprobado, y por eso una fila sin cuerpo cargado no rompe nada: deja constancia de
     * qué plantilla salió, que es más que dejar el mensaje en blanco.
     *
     * @param ClientTemplate $template           Plantilla que se está mandando.
     * @param array          $variables_saneadas Valores ya saneados, en orden.
     *
     * @return string
     */
    protected function renderizar_body(ClientTemplate $template, array $variables_saneadas): string
    {
        $body = (string) $template->body_template;

        if (trim($body) === '') {
            return 'Plantilla ' . $template->template_name;
        }

        $posicion = 1;
        foreach ($variables_saneadas as $valor) {
            $body = str_replace('{{' . $posicion . '}}', (string) $valor, $body);
            $posicion++;
        }

        return $body;
    }
}

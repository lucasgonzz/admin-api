<?php

namespace App\Events;

use App\Models\SupportTicket;
use App\Support\BroadcastGuard;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Emite cambios de ticket (reasignación, nombre, cierre) al canal compartido support.admins.
 *
 * Cualquier operador conectado actualiza la bandeja vía apply_ticket_row sin depender de GET manual.
 */
class SupportTicketUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * Id del ticket recién persistido (se usa para reconstruir el payload completo al broadcast).
     *
     * @var int
     */
    public $support_ticket_id;

    /**
     * @param int $support_ticket_id Identificador del SupportTicket actualizado
     */
    public function __construct(int $support_ticket_id)
    {
        $this->support_ticket_id = $support_ticket_id;
    }

    /**
     * Emite el evento sin poder voltear a quien lo emitió.
     *
     * Sobreescribe el `dispatch()` de {@see \Illuminate\Foundation\Events\Dispatchable} para
     * que la emisión pase por {@see \App\Support\BroadcastGuard}: el ticket ya quedó guardado
     * antes de llegar acá, así que una falla de Pusher se loguea y se sigue. El porqué completo
     * está escrito en esa clase.
     *
     * 🔴 Va acá, en el evento, y no en cada sitio de llamada: así queda cubierto todo emisor
     * que use `dispatch()`, incluidos los que se escriban después.
     *
     * ⚠️ No cubre `dispatchIf()`, `dispatchUnless()` ni `broadcast()` del trait `Dispatchable`:
     * esos llaman a `event()` / `broadcast()` por su cuenta. Hoy no los usa nadie con este
     * evento (verificado el 2/9/2026). El detalle largo está en
     * {@see LeadSuggestionCreated::dispatch()}.
     *
     * @return void
     */
    public static function dispatch()
    {
        BroadcastGuard::emitir(new static(...func_get_args()));
    }

    /**
     * Solo emite si el registro sigue existiendo.
     */
    public function broadcastWhen(): bool
    {
        return SupportTicket::query()->where('id', $this->support_ticket_id)->exists();
    }

    /**
     * Canal global escuchado por admin-spa (Nav / badges); mismo criterio que SupportMessageReceived.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('support.admins'),
        ];
    }

    /**
     * Nombre del evento para Echo (.SupportTicketUpdated).
     */
    public function broadcastAs(): string
    {
        return 'SupportTicketUpdated';
    }

    /**
     * Payload del evento: **solo el id del ticket**, y nada más.
     *
     * 🔴 ACÁ NO SE VUELVE A METER EL TICKET (decisión de Lucas, 2/9/2026). `support.admins` es
     * un `Channel` **público** y la clave de Pusher está horneada en el bundle de admin-spa:
     * cualquiera que abra la SPA, logueado o no, puede suscribirse. El ticket llevaba adentro
     * `client_user_name`, `client_user_email`, `whatsapp_phone` y el `lastMessage` —texto libre
     * escrito por un cliente—, o sea datos de una empresa cliente saliendo sin ninguna
     * autenticación.
     *
     * Y el tamaño tampoco lo acotaba nadie: seleccionar columnas a mano achica el payload pero
     * el `lastMessage` lo escribe alguien de afuera, así que el límite de 10240 bytes de Pusher
     * quedaba en manos del cliente que más escribiera.
     *
     * admin-spa refresca la bandeja por API con el id, que es donde sí se verifica quién pide
     * qué. Si alguna vez hace falta mandar algo más, primero se pasa el canal a privado.
     *
     * @return array{support_ticket_id: int}
     */
    public function broadcastWith(): array
    {
        return [
            'support_ticket_id' => $this->support_ticket_id,
        ];
    }
}

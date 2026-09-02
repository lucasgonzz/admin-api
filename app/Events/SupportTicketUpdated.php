<?php

namespace App\Events;

use App\Models\SupportTicket;
use App\Support\BroadcastGuard;
use App\Support\BroadcastPayloadBudget;
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
     * 🔴 Va acá, en el evento, y no en cada sitio de llamada: así queda cubierto todo emisor,
     * incluidos los que se escriban después.
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
     * Payload del evento: el `support_ticket_id` siempre, y el ticket ligero si entra.
     *
     * Pusher Channels limita el body del evento HTTP a ~10 KB; cargar `messages.attachments`
     * rompía al guardar cabecera (reasignación, nombre, cierre). Seleccionar columnas y
     * relaciones a mano achica el payload pero **no lo acota**: `lastMessage` es texto libre
     * escrito por un cliente, así que el tamaño lo termina fijando alguien de afuera. Por eso
     * el payload pasa por {@see \App\Support\BroadcastPayloadBudget}: si no entra, se emite
     * **sin la clave `ticket`**.
     *
     * 🔴 Compatible hacia atrás: `ticket` conserva nombre y forma, y `support_ticket_id` se
     * **suma**. Una admin-spa vieja recibe `ticket` en el caso normal y se comporta igual; la
     * nueva, cuando no vino, refresca la fila por API con el id.
     *
     * @return array{support_ticket_id: int, ticket?: \App\Models\SupportTicket|null}
     */
    public function broadcastWith(): array
    {
        /**
         * Columnas necesarias para ordenar/merge en admin-spa y cabecera; last_message para preview en listado.
         */
        $ticket = SupportTicket::query()
            ->where('id', $this->support_ticket_id)
            ->select([
                'id',
                'uuid',
                'client_id',
                'client_employee_id',
                'client_user_id',
                'client_user_name',
                'client_user_email',
                'assigned_admin_id',
                'name',
                'status',
                'source',
                'whatsapp_phone',
                /* Interruptores del agente: sin esto, el operador que recibe el broadcast ve
                 * el estado viejo del botón —el merge del store conserva lo que ya tenía— y
                 * al tocarlo hace lo contrario de lo que cree. */
                'claude_auto_reply',
                'requiere_verificacion_mensajes',
                'last_client_message_at',
                'alert_sent_at',
                'escalated_at',
                'escalation_reason',
                'opened_at',
                'closed_at',
                'created_at',
                'updated_at',
            ])
            ->with([
                'client:id,uuid,name,company_name',
                'client_employee:id,name',
                'assigned_admin:id,name',
                'lastMessage.sender_admin',
            ])
            ->withUnreadMessagesCount()
            ->first();

        // El id nunca se recorta: es lo que le permite al consumidor reconstruir la fila
        // por API cuando el ticket no entró en el presupuesto.
        return BroadcastPayloadBudget::ajustar(
            [
                'support_ticket_id' => $this->support_ticket_id,
                'ticket'            => $ticket,
            ],
            'ticket',
            'SupportTicketUpdated'
        );
    }
}

<?php

namespace App\Events;

use App\Models\SupportTicket;
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
     * Ticket ligero para la bandeja: sin lista completa de mensajes; incluye last_message para preview.
     *
     * Pusher Channels limita el body del evento HTTP a ~10 KB; cargar `messages.attachments`
     * rompía al guardar cabecera (reasignación, nombre, cierre).
     *
     * @return array{ticket: \App\Models\SupportTicket|null}
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
                'last_client_message_at',
                'alert_sent_at',
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

        return [
            'ticket' => $ticket,
        ];
    }
}

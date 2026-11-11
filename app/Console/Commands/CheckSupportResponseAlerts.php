<?php

namespace App\Console\Commands;

use App\Events\SupportTicketAlert;
use App\Models\AdminSetting;
use App\Models\SupportTicket;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Detecta tickets abiertos sin respuesta del operador y emite alertas Pusher.
 */
class CheckSupportResponseAlerts extends Command
{
    /**
     * Nombre del comando Artisan.
     *
     * @var string
     */
    protected $signature = 'support:check-response-alerts';

    /**
     * Descripción visible en php artisan list.
     *
     * @var string
     */
    protected $description = 'Emite alertas cuando tickets de soporte superan el umbral sin respuesta del operador';

    /**
     * Ejecuta la búsqueda de tickets demorados y dispara eventos.
     *
     * @return int
     */
    public function handle(): int
    {
        $threshold_minutes = (int) AdminSetting::get('support_alert_minutes', 30);
        if ($threshold_minutes < 1) {
            $threshold_minutes = 30;
        }

        $deadline = now()->subMinutes($threshold_minutes);

        $tickets = SupportTicket::query()
            ->where('status', 'open')
            ->whereNotNull('last_client_message_at')
            ->where('last_client_message_at', '<=', $deadline)
            ->where(function ($query) {
                $query->whereNull('alert_sent_at')
                    ->orWhereColumn('alert_sent_at', '<=', 'last_client_message_at');
            })
            ->whereHas('lastMessage', function ($query) {
                $query->where('sender_type', 'user');
            })
            ->with(['client', 'client_employee', 'lastMessage'])
            ->get();

        foreach ($tickets as $ticket) {
            $minutos = (int) $ticket->last_client_message_at->diffInMinutes(now());

            $ticket_name = trim((string) ($ticket->name ?? ''));
            if ($ticket_name === '') {
                $ticket_name = 'Ticket #'.$ticket->id;
            }

            $client_name = $ticket->resolve_contact_display_name();

            event(new SupportTicketAlert(
                (int) $ticket->id,
                $ticket_name,
                $client_name,
                $minutos
            ));

            $ticket->alert_sent_at = now();
            $ticket->save();

            Log::channel('daily')->info('support:check-response-alerts ticket alertado.', [
                'ticket_id' => $ticket->id,
                'minutos'   => $minutos,
            ]);
        }

        return 0;
    }
}

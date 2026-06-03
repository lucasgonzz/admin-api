<?php

namespace App\Console\Commands;

use App\Models\SupportMessage;
use App\Services\SupportClientSyncService;
use Illuminate\Console\Command;

class SupportRetryPendingSyncs extends Command
{
    /**
     * Firma del comando para reintentar sync pendiente hacia empresa-api.
     *
     * @var string
     */
    protected $signature = 'support:retry-pending-syncs';

    /**
     * Descripción para listado de comandos artisan.
     *
     * @var string
     */
    protected $description = 'Reintenta sincronizar mensajes de soporte pendientes hacia empresa-api';

    /**
     * Reprocesa mensajes pendientes con synced_to_client_at null.
     */
    public function handle(SupportClientSyncService $sync_service)
    {
        // Mensajes de admins pendientes de replicación al cliente.
        $pending_messages = SupportMessage::where('sender_type', 'admin')
            ->whereNull('synced_to_client_at')
            ->orderBy('id')
            ->get();
        // Cantidad de mensajes sincronizados en esta ejecución.
        $synced_count = 0;

        foreach ($pending_messages as $message) {
            // Intenta replicar mensaje al empresa-api destino.
            $ok = $sync_service->sync_message_to_client($message);
            if ($ok) {
                $synced_count++;
            }
        }

        $this->info('Support pending syncs processed: ' . $pending_messages->count() . ' | synced: ' . $synced_count);
        return 0;
    }
}


<?php

namespace App\Services;

use App\Models\SupportMessage;
use App\Services\Prueba\PruebaResolucionLocal;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SupportClientSyncService
{
    /**
     * Sincroniza un mensaje creado en admin-api hacia empresa-api del cliente.
     *
     * @param SupportMessage $message
     * @return bool
     */
    public function sync_message_to_client(SupportMessage $message): bool
    {
        // Carga relaciones necesarias para armar request completo.
        $message->loadMissing('ticket.client', 'attachments', 'sender_admin');

        // Cliente destino al que se le enviará el mensaje.
        $client = optional($message->ticket)->client;
        if (is_null($client)) {
            Log::warning('SupportClientSyncService: ticket sin client asociado');
            return false;
        }

        // URL base del cliente de empresa-api.
        $base_url = $message->ticket->source === 'erp' && ($message->ai_generated_at !== null || $message->ticket->agentes_erp)
            ? app(ClientEmpresaApiUrlResolver::class)->resolve_base_url($client)
            : rtrim((string) $client->api_url, '/');
        if ($base_url === '') {
            Log::warning('SupportClientSyncService: client api_url vacío', [
                'client_id' => $client->id,
            ]);
            return false;
        }

        // Metadata de adjuntos (sin binarios); se envía distinto según body JSON o multipart.
        $attachments_meta = $message->attachments->map(function ($attachment) {
            return [
                'disk' => $attachment->disk,
                'path' => $attachment->path,
                'mime' => $attachment->mime,
                'size' => $attachment->size,
            ];
        })->values()->all();

        // Campos escalares del payload (sin arrays anidados que rompen multipart en Laravel Http).
        $payload = [
            'ticket_uuid' => optional($message->ticket)->uuid,
            'message_uuid' => $message->uuid,
            'sender_admin_uuid' => optional($message->sender_admin)->uuid,
            'kind' => $message->kind,
            'body' => $message->body,
        ];

        try {
            // Crea request base autenticado con api_key del cliente.
            $request = Http::withHeaders([
                    'X-Admin-Api-Key' => $client->api_key,
                    'Accept' => 'application/json',
                ])
                ->timeout((int) config('services.client_api.timeout', 15))
                ->retry((int) config('services.client_api.retries', 2), 500);
            // A6.1: un negocio en prueba (instancia del VPS) se resuelve a la IP del VPS, sin DNS. Otro cliente: igual que antes.
            $request = app(PruebaResolucionLocal::class)->aplicar($request, $client, $base_url . '/api/admin-sync/support/messages');

            // Adjunta binarios para que empresa-api guarde copia local.
            $files_attached = false;
            foreach ($message->attachments as $attachment) {
                $disk = $attachment->disk ?: 'public';
                if (! Storage::disk($disk)->exists($attachment->path)) {
                    Log::warning('SupportClientSyncService: adjunto no encontrado en disco ' . $attachment->path);
                    continue;
                }
                $file_content = Storage::disk($disk)->get($attachment->path);
                if ($file_content === false || $file_content === '') {
                    Log::warning('SupportClientSyncService: adjunto vacío o ilegible ' . $attachment->path);
                    continue;
                }
                $request = $request->attach(
                    'attachments_files[]',
                    $file_content,
                    basename($attachment->path) ?: ('attachment_' . $message->id),
                    ['Content-Type' => $attachment->mime ?: 'application/octet-stream']
                );
                $files_attached = true;
            }

            /**
             * Con attach() el body es multipart: arrays anidados en "attachments" provocan
             * "A contents key is required" en Guzzle. Sin binarios, JSON admite el array.
             */
            if ($files_attached) {
                if (count($attachments_meta) > 0) {
                    $payload['attachments'] = json_encode($attachments_meta);
                }
            } else {
                $payload['attachments'] = $attachments_meta;
            }

            // Ejecuta POST de sincronización a empresa-api.
            $response = $request->post($base_url . '/api/admin-sync/support/messages', $payload);

            // Marca sincronización cuando el cliente confirma éxito y limpia fallo previo.
            if ($response->successful()) {
                $message->synced_to_client_at = now();
                $message->remote_delivery_status = null;
                $message->save();
                return true;
            }

            Log::warning('SupportClientSyncService: status ' . $response->status() . ' body ' . $response->body());
            return false;
        } catch (\Throwable $e) {
            // Guarda error sin cortar flujo de soporte al operador.
            Log::warning('SupportClientSyncService exception: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Sincroniza actualización de ticket (estado/nombre) hacia empresa-api.
     *
     * @param \App\Models\SupportTicket $ticket
     * @param bool $usar_frente_activo
     * @return bool
     */
    public function sync_ticket_to_client($ticket, bool $usar_frente_activo = false): bool
    {
        // Carga cliente para resolver URL y api_key.
        $ticket->loadMissing('client');
        $client = $ticket->client;
        if (is_null($client)) {
            return false;
        }

        $base_url = $usar_frente_activo || $ticket->agentes_erp
            ? app(ClientEmpresaApiUrlResolver::class)->resolve_base_url($client)
            : rtrim((string) $client->api_url, '/');
        if ($base_url === '') {
            return false;
        }

        try {
            $url = $base_url . '/api/admin-sync/support/tickets/' . $ticket->uuid;
            // Envía actualización simple de estado/nombre al ticket remoto.
            $response = $this->pedido($client, $url)
                ->put($url, [
                    'name' => $ticket->name,
                    'status' => $ticket->status,
                ]);

            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning('SupportClientSyncService sync_ticket exception: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Sincroniza marca de lectura del admin hacia empresa-api.
     */
    public function sync_read_to_client(SupportMessage $message): bool
    {
        $message->loadMissing('ticket.client');
        $client = optional($message->ticket)->client;
        if (is_null($client)) {
            return false;
        }

        try {
            $url = rtrim($client->api_url, '/') . '/api/admin-sync/support/messages/read';
            $response = $this->pedido($client, $url)
                ->post($url, [
                    'message_uuid' => $message->uuid,
                    'read_at' => optional($message->read_at)->toIso8601String(),
                ]);

            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning('SupportClientSyncService sync_read_to_client exception: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Sincroniza estado de typing del admin hacia empresa-api.
     */
    public function sync_typing_to_client($ticket): bool
    {
        $ticket->loadMissing('client');
        $client = $ticket->client;
        if (is_null($client)) {
            return false;
        }

        try {
            $url = rtrim($client->api_url, '/') . '/api/admin-sync/support/typing';
            $response = $this->pedido($client, $url)
                ->post($url, [
                    'ticket_uuid' => $ticket->uuid,
                    'actor_type' => 'admin',
                    // Quien está escribiendo: el operador autenticado. Antes salía de
                    // `assigned_admin_id`, que desde el 26/10/2026 viaja en null (los tickets no
                    // tienen operador); se conserva el valor legacy solo como respaldo.
                    'actor_id' => \Illuminate\Support\Facades\Auth::id() ?: $ticket->assigned_admin_id,
                ]);

            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning('SupportClientSyncService sync_typing_to_client exception: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Crea ticket espejo en empresa-api cuando se abre desde admin.
     */
    public function create_ticket_in_client($ticket): bool
    {
        $ticket->loadMissing('client');
        $client = $ticket->client;
        if (is_null($client)) {
            return false;
        }

        try {
            $url = rtrim($client->api_url, '/') . '/api/admin-sync/support/tickets';
            $response = $this->pedido($client, $url)
                ->post($url, [
                    'ticket_uuid' => $ticket->uuid,
                    'client_user_id' => $ticket->client_user_id,
                    'name' => $ticket->name,
                    'status' => $ticket->status,
                ]);

            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning('SupportClientSyncService create_ticket_in_client exception: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * El pedido de siempre hacia el empresa-api del cliente (clave, JSON, timeout y reintentos de config), con la
     * resolución local del VPS si el cliente está en prueba (A6.1). Para cualquier otro cliente es el mismo pedido
     * que se armaba en cada método antes del 14/11/2026.
     *
     * @param \App\Models\Client $client
     * @param string             $url
     *
     * @return \Illuminate\Http\Client\PendingRequest
     */
    protected function pedido($client, string $url)
    {
        $pedido = Http::withHeaders([
                'X-Admin-Api-Key' => $client->api_key,
                'Accept' => 'application/json',
            ])
            ->timeout((int) config('services.client_api.timeout', 15))
            ->retry((int) config('services.client_api.retries', 2), 500);

        return app(PruebaResolucionLocal::class)->aplicar($pedido, $client, $url);
    }
}

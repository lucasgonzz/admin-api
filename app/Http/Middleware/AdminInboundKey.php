<?php

namespace App\Http\Middleware;

use App\Models\Client;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use Closure;
use Illuminate\Http\Request;

class AdminInboundKey
{
    /**
     * Valida X-Admin-Api-Key y adjunta el Cliente sincronizado en la request.
     *
     * Si services.admin_inbound_integration.require_api_key es false, no se valida la clave y el Client
     * se infiere del body (client_uuid) o de mensaje/ticket ya existentes en admin-api (uso temporal).
     *
     * @param Request $request Request entrante (JSON o multipart).
     * @param Closure $next Siguiente middleware o controlador.
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next)
    {
        /**
         * Modo sin validación de API key: el tenant se deduce del payload o de registros locales.
         */
        if (! config('services.admin_inbound_integration.require_api_key', false)) {
            $client = $this->resolve_sync_client_without_api_key($request);
            if (is_null($client)) {
                return response()->json(['error' => 'client not resolved'], 401);
            }
            $request->attributes->set('sync_client', $client);

            return $next($request);
        }

        $apiKey = $request->header('X-Admin-Api-Key');

        if (empty($apiKey)) {
            return response()->json(['error' => 'Missing X-Admin-Api-Key'], 401);
        }

        $client = Client::where('inbound_api_key', $apiKey)
                        ->where('is_active', true)
                        ->first();

        if (is_null($client)) {
            return response()->json(['error' => 'Invalid api key'], 401);
        }

        $request->attributes->set('sync_client', $client);

        return $next($request);
    }

    /**
     * Resuelve el Client activo cuando no se exige API key.
     *
     * Orden: client_uuid del body, luego message_uuid (mensaje en admin-api), luego ticket_uuid.
     *
     * @param Request $request Request actual.
     * @return Client|null Cliente encontrado o null si no hay forma segura de resolverlo.
     */
    protected function resolve_sync_client_without_api_key(Request $request): ?Client
    {
        /**
         * UUID del cliente en admin-api; viene en sync de soporte y notification-reads.
         */
        $client_uuid = $request->input('client_uuid');
        if (! empty($client_uuid)) {
            $by_uuid = Client::where('uuid', $client_uuid)->where('is_active', true)->first();
            if (! is_null($by_uuid)) {
                return $by_uuid;
            }
        }

        /**
         * Marcar lectura de mensaje: el mensaje ya existe en admin-api con ticket y client_id.
         */
        $message_uuid = $request->input('message_uuid');
        if (! empty($message_uuid)) {
            $message = SupportMessage::where('uuid', $message_uuid)->first();
            if (! is_null($message)) {
                $message->loadMissing('ticket.client');
                $from_message = optional($message->ticket)->client;
                if (! is_null($from_message) && $from_message->is_active) {
                    return $from_message;
                }
            }
        }

        /**
         * Typing u otros payloads que sólo envían ticket_uuid.
         */
        $ticket_uuid = $request->input('ticket_uuid');
        if (! empty($ticket_uuid)) {
            $ticket = SupportTicket::where('uuid', $ticket_uuid)->first();
            if (! is_null($ticket)) {
                $ticket->loadMissing('client');
                $from_ticket = $ticket->client;
                if (! is_null($from_ticket) && $from_ticket->is_active) {
                    return $from_ticket;
                }
            }
        }

        return null;
    }
}

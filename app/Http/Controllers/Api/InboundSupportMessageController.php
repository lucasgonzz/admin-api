<?php

namespace App\Http\Controllers\Api;

use App\Events\SupportMessageRead;
use App\Events\SupportMessageReceived;
use App\Http\Controllers\Controller;
use App\Models\SupportMessage;
use App\Models\SupportMessageAttachment;
use App\Models\SupportTicket;
use App\Models\SupportTypingState;
use App\Services\SupportTicketAssignmentService;
use Illuminate\Http\Request;

class InboundSupportMessageController extends Controller
{
    /**
     * Recibe mensaje desde empresa-api y lo persiste en admin-api.
     */
    public function store(Request $request, SupportTicketAssignmentService $assignment_service)
    {
        // Cliente resuelto por middleware admin.inbound.key.
        $client = $request->attributes->get('sync_client');
        if (is_null($client)) {
            return response()->json(['error' => 'client not resolved'], 401);
        }

        // UUID de mensaje para idempotencia entre reintentos.
        $message_uuid = $request->input('message_uuid');
        // Retorna mensaje existente si ya fue recibido.
        $existing = SupportMessage::where('uuid', $message_uuid)->withAll()->first();
        if (!is_null($existing)) {
            return response()->json(['model' => $existing], 200);
        }

        // Busca ticket por uuid compartido o crea uno nuevo abierto.
        $ticket = SupportTicket::where('uuid', $request->input('ticket_uuid'))->first();
        if (is_null($ticket)) {
            // Admin inicial sugerido para primer contacto de soporte.
            $assigned_admin_id = $assignment_service->resolve_assigned_admin_id($client);
            $ticket = SupportTicket::create([
                'uuid' => $request->input('ticket_uuid'),
                'client_id' => $client->id,
                'client_user_id' => (int) $request->input('client_user_id'),
                'client_user_name' => $request->input('sender_user_name'),
                'client_user_email' => $request->input('sender_user_email'),
                'assigned_admin_id' => $assigned_admin_id,
                'name' => $request->input('ticket_name'),
                'status' => $request->input('ticket_status', 'open'),
                'opened_at' => now(),
            ]);
        }

        // Actualiza snapshot de usuario remoto para listado lateral.
        $ticket->client_user_name = $request->input('sender_user_name', $ticket->client_user_name);
        $ticket->client_user_email = $request->input('sender_user_email', $ticket->client_user_email);
        $ticket->save();

        // Crea mensaje inbound de origen usuario.
        $message = SupportMessage::create([
            'uuid' => $message_uuid,
            'support_ticket_id' => $ticket->id,
            'sender_type' => $request->input('sender_type', 'user'),
            'kind' => $request->input('kind', 'text'),
            'body' => $request->input('body'),
            'delivered_at' => now(),
            'synced_to_client_at' => now(),
        ]);

        // Archivos binarios del multipart (prioridad sobre metadata remota).
        $attachments_files = $request->file('attachments_files', []);
        $has_uploaded_files = is_array($attachments_files) && count($attachments_files) > 0;

        if ($has_uploaded_files) {
            foreach ($attachments_files as $uploaded_file) {
                $stored_path = $uploaded_file->store('support_messages/' . $ticket->id, 'public');
                SupportMessageAttachment::create([
                    'support_message_id' => $message->id,
                    'disk' => 'public',
                    'path' => $stored_path,
                    'mime' => $uploaded_file->getMimeType(),
                    'size' => $uploaded_file->getSize(),
                ]);
            }
        } else {
            $attachments = $this->parse_attachments_input($request->input('attachments', []));
            foreach ($attachments as $attachment) {
                if (! is_array($attachment)) {
                    continue;
                }
                SupportMessageAttachment::create([
                    'support_message_id' => $message->id,
                    'disk' => $attachment['disk'] ?? 'public',
                    'path' => $attachment['path'] ?? '',
                    'mime' => $attachment['mime'] ?? null,
                    'size' => $attachment['size'] ?? null,
                ]);
            }
        }

        // Recarga relaciones para respuesta y broadcast.
        $message = SupportMessage::where('id', $message->id)->withAll()->first();
        // Emite actualización realtime para operadores.
        event(new SupportMessageReceived($message->id));

        return response()->json(['model' => $message], 201);
    }

    /**
     * Recibe confirmación de lectura desde empresa-api.
     */
    public function mark_read(Request $request)
    {
        $message = SupportMessage::where('uuid', $request->input('message_uuid'))->first();
        if (is_null($message)) {
            return response()->json(['error' => 'message not found'], 404);
        }

        $message->read_at = $request->input('read_at') ?: now();
        $message->save();

        // Notifica a operadores conectados (doble check / visto en mensajes salientes).
        $updated = SupportMessage::where('id', $message->id)->withAll()->first();
        if (!is_null($updated)) {
            event(new SupportMessageRead($updated->id));
        }

        return response()->json(['ok' => true], 200);
    }

    /**
     * Recibe typing state de empresa-api.
     */
    public function typing(Request $request)
    {
        $ticket = SupportTicket::where('uuid', $request->input('ticket_uuid'))->first();
        if (is_null($ticket)) {
            return response()->json(['error' => 'ticket not found'], 404);
        }

        $typing_state = SupportTypingState::firstOrNew([
            'support_ticket_id' => $ticket->id,
            'actor_type' => $request->input('actor_type', 'user'),
            'actor_id' => $request->input('actor_id'),
        ]);
        $typing_state->last_typing_at = now();
        $typing_state->save();

        return response()->json(['ok' => true], 200);
    }

    /**
     * Normaliza el campo attachments del request (array JSON o string JSON desde multipart).
     *
     * @param mixed $raw Valor crudo del request.
     * @return array<int, array<string, mixed>>
     */
    private function parse_attachments_input($raw): array
    {
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }

            return [];
        }
        if (is_array($raw)) {
            return $raw;
        }

        return [];
    }
}


<?php

namespace App\Http\Controllers\Api;

use App\Events\SupportMessageRead;
use App\Events\SupportMessageReceived;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\SupportMessage;
use App\Models\SupportMessageAttachment;
use App\Models\SupportTicket;
use App\Models\SupportTypingState;
use App\Services\SupportAiSuggestionScheduler;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class InboundSupportMessageController extends Controller
{
    /**
     * Recibe mensaje desde empresa-api y lo persiste en admin-api.
     *
     * Si el ticket es nuevo nace SIN operador (`assigned_admin_id = null`): desde el 26/10/2026 los
     * tickets de soporte no pertenecen a nadie.
     */
    public function store(Request $request)
    {
        // Cliente resuelto por middleware admin.inbound.key.
        $client = $request->attributes->get('sync_client');
        if (is_null($client)) {
            return response()->json(['error' => 'client not resolved'], 401);
        }

        [$message, $creado] = $this->guardar_entrante($request, $client);
        $message = SupportMessage::where('id', $message->id)->withAll()->firstOrFail();
        if ($creado) {
            SupportMessageReceived::dispatch_after_commit($message->id);
        }
        if ($message->ticket !== null && $this->entrada_robotito_valida($request, $client, $message->ticket)) {
            $this->programar_robotito($message);
        }
        return response()->json(['model' => $message], $creado ? 201 : 200);
    }

    private function guardar_entrante(Request $request, Client $client): array
    {
        return DB::transaction(function () use ($request, $client) {
            // El entrante compite por la misma fila que la respuesta del agente y la aprobación.
            // support_messages no tiene una clave foránea que bloquee esa inserción por sí sola.
            $ticket_uuid = $request->input('ticket_uuid');
            $ticket = SupportTicket::where('uuid', $ticket_uuid)->lockForUpdate()->first();
            abort_if($ticket !== null && ((int) $ticket->client_id !== (int) $client->id || $ticket->source !== 'erp'), 404);

            $message_uuid = $request->input('message_uuid');
            $existing = SupportMessage::where('uuid', $message_uuid)->first();
            if ($existing !== null) {
                abort_unless($ticket !== null && (int) $existing->support_ticket_id === (int) $ticket->id, 404);
                return [$existing, false];
            }

            if ($ticket === null) {
                $ticket = new SupportTicket([
                    'uuid' => $ticket_uuid,
                    'client_id' => $client->id,
                    'client_user_id' => (int) $request->input('client_user_id'),
                    'assigned_admin_id' => null,
                    'name' => $request->input('ticket_name'),
                    'status' => $request->input('ticket_status', 'open'),
                    'source' => 'erp',
                    'opened_at' => now(),
                ]);
            }

            // Actualiza snapshot de usuario remoto para listado lateral.
            $ticket->client_user_name = $request->input('sender_user_name', $ticket->client_user_name);
            $ticket->client_user_email = $request->input('sender_user_email', $ticket->client_user_email);
            $ticket->save();

            // La capacidad se guarda al crear el entrante firmado. Una repetición no puede
            // agregarla a una fila histórica o a una que entró sin autenticación.
            $datos = [
                'uuid' => $message_uuid,
                'support_ticket_id' => $ticket->id,
                'sender_type' => $request->input('sender_type', 'user'),
                'kind' => $request->input('kind', 'text'),
                'body' => $request->input('body'),
                'delivered_at' => now(),
                'synced_to_client_at' => now(),
            ];
            if ($this->entrada_robotito_valida($request, $client, $ticket)) {
                $datos['erp_auto_reply'] = ['inbound_version' => 1];
            }
            $message = SupportMessage::create($datos);
            $this->persistir_adjuntos($request, $message);
            return [$message, true];
        });
    }

    private function persistir_adjuntos(Request $request, SupportMessage $message): void
    {
        // Archivos binarios del multipart (prioridad sobre metadata remota).
        $attachments_files = $request->file('attachments_files', []);
        $has_uploaded_files = is_array($attachments_files) && count($attachments_files) > 0;

        if ($has_uploaded_files) {
            foreach ($attachments_files as $uploaded_file) {
                $stored_path = $uploaded_file->store('support_messages/' . $message->support_ticket_id, 'public');
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
    }

    private function entrada_robotito_valida(Request $request, Client $client, SupportTicket $ticket): bool
    {
        $clave = (string) $client->inbound_api_key;
        return in_array($request->input('robotito_support_version'), [1, '1'], true)
            && $clave !== '' && hash_equals($clave, (string) $request->header('X-Admin-Api-Key'))
            && Str::isUuid((string) $request->input('message_uuid')) && Str::isUuid((string) $ticket->uuid)
            && $ticket->source === 'erp' && $ticket->status === 'open' && $request->input('sender_type', 'user') === 'user'
            && (int) $ticket->client_user_id > 0 && (int) $ticket->client_user_id === (int) $request->input('sender_user_id');
    }

    private function programar_robotito(SupportMessage $message): void
    {
        $marca = $message->erp_auto_reply;
        if (($marca['inbound_version'] ?? null) !== 1 || isset($marca['scheduled_at'])) {
            return;
        }

        // Serializa un replay con cualquier otro entrante del mismo ticket: la consulta vieja
        // no puede cambiar el token de programación después de que se agendó la nueva.
        try {
            Cache::store('file')->lock('support-erp-inbound-' . $message->support_ticket_id, 900)->block(2, function () use ($message) {
                $message->refresh();
                $marca = $message->erp_auto_reply;
                $ticket = $message->ticket;
                if (isset($marca['scheduled_at']) || $ticket === null || $ticket->status !== 'open' || $message->sender_type !== 'user') {
                    return;
                }
                $hay_mas_nuevo = SupportMessage::where('support_ticket_id', $ticket->id)
                    ->where('id', '>', $message->id)->where('is_ai_suggestion_draft', false)->exists();
                if ($hay_mas_nuevo) {
                    return;
                }

                app(SupportAiSuggestionScheduler::class)->schedule_after_client_inbound((int) $ticket->id, (int) $message->id);
                $marca['scheduled_at'] = now()->toIso8601String();
                $message->erp_auto_reply = $marca;
                $message->save();
            });
        } catch (\Throwable $exception) {
            if (! $exception instanceof LockTimeoutException) {
                Log::warning('No se pudo programar la atención web de soporte.', ['message_id' => $message->id]);
            }
            abort(503, 'La consulta quedó guardada y falta programar su atención. Reintentá el envío.');
        }
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


<?php

namespace App\Http\Controllers\Api;

use App\Events\SupportMessageReceived;
use App\Http\Controllers\CommonLaravel\BaseController;
use App\Models\SupportMessage;
use App\Models\SupportMessageAttachment;
use App\Models\SupportTicket;
use App\Models\SupportTypingState;
use App\Services\SupportClientSyncService;
use App\Services\WhatsappSendService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class SupportMessageController extends BaseController
{
    /**
     * Crea mensaje desde admin-spa y lo entrega según el canal del ticket (ERP o WhatsApp).
     */
    public function store(Request $request, $ticket_id, SupportClientSyncService $sync_service)
    {
        // Ticket destino donde se agregará el mensaje.
        $ticket = SupportTicket::findOrFail($ticket_id);

        // Evita escribir sobre tickets cerrados.
        if ($ticket->status !== 'open') {
            return response()->json(['error' => 'ticket closed'], 422);
        }

        // Tipo de contenido recibido desde frontend.
        $kind = $request->input('kind', 'text');
        // Cuerpo textual del mensaje.
        $body = $request->input('body');
        if (is_string($body)) {
            $body = trim($body);
        }

        // Crea mensaje del operador actual.
        $message = SupportMessage::create([
            'support_ticket_id' => $ticket->id,
            'sender_type' => 'admin',
            'sender_admin_id' => (int) Auth::id(),
            'kind' => $kind,
            'body' => $body,
            'delivered_at' => now(),
        ]);

        // Persiste adjunto si llegó archivo desde formulario.
        if ($request->hasFile('attachment')) {
            // Archivo enviado por operador.
            $attachment_file = $request->file('attachment');
            // Carpeta lógica por ticket.
            $directory = 'support_messages/' . $ticket->id;
            // Guardado en disco público para acceso controlado.
            $stored_path = $attachment_file->store($directory, 'public');

            SupportMessageAttachment::create([
                'support_message_id' => $message->id,
                'disk' => 'public',
                'path' => $stored_path,
                'mime' => $attachment_file->getMimeType(),
                'size' => $attachment_file->getSize(),
            ]);
        }

        $message = SupportMessage::where('id', $message->id)->withAll()->first();

        // Entrega según origen del ticket: WhatsApp y ERP son flujos excluyentes.
        if ($ticket->source === 'whatsapp') {
            $this->deliver_admin_message_via_whatsapp($ticket, $message);
        } elseif ($ticket->source === 'erp') {
            // Sincroniza a empresa-api del cliente; si falla, el operador ve estado "no recibido" en destino.
            $sync_ok = $sync_service->sync_message_to_client($message);
            if (! $sync_ok) {
                $message->remote_delivery_status = 'not_received';
                $message->save();
            }
        }

        $message = SupportMessage::where('id', $message->id)->withAll()->first();

        // Realtime para operadores en admin-spa (no replica hacia empresa-api).
        event(new SupportMessageReceived($message->id));

        return response()->json(['model' => $message], 201);
    }

    /**
     * Envía el mensaje del operador por Kapso/WhatsApp; degradación elegante si falla el API.
     *
     * @param SupportTicket   $ticket  Ticket con source whatsapp y whatsapp_phone.
     * @param SupportMessage  $message Mensaje ya persistido en admin-api.
     *
     * @return void
     */
    private function deliver_admin_message_via_whatsapp(SupportTicket $ticket, SupportMessage $message): void
    {
        if (empty($ticket->whatsapp_phone)) {
            Log::channel('daily')->error('SupportMessageController: ticket WhatsApp sin número destino.', [
                'ticket_id' => $ticket->id,
            ]);
            $this->mark_whatsapp_delivery_failed($message);

            return;
        }

        try {
            $whatsapp_send_service = new WhatsappSendService();
            $message->loadMissing('attachments');
            $whatsapp_message_id = $whatsapp_send_service->send_support_message(
                (string) $ticket->whatsapp_phone,
                $message
            );

            if ($whatsapp_message_id) {
                $message->update([
                    'whatsapp_message_id'     => $whatsapp_message_id,
                    'remote_delivery_status'  => null,
                ]);

                Log::channel('daily')->info('SupportMessageController: mensaje enviado por WhatsApp.', [
                    'ticket_id'           => $ticket->id,
                    'to'                  => $ticket->whatsapp_phone,
                    'whatsapp_message_id' => $whatsapp_message_id,
                ]);

                return;
            }

            Log::channel('daily')->error('SupportMessageController: falló envío por WhatsApp.', [
                'ticket_id' => $ticket->id,
                'to'        => $ticket->whatsapp_phone,
            ]);
            $this->mark_whatsapp_delivery_failed($message);
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('SupportMessageController: excepción al enviar por WhatsApp.', [
                'ticket_id' => $ticket->id,
                'to'        => $ticket->whatsapp_phone,
                'error'     => $exception->getMessage(),
            ]);
            $this->mark_whatsapp_delivery_failed($message);
        }
    }

    /**
     * Marca el mensaje como no entregado por WhatsApp (admin-spa oculta el check y muestra reintento).
     *
     * @param SupportMessage $message Mensaje del operador ya persistido.
     *
     * @return void
     */
    private function mark_whatsapp_delivery_failed(SupportMessage $message): void
    {
        $message->remote_delivery_status = 'not_received';
        $message->save();
    }

    /**
     * Marca lectura de mensaje desde admin-spa y la sincroniza al cliente.
     */
    public function mark_read($id, SupportClientSyncService $sync_service)
    {
        $message = SupportMessage::findOrFail($id);
        $message->read_at = now();
        $message->save();
        $sync_service->sync_read_to_client($message);

        return response()->json(['ok' => true], 200);
    }

    /**
     * Reintenta replicar en empresa-api un mensaje del operador que ya está guardado en admin-api.
     *
     * @param int|string $id Id del mensaje en admin-api.
     * @param SupportClientSyncService $sync_service Servicio HTTP hacia el cliente.
     * @return \Illuminate\Http\JsonResponse
     */
    public function retry_remote_sync($id, SupportClientSyncService $sync_service)
    {
        $admin_id = (int) Auth::id();
        $message = SupportMessage::where('id', $id)
            ->where('sender_type', 'admin')
            ->where('sender_admin_id', $admin_id)
            ->with('ticket')
            ->firstOrFail();

        $ticket = $message->ticket;

        if ($ticket && $ticket->source === 'whatsapp') {
            if (empty($message->whatsapp_message_id)) {
                $this->deliver_admin_message_via_whatsapp($ticket, $message);
            }
        } else {
            $sync_ok = $sync_service->sync_message_to_client($message);
            if ($sync_ok) {
                $message->remote_delivery_status = null;
                $message->save();
            } else {
                $message->remote_delivery_status = 'not_received';
                $message->save();
            }
        }

        $message = SupportMessage::where('id', $message->id)->withAll()->first();

        return response()->json(['model' => $message], 200);
    }

    /**
     * Guarda typing state del admin y lo sincroniza al cliente.
     */
    public function typing(Request $request, $ticket_id, SupportClientSyncService $sync_service)
    {
        $ticket = SupportTicket::findOrFail($ticket_id);
        $actor_id = (int) Auth::id();

        $typing_state = SupportTypingState::firstOrNew([
            'support_ticket_id' => $ticket->id,
            'actor_type' => 'admin',
            'actor_id' => $actor_id,
        ]);
        $typing_state->last_typing_at = now();
        $typing_state->save();
        $sync_service->sync_typing_to_client($ticket);

        return response()->json(['ok' => true], 200);
    }
}


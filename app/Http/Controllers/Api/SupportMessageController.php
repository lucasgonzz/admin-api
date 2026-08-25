<?php

namespace App\Http\Controllers\Api;

use App\Events\SupportMessageReceived;
use App\Http\Controllers\CommonLaravel\BaseController;
use App\Models\Admin;
use App\Models\SupportMessage;
use App\Models\SupportMessageAttachment;
use App\Models\SupportTicket;
use App\Models\SupportTypingState;
use App\Services\SupportAiSuggestionDeliveryService;
use App\Services\SupportAiSuggestionDraftService;
use App\Services\SupportClientSyncService;
use App\Services\SupportWhatsappOpenerService;
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

        // Respuesta manual del operador: cancela envío automático de sugerencia IA pendiente.
        (new SupportAiSuggestionDraftService())->clear_ticket_pending_state($ticket);

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
            // Pasa por el opener y no derecho por WhatsappSendService: con la ventana de 24hs
            // de Meta cerrada el texto libre se rechaza, y hasta ahora eso se perdía en
            // silencio. Antes casi no pasaba —todo ticket de WhatsApp nacía de un entrante—,
            // pero desde que el operador puede abrir la conversación él, es el caso normal.
            $resultado = app(SupportWhatsappOpenerService::class)
                ->deliver_follow_up($ticket, $message, $this->resolve_current_admin_name());

            if ($resultado['delivery'] === 'sent') {
                Log::channel('daily')->info('SupportMessageController: mensaje enviado por WhatsApp.', [
                    'ticket_id'           => $ticket->id,
                    'to'                  => $ticket->whatsapp_phone,
                    'whatsapp_message_id' => $resultado['message_id'],
                    'used_template'       => $resultado['used_template'],
                ]);

                return;
            }

            Log::channel('daily')->error('SupportMessageController: falló envío por WhatsApp.', [
                'ticket_id'     => $ticket->id,
                'to'            => $ticket->whatsapp_phone,
                'used_template' => $resultado['used_template'],
                'error'         => $resultado['error'],
            ]);
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
     * Nombre del operador autenticado, para la plantilla de WhatsApp.
     *
     * @return string
     */
    private function resolve_current_admin_name(): string
    {
        $admin = Admin::find((int) Auth::id());
        $admin_name = $admin !== null ? trim((string) $admin->name) : '';

        return $admin_name !== '' ? $admin_name : 'Soporte';
    }

    /**
     * Aprueba un borrador del agente y lo manda al cliente, con o sin ajustes.
     *
     * Si el operador cambió el texto, el que había propuesto el agente se guarda en
     * `ai_original_body`: teniendo el par (propuesto, enviado) se puede medir después en qué se
     * equivoca, que es para lo que Lucas pidió que quedara guardada la edición.
     *
     * @param Request    $request Puede traer `body` con el texto final editado.
     * @param int|string $id      Id del mensaje borrador.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function approve_ai_draft(Request $request, $id)
    {
        $message = SupportMessage::with('ticket')->findOrFail($id);

        $ticket = $message->ticket;
        if ($ticket === null || $ticket->status !== 'open') {
            return response()->json(['error' => 'El ticket está cerrado.'], 422);
        }

        // Se toma el borrador con un UPDATE condicional en vez de leer-y-después-escribir. El
        // job de autoenvío y el operador pueden estar los dos sobre el mismo borrador —el
        // operador aprieta Enviar en el segundo 119 de una demora de 120— y sin esto los dos
        // pasaban el chequeo y el cliente recibía el mensaje dos veces. Quien gane la carrera
        // se lo lleva: el que pierde, ve 0 filas afectadas y corta acá.
        $tomado = SupportMessage::where('id', $message->id)
            ->where('is_ai_suggestion_draft', true)
            ->update(['is_ai_suggestion_draft' => false, 'ai_auto_send_at' => null]);

        if ($tomado === 0) {
            return response()->json(['error' => 'Ese borrador ya se envió o se descartó.'], 422);
        }

        $message->refresh();

        $original_body = trim((string) ($message->body ?? ''));
        $final_body = $request->has('body') ? trim((string) $request->input('body')) : $original_body;

        if ($final_body === '') {
            return response()->json(['error' => 'El mensaje no puede quedar vacío.'], 422);
        }

        if ($final_body !== $original_body) {
            $message->ai_original_body = $original_body;
            $message->body = $final_body;
        }

        // Queda a nombre de quien lo aprueba: el agente propone, la persona firma.
        $message->sender_admin_id = (int) Auth::id();
        $message->save();

        $delivery_service = app(SupportAiSuggestionDeliveryService::class);
        $delivered = $delivery_service->deliver_draft_message($message, $ticket);
        $resultado_envio = $delivery_service->ultimo_resultado();

        // Limpia solo el estado pendiente del TICKET. clear_ticket_pending_state() también
        // borra borradores, y este mensaje ya dejó de serlo arriba, así que no se lo lleva.
        $ticket->ai_pending_suggestion = null;
        $ticket->ai_suggestion_send_at = null;
        $ticket->save();

        $message = SupportMessage::where('id', $message->id)->withAll()->first();

        if ($delivered === null) {
            // El ticket no tiene canal por dónde salir. El mensaje quedó guardado y visible,
            // pero decirle 200 al operador le haría creer que el cliente lo recibió.
            return response()->json([
                'model' => $message,
                'error' => 'El mensaje quedó guardado pero no se pudo entregar: el ticket no tiene un canal de WhatsApp válido.',
            ], 422);
        }

        // 🔴 Un envío PARTIDO a medias deja la primera parte entregada, así que mirar solo el
        // estado de esa fila decía "entregado" mientras las otras dos nunca llegaron al cliente.
        // Es el mismo error que esta misión vino a arreglar, una capa más arriba: la verdad la
        // tiene el resultado del envío, no el mensaje.
        if (is_array($resultado_envio) && $resultado_envio['delivery'] === 'partial') {
            return response()->json([
                'model'       => $message,
                'delivered'   => false,
                'partial'     => true,
                'sent_parts'  => $resultado_envio['sent_parts'],
                'total_parts' => $resultado_envio['total_parts'],
                'error'       => 'Salieron ' . $resultado_envio['sent_parts'] . ' de '
                    . $resultado_envio['total_parts'] . ' mensajes. Los que faltan quedaron en la '
                    . 'conversación marcados como no enviados: reintentalos desde ahí.',
            ], 200);
        }

        // El servicio se traga los fallos de Meta, así que devolver true por haber recibido un
        // modelo mentiría: lo que dice si salió es el estado que quedó estampado en el mensaje.
        $entregado = $message !== null && $message->remote_delivery_status !== 'not_received';

        return response()->json([
            'model'     => $message,
            'delivered' => $entregado,
        ], 200);
    }

    /**
     * Descarta un borrador del agente sin mandarlo.
     *
     * @param int|string $id Id del mensaje borrador.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function discard_ai_draft($id)
    {
        $message = SupportMessage::with('ticket')->findOrFail($id);

        if (! (bool) $message->is_ai_suggestion_draft) {
            return response()->json(['error' => 'Ese mensaje no es un borrador del agente.'], 422);
        }

        $ticket = $message->ticket;

        if ($ticket !== null) {
            // clear_ticket_pending_state() ya borra los borradores del ticket además de
            // limpiar el pendiente: llamar antes a delete_drafts_for_ticket() era una query
            // repetida.
            (new SupportAiSuggestionDraftService())->clear_ticket_pending_state($ticket);
        } else {
            (new SupportAiSuggestionDraftService())->delete_drafts_for_ticket((int) $message->support_ticket_id);
        }

        return response()->json(['ok' => true], 200);
    }

    /**
     * Marca lectura de mensaje desde admin-spa y la sincroniza al cliente.
     */
    public function mark_read($id, SupportClientSyncService $sync_service)
    {
        $message = SupportMessage::with('ticket')->findOrFail($id);
        $message->read_at = now();
        $message->save();

        // En un ticket de WhatsApp el cliente no tiene chat del ERP donde ver la lectura:
        // sincronizar sería un POST con dos reintentos y 15s de timeout contra una API que
        // ni siquiera conoce este ticket, por cada mensaje que el operador abre.
        if (! $this->ticket_is_whatsapp($message->ticket)) {
            $sync_service->sync_read_to_client($message);
        }

        return response()->json(['ok' => true], 200);
    }

    /**
     * Indica si el ticket viaja por WhatsApp y por lo tanto no se sincroniza al ERP.
     *
     * Un ticket nulo se trata como ERP: es el comportamiento que había antes de esta guarda.
     *
     * @param SupportTicket|null $ticket Ticket del mensaje.
     *
     * @return bool
     */
    private function ticket_is_whatsapp($ticket): bool
    {
        return $ticket !== null && $ticket->source === 'whatsapp';
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

        // Mismo motivo que en mark_read(), pero peor: acá el POST salía por cada tecla.
        if (! $this->ticket_is_whatsapp($ticket)) {
            $sync_service->sync_typing_to_client($ticket);
        }

        return response()->json(['ok' => true], 200);
    }
}


<?php

namespace App\Http\Controllers\Api;

use App\Events\SupportMessageReceived;
use App\Events\SupportMessageStatusUpdated;
use App\Http\Controllers\CommonLaravel\BaseController;
use App\Models\Admin;
use App\Models\SupportMessage;
use App\Models\SupportMessageAttachment;
use App\Models\SupportTicket;
use App\Models\SupportTypingState;
use App\Services\SignedAttachmentResponder;
use App\Services\SupportAiSuggestionDeliveryService;
use App\Services\SupportAiSuggestionDraftService;
use App\Services\SupportAttachmentFileResolver;
use App\Services\SupportAttachmentPlaybackService;
use App\Services\SupportClientSyncService;
use App\Services\SupportOutboundMediaService;
use App\Services\SupportWhatsappOpenerService;
use App\Services\WhatsappSendService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SupportMessageController extends BaseController
{
    /**
     * Crea mensaje desde admin-spa y lo entrega según el canal del ticket (ERP o WhatsApp).
     */
    public function store(Request $request, $ticket_id, SupportClientSyncService $sync_service)
    {
        // Ticket destino donde se agregará el mensaje.
        $ticket = SupportTicket::findOrFail($ticket_id);

        // El identificador pertenece al borrador, no al intento HTTP: una respuesta perdida no debe
        // crear ni enviar una segunda nota. Se acepta antes del cierre del ticket y de validar el archivo
        // nuevamente, porque ese mismo audio ya puede estar guardado de forma duradera.
        $request->validate([
            'client_message_id' => ['nullable', 'string', 'min:8', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/'],
        ]);
        $client_message_id = $request->input('client_message_id');
        $admin_id = (int) Auth::id();
        if ($client_message_id !== null) {
            $existing = SupportMessage::where('support_ticket_id', $ticket->id)
                ->where('sender_admin_id', $admin_id)
                ->where('client_message_id', $client_message_id)->withAll()->first();
            if ($existing !== null) {
                return response()->json(['model' => $existing], 200);
            }
        }

        // Evita escribir sobre tickets cerrados.
        if ($ticket->status !== 'open') {
            return response()->json(['error' => 'ticket closed'], 422);
        }

        // Tipo de contenido recibido desde frontend (en WhatsApp se corrige abajo con el que sale de los bytes).
        $kind = $request->input('kind', 'text');

        if ($kind === 'audio' && (! $request->hasFile('attachment') || (int) $request->file('attachment')->getSize() === 0)) {
            return response()->json(['error' => 'El audio no llegó completo. Conservá el borrador y volvé a intentar.'], 422);
        }

        // Adjunto ya analizado (kind real, mime y extensión coherentes), o null si no hay archivo.
        $prepared_attachment = null;

        // Se valida el archivo ANTES de crear nada y antes de pausar al agente: un audio que WhatsApp no va
        // a aceptar se rechaza acá con un mensaje claro, en vez de dejar un mensaje "enviado" que Meta descarta.
        if ($request->hasFile('attachment')) {
            $prepared_attachment = app(SupportOutboundMediaService::class)->prepare(
                $request->file('attachment'),
                (string) $kind,
                $ticket->source === 'whatsapp'
            );

            if ($prepared_attachment['error'] !== null) {
                return response()->json(['error' => $prepared_attachment['error']], 422);
            }

            // El kind real sale del contenido (el SPA puede mandar `text` con un audio adentro).
            $kind = $prepared_attachment['kind'];
        }

        // Cuerpo textual del mensaje.
        $body = $request->input('body');
        if (is_string($body)) {
            $body = trim($body);
        }

        $stored_path = null;
        $created = false;
        try {
            $message = DB::transaction(function () use ($ticket, $kind, $body, $request, $prepared_attachment, $client_message_id, $admin_id, &$stored_path, &$created) {
                $locked_ticket = SupportTicket::where('id', $ticket->id)->lockForUpdate()->firstOrFail();
                // El lock del ticket serializa dos subidas simultáneas del mismo borrador; el índice
                // único también protege a cualquier consumidor que inserte sin pasar por este método.
                if ($client_message_id !== null) {
                    $existing = SupportMessage::where('support_ticket_id', $ticket->id)
                        ->where('sender_admin_id', $admin_id)
                        ->where('client_message_id', $client_message_id)->first();
                    if ($existing !== null) {
                        return $existing;
                    }
                }
                abort_if($locked_ticket->status !== 'open', 422, 'ticket closed');
                app(\App\Services\SupportCodexService::class)->pause_for_human($locked_ticket);
                (new SupportAiSuggestionDraftService())->clear_ticket_pending_state($locked_ticket);
                $attributes = [
                    'support_ticket_id' => $locked_ticket->id,
                    'sender_type' => 'admin',
                    'sender_admin_id' => $admin_id,
                    'kind' => $kind,
                    'body' => $body,
                    'delivered_at' => now(),
                    // Si el proceso muere después de guardar y antes de entregar, queda visible y
                    // reintentable en el hilo, nunca como una entrega confirmada al cliente.
                    'remote_delivery_status' => 'not_received',
                ];
                if ($client_message_id !== null) {
                    $attributes['client_message_id'] = $client_message_id;
                }
                $message = SupportMessage::create($attributes);

                if ($prepared_attachment !== null) {
                    $attachment_file = $request->file('attachment');
                    $directory = 'support_messages/' . $ticket->id;
                    $stored_name = 'out_' . now()->format('Ymd_His') . '_' . bin2hex(random_bytes(4))
                        . '.' . $prepared_attachment['extension'];
                    $stored_path = $directory . '/' . $stored_name;
                    $disk = Storage::disk('public');
                    $written_path = $disk->putFileAs($directory, $attachment_file, $stored_name);
                    if (! is_string($written_path) || $written_path !== $stored_path || ! $disk->exists($stored_path)
                        || (int) $disk->size($stored_path) !== (int) $attachment_file->getSize()) {
                        throw new \RuntimeException('No se pudo guardar el adjunto completo. El audio sigue en tu borrador; volvé a intentar.');
                    }
                    SupportMessageAttachment::create([
                        'support_message_id' => $message->id,
                        'disk' => 'public',
                        'path' => $stored_path,
                        'mime' => $prepared_attachment['mime'],
                        'size' => $attachment_file->getSize(),
                    ]);
                }
                $created = true;

                return $message;
            });
        } catch (\Throwable $exception) {
            // El archivo no es transaccional. Sólo se limpia si la base confirma que no quedó
            // referenciado: ante una caída de DB es preferible un huérfano a borrar un audio válido.
            if ($stored_path !== null) {
                try {
                    if (! SupportMessageAttachment::where('path', $stored_path)->exists()) {
                        Storage::disk('public')->delete($stored_path);
                    }
                } catch (\Throwable $cleanup_exception) {
                    Log::warning('No se pudo limpiar un adjunto de soporte sin confirmar.', ['path' => $stored_path]);
                }
            }
            throw $exception;
        }

        if (! $created) {
            return response()->json(['model' => $message->load('ticket', 'attachments', 'sender_admin')], 200);
        }

        $message = SupportMessage::where('id', $message->id)->withAll()->first();

        $this->deliver_saved_admin_message($ticket, $message, $sync_service);

        $message = SupportMessage::where('id', $message->id)->withAll()->first();

        // Realtime para operadores en admin-spa (no replica hacia empresa-api).
        event(new SupportMessageReceived($message->id));

        return response()->json(['model' => $message], 201);
    }

    /**
     * Sirve el archivo de un adjunto de soporte por URL firmada (imagen, audio, video o documento).
     *
     * La política de mime/disposición/cabeceras/Range vive en {@see SignedAttachmentResponder}, compartida con
     * los adjuntos de leads; acá queda la resolución de la ruta y el tramo `playable=1` (solo de soporte).
     *
     * Reemplaza al `/storage/<path>` directo del SPA, que dependía del symlink y dejaba el Content-Type
     * en manos del servidor web por extensión (un `.bin` salía como octet-stream). Acá:
     *  - Responde con BinaryFileResponse (`response()->file()`), que soporta `Range` y contesta 206:
     *    iOS Safari exige rangos para reproducir audio y video. NO se usa `Storage::response()` porque
     *    en Laravel 8 devuelve un StreamedResponse sin rangos.
     *  - El Content-Type es el mime guardado SIN parámetros (`audio/ogg; codecs=opus` → `audio/ogg`).
     *  - Inline solo para image/* (menos svg), audio/*, video/* y application/pdf. Todo lo demás
     *    (html, svg, js…) se fuerza como descarga: el archivo lo manda un tercero y, servido inline,
     *    correría desde el origen del admin.
     *  - `?disposition=attachment` fuerza la descarga; `?playable=1` en un audio que Safari no
     *    reproduce (ogg/webm/amr) sirve la copia `.m4a` si hay ffmpeg, o el original si no.
     *  - 🔴 El disco es SIEMPRE `public`: la columna `attachments.disk` la puede escribir el cliente
     *    (metadata remota del ERP) y servir lo que ella diga dejaba leer `storage/app/`. Además el
     *    archivo real tiene que quedar adentro de la raíz del disco `public` (si no, 404). Ver
     *    {@see SupportAttachmentFileResolver}.
     *
     * @param Request    $request Petición con la firma en la query (la valida el middleware `signed`).
     * @param int|string $id      Id de support_message_attachments.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function serve_attachment_file(Request $request, $id)
    {
        // Defensa extra: la ruta ya tiene el middleware `signed`, pero el archivo es de un tercero.
        if (! $request->hasValidSignature()) {
            abort(403, 'Enlace de adjunto inválido o expirado.');
        }

        // Adjunto pedido (404 si el id no existe).
        $attachment = SupportMessageAttachment::query()->findOrFail($id);

        // Ruta relativa dentro del disco `public` (la columna `disk` se ignora a propósito: ver el docblock).
        $relative_path = trim((string) ($attachment->path ?? ''));

        // Archivo real adentro de la raíz de `public`; ruta con `..`, absoluta, directorio o fuera de la raíz: 404.
        $absolute_path = app(SupportAttachmentFileResolver::class)->resolve($relative_path);
        if ($absolute_path === null) {
            abort(404, 'Adjunto no encontrado.');
        }

        // Servicio compartido con los adjuntos de leads: arma el mime definitivo y la respuesta.
        $responder = app(SignedAttachmentResponder::class);

        // Nombre con el que se baja el archivo.
        $download_name = basename($relative_path);

        // Mime definitivo (guardado normalizado, deducido de los bytes u octet-stream) y si obliga a descargar.
        list($mime, $force_download) = $responder->resolve_mime($absolute_path, (string) ($attachment->mime ?? ''));

        // Audio que Safari no reproduce: si piden la versión reproducible y hay ffmpeg, se sirve la copia .m4a.
        $wants_playable = (string) $request->query('playable', '') === '1';
        if ($wants_playable) {
            $playback_service = app(SupportAttachmentPlaybackService::class);
            if ($playback_service->needs_transcoding($mime, $relative_path)) {
                $playable_path = $playback_service->playable_absolute_path($attachment, $absolute_path);
                if ($playable_path !== null) {
                    $absolute_path = $playable_path;
                    $mime = 'audio/mp4';
                    $download_name = pathinfo($download_name, PATHINFO_FILENAME) . '.m4a';
                }
            }
        }

        // Disposición, cabeceras de seguridad, Range/206 y caché: política compartida (SignedAttachmentResponder).
        return $responder->build_response($request, $absolute_path, $mime, $force_download, $download_name);
    }

    /**
     * Serializa entrega inicial y reintento sin mantener una transacción de base durante HTTP.
     * El store de archivo se elige explícitamente: un cache array por proceso no impediría dos
     * envíos de PHP-FPM. El plazo supera los timeouts del proveedor y el lock se libera en finally.
     * Una respuesta incierta del proveedor sigue sin poder ofrecer exactamente una entrega externa.
     */
    private function deliver_saved_admin_message(SupportTicket $ticket, SupportMessage $message, SupportClientSyncService $sync_service): void
    {
        $lock = Cache::store('file')->lock('support-outbound-' . $message->id, 300);
        if (! $lock->get()) {
            return;
        }
        try {
            $message->refresh();
            if ($ticket->source === 'whatsapp') {
                if (empty($message->whatsapp_message_id)) {
                    $this->deliver_admin_message_via_whatsapp($ticket, $message);
                }
            } elseif ($ticket->source === 'erp' && $message->synced_to_client_at === null) {
                $sync_ok = $sync_service->sync_message_to_client($message);
                $message->remote_delivery_status = $sync_ok ? null : 'not_received';
                $message->save();
            }
        } finally {
            $lock->release();
        }
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
        $codex = \App\Models\SupportCodexOperation::where('message_id', $id)->first();
        if ($codex !== null) {
            $request->validate(['body' => 'sometimes|string|max:3500']);
            $result = app(\App\Services\SupportCodexService::class)->approve_draft(
                (int) $id, $request->has('body') ? (string) $request->input('body') : null, (int) Auth::id()
            );
            $sent = $result['operation']['status'] === 'sent';
            return response()->json([
                'model' => SupportMessage::whereKey($id)->withAll()->first(),
                'delivered' => $sent,
                'operation' => $result['operation'],
                'error' => $sent ? null : $result['operation']['detail'],
            ], $sent ? 200 : 409);
        }
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
        $codex = \App\Models\SupportCodexOperation::where('message_id', $id)->first();
        if ($codex !== null) {
            app(\App\Services\SupportCodexService::class)->discard_draft((int) $id);
            return response()->json(['ok' => true]);
        }
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
     * Pone, cambia o quita la reacción con emoji del operador sobre un mensaje que mandó el cliente.
     *
     * Contrato con admin-spa: `POST support-message/{id}/reaction` con `{ "emoji": "👍" }`; un
     * `emoji` vacío o `null` QUITA la reacción (el campo tiene que venir: sin él es 422, para que
     * un body mal armado no borre una reacción en silencio).
     *
     * Solo se puede reaccionar a mensajes `sender_type = 'user'` con `whatsapp_message_id` de un
     * ticket `source = 'whatsapp'` con `whatsapp_phone`. Cualquier otro caso es **422** con
     * `{ "error": "<motivo en español>" }`.
     *
     * 🔴 Si Meta no acepta el envío (ventana de 24 h cerrada, mensaje de más de 30 días, etc.) es
     * **422** y **no se guarda nada**: una reacción pintada en la burbuja dice que el cliente la
     * vio, y si no salió, no la vio. Al quitar pasa lo mismo: si Meta rechaza el quitado, la
     * reacción sigue puesta en el teléfono del cliente y el panel tiene que seguir mostrándola.
     *
     * Una reacción propia NO crea un `SupportMessage`, no toca `last_message`, ni el `updated_at`
     * del ticket, ni dispara al agente: es solo un atributo del mensaje reaccionado. Las otras
     * pestañas se enteran por {@see SupportMessageStatusUpdated}.
     *
     * Usa `skip_failure_notification = true`: el aviso por WhatsApp a los admins está throttleado
     * globalmente (1 cada 10 minutos) y un operador probando la paleta en un hilo frío se comería
     * ese cupo; quien apretó ya ve el error en la respuesta.
     *
     * @param Request             $request       Trae `emoji` ('' o null = quitar).
     * @param int|string          $id            Id del SupportMessage al que se reacciona.
     * @param WhatsappSendService $send_service  Envío saliente vía Kapso.
     *
     * @return \Illuminate\Http\JsonResponse 200 `{ model }` | 422 `{ error }`.
     */
    public function reaction(Request $request, $id, WhatsappSendService $send_service)
    {
        // El campo tiene que venir (puede venir null o ''); si viene con algo, tiene que ser string.
        $request->validate([
            'emoji' => ['present', 'nullable', 'string', 'max:32'],
        ]);

        // Mensaje reaccionado con su ticket (404 si no existe).
        $message = SupportMessage::with('ticket')->findOrFail($id);
        $ticket = $message->ticket;

        // Solo los mensajes del cliente que vinieron por WhatsApp tienen a qué engancharse.
        if ($message->sender_type !== 'user') {
            return $this->reaction_error('Solo se puede reaccionar a los mensajes que mandó el cliente.');
        }
        if ($ticket === null || $ticket->source !== 'whatsapp' || trim((string) $ticket->whatsapp_phone) === '') {
            return $this->reaction_error('Solo se puede reaccionar en conversaciones de WhatsApp: este ticket no es de WhatsApp.');
        }
        // wamid del mensaje del cliente al que se reacciona (lo pide Meta como objetivo).
        $target_wamid = trim((string) $message->whatsapp_message_id);
        if ($target_wamid === '') {
            return $this->reaction_error('Este mensaje no tiene el id de WhatsApp, así que no se le puede reaccionar.');
        }

        // Emoji pedido, ya sin espacios. Vacío = quitar.
        $raw_emoji = trim((string) $request->input('emoji'));

        if ($raw_emoji === '') {
            return $this->remove_reaction($message, $ticket, $target_wamid, $send_service);
        }

        // Forma canónica del emoji (la que sale a Meta), o null si no está en la paleta.
        $emoji = $this->resolve_reaction_emoji($raw_emoji);
        if ($emoji === null) {
            return $this->reaction_error('Ese emoji no está en la paleta de reacciones rápidas.');
        }

        // Meta no entrega una reacción cuyo mensaje objetivo tenga más de 30 días, pero acepta el
        // POST y avisa recién después por webhook: se corta acá para no pintar algo que no llega.
        $target_date = $message->delivered_at !== null ? $message->delivered_at : $message->created_at;
        if ($target_date !== null && $target_date->lt(now()->subDays(30))) {
            return $this->reaction_error('WhatsApp no permite reaccionar a mensajes de más de 30 días: el cliente no vería la reacción.');
        }

        // wamid simulado de una prueba local con el modo de prueba ya apagado: no existe en WhatsApp.
        if (strncmp($target_wamid, 'test-', 5) === 0) {
            $whatsapp_config = \App\Models\WhatsappConfig::getActive();
            $in_test_mode = $whatsapp_config && $whatsapp_config->is_active && $whatsapp_config->test_mode;
            if (! $in_test_mode) {
                return $this->reaction_error('Ese mensaje se recibió con el modo de prueba activo: su id no existe en WhatsApp.');
            }
        }

        // wamid de la reacción que devuelve Meta, o null si no salió.
        $reaction_wamid = null;
        try {
            $reaction_wamid = $send_service->send_reaction(
                (string) $ticket->whatsapp_phone,
                $target_wamid,
                $emoji,
                'Reacción del panel - Ticket #' . $ticket->id,
                true
            );
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('SupportMessageController@reaction: excepción de WhatsApp.', [
                'support_message_id' => $message->id,
                'error'              => $exception->getMessage(),
            ]);

            return $this->reaction_error($this->describe_reaction_failure($exception->getMessage(), $send_service->last_send_status_code, false));
        }

        // Si no salió, no se guarda nada. El detalle crudo va al log; a la pantalla, el motivo en español.
        if ($reaction_wamid === null) {
            $detail = trim((string) $send_service->last_send_error);
            Log::channel('daily')->error('SupportMessageController@reaction: la reacción no salió.', [
                'support_message_id' => $message->id,
                'status_code'        => $send_service->last_send_status_code,
                'detalle'            => $detail,
            ]);

            return $this->reaction_error($this->describe_reaction_failure($detail, $send_service->last_send_status_code, false));
        }

        // Se guarda con update() sobre el mensaje: toca su propio updated_at pero NO el del ticket.
        $message->update([
            'admin_reaction_emoji'               => $emoji,
            'admin_reaction_at'                  => now(),
            'admin_reaction_admin_id'            => (int) Auth::id(),
            'admin_reaction_whatsapp_message_id' => $reaction_wamid,
        ]);

        // Avisa a las otras pestañas/operadores.
        SupportMessageStatusUpdated::dispatch((int) $message->id);

        return response()->json(['model' => SupportMessage::where('id', $message->id)->withAll()->first()], 200);
    }

    /**
     * Quita la reacción que el operador había dejado sobre un mensaje del cliente.
     *
     * - Sin reacción guardada: 200 sin hacer nada (el endpoint es idempotente).
     * - Reacción simulada (wamid `test-…` o sin wamid): nunca llegó al cliente, limpieza local.
     * - Reacción real: se le pide a Meta el quitado (emoji vacío). Si lo rechaza NO se limpia nada.
     *
     * @param SupportMessage      $message      Mensaje del cliente con la reacción.
     * @param SupportTicket       $ticket       Ticket de WhatsApp del mensaje.
     * @param string              $target_wamid wamid del mensaje del cliente.
     * @param WhatsappSendService $send_service Envío saliente vía Kapso.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    private function remove_reaction(SupportMessage $message, SupportTicket $ticket, string $target_wamid, WhatsappSendService $send_service)
    {
        // Nada que quitar: se devuelve el modelo igual para que el SPA tenga qué commitear.
        if (trim((string) $message->admin_reaction_emoji) === '') {
            return response()->json(['model' => SupportMessage::where('id', $message->id)->withAll()->first()], 200);
        }

        // wamid de la reacción puesta; vacío o 'test-…' = simulada.
        $reaction_wamid = trim((string) $message->admin_reaction_whatsapp_message_id);
        $is_simulated = ($reaction_wamid === '' || strncmp($reaction_wamid, 'test-', 5) === 0);

        if (! $is_simulated) {
            try {
                $removed_wamid = $send_service->send_reaction(
                    (string) $ticket->whatsapp_phone,
                    $target_wamid,
                    '',
                    'Quitar reacción del panel - Ticket #' . $ticket->id,
                    true
                );
            } catch (\Throwable $exception) {
                Log::channel('daily')->error('SupportMessageController@reaction: excepción al quitar la reacción.', [
                    'support_message_id' => $message->id,
                    'error'              => $exception->getMessage(),
                ]);

                return $this->reaction_error($this->describe_reaction_failure($exception->getMessage(), $send_service->last_send_status_code, true));
            }

            // Meta lo rechazó: las columnas quedan como están, a propósito.
            if ($removed_wamid === null) {
                $detail = trim((string) $send_service->last_send_error);
                Log::channel('daily')->error('SupportMessageController@reaction: el quitado no salió.', [
                    'support_message_id' => $message->id,
                    'status_code'        => $send_service->last_send_status_code,
                    'detalle'            => $detail,
                ]);

                return $this->reaction_error($this->describe_reaction_failure($detail, $send_service->last_send_status_code, true));
            }
        }

        $message->update([
            'admin_reaction_emoji'               => null,
            'admin_reaction_at'                  => null,
            'admin_reaction_admin_id'            => null,
            'admin_reaction_whatsapp_message_id' => null,
        ]);

        SupportMessageStatusUpdated::dispatch((int) $message->id);

        return response()->json(['model' => SupportMessage::where('id', $message->id)->withAll()->first()], 200);
    }

    /**
     * Respuesta 422 con el formato del contrato: `{ "error": "<motivo legible>" }`.
     *
     * @param string $reason Motivo en español, listo para mostrarle al operador.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    private function reaction_error(string $reason)
    {
        return response()->json(['error' => $reason], 422);
    }

    /**
     * Traduce el fallo de una reacción al mensaje que ve el operador, en español.
     *
     * El detalle crudo de Guzzle es inglés técnico y truncado; se loguea aparte. El status HTTP
     * sirve para no diagnosticar la ventana de 24 h sobre un fallo que es otra cosa (Meta la
     * rechaza con un 400).
     *
     * @param string   $detail      Detalle crudo (last_send_error o mensaje de la excepción).
     * @param int|null $status_code last_send_status_code del servicio, si se pudo capturar.
     * @param bool     $removing    true si lo que falló era el quitado de una reacción ya puesta.
     *
     * @return string
     */
    private function describe_reaction_failure(string $detail, $status_code, bool $removing): string
    {
        // Detalle en minúsculas para buscar las marcas de la ventana cerrada.
        $normalized = strtolower($detail);
        $status = $status_code === null ? null : (int) $status_code;
        $closed_window = ($status === null || $status === 400) && (
            strpos($normalized, '131047') !== false
            || strpos($normalized, '24 hours') !== false
            || strpos($normalized, '24 horas') !== false
        );

        if ($closed_window) {
            return $removing
                ? 'El cliente no escribe hace más de 24 horas, así que WhatsApp no deja quitarle la reacción: sigue puesta en su teléfono hasta que vuelva a escribir.'
                : 'El cliente no escribe hace más de 24 horas y WhatsApp no deja mandarle nada nuevo hasta que vuelva a escribir.';
        }

        return $removing
            ? 'No se pudo quitar la reacción del WhatsApp del cliente: sigue puesta en su teléfono. Probá de nuevo en un rato.'
            : 'No se pudo enviar la reacción por WhatsApp. El detalle quedó en el log; probá de nuevo en un rato.';
    }

    /**
     * Valida un emoji contra la paleta del panel y devuelve su forma canónica.
     *
     * Se compara sin el selector de variación (U+FE0F) de los dos lados: el ❤️ viaja del navegador
     * con o sin él según cómo se escribió el literal. Lo que sale a Meta es siempre la forma
     * canónica de {@see SupportMessage::REACCIONES_DEL_PANEL}, nunca lo que mandó el cliente.
     *
     * @param string $emoji Emoji tal como llegó en el request.
     *
     * @return string|null Forma canónica, o null si no está en la paleta.
     */
    private function resolve_reaction_emoji(string $emoji): ?string
    {
        $received = str_replace("\u{FE0F}", '', $emoji);
        if ($received === '') {
            return null;
        }

        foreach (SupportMessage::REACCIONES_DEL_PANEL as $allowed) {
            if (str_replace("\u{FE0F}", '', $allowed) === $received) {
                return $allowed;
            }
        }

        return null;
    }

    /**
     * Marca leídos VARIOS mensajes de una sola pasada.
     *
     * 🔴 Se AGREGA, no reemplaza: mark_read() sigue existiendo y funcionando igual, y admin-spa
     * lo mantiene como camino de respaldo. El motivo es que al entrar a un ticket con 40 mensajes
     * sin leer, el SPA hacía 40 POST — 40 requests, cada uno con su login de sesión, su
     * findOrFail y su UPDATE.
     *
     * Lo que se ahorra son las consultas y los requests, NO la sincronización al ERP: esa sigue
     * siendo un POST por mensaje, igual que en el de a uno, porque el endpoint de empresa-api es
     * por mensaje (manda message_uuid) y no existe una variante en lote del otro lado. En un
     * ticket de WhatsApp —el caso que motivó esto— no se sincroniza nada, así que quedan dos
     * consultas y listo.
     *
     * Por eso el tope de 500 ids es una validación y no un recorte silencioso: si alguna vez
     * llega un lote más grande, tiene que fallar con 422 y que el SPA caiga al camino de a uno,
     * no marcar 500 y dejar el resto sin leer sin que nadie se entere.
     *
     * @param Request                  $request      Trae `ids`: array de ids de SupportMessage.
     * @param SupportClientSyncService $sync_service Servicio HTTP hacia el ERP del cliente.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function mark_read_bulk(Request $request, SupportClientSyncService $sync_service)
    {
        $validated = $request->validate([
            'ids'   => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['required', 'integer'],
        ]);

        // Ids normalizados y sin repetidos: el SPA puede mandar el mismo dos veces.
        $ids = array_values(array_unique(array_map('intval', $validated['ids'])));

        // Los que existen de verdad. A diferencia del de a uno, un id inexistente no tira 404:
        // en un lote eso dejaría sin marcar a los otros 39 por culpa de uno solo.
        $messages = SupportMessage::with('ticket')->whereIn('id', $ids)->get();

        if ($messages->isEmpty()) {
            return response()->json(['ok' => true, 'marked' => 0], 200);
        }

        // Mismo instante para todo el lote: es una sola acción del operador (abrir el ticket).
        $read_at = now();

        // Un solo UPDATE. Eloquent le suma updated_at solo, igual que haría cada save().
        SupportMessage::whereIn('id', $messages->pluck('id')->all())
            ->update(['read_at' => $read_at]);

        foreach ($messages as $message) {
            // El modelo en memoria tiene que llevar el read_at nuevo: es el valor que
            // sync_read_to_client() le manda al ERP.
            $message->read_at = $read_at;

            // Misma guarda que en mark_read(): en un ticket de WhatsApp el cliente no tiene chat
            // del ERP donde ver la lectura, así que sincronizar sería un POST al pedo.
            if (! $this->ticket_is_whatsapp($message->ticket)) {
                $sync_service->sync_read_to_client($message);
            }
        }

        return response()->json(['ok' => true, 'marked' => $messages->count()], 200);
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

        if ($ticket !== null) {
            $this->deliver_saved_admin_message($ticket, $message, $sync_service);
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


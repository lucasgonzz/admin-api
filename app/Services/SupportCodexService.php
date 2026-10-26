<?php

namespace App\Services;

use App\Events\SupportMessageReceived;
use App\Events\SupportTicketUpdated;
use App\Models\Implementation;
use App\Models\ImplementationStage;
use App\Models\SupportCodexCase;
use App\Models\SupportCodexOperation;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Reservas y envíos del asistente técnico. La base, y no el worker local, decide qué sale. */
class SupportCodexService
{
    const LEASE_MINUTES = 15;

    /**
     * Cuántos tickets ANTERIORES del cliente viajan en `previous_tickets` y en `history` (misión
     * `hitos-de-implementacion-por-soporte`, 26/10/2026). Cinco alcanzan para saber dónde quedó una
     * implementación o un problema recurrente sin cargarle a Martín el archivo entero del cliente.
     */
    const TICKETS_ANTERIORES = 5;

    /** Cuántos mensajes reales (los últimos) de cada ticket anterior devuelve `history`. */
    const MENSAJES_POR_TICKET_ANTERIOR = 30;

    public function pending(): array
    {
        $settings = SupportCodexSettings::to_array();
        $items = [];
        if ($settings['enabled'] && $settings['activated_at'] !== null) {
            $tickets = SupportTicket::where('status', 'open')->where('source', 'whatsapp')
                ->where('last_client_message_at', '>=', $settings['activated_at'])
                ->with('client', 'client_employee')->orderBy('last_client_message_at')->get();
            foreach ($tickets as $ticket) {
                if (! SupportCodexSettings::manages($ticket)) {
                    continue;
                }
                $case = SupportCodexCase::where('support_ticket_id', $ticket->id)->first();
                if ($case !== null && $case->lease_expires_at !== null && $case->lease_expires_at->isFuture()) {
                    continue;
                }
                $reason = $this->pending_reason($ticket, $case);
                if ($reason === null) {
                    continue;
                }
                $last = $this->last_real_message($ticket->id);
                $items[] = [
                    'id' => (int) $ticket->id,
                    'client_id' => (int) $ticket->client_id,
                    'client_name' => $ticket->client ? $ticket->client->resolve_display_name() : 'Cliente',
                    'contact_name' => $ticket->resolve_contact_display_name(),
                    'expected_message_id' => (int) $last->id,
                    'thread_id' => $case ? $case->thread_id : null,
                    'reason' => $reason,
                    'state' => $case ? $case->state : 'ready',
                ];
                if (count($items) >= 50) {
                    break;
                }
            }
        }
        return array_merge($settings, ['tickets' => $items, 'server_time' => now()->toIso8601String()]);
    }

    public function claim(int $id, array $input): array
    {
        return DB::transaction(function () use ($id, $input) {
            $ticket = SupportTicket::lockForUpdate()->findOrFail($id);
            $this->assert_managed($ticket);
            $case = SupportCodexCase::firstOrCreate(['support_ticket_id' => $id]);
            $case = SupportCodexCase::where('id', $case->id)->lockForUpdate()->first();
            $token = $input['lease_token'] ?? null;
            $renewing = $token !== null && $case->worker_id === $input['worker_id']
                && $case->lease_hash !== null && hash_equals($case->lease_hash, hash('sha256', $token));
            if (! $renewing && $case->lease_expires_at !== null && $case->lease_expires_at->isFuture()) {
                $this->fail(423, 'lease_busy', 'Otro proceso está atendiendo este ticket.');
            }
            if ($this->pending_reason($ticket, $case) === null) {
                $this->fail(409, 'not_pending', 'El ticket ya fue atendido o necesita revisión humana.');
            }
            $last = $this->last_real_message($id);
            if ($last === null || (int) $last->id !== (int) $input['expected_message_id']) {
                $this->fail(409, 'stale_message', 'La conversación cambió; volvé a leerla.');
            }
            $token = $renewing ? $token : Str::random(64);
            if ($case->state === 'manual_review') {
                // pending_reason solo admite acá una pausa por respuesta humana y un entrante
                // posterior. El turno viejo sigue inválido; esta atención usa una reserva nueva.
                $case->state = trim((string) $case->mission) !== '' ? 'mission_pending' : 'ready';
                $case->review_reason = null;
                $case->confirmation_due_at = null;
            }
            $case->worker_id = $input['worker_id'];
            $case->lease_hash = hash('sha256', $token);
            $case->lease_expires_at = now()->addMinutes(self::LEASE_MINUTES);
            $case->expected_message_id = (int) $last->id;
            if (! empty($input['thread_id'])) {
                $this->bind_thread($case, $input['thread_id']);
            }
            $case->save();
            // Invalida borradores ya encolados del proveedor anterior.
            app(SupportAiSuggestionDraftService::class)->clear_ticket_pending_state($ticket);
            return [
                'lease_token' => $token,
                'lease_expires_at' => $case->lease_expires_at->toIso8601String(),
                'ticket' => $this->context($ticket, $case),
            ];
        });
    }

    public function context(SupportTicket $ticket, SupportCodexCase $case = null): array
    {
        $ticket->loadMissing('client', 'client_employee');
        $case = $case ?: SupportCodexCase::where('support_ticket_id', $ticket->id)->first();
        $messages = SupportMessage::where('support_ticket_id', $ticket->id);
        SupportMessage::apply_mensaje_real_conditions($messages, 'support_messages');
        $messages = $messages->with('attachments')->orderByDesc('id')->limit(100)->get()->reverse()->values();
        $last = $messages->last();
        return [
            'id' => (int) $ticket->id,
            'status' => $ticket->status,
            'name' => $ticket->name,
            'source' => $ticket->source,
            'client' => [
                'id' => (int) $ticket->client_id,
                'name' => $ticket->client ? $ticket->client->resolve_display_name() : 'Cliente',
                'api_url' => $ticket->client ? $ticket->client->api_url : null,
                // El programa local de Martín ubica la bitácora de la implementación por slug cuando no hay estado.json.
                'slug' => $ticket->client ? $ticket->client->slug : null,
            ],
            'contact_name' => $ticket->resolve_contact_display_name(),
            'contact_role' => $ticket->client_employee_id ? 'employee' : 'owner',
            'client_employee_id' => $ticket->client_employee_id ? (int) $ticket->client_employee_id : null,
            'can_query_system' => $ticket->client !== null && SistemaQueryService::client_employee_can_query($ticket->client, $ticket->client_employee),
            'state' => $case ? $case->state : 'ready',
            'thread_id' => $case ? $case->thread_id : null,
            'mission' => $case ? $case->mission : null,
            'mission_lifecycle' => $case ? $case->mission_lifecycle() : null,
            'review_reason' => $case ? $case->review_reason : null,
            'reason' => $this->pending_reason($ticket, $case),
            // El helper debe verificar esta capacidad ANTES de pedir el modo nuevo: un admin
            // anterior ignoraría el campo y ejecutaría el resume que consume la consulta.
            'capabilities' => ['continue_pending' => true, 'mission_started' => true],
            'expected_message_id' => $last ? (int) $last->id : null,
            'window' => app(WhatsappSessionWindowService::class)->window_state((string) $ticket->whatsapp_phone),
            'client_context' => app(SupportClientContextService::class)->bloque_para_el_prompt($ticket),
            /* La implementación del sistema y de la tienda (abierta, o cerrada hace ≤ 30 días), para que
             * Martín siga la conversación de la implementación donde la dejó Lucas. null = no tiene. */
            'implementation' => app(SupportImplementationContextService::class)->bloque_para_el_ticket($ticket),
            /* Los tickets anteriores del cliente, resumidos (hasta 5, sin el actual; de un empleado, solo los
             * suyos), para que Martín sepa que existen y los pida con GET .../history si le hacen falta. */
            'previous_tickets' => $this->previous_tickets($ticket),
            'messages' => $messages->map(function ($message) use ($ticket) {
                return [
                    'id' => (int) $message->id,
                    'sender_type' => $message->sender_type,
                    'body' => $this->cuerpo_para_el_monitor($message),
                    'kind' => $message->kind,
                    'created_at' => $message->created_at ? $message->created_at->toIso8601String() : null,
                    'attachments' => $message->attachments->map(function ($attachment) use ($ticket) {
                        return [
                            'id' => (int) $attachment->id,
                            'mime' => $attachment->mime,
                            'size' => $attachment->size,
                            'download_path' => '/api/claude/support-codex/tickets/' . $ticket->id . '/attachments/' . $attachment->id,
                        ];
                    })->all(),
                ];
            })->all(),
        ];
    }

    /**
     * Los tickets anteriores del cliente, resumidos, para el bloque `previous_tickets` del contexto:
     * `[{id, status, opened_at, closed_at, mensajes}]`, donde `mensajes` es cuántos mensajes reales tiene
     * (sin los borradores del agente).
     *
     * Misma regla que `history()` (ver `consulta_de_tickets_anteriores()`): hasta 5, el más nuevo primero, sin
     * el actual, en cualquier estado; si el contacto del ticket es un empleado, solo los de ese empleado.
     *
     * @param SupportTicket $ticket El ticket que se está atendiendo.
     *
     * @return array<int, array<string, mixed>>
     */
    public function previous_tickets(SupportTicket $ticket): array
    {
        $consulta = $this->consulta_de_tickets_anteriores($ticket);
        if ($consulta === null) {
            return [];
        }

        $tickets = $consulta->withCount(['messages as mensajes_reales' => function ($mensajes) {
            SupportMessage::apply_mensaje_real_conditions($mensajes, 'support_messages');
        }])->get();

        return $tickets->map(function (SupportTicket $anterior) {
            return [
                'id' => (int) $anterior->id,
                'status' => $anterior->status,
                'opened_at' => $anterior->opened_at ? $anterior->opened_at->toIso8601String() : null,
                'closed_at' => $anterior->closed_at ? $anterior->closed_at->toIso8601String() : null,
                'mensajes' => (int) $anterior->mensajes_reales,
            ];
        })->values()->all();
    }

    /**
     * Los tickets anteriores del cliente con su conversación, para `GET support-codex/tickets/{id}/history`.
     *
     * Hasta 5 tickets (el más nuevo primero, sin el actual, en cualquier estado), cada uno con su contacto y
     * sus últimos 30 mensajes REALES en orden cronológico (sin los borradores del agente), con los adjuntos.
     * 🔴 El `download_path` de un adjunto apunta a la ruta de adjuntos del TICKET ANTERIOR
     * (`tickets/{anterior}/attachments/{aid}`), que ya valida que el adjunto sea de ese ticket: no hace falta
     * otra ruta ni aflojar la que hay.
     *
     * Es solo lectura y no pide reserva: no cambia nada del caso ni del ticket.
     *
     * @param SupportTicket $ticket El ticket que se está atendiendo.
     *
     * @return array<string, mixed> `{ticket_id, client_id, contact_role, tickets:[...]}`.
     */
    public function history(SupportTicket $ticket): array
    {
        $consulta = $this->consulta_de_tickets_anteriores($ticket);
        $tickets = $consulta === null ? collect() : $consulta->with('client', 'client_employee')->get();

        return [
            'ticket_id' => (int) $ticket->id,
            'client_id' => $ticket->client_id ? (int) $ticket->client_id : null,
            'contact_role' => $ticket->client_employee_id ? 'employee' : 'owner',
            'tickets' => $tickets->map(function (SupportTicket $anterior) {
                $mensajes = SupportMessage::where('support_ticket_id', $anterior->id);
                SupportMessage::apply_mensaje_real_conditions($mensajes, 'support_messages');
                $mensajes = $mensajes->with('attachments')->orderByDesc('id')
                    ->limit(self::MENSAJES_POR_TICKET_ANTERIOR)->get()->reverse()->values();

                return [
                    'id' => (int) $anterior->id,
                    'status' => $anterior->status,
                    'opened_at' => $anterior->opened_at ? $anterior->opened_at->toIso8601String() : null,
                    'closed_at' => $anterior->closed_at ? $anterior->closed_at->toIso8601String() : null,
                    'contact_name' => $anterior->resolve_contact_display_name(),
                    'contact_role' => $anterior->client_employee_id ? 'employee' : 'owner',
                    'messages' => $mensajes->map(function (SupportMessage $mensaje) use ($anterior) {
                        return [
                            'id' => (int) $mensaje->id,
                            'sender_type' => $mensaje->sender_type,
                            'body' => $this->cuerpo_para_el_monitor($mensaje),
                            'kind' => $mensaje->kind,
                            'created_at' => $mensaje->created_at ? $mensaje->created_at->toIso8601String() : null,
                            'attachments' => $mensaje->attachments->map(function ($adjunto) use ($anterior) {
                                return [
                                    'id' => (int) $adjunto->id,
                                    'mime' => $adjunto->mime,
                                    'size' => $adjunto->size,
                                    'download_path' => '/api/claude/support-codex/tickets/' . $anterior->id . '/attachments/' . $adjunto->id,
                                ];
                            })->all(),
                        ];
                    })->all(),
                ];
            })->values()->all(),
        ];
    }

    /**
     * La consulta de los tickets anteriores de un ticket: los del mismo `client_id` con id MENOR que el actual (los
     * que se abrieron antes; uno más nuevo no es «anterior» y un ticket viejo que se relee no tiene por qué ver lo
     * que vino después), el más nuevo primero (por id: es el orden de creación), hasta `TICKETS_ANTERIORES`, en
     * cualquier estado y de cualquier canal.
     *
     * 🔴 EMPLEADOS. Si el contacto del ticket es un empleado (`client_employee_id`), solo los tickets de ESE
     * empleado: lo que el dueño u otro empleado hablaron con soporte no es de él. Si es el dueño, todos los del
     * cliente (los de sus empleados incluidos).
     *
     * Un ticket sin cliente no tiene anteriores (null): con `client_id` vacío la consulta juntaría los tickets
     * sueltos de todos los clientes.
     *
     * @param SupportTicket $ticket El ticket actual.
     *
     * @return \Illuminate\Database\Eloquent\Builder|null
     */
    private function consulta_de_tickets_anteriores(SupportTicket $ticket)
    {
        if (empty($ticket->client_id)) {
            return null;
        }

        $consulta = SupportTicket::where('client_id', (int) $ticket->client_id)->where('id', '<', (int) $ticket->id);

        if ($ticket->client_employee_id) {
            $consulta->where('client_employee_id', (int) $ticket->client_employee_id);
        }

        return $consulta->orderByDesc('id')->limit(self::TICKETS_ANTERIORES);
    }

    /**
     * El cuerpo de un mensaje tal como lo lee el monitor: a los del EQUIPO (`sender_type: admin`) se les oculta lo
     * que sigue a «contraseña» / «clave» (`[oculta]`), con la misma regla que el contexto de la implementación
     * (`SupportImplementationContextService::ocultar_claves()`). Los del cliente van tal cual.
     *
     * 🔴 Solo esa regla, no la de los números: en un ticket de soporte un número largo es un código de barras, un
     * comprobante o un precio, y taparlo dejaría a Martín sin con qué trabajar. La contraseña, en cambio, la puso
     * el equipo al entregar el acceso y Martín no la necesita para nada.
     *
     * @param SupportMessage $mensaje El mensaje.
     *
     * @return string|null
     */
    private function cuerpo_para_el_monitor(SupportMessage $mensaje)
    {
        if ($mensaje->sender_type !== 'admin') {
            return $mensaje->body;
        }

        return SupportImplementationContextService::ocultar_claves($mensaje->body);
    }

    /**
     * Si hay algo esperando a Martín en el ticket (`inbound`, `follow_up` o `mission_completed`), o null.
     *
     * Envoltorio público mínimo de `pending_reason()` (misión `hitos-de-implementacion-por-soporte`, 26/10/2026):
     * el envío de los hitos de la implementación lo mira para no tapar con un mensaje del equipo un entrante que
     * Martín todavía no contestó. No cambia nada.
     *
     * @param SupportTicket          $ticket El ticket.
     * @param SupportCodexCase|null  $case   Su caso, si ya lo tiene cargado (si no, se busca).
     *
     * @return string|null
     */
    public function motivo_pendiente(SupportTicket $ticket, SupportCodexCase $case = null): ?string
    {
        $case = $case ?: SupportCodexCase::where('support_ticket_id', $ticket->id)->first();

        return $this->pending_reason($ticket, $case);
    }

    /**
     * Martín deja asentada una nota en la implementación del cliente del ticket (`POST tickets/{id}/implementation-note`,
     * misión `hitos-de-implementacion-por-soporte`, 26/10/2026): «el cliente mandó la lista de precios», «quedó en
     * mandar las fotos el lunes».
     *
     * Solo AGREGA a `data.actions[]` de la etapa `{action: 'nota', stage, at, canal: 'whatsapp', origen: 'martin',
     * texto, support_ticket_id}`: no cambia el estado de ninguna etapa ni de la implementación, y no manda nada.
     *
     * Frenos: la reserva vigente del ticket (423 `invalid_lease`); una implementación del SISTEMA `in_progress` del
     * cliente del ticket (409 `sin_implementacion_abierta`; el cliente sale del ticket, nunca de un parámetro); la
     * etapa cargada (422 `sin_etapa`). Idempotente: el mismo texto de Martín, del mismo ticket, en la misma etapa,
     * devuelve la entrada que ya estaba con `registrada: false`. Todo en una transacción con el ticket, el caso y la
     * etapa bloqueados.
     *
     * @param int                  $id    Id del ticket.
     * @param array<string, mixed> $input lease_token, texto, etapa (opcional).
     *
     * @return array{registrada: bool, implementation_id: int, etapa: int, at: string}
     */
    public function implementation_note(int $id, array $input): array
    {
        return DB::transaction(function () use ($id, $input) {
            $ticket = SupportTicket::lockForUpdate()->findOrFail($id);
            $case = SupportCodexCase::where('support_ticket_id', $id)->lockForUpdate()->first();
            $this->assert_lease($case, (string) $input['lease_token']);

            $implementation = empty($ticket->client_id) ? null : Implementation::where('client_id', (int) $ticket->client_id)
                ->where('status', 'in_progress')->orderByDesc('id')->first();
            if ($implementation === null) {
                $this->fail(409, 'sin_implementacion_abierta', 'El cliente de este ticket no tiene una implementación del sistema en curso.');
            }

            $etapa = isset($input['etapa']) && $input['etapa'] !== null ? (int) $input['etapa'] : (int) $implementation->current_stage;
            $registro = ImplementationStage::where('implementation_id', $implementation->id)
                ->where('stage_number', $etapa)->lockForUpdate()->first();
            if ($registro === null) {
                $this->fail(422, 'sin_etapa', 'La implementación no tiene esa etapa cargada.');
            }

            $texto = trim((string) $input['texto']);
            $datos = is_array($registro->data) ? $registro->data : [];
            $entradas = isset($datos['actions']) && is_array($datos['actions']) ? $datos['actions'] : [];

            foreach ($entradas as $existente) {
                if (is_array($existente)
                    && ($existente['origen'] ?? null) === 'martin'
                    && ($existente['action'] ?? null) === 'nota'
                    && (int) ($existente['support_ticket_id'] ?? 0) === (int) $ticket->id
                    && (string) ($existente['texto'] ?? '') === $texto) {
                    return [
                        'registrada' => false,
                        'implementation_id' => (int) $implementation->id,
                        'etapa' => $etapa,
                        'at' => (string) ($existente['at'] ?? ''),
                    ];
                }
            }

            $entrada = [
                'action' => 'nota',
                'stage' => $etapa,
                'at' => now()->toISOString(),
                'canal' => 'whatsapp',
                'origen' => 'martin',
                'texto' => $texto,
                'support_ticket_id' => (int) $ticket->id,
            ];
            $entradas[] = $entrada;
            $datos['actions'] = $entradas;
            $registro->data = $datos;
            $registro->save();

            return [
                'registrada' => true,
                'implementation_id' => (int) $implementation->id,
                'etapa' => $etapa,
                'at' => $entrada['at'],
            ];
        });
    }

    public function reply(int $id, array $input): array
    {
        $hash = hash('sha256', json_encode([
            $id, (int) $input['expected_message_id'], $input['action'], trim($input['body']),
            $input['mission'] ?? null, $input['confirmation_message_id'] ?? null,
        ]));
        // La operación se confirma en una transacción ANTES de enviar. Si el proceso cae
        // durante el HTTP, queda sending y requiere conciliación: jamás otro intento ciego.
        $operation = DB::transaction(function () use ($id, $input, $hash) {
            $ticket = SupportTicket::lockForUpdate()->findOrFail($id);
            $old = SupportCodexOperation::where('operation_id', $input['operation_id'])->first();
            if ($old !== null) {
                if (! hash_equals($old->request_hash, $hash)) {
                    $this->fail(409, 'operation_conflict', 'La clave idempotente ya se usó con otro contenido.');
                }
                return $old;
            }
            $case = $this->assert_current($ticket, $input);
            if ($input['action'] === 'cerrar') {
                $this->assert_confirmation($ticket, $case, $input);
            }
            if ($input['action'] === 'escalar' && empty(trim((string) ($input['mission'] ?? '')))) {
                $this->fail(422, 'mission_required', 'El escalado requiere el texto de la misión preparada.');
            }
            $window = app(WhatsappSessionWindowService::class)->window_state((string) $ticket->whatsapp_phone);
            if (! $window['open']) {
                $this->fail(422, 'window_expired', 'Venció la ventana de WhatsApp; no se envía texto libre ni una plantilla improvisada.');
            }
            if (! empty($input['thread_id'])) {
                $this->bind_thread($case, $input['thread_id']);
                $case->save();
            }
            return SupportCodexOperation::create([
                'operation_id' => $input['operation_id'],
                'support_ticket_id' => $id,
                'expected_message_id' => $input['expected_message_id'],
                'action' => $input['action'],
                'request_hash' => $hash,
                'status' => 'sending',
            ]);
        });
        if (! $operation->wasRecentlyCreated) {
            return $this->operation_result($operation);
        }

        return DB::transaction(function () use ($id, $input, $operation) {
            $ticket = SupportTicket::lockForUpdate()->findOrFail($id);
            $operation = SupportCodexOperation::where('id', $operation->id)->lockForUpdate()->first();
            if ($operation->status !== 'sending') {
                return $this->operation_result($operation);
            }
            $case = $this->assert_current($ticket, $input, false);
            // sending ya existe fuera de la transacción: una caída deja un intento ambiguo.
            $input['_follow_up'] = $this->is_follow_up($case, (int) $input['expected_message_id']);
            $body = trim($input['body']);
            if ($input['action'] === 'cerrar' && mb_stripos($body, 'de nada') === false) {
                $body = 'De nada, ¡me alegra que haya quedado resuelto! ' . $body;
            }
            // Una sola pieza: no hay reintentos de partes ni demoras de separación de mensajes.
            $body = preg_replace('/^\s*---\s*$/m', '', $body);
            $message = SupportMessage::create([
                'support_ticket_id' => $id,
                'sender_type' => 'admin',
                'sender_admin_id' => null,
                'kind' => 'text',
                'body' => $body,
                'ai_generated_at' => now(),
                'delivered_at' => now(),
            ]);
            $operation->message_id = (int) $message->id;
            $result = null;
            try {
                $result = app(SupportWhatsappOpenerService::class)->deliver_follow_up($ticket, $message, 'Martín, asistente técnico');
            } catch (\Throwable $exception) {
                // No se devuelve el error crudo: puede contener encabezados/credenciales remotas.
                $message->remote_delivery_status = 'not_received';
                $message->save();
            }
            if ($result === null || ($result['delivery'] ?? '') !== 'sent') {
                $operation->status = 'unknown';
                $operation->detail = 'Entrega no confirmada. Revisá WhatsApp antes de reintentar manualmente.';
                $case->state = 'manual_review';
                $case->review_reason = $operation->detail;
            } else {
                $operation->status = 'sent';
                $case->handled_message_id = (int) $input['expected_message_id'];
                $case->last_reply_id = (int) $message->id;
                $this->apply_action($ticket, $case, $input);
            }
            $case->lease_hash = null;
            $case->lease_expires_at = null;
            $case->save();
            $operation->save();
            try {
                SupportMessageReceived::dispatch((int) $message->id);
                SupportTicketUpdated::dispatch((int) $ticket->id);
            } catch (\Throwable $exception) {
                // El canal realtime es accesorio: no revierte un mensaje que WhatsApp ya aceptó.
            }
            return $this->operation_result($operation);
        });
    }

    private function apply_action(SupportTicket $ticket, SupportCodexCase $case, array $input): void
    {
        if (! empty($input['_follow_up']) && in_array($input['action'], ['responder', 'solicitar_confirmacion'], true)) {
            $case->follow_up_sent = true;
            $case->confirmation_due_at = null;
        } elseif ($input['action'] === 'solicitar_confirmacion') {
            if ($case->state === 'mission_pending') {
                $this->fail(409, 'mission_pending', 'Una misión pendiente no puede anunciarse como resuelta.');
            }
            $case->state = 'awaiting_confirmation';
            $case->confirmation_due_at = now()->addHours(4);
            $case->follow_up_sent = false;
        } elseif ($input['action'] === 'cerrar') {
            $case->state = 'closed';
            $case->confirmation_due_at = null;
            $ticket->status = 'closed';
            $ticket->closed_at = now();
            $ticket->escalated_at = null;
            $ticket->escalation_reason = null;
            $ticket->save();
        } elseif ($input['action'] === 'escalar') {
            $mission = trim($input['mission']);
            if ((string) $case->mission !== $mission) {
                $case->mission_thread_id = null;
                $case->mission_started_at = null;
            }
            $case->state = 'mission_pending';
            $case->mission = $mission;
            $case->confirmation_due_at = null;
            $ticket->escalated_at = now();
            $ticket->escalation_reason = $case->mission_started_at !== null
                ? 'Misión iniciada en Codex; pendiente de corrección verificada.'
                : 'Misión pendiente de inicio en Codex.';
            $ticket->save();
        } elseif ($case->state !== 'mission_pending') {
            $case->state = 'ready';
            $case->confirmation_due_at = null;
        }
    }

    public function release(int $id, array $input): array
    {
        return DB::transaction(function () use ($id, $input) {
            $ticket = SupportTicket::lockForUpdate()->findOrFail($id);
            $case = SupportCodexCase::where('support_ticket_id', $id)->lockForUpdate()->first();
            $this->assert_lease($case, $input['lease_token']);
            if (! empty($input['thread_id'])) {
                $this->bind_thread($case, $input['thread_id']);
            }
            if (! empty($input['manual_review'])) {
                $case->state = 'manual_review';
                $case->review_reason = mb_substr((string) ($input['reason'] ?? 'Atención humana requerida.'), 0, 500);
            }
            $case->lease_hash = null;
            $case->lease_expires_at = null;
            $case->save();
            return ['released' => true];
        });
    }

    public function pause_for_human(SupportTicket $ticket): void
    {
        if (! SupportCodexSettings::manages($ticket)) {
            return;
        }
        DB::transaction(function () use ($ticket) {
            SupportTicket::where('id', $ticket->id)->lockForUpdate()->first();
            $case = SupportCodexCase::firstOrCreate(['support_ticket_id' => $ticket->id]);
            // Una respuesta manual no convierte una revisión por error o conciliación en una
            // pausa recuperable. Solo cedemos este turno; el próximo entrante puede volver a Martín.
            if ($case->state !== 'manual_review' || $this->is_human_reply_pause($case)) {
                $case->state = 'manual_review';
                $case->review_reason = SupportCodexCase::HUMAN_REPLY_REVIEW_REASON;
                $last = $this->last_real_message($ticket->id);
                $case->expected_message_id = $last ? (int) $last->id : null;
            }
            $case->lease_hash = null;
            $case->lease_expires_at = null;
            $case->save();
        });
    }

    /**
     * El interruptor devuelve la atención cedida a Lucas, sin revivir un turno viejo.
     * El llamador debe estar en una transacción con el ticket bloqueado, igual que claim/reply.
     */
    public function agent_toggled(SupportTicket $ticket): void
    {
        $case = SupportCodexCase::where('support_ticket_id', $ticket->id)->lockForUpdate()->first();
        if ($case === null) {
            return;
        }
        if (! $ticket->claude_auto_reply) {
            // El chequeo del flag solo no alcanza: apagado y encendido rápidos permitirían
            // que un agente anterior mande después de que Lucas retomó la conversación.
            $case->lease_hash = null;
            $case->lease_expires_at = null;
            $case->save();
            return;
        }
        if (! SupportCodexSettings::manages($ticket) || $ticket->status !== 'open'
            || $case->state !== 'manual_review'
            || $case->review_reason !== SupportCodexCase::HUMAN_REPLY_REVIEW_REASON
            || ! empty($case->mission) || $this->has_unfinished_operation($ticket->id)
            || ($case->lease_expires_at !== null && $case->lease_expires_at->isFuture())) {
            return;
        }
        $last = $this->last_real_message($ticket->id);
        if ($last === null || $last->sender_type !== 'user'
            || (int) $last->id <= (int) $case->handled_message_id) {
            return;
        }
        // No usar resume(): marca el último mensaje como atendido y perdería la consulta
        // que llegó mientras Lucas tenía el agente apagado (caso SanBlas, 26/10/2026).
        $case->state = 'ready';
        $case->review_reason = 'Lucas encendió el agente para atender la consulta pendiente.';
        $case->save();
    }

    public function assert_current(SupportTicket $ticket, array $input, bool $check_unfinished = true): SupportCodexCase
    {
        $this->assert_managed($ticket);
        $case = SupportCodexCase::where('support_ticket_id', $ticket->id)->lockForUpdate()->first();
        $this->assert_lease($case, $input['lease_token']);
        $last = $this->last_real_message($ticket->id);
        if ($last === null || (int) $last->id !== (int) $input['expected_message_id']
            || (int) $case->expected_message_id !== (int) $last->id) {
            $this->fail(409, 'stale_message', 'Cambió la conversación; se descarta la respuesta vieja.');
        }
        if (in_array($case->state, ['manual_review', 'closed'], true)
            || ($check_unfinished && $this->has_unfinished_operation($ticket->id))) {
            $this->fail(409, 'manual_review', 'La atención está pausada y requiere revisión.');
        }
        if (in_array($input['action'], ['solicitar_confirmacion', 'cerrar'], true) && $case->state === 'mission_pending') {
            $this->fail(409, 'mission_pending', 'No se puede anunciar resuelta una misión que todavía no se ejecutó.');
        }
        return $case;
    }

    /** Retomar es una acción expresa de Lucas; no vuelve a procesar mensajes viejos. */
    public function resume(int $id, array $input): array
    {
        return DB::transaction(function () use ($id, $input) {
            $ticket = SupportTicket::where('id', $id)->lockForUpdate()->findOrFail($id);
            $this->assert_managed($ticket);
            $case = SupportCodexCase::firstOrCreate(['support_ticket_id' => $id]);
            if ($case->lease_expires_at !== null && $case->lease_expires_at->isFuture()) {
                $this->fail(423, 'lease_busy', 'Hay una atención en curso; no se pisa.');
            }
            if ($this->has_unfinished_operation($id)) {
                $this->fail(409, 'delivery_unknown', 'Primero conciliá el envío ambiguo; retomar no autoriza reenviarlo.');
            }
            $last = $this->last_real_message($id);
            if ($last === null || (int) $last->id !== (int) $input['expected_message_id']) {
                $this->fail(409, 'stale_message', 'Cambió la conversación.');
            }
            if (! empty($input['continue_pending'])) {
                if (! empty($input['request_confirmation'])) {
                    $this->fail(422, 'incompatible_resume_mode', 'Continuar una consulta no anuncia una misión resuelta.');
                }
                if ($last->sender_type !== 'user' || (int) $last->id <= (int) $case->handled_message_id) {
                    $this->fail(409, 'not_pending', 'No hay una consulta nueva del cliente sin atender.');
                }
                if (! app(WhatsappSessionWindowService::class)->window_state((string) $ticket->whatsapp_phone)['open']) {
                    $this->fail(422, 'window_expired', 'Venció la ventana de WhatsApp; no se retoma el texto libre.');
                }
                // Recuperar una consulta técnica rechazada NO resuelve la misión que ya existía.
                // Tampoco consume el entrante: pending/claim deben poder ofrecérselo a Martín.
                $case->state = trim((string) $case->mission) !== '' ? 'mission_pending' : 'ready';
                $case->review_reason = mb_substr('Retomado por Lucas: ' . $input['reason'], 0, 500);
                $case->lease_hash = null;
                $case->lease_expires_at = null;
                $case->confirmation_due_at = null;
                $case->save();
                return ['ticket' => $this->context($ticket, $case)];
            }
            $case->state = ! empty($input['request_confirmation']) ? 'ready_to_confirm' : 'ready';
            $case->handled_message_id = (int) $last->id;
            $case->review_reason = mb_substr('Retomado por Lucas: ' . $input['reason'], 0, 500);
            $case->lease_hash = null;
            $case->lease_expires_at = null;
            $case->confirmation_due_at = null;
            $case->mission = null;
            $case->mission_thread_id = null;
            $case->mission_started_at = null;
            $case->save();
            $ticket->escalated_at = null;
            $ticket->escalation_reason = null;
            $ticket->save();
            return ['ticket' => $this->context($ticket, $case)];
        });
    }

    /** Registra la tarea creada por Lucas; no inicia, atiende ni resuelve el ticket. */
    public function mission_started(int $id, array $input): array
    {
        $result = DB::transaction(function () use ($id, $input) {
            $ticket = SupportTicket::where('id', $id)->lockForUpdate()->findOrFail($id);
            // Registrar una tarea ya creada no es retomar la atención: debe poder asentarse
            // aunque Lucas haya apagado al agente o pausado el proveedor mientras tanto.
            if ($ticket->status !== 'open') {
                $this->fail(409, 'disabled_or_ineligible', 'El ticket cerrado no admite registrar una misión pendiente.');
            }
            $case = SupportCodexCase::where('support_ticket_id', $id)->lockForUpdate()->first();
            if ($case === null || trim((string) $case->mission) === '') {
                $this->fail(409, 'mission_missing', 'El ticket no tiene una misión pendiente para vincular.');
            }
            if (! hash_equals(hash('sha256', (string) $case->mission), $input['mission_hash'])) {
                $this->fail(409, 'mission_changed', 'El diagnóstico cambió; volvé a leerlo antes de vincular la tarea.');
            }
            if ($case->thread_id !== null && $case->thread_id === $input['mission_thread_id']) {
                $this->fail(409, 'mission_thread_conflict', 'El hilo de atención no es la tarea de la misión.');
            }
            if ($case->mission_thread_id !== null && $case->mission_thread_id !== $input['mission_thread_id']) {
                $this->fail(409, 'mission_thread_conflict', 'La misión ya tiene otra tarea vinculada.');
            }
            if ($case->mission_started_at === null) {
                $case->mission_thread_id = $input['mission_thread_id'];
                $case->mission_started_at = now();
                $case->save();
            }
            // Es una actualización de seguimiento: no cambia manual_review ni consume la reserva.
            // El motivo humano, si lo hubiera, sigue en review_reason y convive con la misión.
            $ticket->escalation_reason = 'Misión iniciada en Codex; pendiente de corrección verificada.';
            $ticket->save();
            return ['ticket' => $this->context($ticket, $case)];
        });
        try {
            SupportTicketUpdated::dispatch($id);
        } catch (\Throwable $exception) {
            // El registro durable no se revierte si el realtime no está disponible.
        }
        return $result;
    }

    public function assert_lease(?SupportCodexCase $case, string $token): void
    {
        if ($case === null || $case->lease_hash === null || $case->lease_expires_at === null
            || ! $case->lease_expires_at->isFuture() || ! hash_equals($case->lease_hash, hash('sha256', $token))) {
            $this->fail(423, 'invalid_lease', 'La reserva venció o pertenece a otro proceso.');
        }
    }

    public function operation_result(SupportCodexOperation $operation): array
    {
        return [
            'operation' => [
                'id' => $operation->operation_id,
                'status' => in_array($operation->status, ['sent', 'reconciled_sent'], true) ? 'sent' : ($operation->status === 'reconciled_not_sent' ? 'not_sent' : 'unknown'),
                'message_id' => $operation->message_id ? (int) $operation->message_id : null,
                'action' => $operation->action,
                'detail' => $operation->detail,
                'reconciliation' => $operation->reconciled_at === null ? null : [
                    'outcome' => $operation->reconciliation_outcome,
                    'by' => $operation->reconciled_by,
                    'at' => $operation->reconciled_at,
                    'reason' => $operation->reconciliation_reason,
                    'evidence' => $operation->reconciliation_evidence,
                    'whatsapp_message_id' => $operation->reconciliation_reference,
                ],
            ],
            'ticket' => $this->context(SupportTicket::findOrFail($operation->support_ticket_id)),
        ];
    }

    /** Conciliación MANUAL: jamás reenvía ni ejecuta la transición de cierre pendiente. */
    public function reconcile(string $operation_id, array $input): array
    {
        $found = SupportCodexOperation::where('operation_id', $operation_id)->firstOrFail();
        return DB::transaction(function () use ($found, $input) {
            $ticket = SupportTicket::where('id', $found->support_ticket_id)->lockForUpdate()->firstOrFail();
            $case = SupportCodexCase::where('support_ticket_id', $ticket->id)->lockForUpdate()->first();
            $operation = SupportCodexOperation::where('id', $found->id)->lockForUpdate()->first();
            if (in_array($operation->status, ['sent', 'reconciled_sent', 'reconciled_not_sent'], true)) {
                $this->fail(409, 'already_final', 'La operación ya tiene un resultado definitivo.');
            }
            if ($case !== null && $case->lease_expires_at !== null && $case->lease_expires_at->isFuture()) {
                $this->fail(423, 'lease_busy', 'Esperá a que termine o venza la reserva antes de conciliar.');
            }
            if ($input['outcome'] === 'sent' && (empty($input['whatsapp_message_id']) || empty($input['evidence']))) {
                $this->fail(422, 'evidence_required', 'Confirmar un envío exige su ID de WhatsApp y evidencia de la verificación manual.');
            }
            $operation->status = $input['outcome'] === 'sent' ? 'reconciled_sent' : 'reconciled_not_sent';
            $operation->reconciliation_outcome = $input['outcome'];
            $operation->reconciled_at = now();
            $operation->reconciled_by = $input['confirmed_by'];
            $operation->reconciliation_reason = trim($input['reason']);
            $operation->reconciliation_evidence = $input['evidence'] ?? null;
            $operation->reconciliation_reference = $input['whatsapp_message_id'] ?? null;
            $operation->detail = 'Conciliado manualmente por Lucas; no hubo reenvío ni cierre automático.';
            $operation->save();
            if ($case !== null) {
                $case->state = 'manual_review';
                $case->review_reason = $operation->detail;
                $case->handled_message_id = max((int) $case->handled_message_id, (int) $operation->expected_message_id);
                $case->save();
            }
            if ($input['outcome'] === 'sent' && $operation->message_id !== null) {
                $message = SupportMessage::where('id', $operation->message_id)->where('support_ticket_id', $ticket->id)->first();
                if ($message !== null && empty($message->whatsapp_message_id)) {
                    $message->whatsapp_message_id = $input['whatsapp_message_id'];
                    $message->remote_delivery_status = null;
                    $message->save();
                }
            }
            return $this->operation_result($operation);
        });
    }

    private function pending_reason(SupportTicket $ticket, ?SupportCodexCase $case): ?string
    {
        if ($ticket->status !== 'open' || ($case !== null && $case->state === 'closed')
            || $this->has_unfinished_operation($ticket->id)) {
            return null;
        }
        $last = $this->last_real_message($ticket->id);
        if ($last === null) {
            return null;
        }
        if (! (bool) $ticket->claude_auto_reply) {
            return null;
        }
        if ($case !== null && $case->state === 'manual_review') {
            // Panchito (26/10): un mensaje de Lucas no debe apagar para siempre un agente
            // que sigue encendido. Las revisiones técnicas conservan su bloqueo explícito.
            return $this->is_human_reply_pause($case) && $last->sender_type === 'user'
                && (int) $last->id > max((int) $case->expected_message_id, (int) $case->handled_message_id)
                ? 'inbound' : null;
        }
        if ($case !== null && $case->state === 'ready_to_confirm'
            && (int) $last->id === (int) $case->handled_message_id
            && app(WhatsappSessionWindowService::class)->window_state((string) $ticket->whatsapp_phone)['open']) {
            return 'mission_completed';
        }
        if ($last->sender_type === 'user' && ($case === null || (int) $last->id > (int) $case->handled_message_id)) {
            return 'inbound';
        }
        if ($case !== null && (int) $last->id === (int) $case->last_reply_id
            && $this->is_follow_up($case, (int) $last->id)
            && app(WhatsappSessionWindowService::class)->window_state((string) $ticket->whatsapp_phone)['open']) {
            return 'follow_up';
        }
        return null;
    }

    private function is_human_reply_pause(SupportCodexCase $case): bool
    {
        return $case->is_human_reply_pause();
    }

    private function is_follow_up(SupportCodexCase $case, int $expected): bool
    {
        return $case->state === 'awaiting_confirmation' && ! $case->follow_up_sent
            && $case->confirmation_due_at !== null && $case->confirmation_due_at->lte(now())
            && (int) $case->last_reply_id === $expected;
    }

    private function has_unfinished_operation(int $id): bool
    {
        return SupportCodexOperation::where('support_ticket_id', $id)
            ->whereNotIn('status', ['sent', 'reconciled_sent', 'reconciled_not_sent'])->exists();
    }

    private function last_real_message(int $id): ?SupportMessage
    {
        $query = SupportMessage::where('support_ticket_id', $id);
        SupportMessage::apply_mensaje_real_conditions($query, 'support_messages');
        return $query->orderByDesc('id')->first();
    }

    private function assert_managed(SupportTicket $ticket): void
    {
        if ($ticket->status !== 'open' || ! SupportCodexSettings::manages($ticket)) {
            $this->fail(409, 'disabled_or_ineligible', 'Este ticket no está habilitado para atención automática en Codex.');
        }
        if (! (bool) $ticket->claude_auto_reply) {
            $this->fail(409, 'ticket_paused', 'Lucas apagó el agente de este ticket.');
        }
    }

    private function bind_thread(SupportCodexCase $case, string $thread): void
    {
        if ($case->thread_id !== null && $case->thread_id !== $thread) {
            $this->fail(409, 'thread_conflict', 'El ticket ya está vinculado a otra conversación de Codex.');
        }
        $case->thread_id = $thread;
    }

    private function assert_confirmation(SupportTicket $ticket, SupportCodexCase $case, array $input): void
    {
        $message = $this->last_real_message($ticket->id);
        if ($message === null || $message->sender_type !== 'user'
            || (int) ($input['confirmation_message_id'] ?? 0) !== (int) $message->id) {
            $this->fail(422, 'confirmation_required', 'El cierre requiere el último mensaje de confirmación del cliente.');
        }
        $body = Str::ascii(mb_strtolower(trim((string) $message->body)));
        $negative = strpos($body, '?') !== false
            || preg_match('/\b(no|nunca|tampoco|todavia|aun|sigue|pero|falta|error|problema|manana|luego|despues|probare|probar|voy|cuando|veremos|ojala|deberia|podria|creo|supongo|parece|quizas)\b/', $body)
            || preg_match('/\bsi (funciona|anda|queda|(?:ya )?lo (arreglamos|arregle|solucionamos|solucione))\b/', $body);
        // «Todo normal» confirma el resultado si contesta una pregunta sobre la solución,
        // pero puede ser un saludo fuera de ese contexto. No alcanza con guardar el estado:
        // se comprueba también el mensaje que originó el pedido, dentro de este mismo ticket.
        $question = $case->state === 'awaiting_confirmation' && $case->last_reply_id !== null
            ? SupportMessage::where('support_ticket_id', $ticket->id)->where('id', $case->last_reply_id)
                ->where('sender_type', 'admin')->first()
            : null;
        $question_body = $question === null ? '' : Str::ascii(mb_strtolower((string) $question->body));
        $answer_to_resolution = $question !== null && $question->id < $message->id
            && strpos($question_body, '?') !== false
            // Un sí a «¿podés probar mañana?» acepta una tarea, no confirma su resultado.
            && ! preg_match('/\b(manana|luego|despues|cuando|proximamente)\b/', $question_body)
            && (preg_match('/\b(resuelt[oa]|solucionad[oa])\b/', $question_body)
                || (! preg_match('/\b(podes|puedes) probar\b/', $question_body)
                    && preg_match('/\b(confirm[a-z]*|ya|ahora)\b.*\b(funciona|funcionando|anda|abre|abrir|trabajar|usar|vender)\b/s', $question_body)));
        // «Perfecto, lo pruebo mañana» no confirma nada. Se exige un resultado concreto
        // actual/pasado, o una respuesta breve al pedido de verificar ese resultado.
        $resolved = preg_match('/\b(ya )?(esta|quedo) (resuelt[oa]|solucionad[oa]|bien|funcionando)\b/', $body)
            || preg_match('/\b(ya|ahora) (funciona|anda|funciono)\b/', $body)
            || preg_match('/^(si[,.! ]+)?(ya )?lo (arreglamos|arregle|solucionamos|solucione)([,.! ]+(muchas )?gracias)?[!. ]*$/', $body)
            || preg_match('/^(si[,.! ]+)?(ya )?(resuelt[oa]|solucionad[oa])[,.! ]*(muchas )?gracias[!. ]*$/', $body);
        $short_answer = $answer_to_resolution
            && preg_match('/^(si[,.! ]+)?(todo (normal|bien)|si)([,.! ]+(muchas )?gracias)?[!. ]*$/', $body);
        if ($negative || (! $resolved && ! $short_answer)) {
            $this->fail(422, 'confirmation_required', 'El mensaje no confirma de forma suficiente que el caso quedó resuelto.');
        }
    }

    private function fail(int $status, string $code, string $message): void
    {
        throw new HttpException($status, $code . ': ' . $message);
    }
}

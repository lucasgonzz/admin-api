<?php

namespace App\Services;

use App\Events\SupportMessageReceived;
use App\Events\SupportTicketUpdated;
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
            ],
            'contact_name' => $ticket->resolve_contact_display_name(),
            'contact_role' => $ticket->client_employee_id ? 'employee' : 'owner',
            'client_employee_id' => $ticket->client_employee_id ? (int) $ticket->client_employee_id : null,
            'can_query_system' => $ticket->client !== null && SistemaQueryService::client_employee_can_query($ticket->client, $ticket->client_employee),
            'state' => $case ? $case->state : 'ready',
            'thread_id' => $case ? $case->thread_id : null,
            'mission' => $case ? $case->mission : null,
            'review_reason' => $case ? $case->review_reason : null,
            'reason' => $this->pending_reason($ticket, $case),
            'expected_message_id' => $last ? (int) $last->id : null,
            'window' => app(WhatsappSessionWindowService::class)->window_state((string) $ticket->whatsapp_phone),
            'client_context' => app(SupportClientContextService::class)->bloque_para_el_prompt($ticket),
            'messages' => $messages->map(function ($message) use ($ticket) {
                return [
                    'id' => (int) $message->id,
                    'sender_type' => $message->sender_type,
                    'body' => $message->body,
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
            $case->state = 'mission_pending';
            $case->mission = trim($input['mission']);
            $case->confirmation_due_at = null;
            $ticket->escalated_at = now();
            $ticket->escalation_reason = 'Misión preparada en Codex; Lucas debe iniciarla.';
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
            $case->state = 'manual_review';
            $case->review_reason = 'Lucas retomó la atención desde el admin.';
            $case->lease_hash = null;
            $case->lease_expires_at = null;
            $case->save();
        });
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
        if ($input['action'] === 'solicitar_confirmacion' && $case->state === 'mission_pending') {
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
            $case->state = ! empty($input['request_confirmation']) ? 'ready_to_confirm' : 'ready';
            $case->handled_message_id = (int) $last->id;
            $case->review_reason = mb_substr('Retomado por Lucas: ' . $input['reason'], 0, 500);
            $case->lease_hash = null;
            $case->lease_expires_at = null;
            $case->confirmation_due_at = null;
            $case->mission = null;
            $case->save();
            $ticket->escalated_at = null;
            $ticket->escalation_reason = null;
            $ticket->save();
            return ['ticket' => $this->context($ticket, $case)];
        });
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
        if ($ticket->status !== 'open' || ($case !== null && in_array($case->state, ['manual_review', 'closed'], true))
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
        $negative = preg_match('/\b(no|todavia|aun|sigue|pero|falta|error|problema|manana|luego|despues|probare|probar|voy|cuando|veremos|ojala|deberia|podria)\b/', $body)
            || preg_match('/\bsi (funciona|anda|queda)\b/', $body);
        // «Perfecto, lo pruebo mañana» no confirma nada. Se exige un resultado concreto
        // actual/pasado, o un sí explícito como respuesta a la pregunta de confirmación.
        $resolved = preg_match('/\b(ya )?(esta|quedo) (resuelto|solucionado|bien|funcionando)\b/', $body)
            || preg_match('/\b(ya|ahora) (funciona|anda|funciono)\b/', $body)
            || preg_match('/^(si[,.! ]+)?(ya )?(resuelto|solucionado)[,.! ]*(muchas )?gracias[!. ]*$/', $body);
        $thanks_after_question = $case->state === 'awaiting_confirmation'
            && preg_match('/^si([,.! ]+(muchas )?gracias)?[!. ]*$/', $body);
        if ($negative || (! $resolved && ! $thanks_after_question)) {
            $this->fail(422, 'confirmation_required', 'El mensaje no confirma de forma suficiente que el caso quedó resuelto.');
        }
    }

    private function fail(int $status, string $code, string $message): void
    {
        throw new HttpException($status, $code . ': ' . $message);
    }
}

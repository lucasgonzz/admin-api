<?php

namespace App\Services;

use App\Events\SupportMessageReceived;
use App\Events\SupportTicketUpdated;
use App\Models\AgentImplementation;
use App\Models\AgentImplementationEvent;
use App\Models\Implementation;
use App\Models\ImplementationStage;
use App\Models\SupportCodexCase;
use App\Models\SupportCodexMissionFixEvidence;
use App\Models\SupportCodexScheduledUpgrade;
use App\Models\SupportCodexUpgradeAgreement;
use App\Models\SupportCodexOperation;
use App\Models\SupportMessage;
use App\Models\SupportMessageAttachment;
use App\Models\SupportTicket;
use App\Services\Local\EscenaService;
use App\Services\Prueba\PruebaContextoSoporteService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Reservas y envíos del asistente técnico. La base, y no el worker local, decide qué sale. */
class SupportCodexService
{
    const LEASE_MINUTES = 15;

    private const CONFIRMATION_REVIEW_REASON = 'El cierre fue rechazado porque el mensaje no confirma el resultado. Revisar sin volver a pedir confirmación.';

    /**
     * Cuántos tickets ANTERIORES del cliente viajan en `previous_tickets` y en `history` (misión
     * `hitos-de-implementacion-por-soporte`, 26/10/2026). Cinco alcanzan para saber dónde quedó una
     * implementación o un problema recurrente sin cargarle a Martín el archivo entero del cliente.
     */
    const TICKETS_ANTERIORES = 5;

    /** Cuántos mensajes reales (los últimos) de cada ticket anterior devuelve `history`. */
    const MENSAJES_POR_TICKET_ANTERIOR = 30;

    /** `tipo` de `implementation-note` por defecto: la implementación del SISTEMA (el comportamiento de siempre). */
    const NOTE_TYPE_SISTEMA = 'sistema';

    /** `tipo` de `implementation-note` para la implementación de Agentes de IA (misión `implementacion-agentes-ia`). */
    const NOTE_TYPE_AGENTES = 'agentes';

    public function pending(bool $supports_upgrade_agreement = false): array
    {
        $settings = SupportCodexSettings::to_array();
        $items = [];
        if ($settings['enabled'] && $settings['activated_at'] !== null) {
            $activado = $settings['activated_at'];
            $tickets = SupportTicket::where('status', 'open')->paraCodex()
                ->where(function ($candidatos) use ($activado) {
                    $candidatos->where('last_client_message_at', '>=', $activado)
                        ->orWhere(function ($erp) use ($activado) {
                            $erp->where('source', 'erp')->whereNull('last_client_message_at')
                                ->whereHas('messages', function ($entrantes) use ($activado) {
                                    SupportCodexSettings::entrantes_desde_el_corte($entrantes, $activado);
                                });
                        });
                })
                ->with('client', 'client_employee')->orderBy('last_client_message_at')->get();
            foreach ($tickets as $ticket) {
                if (! SupportCodexSettings::manages($ticket)) {
                    continue;
                }
                $this->invalidate_stale_approval($ticket);
                $case = SupportCodexCase::where('support_ticket_id', $ticket->id)->first();
                if ($case !== null && $case->lease_expires_at !== null && $case->lease_expires_at->isFuture()) {
                    continue;
                }
                $reason = $this->pending_reason($ticket, $case, $supports_upgrade_agreement);
                if ($reason === null) {
                    continue;
                }
                $last = $this->last_real_message($ticket->id);
                $items[] = [
                    'id' => (int) $ticket->id,
                    'client_id' => (int) $ticket->client_id,
                    'canal' => $ticket->source === 'erp' ? 'sistema' : 'whatsapp',
                    'client_name' => $ticket->client ? $ticket->client->resolve_display_name() : 'Cliente',
                    'contact_name' => $ticket->es_erp_sin_identidad_verificada() ? null : $ticket->resolve_contact_display_name(),
                    'expected_message_id' => (int) $last->id,
                    'thread_id' => $case ? $case->thread_id : null,
                    'reason' => $reason,
                    'state' => $case ? $case->state : 'ready',
                    // Negocio en prueba gratuita (misión prueba-martin-importacion, 3/11/2026): el monitor
                    // solo revisa los adjuntos PDF de estos tickets para abrir la tarea de importación.
                    'prueba' => app(PruebaDelClienteService::class)->es_cliente_en_prueba($ticket->client),
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
            if ($renewing && ! empty($input['execution_id']) && $case->dispatch_execution_id !== null
                && $case->dispatch_execution_id !== $input['execution_id']) {
                $this->fail(409, 'operation_conflict', 'La renovación pertenece a otra ejecución.');
            }
            if (! $renewing && $case->lease_expires_at !== null && $case->lease_expires_at->isFuture()) {
                $this->fail(423, 'lease_busy', 'Otro proceso está atendiendo este ticket.');
            }
            $recover_dispatch = ! empty($input['dispatch_recovery']);
            if ($recover_dispatch) {
                if ($case->state !== 'manual_review'
                    || $case->review_reason !== 'Disparo de conversación sin confirmar; conciliar en Codex'
                    || (int) $case->expected_message_id !== (int) $input['expected_message_id']
                    || ($case->dispatch_execution_id !== null && $case->dispatch_execution_id !== ($input['execution_id'] ?? null))
                    || $this->has_unfinished_operation($id)) {
                    $this->fail(409, 'not_pending', 'La recuperación no corresponde a una apertura de chat sin confirmar.');
                }
                $case->state = trim((string) $case->mission) !== '' ? 'mission_pending' : 'ready';
                $case->review_reason = null;
            }
            $reason = $this->pending_reason($ticket, $case, ! empty($input['supports_upgrade_agreement']));
            if ($reason === null) {
                $this->fail(409, 'not_pending', 'El ticket ya fue atendido o necesita revisión humana.');
            }
            $last = $this->last_real_message($id);
            if ($last === null || (int) $last->id !== (int) $input['expected_message_id']) {
                $this->fail(409, 'stale_message', 'La conversación cambió; volvé a leerla.');
            }
            $cortesia_de_prueba = ! $renewing && ! $recover_dispatch && $reason === 'inbound'
                && ! empty($input['supports_trial_import_quiet']) && $this->es_cortesia_aislada($case, $last);
            $token = $renewing ? $token : Str::random(64);
            if ($case->state === 'manual_review') {
                // Una revisión pedida por el propio agente admite información nueva. Tomar el
                // turno no la resuelve: su marcador y el aviso a Lucas se conservan hasta cerrar.
                $human_reply_pause = $this->is_human_reply_pause($case);
                $case->state = trim((string) $case->mission) !== '' ? 'mission_pending' : 'ready';
                if ($human_reply_pause) {
                    $case->review_reason = null;
                }
                $case->confirmation_due_at = null;
            }
            if ($recover_dispatch) {
                $ticket->support_attention_enabled = false;
                $ticket->save();
            }
            if (! empty($input['execution_id'])) {
                $case->dispatch_execution_id = $input['execution_id'];
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
            $context = $this->context($ticket, $case);
            if ($cortesia_de_prueba && ($context['importaciones_prueba_en_proceso'] === true
                || $context['importaciones_soporte_en_proceso'] === true)) {
                // El aviso de Empresa lleva la aclaración. Consumir sólo este agradecimiento evita
                // que un turno conversacional vuelva a preguntar o cierre la importación.
                $case->handled_message_id = (int) $last->id;
                $case->lease_hash = null;
                $case->lease_expires_at = null;
                $case->save();
                return ['trial_import_quiet' => true];
            }
            return [
                'lease_token' => $token,
                'lease_expires_at' => $case->lease_expires_at->toIso8601String(),
                'ticket' => $context,
            ];
        });
    }

    private function es_cortesia_aislada(SupportCodexCase $case, SupportMessage $last): bool
    {
        if ($case->state !== 'ready' || $case->last_reply_id === null || $case->handled_message_id === null || $last->kind !== 'text'
            || $last->reply_to_message_id !== null) {
            return false;
        }
        $body = Str::ascii(mb_strtolower((string) $last->body));
        if (! preg_match('/^(?:(?:ah|ok|oka|dale|listo|perfecto|buenisimo|genial|barbaro)[,.!\s]+)*(?:(?:muchas|muchisimas|mil)\s+)?gracias[,.!\s]*$/D', $body)
            || $last->attachments()->exists()) {
            return false;
        }

        // El agradecimiento tiene que seguir al recibo del Excel. Si hubo otro entrante,
        // como «Es costo» y enseguida «Gracias», Martín tiene que leer los dos.
        $adjuntos = SupportMessageAttachment::where('support_message_id', $case->handled_message_id)
            ->whereHas('message', function ($mensaje) use ($last) {
                $mensaje->where('support_ticket_id', $last->support_ticket_id)->where('sender_type', 'user');
            })->get(['mime', 'path']);
        return $adjuntos->contains(function (SupportMessageAttachment $adjunto) {
            return isset(PruebaImportacionService::EXCEL[PruebaImportacionService::extension_del_adjunto($adjunto)]);
        }) && ! SupportMessage::where('support_ticket_id', $last->support_ticket_id)->where('sender_type', 'user')
            ->where('id', '>', $case->handled_message_id)->where('id', '<', $last->id)->exists();
    }

    public function context(SupportTicket $ticket, SupportCodexCase $case = null): array
    {
        $ticket->loadMissing('client', 'client_employee');
        $case = $case ?: SupportCodexCase::where('support_ticket_id', $ticket->id)->first();
        $messages = SupportMessage::where('support_ticket_id', $ticket->id);
        SupportMessage::apply_mensaje_real_conditions($messages, 'support_messages');
        $messages = $messages->with('attachments')->orderByDesc('id')->limit(100)->get()->reverse()->values();
        $last = $messages->last();
        $messages = $messages->filter(function (SupportMessage $mensaje) use ($ticket) {
            return $ticket->mensaje_confiable_para_codex($mensaje);
        })->values();
        if ($last !== null && $last->is_trial_import_notice) {
            $last = $this->last_real_message($ticket->id);
        }
        $fix_evidence = SupportCodexMissionFixEvidence::current_for($ticket, $case);
        $importador = app(PruebaImportacionService::class);
        $importaciones = $importador->estado_en_ticket($ticket);
        $soporte_activo = $importador->puerta_activo($ticket) === null;
        return [
            'id' => (int) $ticket->id,
            'status' => $ticket->status,
            'name' => $ticket->es_erp_sin_identidad_verificada() ? null : $ticket->name,
            'source' => $ticket->source,
            'canal' => $ticket->source === 'erp' ? 'sistema' : 'whatsapp',
            'client' => [
                'id' => (int) $ticket->client_id,
                'name' => $ticket->client ? $ticket->client->resolve_display_name() : 'Cliente',
                'api_url' => $ticket->client ? $ticket->client->api_url : null,
                // El programa local de Martín ubica la bitácora de la implementación por slug cuando no hay estado.json.
                'slug' => $ticket->client ? $ticket->client->slug : null,
                // `prueba` si el negocio está en prueba gratuita (misión prueba-martin-importacion, 3/11/2026); si no, null.
                'estado' => app(PruebaDelClienteService::class)->es_cliente_en_prueba($ticket->client) ? (string) $ticket->client->estado : null,
            ],
            'contact_name' => $ticket->es_erp_sin_identidad_verificada() ? null : $ticket->resolve_contact_display_name(),
            'contact_role' => $ticket->es_contacto_empleado() ? 'employee' : 'owner',
            'client_employee_id' => $ticket->client_employee_id ? (int) $ticket->client_employee_id : null,
            'can_query_system' => $ticket->contacto_puede_consultar(),
            'state' => $case ? $case->state : 'ready',
            'thread_id' => $case ? $case->thread_id : null,
            'mission' => $case ? $case->mission : null,
            'mission_lifecycle' => $case ? $case->mission_lifecycle() : null,
            'mission_fix_evidence' => $fix_evidence === null ? null : $fix_evidence->payload,
            'upgrade_agreement' => app(SupportCodexUpgradeAgreementService::class)->context($fix_evidence),
            'review_reason' => $case ? $case->review_reason : null,
            'reason' => $this->pending_reason($ticket, $case, true),
            // El helper debe verificar esta capacidad ANTES de pedir el modo nuevo: un admin
            // anterior ignoraría el campo y ejecutaría el resume que consume la consulta.
            'capabilities' => ['continue_pending' => true, 'mission_started' => true, 'feature_handoff_closure' => true, 'human_review_action' => true, 'human_approval' => true, 'recover_dispatch' => true, 'natural_thanks_closure' => true, 'outbound_attachments' => true, 'upgrade_window' => true, 'mission_fix_evidence' => true, 'upgrade_agreement' => true, 'scheduled_upgrade' => SupportScheduledUpgradeService::enabled(), 'verification_fallback' => SupportScheduledUpgradeService::enabled(), 'prueba_import' => true, 'prueba_import_resolver' => true,
                'soporte_import' => $soporte_activo, 'soporte_import_resolver' => $soporte_activo,
                'support_tour_consent' => true, 'support_dynamic_tour' => true],
            'requires_verification' => (bool) $ticket->requiere_verificacion_mensajes,
            'expected_message_id' => $last ? (int) $last->id : null,
            'robotito_available' => SupportTourService::entrante_admite_robotito($ticket, $last),
            'accepted_tour' => $last && ! $ticket->requiere_verificacion_mensajes
                ? app(SupportTourService::class)->guion_aceptado($ticket, $last) : null,
            'accepted_tour_request' => $last && ! $ticket->requiere_verificacion_mensajes
                && app(SupportTourService::class)->solicitud_aceptada($ticket, $last) !== null,
            'window' => $ticket->source === 'erp'
                ? ['open' => null, 'last_inbound_at' => null, 'expires_at' => null, 'origin' => null]
                : app(WhatsappSessionWindowService::class)->window_state((string) $ticket->whatsapp_phone),
            'client_context' => app(SupportClientContextService::class)->bloque_para_el_prompt($ticket),
            /* La prueba gratuita del cliente (misión prueba-alta-instancia, 3/11/2026): estado, vencimiento, días
             * restantes y los hitos `prueba.*` recibidos por C2. Solo datos; el protocolo de Martín para un negocio en
             * prueba lo escribe M4. null = el cliente no es una prueba. M4 (prueba-martin-importacion) le suma sus
             * claves (día actual, próximo paso, hitos en vivo por C3, bienvenida) por
             * `PruebaContextoSoporteService::extender()`, registrada en `SupportPruebaContextService::registrar()`. */
            'prueba' => app(PruebaContextoSoporteService::class)->bloque_para_el_cliente($ticket->client),
            'importaciones_prueba_pendientes' => $soporte_activo ? [] : $importaciones['pendientes'],
            'importaciones_prueba_en_proceso' => $soporte_activo ? false : $importaciones['en_proceso'],
            'importaciones_soporte_pendientes' => $soporte_activo ? $importaciones['pendientes'] : [],
            'importaciones_soporte_en_proceso' => $soporte_activo ? $importaciones['en_proceso'] : false,
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
                    'is_trial_import_notice' => (bool) $message->is_trial_import_notice,
                    'reply_to_message_id' => $message->reply_to_message_id === null ? null : (int) $message->reply_to_message_id,
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
            'contact_role' => $ticket->es_contacto_empleado() ? 'employee' : 'owner',
            'tickets' => $tickets->map(function (SupportTicket $anterior) {
                $mensajes = SupportMessage::where('support_ticket_id', $anterior->id);
                SupportMessage::apply_mensaje_real_conditions($mensajes, 'support_messages');
                $mensajes = $mensajes->with('attachments')->orderByDesc('id')
                    ->limit(self::MENSAJES_POR_TICKET_ANTERIOR)->get()->filter(function (SupportMessage $mensaje) use ($anterior) {
                        return $anterior->mensaje_confiable_para_codex($mensaje);
                    })->reverse()->values();

                return [
                    'id' => (int) $anterior->id,
                    'status' => $anterior->status,
                    'opened_at' => $anterior->opened_at ? $anterior->opened_at->toIso8601String() : null,
                    'closed_at' => $anterior->closed_at ? $anterior->closed_at->toIso8601String() : null,
                    'contact_name' => $anterior->es_erp_sin_identidad_verificada() ? null : $anterior->resolve_contact_display_name(),
                    'contact_role' => $anterior->es_contacto_empleado() ? 'employee' : 'owner',
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
        if (empty($ticket->client_id) || $ticket->es_erp_sin_identidad_verificada()) {
            return null;
        }

        $consulta = SupportTicket::where('client_id', (int) $ticket->client_id)->where('id', '<', (int) $ticket->id);

        if ($ticket->client_employee_id) {
            $consulta->where('client_employee_id', (int) $ticket->client_employee_id);
        } elseif ($ticket->es_contacto_empleado()) {
            $consulta->where('source', 'erp')->where('client_user_id', (int) $ticket->client_user_id);
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
     * Con `tipo: agentes` (misión `implementacion-agentes-ia`, 28/10/2026) la nota va a la implementación de Agentes
     * de IA: ver `agent_implementation_note()`. Sin `tipo` (o con `sistema`) el camino es exactamente el de siempre.
     *
     * @param int                  $id    Id del ticket.
     * @param array<string, mixed> $input lease_token, texto, etapa (opcional), tipo (opcional).
     *
     * @return array{registrada: bool, implementation_id: int, etapa: int, at: string}
     */
    public function implementation_note(int $id, array $input): array
    {
        if (isset($input['tipo']) && $input['tipo'] === self::NOTE_TYPE_AGENTES) {
            return $this->agent_implementation_note($id, $input);
        }

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

    /**
     * La nota de Martín en la implementación de AGENTES DE IA del cliente del ticket (`implementation-note` con
     * `tipo: agentes`, misión `implementacion-agentes-ia`, 28/10/2026).
     *
     * Agrega un evento `{kind: nota, source: martin}` a la bitácora de la implementación de agentes ABIERTA del
     * cliente del ticket, en la etapa pedida (1..8) o en la actual. No cambia estados ni manda nada.
     *
     * Frenos, los mismos que la del sistema: la reserva vigente del ticket (423 `invalid_lease`); una implementación
     * de agentes `in_progress` del cliente del ticket (409 `sin_implementacion_abierta`, que `soporte.py` traduce a
     * `registrada: false` con el motivo, igual que en el sistema; el cliente sale del ticket, nunca de un
     * parámetro). Idempotente: el mismo texto de Martín en la misma etapa devuelve el evento que ya estaba con
     * `registrada: false` (la bitácora no guarda el ticket, así que la clave es texto + etapa + origen).
     *
     * @param int                  $id    Id del ticket.
     * @param array<string, mixed> $input lease_token, texto, etapa (opcional), tipo (`agentes`).
     *
     * @return array{registrada: bool, tipo: string, implementation_id: int, etapa: int, at: string}
     */
    private function agent_implementation_note(int $id, array $input): array
    {
        return DB::transaction(function () use ($id, $input) {
            $ticket = SupportTicket::lockForUpdate()->findOrFail($id);
            $case = SupportCodexCase::where('support_ticket_id', $id)->lockForUpdate()->first();
            $this->assert_lease($case, (string) $input['lease_token']);

            $implementation = empty($ticket->client_id) ? null : AgentImplementation::where('client_id', (int) $ticket->client_id)
                ->where('status', AgentImplementation::STATUS_IN_PROGRESS)->orderByDesc('id')->lockForUpdate()->first();
            if ($implementation === null) {
                $this->fail(409, 'sin_implementacion_abierta', 'El cliente de este ticket no tiene una implementación de Agentes de IA en curso.');
            }

            $etapa = isset($input['etapa']) && $input['etapa'] !== null ? (int) $input['etapa'] : (int) $implementation->current_stage;
            $texto = trim((string) $input['texto']);

            $existente = AgentImplementationEvent::where('agent_implementation_id', $implementation->id)
                ->where('kind', AgentImplementationEvent::KIND_NOTA)
                ->where('source', AgentImplementationEvent::SOURCE_MARTIN)
                ->where('stage_number', $etapa)
                ->where('texto', AgentImplementationEvent::fit_texto($texto))
                ->orderBy('id')
                ->first();
            if ($existente !== null) {
                return [
                    'registrada' => false,
                    'tipo' => self::NOTE_TYPE_AGENTES,
                    'implementation_id' => (int) $implementation->id,
                    'etapa' => $etapa,
                    'at' => $existente->created_at === null ? '' : $existente->created_at->toISOString(),
                ];
            }

            $evento = app(AgentImplementationService::class)->add_event(
                $implementation,
                $texto,
                $etapa,
                AgentImplementationEvent::KIND_NOTA,
                AgentImplementationEvent::SOURCE_MARTIN
            );

            return [
                'registrada' => true,
                'tipo' => self::NOTE_TYPE_AGENTES,
                'implementation_id' => (int) $implementation->id,
                'etapa' => $etapa,
                'at' => $evento->created_at === null ? now()->toISOString() : $evento->created_at->toISOString(),
            ];
        });
    }

    public function reply(int $id, array $input): array
    {
        $tour_requested = (bool) ($input['tour_requested'] ?? false);
        $tour_import_history_id = isset($input['tour_import_history_id']) ? (int) $input['tour_import_history_id'] : null;
        if ($tour_import_history_id !== null && (! $tour_requested || $tour_import_history_id < 1
            || $tour_import_history_id > SupportTourService::MAX_IMPORT_HISTORY_ID)) {
            $this->fail(422, 'tour_invitation_invalid', 'La referencia de importación requiere una invitación a Robotito.');
        }
        $input['tour_import_history_id'] = $tour_import_history_id;
        $tour_invitation = null;
        if (isset($input['tour_invitation'])) {
            $tour_invitation = SupportTourService::guion($input['tour_invitation']);
            if ($tour_invitation === null || $tour_invitation['tema'] === SupportTourService::TEMA_DINAMICO || $tour_requested) {
                $this->fail(422, 'tour_invitation_invalid', 'El recorrido de la invitación no es válido.');
            }
            $input['tour_invitation'] = $tour_invitation;
        }
        if (($tour_requested || $tour_invitation !== null) && (! SupportTourService::tiene_invitacion($input['body'])
            || SupportTourService::quitar_invitacion($input['body']) === ''
            || $input['action'] !== 'responder' || isset($input['attachment']) || isset($input['upgrade_proposal']))) {
            $this->fail(422, 'tour_invitation_invalid', 'Terminá el mensaje de texto con la pregunta de Robotito.');
        }
        $input['tour_requested'] = $tour_requested;
        $attachment = null;
        if (isset($input['attachment'])) {
            if ($input['action'] === 'cerrar' || mb_strlen(trim($input['body'])) > SupportCodexOutboundAttachment::MAX_CAPTION_LENGTH) {
                $this->fail(422, 'invalid_attachment_caption', 'Usá hasta 1024 caracteres y una acción distinta del cierre para enviar un adjunto.');
            }
            $attachment = app(SupportCodexOutboundAttachment::class)->prepare($input['attachment']);
            unset($input['attachment']);
        }
        $hash_payload = [
            $id, (int) $input['expected_message_id'], $input['action'], trim($input['body']),
            $input['mission'] ?? null, $input['confirmation_message_id'] ?? null,
        ];
        // Se conserva el hash anterior de las acciones existentes para sus replays tras el deploy.
        if ($input['action'] === 'pedir_revision') {
            $hash_payload[] = $input['review_reason'] ?? null;
        }
        if ($input['action'] === 'escalar' && isset($input['close_on_mission_start'])) {
            $hash_payload[] = (bool) $input['close_on_mission_start'];
        }
        if ($attachment !== null) {
            $hash_payload[] = [$attachment['filename'], hash('sha256', $attachment['bytes'])];
        }
        $proposal = $input['upgrade_proposal'] ?? null;
        if ($proposal !== null) {
            $hash_payload[] = $proposal;
        }
        if ($tour_invitation !== null) {
            $hash_payload[] = $tour_invitation;
        }
        if ($tour_requested) {
            $hash_payload[] = ['tour_requested' => true];
            if ($tour_import_history_id !== null) {
                $hash_payload[] = ['tour_import_history_id' => $tour_import_history_id];
            }
        }
        $hash = hash('sha256', json_encode($hash_payload));
        // La operación se confirma en una transacción ANTES de enviar. Si el proceso cae
        // durante el HTTP, queda sending y requiere conciliación: jamás otro intento ciego.
        $operation = DB::transaction(function () use ($id, $input, $hash, $attachment, $proposal) {
            $ticket = SupportTicket::lockForUpdate()->findOrFail($id);
            $old = SupportCodexOperation::where('operation_id', $input['operation_id'])->first();
            if ($old !== null && $old->status !== 'cancelled') {
                if (! hash_equals($old->request_hash, $hash)) {
                    $this->fail(409, 'operation_conflict', 'La clave idempotente ya se usó con otro contenido.');
                }
                return $old;
            }
            if ($old !== null && ((int) $old->support_ticket_id !== $id || (int) $old->expected_message_id !== (int) $input['expected_message_id'])) {
                $this->fail(409, 'operation_conflict', 'La operación cancelada corresponde a otro ticket o mensaje.');
            }
            if ($old !== null && $proposal === null && SupportCodexUpgradeAgreement::where('support_codex_operation_id', $old->id)->exists()) {
                $this->fail(409, 'operation_conflict', 'La operación cancelada pertenece a una propuesta de actualización.');
            }
            $case = $this->assert_current($ticket, $input);
            $evidence = $proposal === null ? null : app(SupportCodexUpgradeAgreementService::class)->assert_valid_proposal($ticket, $case, $proposal);
            if ($ticket->requiere_verificacion_mensajes && (isset($input['tour_invitation']) || $input['tour_requested'])) {
                $this->fail(422, 'verification_required', 'Este ticket exige aprobación. Prepará la respuesta escrita sin invitar a Robotito.');
            }
            if ($ticket->requiere_verificacion_mensajes && empty($input['supports_human_approval'])) {
                $this->fail(422, 'verification_required', 'Este ticket exige aprobación. Actualizá el monitor para preparar el borrador.');
            }
            if (($input['tour_requested'] || isset($input['tour_invitation']))
                && ! SupportTourService::entrante_admite_robotito($ticket, SupportMessage::find($input['expected_message_id']))) {
                $this->fail(422, 'tour_target_unavailable', 'Este mensaje llegó desde una sesión del sistema sin Robotito disponible.');
            }
            $id_importacion = $input['tour_invitation']['import_history_id'] ?? null;
            if ($id_importacion !== null && ! app(SupportTourService::class)->objetivo_disponible(
                $ticket, SupportMessage::findOrFail($input['expected_message_id']), $id_importacion
            )) {
                $this->fail(422, 'tour_target_unavailable', 'El sistema no confirmó que esa persona pueda encontrar la importación en el historial.');
            }
            if ($input['action'] === 'cerrar') {
                $confirmation = $this->confirmation_message($ticket, $input);
                if (! $this->confirms_resolution($ticket, $case, $confirmation)) {
                    if (! empty($input['thread_id'])) {
                        $this->bind_thread($case, $input['thread_id']);
                    }
                    $case->state = 'manual_review';
                    if (! $case->is_agent_requested_review()) {
                        $case->review_reason = self::CONFIRMATION_REVIEW_REASON;
                        $case->review_requested_message_id = null;
                    }
                    $case->lease_hash = null;
                    $case->lease_expires_at = null;
                    $case->save();
                    $this->request_attention($ticket);
                    return null;
                }
                $this->assert_mission_allows_action($case, $input['action']);
            } elseif ($attachment === null && $proposal === null && $this->can_close_on_reply($ticket, $case, $input)) {
                // La operación guarda la acción efectiva. El replay conserva el hash del pedido original.
                $input['action'] = 'cerrar';
                $input['confirmation_message_id'] = (int) $input['expected_message_id'];
                $input['_inferred_closure'] = true;
            }
            if ($input['action'] === 'escalar' && empty(trim((string) ($input['mission'] ?? '')))) {
                $this->fail(422, 'mission_required', 'El escalado requiere el texto de la misión preparada.');
            }
            if ($input['action'] === 'pedir_revision' && trim((string) ($input['review_reason'] ?? '')) === '') {
                $this->fail(422, 'review_reason_required', 'La revisión requiere un motivo concreto para Lucas.');
            }
            if (! $this->puede_entregar($ticket)) {
                $this->fail(422, 'window_expired', 'Venció la ventana de WhatsApp; no se envía texto libre ni una plantilla improvisada.');
            }
            if (! empty($input['thread_id'])) {
                $this->bind_thread($case, $input['thread_id']);
                $case->save();
            }
            // cancelled demuestra que jamás se tocó la red; una atención vigente puede reformular.
            // Los intentos sending/unknown/sent conservan su hash y jamás pasan por este camino.
            $operation = $old ?: new SupportCodexOperation();
            $operation->fill([
                'operation_id' => $input['operation_id'],
                'support_ticket_id' => $id,
                'expected_message_id' => $input['expected_message_id'],
                'action' => $input['action'],
                'request_hash' => $hash,
                'status' => 'sending',
                'message_id' => null, 'detail' => null, 'approval_payload' => null,
            ]);
            $operation->save();
            if ($evidence !== null) {
                app(SupportCodexUpgradeAgreementService::class)->record_proposal($operation, $evidence, $proposal, $input['body']);
            }
            $operation->wasRecentlyCreated = true;
            if ($ticket->requiere_verificacion_mensajes) {
                $this->prepare_approval($ticket, $case, $operation, $input, $attachment);
            }
            return $operation;
        });
        if ($operation === null) {
            // El freno del entrante queda confirmado antes de devolver el rechazo del cierre.
            $this->fail(422, 'confirmation_required', 'El mensaje no confirma de forma suficiente que el caso quedó resuelto.');
        }
        if (! $operation->wasRecentlyCreated || $operation->status === 'awaiting_approval') {
            return $this->operation_result($operation);
        }
        if ($operation->action !== $input['action']) {
            $input['action'] = $operation->action;
            $input['confirmation_message_id'] = (int) $operation->expected_message_id;
            $input['_inferred_closure'] = true;
        }

        return DB::transaction(function () use ($id, $input, $operation, $attachment, $proposal) {
            $ticket = SupportTicket::lockForUpdate()->findOrFail($id);
            $operation = SupportCodexOperation::where('id', $operation->id)->lockForUpdate()->first();
            if ($operation->status !== 'sending') {
                return $this->operation_result($operation);
            }
            try {
                $case = $this->assert_current($ticket, $input, false);
                if ($proposal !== null) {
                    app(SupportCodexUpgradeAgreementService::class)->assert_valid_proposal($ticket, $case, $proposal);
                }
            } catch (HttpException $exception) {
                // El envío aún no empezó: un entrante o una toma humana no son resultado incierto.
                $operation->status = 'cancelled';
                $operation->detail = $proposal !== null && strpos($exception->getMessage(), 'upgrade_window_changed:') === 0
                    ? $exception->getMessage() : 'La conversación o el agente cambió antes del envío; no se envió.';
                $operation->save();
                return $this->operation_result($operation);
            }
            // El operador pudo encender la verificación después de la primera transacción.
            if ($ticket->requiere_verificacion_mensajes) {
                if (empty($input['supports_human_approval']) || isset($input['tour_invitation']) || $input['tour_requested']) {
                    $operation->status = 'cancelled';
                    $operation->detail = 'La verificación fue activada; no se envió automáticamente.';
                    $operation->save();
                    return $this->operation_result($operation);
                }
                $this->prepare_approval($ticket, $case, $operation, $input, $attachment);
                return $this->operation_result($operation);
            }
            // sending ya existe fuera de la transacción: una caída deja un intento ambiguo.
            $input['_follow_up'] = $this->is_follow_up($case, (int) $input['expected_message_id']);
            $body = trim($input['body']);
            if ($input['action'] === 'cerrar' && mb_stripos($body, 'de nada') === false) {
                $body = 'De nada! ' . $body;
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
                'erp_auto_reply' => $input['tour_requested'] ? array_filter([
                    'tour_requested' => true, 'tour_import_history_id' => $input['tour_import_history_id'],
                ])
                    : (isset($input['tour_invitation']) ? ['tour_invitation' => $input['tour_invitation']] : null),
            ]);
            if ($attachment !== null) {
                try {
                    app(SupportCodexOutboundAttachment::class)->persist($message, $attachment);
                } catch (\Throwable $exception) {
                    $message->delete();
                    $operation->status = 'cancelled';
                    $operation->detail = 'No se pudo guardar el adjunto. No se envió ningún mensaje.';
                    $operation->save();
                    return $this->operation_result($operation);
                }
            }
            $operation->message_id = (int) $message->id;
            $result = null;
            try {
                $result = $this->entregar_respuesta($ticket, $message);
            } catch (\Throwable $exception) {
                // No se devuelve el error crudo: puede contener encabezados/credenciales remotas.
                $message->remote_delivery_status = 'not_received';
                $message->save();
            }
            if ($result === null || ($result['delivery'] ?? '') !== 'sent') {
                $operation->status = 'unknown';
                $operation->detail = $ticket->source === 'erp'
                    ? 'Entrega no confirmada. Revisá el chat del sistema antes de reintentar manualmente.'
                    : 'Entrega no confirmada. Revisá WhatsApp antes de reintentar manualmente.';
                $case->state = 'manual_review';
                $case->review_reason = $operation->detail;
                $case->review_requested_message_id = null;
                $this->request_attention($ticket);
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
                if ($attachment !== null) {
                    SupportMessageReceived::dispatch_after_commit((int) $message->id);
                } else {
                    SupportMessageReceived::dispatch((int) $message->id);
                }
                SupportTicketUpdated::dispatch((int) $ticket->id);
            } catch (\Throwable $exception) {
                // El canal realtime es accesorio: no revierte un mensaje que WhatsApp ya aceptó.
            }
            return $this->operation_result($operation);
        });
    }

    private function puede_entregar(SupportTicket $ticket): bool
    {
        return $ticket->source === 'erp'
            || app(WhatsappSessionWindowService::class)->window_state((string) $ticket->whatsapp_phone)['open'];
    }

    private function entregar_respuesta(SupportTicket $ticket, SupportMessage $message): array
    {
        if ($ticket->source === 'whatsapp') {
            return app(SupportWhatsappOpenerService::class)->deliver_follow_up($ticket, $message, 'Martín, asistente técnico');
        }

        $enviado = app(SupportClientSyncService::class)->sync_message_to_client($message);
        if (! $enviado) {
            $message->remote_delivery_status = 'not_received';
            $message->save();
        }

        return ['delivery' => $enviado ? 'sent' : 'unknown'];
    }

    private function sincronizar_cierre_en_sistema(SupportTicket $ticket): void
    {
        if ($ticket->source === 'erp') {
            DB::afterCommit(function () use ($ticket) {
                app(SupportClientSyncService::class)->sync_ticket_to_client($ticket, true);
            });
        }
    }

    private function request_attention(SupportTicket $ticket): void
    {
        $ticket->support_attention_enabled = true;
        $ticket->save();
    }

    private function prepare_approval(SupportTicket $ticket, SupportCodexCase $case, SupportCodexOperation $operation, array $input, ?array $attachment): void
    {
        if ($input['action'] === 'cerrar' && mb_stripos($input['body'], 'de nada') === false) {
            $input['body'] = 'De nada! ' . trim($input['body']);
        }
        $draft_service = app(SupportAiSuggestionDraftService::class);
        $prepare = null;
        if ($attachment !== null) {
            $prepare = function (SupportMessage $draft) use ($attachment) {
                app(SupportCodexOutboundAttachment::class)->persist($draft, $attachment);
            };
            $input['requires_attachment'] = true;
        }
        try {
            $draft = $draft_service->create_draft($ticket, trim($input['body']), 0, $prepare);
        } catch (\RuntimeException $exception) {
            if ($attachment === null) {
                throw $exception;
            }
            $draft_service->clear_ticket_pending_state($ticket);
            $operation->status = 'cancelled';
            $operation->detail = 'No se pudo preparar el adjunto del borrador. No se envió ningún mensaje.';
            $operation->save();
            return;
        }
        $operation->message_id = (int) $draft->id;
        $operation->status = 'awaiting_approval';
        // No se guarda la reserva: la aprobación es humana y revalida el mensaje actual.
        unset($input['lease_token']);
        $operation->approval_payload = $input;
        $operation->detail = 'La respuesta está lista y espera tu aprobación en este ticket.';
        $operation->save();
        $case->lease_hash = null;
        $case->lease_expires_at = null;
        $case->save();
        try {
            app(SupportAttentionNotificationService::class)->registrar((int) $ticket->id);
        } catch (\Throwable $exception) {
            // El conciliador recupera la push; nunca se pierde el borrador por la cola.
            \Illuminate\Support\Facades\Log::warning('Soporte: aviso de aprobación pendiente de recuperación.', ['ticket_id' => $ticket->id]);
        }
        SupportTicketUpdated::dispatch((int) $ticket->id);
    }

    public function invalidate_stale_approval(SupportTicket $ticket): void
    {
        $last = $this->last_real_message((int) $ticket->id);
        $pending = SupportCodexOperation::where('support_ticket_id', $ticket->id)->where('status', 'awaiting_approval')->first();
        if ($pending !== null && ($last === null || (int) $last->id !== (int) $pending->expected_message_id || $ticket->status !== 'open')) {
            $this->cancel_approvals($ticket, 'La conversación cambió; se descartó la respuesta anterior.');
        }
    }

    public function cancel_approvals(SupportTicket $ticket, string $reason): void
    {
        SupportCodexOperation::where('support_ticket_id', $ticket->id)->where('status', 'awaiting_approval')
            ->update(['status' => 'cancelled', 'detail' => $reason, 'approval_payload' => null]);
        app(SupportAiSuggestionDraftService::class)->clear_ticket_pending_state($ticket);
    }

    public function discard_draft(int $message_id): void
    {
        DB::transaction(function () use ($message_id) {
            $found = SupportCodexOperation::where('message_id', $message_id)->firstOrFail();
            $ticket = SupportTicket::whereKey($found->support_ticket_id)->lockForUpdate()->firstOrFail();
            $operation = SupportCodexOperation::whereKey($found->id)->lockForUpdate()->first();
            if ($operation->status !== 'awaiting_approval') {
                $this->fail(409, 'operation_conflict', 'Ese borrador ya fue tomado o descartado.');
            }
            $case = SupportCodexCase::where('support_ticket_id', $ticket->id)->lockForUpdate()->firstOrFail();
            // Descartar es la decisión humana sobre ese entrante, no una orden de generar lo mismo otra vez.
            $case->handled_message_id = max((int) $case->handled_message_id, (int) $operation->expected_message_id);
            $case->save();
            $this->cancel_approvals($ticket, 'Lucas descartó el borrador sin enviarlo.');
            SupportTicketUpdated::dispatch((int) $ticket->id);
        });
    }

    private function assert_approval_action(SupportTicket $ticket, SupportCodexCase $case, array $input): void
    {
        $this->assert_mission_allows_action($case, $input['action']);
        if ($input['action'] === 'cerrar') {
            $this->assert_upgrade_allows_closure($ticket, $case);
            $this->assert_importacion_cerrable($ticket);
            $this->assert_confirmation($ticket, $case, $input, empty($input['_inferred_closure']));
        }
    }

    private function assert_mission_allows_action(SupportCodexCase $case, string $action): void
    {
        if ($this->mission_blocks_action($case, $action)) {
            $this->fail(409, 'mission_pending', 'No se puede anunciar resuelta una misión que todavía no se ejecutó.');
        }
    }

    private function mission_blocks_action(SupportCodexCase $case, string $action): bool
    {
        $mission = $case->mission_lifecycle();
        return ($mission !== null || $case->state === 'mission_pending')
            && ($action === 'solicitar_confirmacion' || ($action === 'cerrar' && ($mission['status'] ?? null) !== 'started'));
    }

    private function assert_upgrade_allows_closure(SupportTicket $ticket, SupportCodexCase $case): void
    {
        if ($this->upgrade_blocks_closure($ticket, $case)) {
            $this->fail(409, 'upgrade_in_progress', 'Hay una actualización acordada o en curso; el ticket debe seguir abierto hasta que termine.');
        }
    }

    private function upgrade_blocks_closure(SupportTicket $ticket, SupportCodexCase $case): bool
    {
        $evidence = SupportCodexMissionFixEvidence::current_for($ticket, $case);
        if ($evidence === null) {
            return false;
        }
        $agreements = SupportCodexUpgradeAgreement::where('mission_fix_evidence_id', $evidence->id)->whereNotNull('accepted_at');
        return (clone $agreements)->where('ends_at_utc', '>', now()->utc()->toDateTimeString())->exists()
            || SupportCodexScheduledUpgrade::whereIn('agreement_id', (clone $agreements)->select('id'))
                ->whereIn('status', array_merge(SupportCodexScheduledUpgrade::EXECUTING, [
                    SupportCodexScheduledUpgrade::SCHEDULED, SupportCodexScheduledUpgrade::VERIFICATION_PENDING,
                ]))->exists()
            || DB::table('support_codex_release_claims')->whereIn('agreement_id', $agreements->select('id'))->whereNotNull('active_key')->exists();
    }

    private function assert_importacion_cerrable(SupportTicket $ticket): void
    {
        $en_proceso = app(PruebaImportacionService::class)->estado_en_ticket($ticket)['en_proceso'];
        if ($en_proceso !== false) {
            $mensaje = $en_proceso ? 'Hay una importación en curso en este ticket.' : 'Todavía no se pudo confirmar si la importación terminó.';
            $this->fail(409, 'trial_import_pending', $mensaje . ' Dejá abierta la conversación.');
        }
    }

    public function approve_draft(int $message_id, ?string $body, int $admin_id): array
    {
        // El primer commit reserva el único intento antes de tocar WhatsApp: dos clics no envían dos veces.
        $operation = DB::transaction(function () use ($message_id, $body, $admin_id) {
            $found = SupportCodexOperation::where('message_id', $message_id)->firstOrFail();
            $ticket = SupportTicket::whereKey($found->support_ticket_id)->lockForUpdate()->firstOrFail();
            $operation = SupportCodexOperation::whereKey($found->id)->lockForUpdate()->first();
            if ($operation->status !== 'awaiting_approval') {
                return $operation;
            }
            $last = $this->last_real_message((int) $ticket->id);
            if ($ticket->status !== 'open' || ! $ticket->claude_auto_reply || $last === null
                || ! $ticket->mensaje_confiable_para_codex($last)
                || (int) $last->id !== (int) $operation->expected_message_id) {
                $this->fail(409, 'stale_message', 'Cambió la conversación o el agente está apagado; no se envió el borrador.');
            }
            if (! $this->puede_entregar($ticket)) {
                $this->fail(422, 'window_expired', 'Venció la ventana de WhatsApp; el borrador no se envió.');
            }
            $message = SupportMessage::whereKey($message_id)->where('is_ai_suggestion_draft', true)->firstOrFail();
            $input = $operation->approval_payload;
            $case = SupportCodexCase::where('support_ticket_id', $ticket->id)->lockForUpdate()->firstOrFail();
            $final = $body === null ? trim($message->body) : trim($body);
            if ($final === '') {
                $this->fail(422, 'invalid_body', 'El mensaje no puede quedar vacío.');
            }
            if (! empty($input['_inferred_closure']) && ! $this->is_closing_farewell($final)) {
                $input['action'] = 'responder';
                $operation->action = 'responder';
                unset($input['confirmation_message_id'], $input['_inferred_closure']);
            }
            if ($input['action'] === 'escalar' && ! empty($input['close_on_mission_start']) && $final !== trim($message->body)) {
                $input['close_on_mission_start'] = false;
            }
            $this->assert_approval_action($ticket, $case, $input);
            if (isset($input['upgrade_proposal'])) {
                if ($final !== $input['body']) {
                    $this->fail(422, 'proposal_text_changed', 'El texto de una propuesta de actualización debe conservar su horario exacto.');
                }
                app(SupportCodexUpgradeAgreementService::class)->assert_valid_proposal($ticket, $case, $input['upgrade_proposal']);
            }
            if (! empty($input['requires_attachment'])) {
                if (mb_strlen($final) > SupportCodexOutboundAttachment::MAX_CAPTION_LENGTH) {
                    $this->fail(422, 'invalid_attachment_caption', 'El texto del adjunto admite hasta 1024 caracteres.');
                }
                $this->assert_approval_attachment($message);
            }
            if ($message->body !== $final) {
                $message->ai_original_body = $message->body;
            }
            $message->body = $final;
            $message->sender_admin_id = $admin_id;
            $message->ai_auto_send_at = null;
            $message->save();
            $input['body'] = $final;
            $operation->approval_payload = $input;
            $operation->status = 'sending';
            $operation->detail = null;
            $operation->save();
            return $operation;
        });
        if ($operation->status !== 'sending' || ! $operation->wasChanged('status')) {
            return $this->operation_result($operation);
        }
        return DB::transaction(function () use ($operation) {
            $ticket = SupportTicket::whereKey($operation->support_ticket_id)->lockForUpdate()->firstOrFail();
            $operation = SupportCodexOperation::whereKey($operation->id)->lockForUpdate()->first();
            if ($operation->status !== 'sending') {
                return $this->operation_result($operation);
            }
            $last = $this->last_real_message((int) $ticket->id);
            $message = SupportMessage::findOrFail($operation->message_id);
            // La fila sigue siendo borrador hasta completar todas las validaciones del envío.
            if ($ticket->status !== 'open' || ! $ticket->claude_auto_reply || $last === null
                || ! $ticket->mensaje_confiable_para_codex($last)
                || (int) $last->id !== (int) $operation->expected_message_id
                || ! $this->puede_entregar($ticket)) {
                $operation->status = 'cancelled';
                $operation->detail = 'La conversación cambió antes del envío aprobado; no se envió.';
                $operation->save();
                return $this->operation_result($operation);
            }
            $case = SupportCodexCase::where('support_ticket_id', $ticket->id)->lockForUpdate()->firstOrFail();
            $input = $operation->approval_payload;
            try {
                $this->assert_approval_action($ticket, $case, $input);
                if (isset($input['upgrade_proposal'])) {
                    app(SupportCodexUpgradeAgreementService::class)->assert_valid_proposal($ticket, $case, $input['upgrade_proposal']);
                }
                if (! empty($input['requires_attachment'])) {
                    $this->assert_approval_attachment($message);
                }
            } catch (HttpException $exception) {
                // Un rechazo conocido previo a la red no es una entrega incierta.
                $operation->status = 'cancelled';
                $operation->detail = 'La acción dejó de ser válida antes del envío; no se envió.';
                $operation->save();
                return $this->operation_result($operation);
            }
            $message->is_ai_suggestion_draft = false;
            $message->save();
            $result = null;
            try {
                $result = $this->entregar_respuesta($ticket, $message);
            } catch (\Throwable $exception) {
                $message->remote_delivery_status = 'not_received';
                $message->save();
            }
            if ($result !== null && ($result['delivery'] ?? '') === 'sent') {
                $operation->status = 'sent';
                $case->handled_message_id = (int) $operation->expected_message_id;
                $case->last_reply_id = (int) $message->id;
                $this->apply_action($ticket, $case, $input);
            } else {
                $operation->status = 'unknown';
                $operation->detail = $ticket->source === 'erp'
                    ? 'Entrega no confirmada. Revisá el chat del sistema antes de reintentar manualmente.'
                    : 'Entrega no confirmada. Revisá WhatsApp antes de reintentar manualmente.';
                $case->state = 'manual_review';
                $case->review_reason = $operation->detail;
                $case->review_requested_message_id = null;
                $this->request_attention($ticket);
            }
            $message->delivered_at = now();
            $message->save();
            $case->save();
            $operation->approval_payload = null;
            $operation->save();
            $ticket->ai_pending_suggestion = null;
            $ticket->ai_suggestion_send_at = null;
            $ticket->save();
            try {
                if (! empty($input['requires_attachment'])) {
                    SupportMessageReceived::dispatch_after_commit((int) $message->id);
                } else {
                    SupportMessageReceived::dispatch((int) $message->id);
                }
                SupportTicketUpdated::dispatch((int) $ticket->id);
            } catch (\Throwable $exception) {
                // El envío ya fue aceptado: realtime no puede revertir el resultado durable.
            }
            return $this->operation_result($operation);
        });
    }

    private function assert_approval_attachment(SupportMessage $message): void
    {
        $attachment = $message->attachments()->first();
        if ($attachment === null || ! SupportCodexOutboundAttachment::is_private($attachment)
            || SupportCodexOutboundAttachment::resolve_private($attachment) === null) {
            $this->fail(422, 'missing_attachment', 'El adjunto del borrador no está disponible.');
        }
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
            // Una sola pregunta por solución: el silencio conserva el pendiente sin perseguir al cliente.
            $case->confirmation_due_at = null;
            $case->follow_up_sent = false;
        } elseif ($input['action'] === 'cerrar') {
            $ticket->support_attention_enabled = false;
            $case->state = 'closed';
            $case->close_on_mission_start = false;
            $case->review_reason = null;
            $case->review_requested_message_id = null;
            $case->confirmation_due_at = null;
            $ticket->status = 'closed';
            $ticket->closed_at = now();
            $ticket->escalated_at = null;
            $ticket->escalation_reason = null;
            $ticket->save();
            $this->sincronizar_cierre_en_sistema($ticket);
        } elseif ($input['action'] === 'pedir_revision') {
            $this->request_attention($ticket);
            // Una urgencia humana no exige inventar una misión; si ya existe, se conserva.
            $case->state = 'manual_review';
            $case->review_reason = trim($input['review_reason']);
            $case->review_requested_message_id = $case->last_reply_id;
            $case->confirmation_due_at = null;
        } elseif ($input['action'] === 'escalar') {
            $mission = trim($input['mission']);
            if ((string) $case->mission !== $mission) {
                $case->mission_thread_id = null;
                $case->mission_started_at = null;
                $case->local_mission_request_id = null;
                $case->local_mission_claim_token = null;
                $case->close_on_mission_start = false;
            }
            if (isset($input['close_on_mission_start'])) {
                $case->close_on_mission_start = (bool) $input['close_on_mission_start'];
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
                $case->review_requested_message_id = null;
                $this->request_attention($ticket);
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
            $this->cancel_approvals($ticket, 'Lucas respondió desde el admin.');
            $case = SupportCodexCase::firstOrCreate(['support_ticket_id' => $ticket->id]);
            // Una respuesta humana cede este turno. Solo la apertura fallida conocida admite
            // recuperación; una entrega incierta o una conciliación mantienen su bloqueo.
            if ($case->state !== 'manual_review' || $this->is_human_reply_pause($case) || $case->is_agent_requested_review()
                || ($this->is_dispatch_review($case) && ! $this->has_unfinished_operation((int) $ticket->id))) {
                $case->state = 'manual_review';
                $case->review_reason = SupportCodexCase::HUMAN_REPLY_REVIEW_REASON;
                $last = $this->last_real_message($ticket->id);
                $case->expected_message_id = $last ? (int) $last->id : null;
            }
            $case->review_requested_message_id = null;
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

    /** El clic pide trabajo durable; nunca fabrica una respuesta ni consume el entrante. */
    public function request_manual_attention(int $id): array
    {
        return DB::transaction(function () use ($id) {
            $ticket = SupportTicket::lockForUpdate()->findOrFail($id);
            $case = SupportCodexCase::where('support_ticket_id', $id)->lockForUpdate()->first();
            $result = function (string $status, string $code, string $message) {
                return ['status' => $status, 'code' => $code, 'message' => $message];
            };
            if (! SupportCodexSettings::manages($ticket) || $ticket->status !== 'open'
                || ! $ticket->claude_auto_reply) {
                return $result('blocked', 'agent_disabled', 'Bloqueado: el agente está apagado o el ticket no está habilitado para Codex.');
            }
            $this->invalidate_stale_approval($ticket);
            $operation = SupportCodexOperation::where('support_ticket_id', $id)
                ->whereNotIn('status', ['sent', 'reconciled_sent', 'reconciled_not_sent', 'cancelled'])
                ->orderByDesc('created_at')->first();
            if ($operation !== null) {
                return $operation->status === 'awaiting_approval'
                    ? $result('awaiting_approval', 'approval_required', 'Pendiente de aprobación: revisá el borrador de Martín en la conversación.')
                    : $result('blocked', 'unfinished_operation', 'Bloqueado: hay un envío en curso o incierto que debe conciliarse antes de reintentar.');
            }
            if ($case !== null && $case->lease_expires_at !== null && $case->lease_expires_at->isFuture()) {
                return $result('in_progress', 'active_lease', 'En curso: Martín ya está atendiendo este ticket desde Codex.');
            }
            $last = $this->last_real_message($id);
            if ($last === null || $last->sender_type !== 'user'
                || ($case !== null && (int) $last->id <= (int) $case->handled_message_id)) {
                return $result('blocked', 'no_pending_inbound', 'Sin consulta pendiente: el último mensaje ya fue atendido o es una respuesta del equipo.');
            }
            if (! $ticket->mensaje_confiable_para_codex($last)) {
                return $result('blocked', 'inbound_not_verified', 'El origen del último mensaje del sistema no está verificado. Requiere atención humana.');
            }
            if (! $this->puede_entregar($ticket)) {
                return $result('blocked', 'whatsapp_window_closed', 'Bloqueado: venció la ventana de WhatsApp; hace falta un nuevo mensaje del cliente o una plantilla aprobada.');
            }
            $can_resume_human_pause = $case !== null && $this->is_human_reply_pause($case) && empty($case->mission);
            if ($this->pending_reason($ticket, $case) === null && ! $can_resume_human_pause) {
                return $result('blocked', 'review_required', 'Bloqueado: el ticket tiene una misión o revisión pendiente que requiere intervención.');
            }
            // Reutilizamos el estado que consulta pending. No usar resume(): consumiría el
            // entrante; tampoco liberar una revisión técnica ni una reserva del monitor.
            if ($case === null || $can_resume_human_pause) {
                $case = $case ?: new SupportCodexCase(['support_ticket_id' => $id]);
                $case->state = 'ready';
                $case->review_reason = null;
                $case->save();
            }
            return $result('queued', 'pending_inbound', 'En cola: Martín tomará la consulta pendiente en la próxima consulta del monitor de Codex.');
        });
    }

    public function assert_current(SupportTicket $ticket, array $input, bool $check_unfinished = true): SupportCodexCase
    {
        $this->assert_managed($ticket);
        $case = SupportCodexCase::where('support_ticket_id', $ticket->id)->lockForUpdate()->first();
        $this->assert_lease($case, $input['lease_token']);
        $last = $this->last_real_message($ticket->id);
        if ($last === null || (int) $last->id !== (int) $input['expected_message_id']
            || (int) $case->expected_message_id !== (int) $last->id || ! $ticket->mensaje_confiable_para_codex($last)) {
            $this->fail(409, 'stale_message', 'Cambió la conversación; se descarta la respuesta vieja.');
        }
        if (in_array($case->state, ['manual_review', 'closed'], true)
            || ($check_unfinished && $this->has_unfinished_operation($ticket->id))) {
            $this->fail(409, 'manual_review', 'La atención está pausada y requiere revisión.');
        }
        if ($case->state === 'mission_pending' || ! $check_unfinished) {
            $this->assert_mission_allows_action($case, $input['action']);
        }
        if ($input['action'] === 'cerrar') {
            $this->assert_upgrade_allows_closure($ticket, $case);
            $this->assert_importacion_cerrable($ticket);
        }
        return $case;
    }

    /** Retomar es una acción expresa de Lucas; no vuelve a procesar mensajes viejos. */
    public function resume(int $id, array $input, string $origen = 'Retomado por Lucas'): array
    {
        return DB::transaction(function () use ($id, $input, $origen) {
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
            if ($last === null || (int) $last->id !== (int) $input['expected_message_id']
                || ! $ticket->mensaje_confiable_para_codex($last)) {
                $this->fail(409, 'stale_message', 'Cambió la conversación.');
            }
            if (! empty($input['continue_pending'])) {
                if (! empty($input['request_confirmation'])) {
                    $this->fail(422, 'incompatible_resume_mode', 'Continuar una consulta no anuncia una misión resuelta.');
                }
                if ($last->sender_type !== 'user' || (int) $last->id <= (int) $case->handled_message_id) {
                    $this->fail(409, 'not_pending', 'No hay una consulta nueva del cliente sin atender.');
                }
                if (! $this->puede_entregar($ticket)) {
                    $this->fail(422, 'window_expired', 'Venció la ventana de WhatsApp; no se retoma el texto libre.');
                }
                // Recuperar una consulta técnica rechazada NO resuelve la misión que ya existía.
                // Tampoco consume el entrante: pending/claim deben poder ofrecérselo a Martín.
                $case->state = trim((string) $case->mission) !== '' ? 'mission_pending' : 'ready';
                $case->review_reason = mb_substr('Retomado por Lucas: ' . $input['reason'], 0, 500);
                $case->review_requested_message_id = null;
                $case->lease_hash = null;
                $case->lease_expires_at = null;
                $case->confirmation_due_at = null;
                $case->save();
                return ['ticket' => $this->context($ticket, $case)];
            }
            $case->state = ! empty($input['request_confirmation']) ? 'ready_to_confirm' : 'ready';
            $case->handled_message_id = (int) $last->id;
            $case->review_reason = mb_substr($origen . ': ' . $input['reason'], 0, 500);
            $case->review_requested_message_id = null;
            $case->lease_hash = null;
            $case->lease_expires_at = null;
            $case->confirmation_due_at = null;
            $case->mission = null;
            $case->mission_thread_id = null;
            $case->mission_started_at = null;
            $case->close_on_mission_start = false;
            $case->local_mission_request_id = null;
            $case->local_mission_claim_token = null;
            $case->save();
            $ticket->escalated_at = null;
            $ticket->escalation_reason = null;
            $ticket->save();
            return ['ticket' => $this->context($ticket, $case)];
        });
    }

    /** Registra la tarea creada por Lucas y, si corresponde, cierra el traspaso de una mejora. */
    public function mission_started(int $id, array $input): array
    {
        $result = DB::transaction(function () use ($id, $input) {
            $ticket = SupportTicket::where('id', $id)->lockForUpdate()->findOrFail($id);
            $case = SupportCodexCase::where('support_ticket_id', $id)->lockForUpdate()->first();
            // Registrar una tarea ya creada no es retomar la atención: debe poder asentarse
            // aunque Lucas haya apagado al agente o pausado el proveedor mientras tanto.
            if ($ticket->status !== 'open') {
                if ($case !== null && $case->state === 'closed' && $case->close_on_mission_start
                    && $case->mission_started_at !== null && $case->mission_thread_id === $input['mission_thread_id']
                    && hash_equals(hash('sha256', (string) $case->mission), $input['mission_hash'])) {
                    return ['ticket' => $this->context($ticket, $case)];
                }
                $this->fail(409, 'disabled_or_ineligible', 'El ticket cerrado no admite registrar una misión pendiente.');
            }
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
                if ($case->close_on_mission_start && $this->can_close_on_mission_start($ticket, $case)) {
                    $this->apply_action($ticket, $case, ['action' => 'cerrar']);
                    $case->close_on_mission_start = true; // Permite reconocer la repetición del mismo inicio.
                    $case->save();
                    return ['ticket' => $this->context($ticket, $case)];
                }
                $case->save();
            }
            // Es una actualización de seguimiento: no cambia manual_review ni consume la reserva.
            // El motivo humano, si lo hubiera, sigue en review_reason y convive con la misión.
            $ticket->escalation_reason = 'Misión iniciada en Codex; pendiente de corrección verificada.';
            $ticket->save();
            return ['ticket' => $this->context($ticket, $case)];
        });
        try {
            Cache::forget(EscenaService::CACHE_KEY);
            SupportTicketUpdated::dispatch($id);
        } catch (\Throwable $exception) {
            // El registro durable no se revierte si el caché o el realtime no están disponibles.
        }
        return $result;
    }

    private function can_close_on_mission_start(SupportTicket $ticket, SupportCodexCase $case): bool
    {
        if ($case->state !== 'mission_pending' || $case->is_agent_requested_review()
            || ($case->lease_expires_at !== null && $case->lease_expires_at->isFuture())
            || $case->last_reply_id === null || $this->has_unfinished_operation($ticket->id)) {
            return false;
        }
        $last = $this->last_real_message($ticket->id);
        return $last !== null && $last->sender_type === 'admin' && (int) $last->id === (int) $case->last_reply_id
            && SupportCodexOperation::where('support_ticket_id', $ticket->id)->where('message_id', $last->id)
                ->where('action', 'escalar')->where('status', 'sent')->exists()
            && ! $this->upgrade_blocks_closure($ticket, $case)
            && app(PruebaImportacionService::class)->estado_en_ticket($ticket)['en_proceso'] === false;
    }

    public function register_mission_fix_evidence(int $id, array $input): array
    {
        ksort($input['commits']);
        ksort($input['bases_verificadas']);
        if (array_keys($input['commits']) !== array_keys($input['bases_verificadas'])) {
            $this->fail(422, 'invalid_evidence_repositories', 'Las bases verificadas deben corresponder a todos los repositorios de los commits.');
        }
        if (array_values($input['pruebas']) !== $input['pruebas']) {
            $this->fail(422, 'invalid_evidence_tests', 'Las pruebas deben ser una lista.');
        }
        ksort($input);
        $payload_hash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($id, $input, $payload_hash) {
            $ticket = SupportTicket::where('id', $id)->lockForUpdate()->findOrFail($id);
            if ($ticket->status !== 'open') {
                $this->fail(409, 'disabled_or_ineligible', 'El ticket cerrado no admite registrar evidencia de una misión.');
            }
            $case = SupportCodexCase::where('support_ticket_id', $id)->lockForUpdate()->first();
            if ($case === null || ($case->mission_lifecycle()['status'] ?? null) !== 'started') {
                $this->fail(409, 'mission_not_started', 'El ticket no tiene una misión iniciada.');
            }
            if ($case->mission_thread_id !== $input['mission_thread_id'] || $case->thread_id === $input['mission_thread_id']) {
                $this->fail(409, 'mission_thread_conflict', 'La evidencia no corresponde al hilo vigente de la misión.');
            }
            if (! hash_equals(hash('sha256', (string) $case->mission), $input['mission_hash'])) {
                $this->fail(409, 'mission_changed', 'El diagnóstico de la misión cambió.');
            }

            $evidence = SupportCodexMissionFixEvidence::firstOrCreate([
                'support_ticket_id' => $id,
                'mission_thread_id' => $input['mission_thread_id'],
                'mission_hash' => $input['mission_hash'],
            ], ['payload_hash' => $payload_hash, 'payload' => $input]);
            if (! hash_equals($evidence->payload_hash, $payload_hash)) {
                $this->fail(409, 'mission_evidence_conflict', 'La misión ya tiene evidencia distinta registrada.');
            }

            return ['mission_fix_evidence' => $evidence->payload];
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
                'status' => in_array($operation->status, ['sent', 'reconciled_sent'], true) ? 'sent' : (in_array($operation->status, ['reconciled_not_sent', 'cancelled'], true) ? 'not_sent' : ($operation->status === 'awaiting_approval' ? 'awaiting_approval' : 'unknown')),
                'raw_status' => $operation->status,
                'message_id' => $operation->message_id ? (int) $operation->message_id : null,
                'action' => $operation->action,
                'detail' => $operation->detail,
                'reconciliation' => $operation->reconciled_at === null ? null : [
                    'outcome' => $operation->reconciliation_outcome,
                    'by' => $operation->reconciled_by,
                    'at' => $operation->reconciled_at,
                    'reason' => $operation->reconciliation_reason,
                    'evidence' => $operation->reconciliation_evidence,
                    'whatsapp_message_id' => Str::isUuid((string) $operation->reconciliation_reference) ? null : $operation->reconciliation_reference,
                    'erp_message_uuid' => Str::isUuid((string) $operation->reconciliation_reference) ? $operation->reconciliation_reference : null,
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
            $erp_uuid = $input['erp_message_uuid'] ?? null;
            $message = $operation->message_id === null ? null : SupportMessage::where('id', $operation->message_id)
                ->where('support_ticket_id', $ticket->id)->first();
            if ($input['outcome'] === 'sent') {
                if (empty($input['evidence']) || ($erp_uuid === null && empty($input['whatsapp_message_id']))) {
                    $this->fail(422, 'evidence_required', 'Confirmar un envío exige su identificador y evidencia de la verificación manual.');
                }
                if ($erp_uuid !== null && ($ticket->source !== 'erp' || $message === null || $message->synced_to_client_at === null
                        || ! hash_equals((string) $message->uuid, $erp_uuid))) {
                    $this->fail(422, 'evidence_required', 'El mensaje indicado todavía no tiene confirmación del sistema.');
                }
            }
            $operation->status = $input['outcome'] === 'sent' ? 'reconciled_sent' : 'reconciled_not_sent';
            $operation->reconciliation_outcome = $input['outcome'];
            $operation->reconciled_at = now();
            $operation->reconciled_by = $input['confirmed_by'];
            $operation->reconciliation_reason = trim($input['reason']);
            $operation->reconciliation_evidence = $input['evidence'] ?? null;
            $operation->reconciliation_reference = $erp_uuid ?? ($input['whatsapp_message_id'] ?? null);
            $operation->detail = 'Conciliado manualmente por Lucas; no hubo reenvío ni cierre automático.';
            $operation->save();
            if ($case !== null) {
                $case->state = 'manual_review';
                $case->review_reason = $operation->detail;
                $case->review_requested_message_id = null;
                $case->handled_message_id = max((int) $case->handled_message_id, (int) $operation->expected_message_id);
                $case->save();
            }
            if ($input['outcome'] === 'sent' && $erp_uuid === null && $message !== null && empty($message->whatsapp_message_id)) {
                $message->whatsapp_message_id = $input['whatsapp_message_id'];
                $message->remote_delivery_status = null;
                $message->save();
            }
            return $this->operation_result($operation);
        });
    }

    private function pending_reason(SupportTicket $ticket, ?SupportCodexCase $case, bool $supports_upgrade_agreement = false): ?string
    {
        $last = $this->last_real_message($ticket->id);
        if ($last === null || ! $ticket->mensaje_confiable_para_codex($last)) {
            return null;
        }

        return $this->pending_reason_for($ticket, $case, $last, $supports_upgrade_agreement);
    }

    /** El solicitante debe haber seleccionado el último mensaje real del ticket. */
    public function puede_cotejar_entrante_erp(SupportTicket $ticket, SupportMessage $mensaje): bool
    {
        $case = $ticket->support_codex_case;
        return $ticket->es_erp_sin_identidad_verificada() && $ticket->status === 'open'
            && $ticket->claude_auto_reply && $mensaje->sender_type === 'user' && ! $mensaje->is_trial_import_notice
            && (int) $mensaje->support_ticket_id === (int) $ticket->id
            && ($case === null || $case->lease_expires_at === null || ! $case->lease_expires_at->isFuture())
            && SupportCodexSettings::manages($ticket)
            && $this->pending_reason_for($ticket, $case, $mensaje) === 'inbound';
    }

    private function pending_reason_for(SupportTicket $ticket, ?SupportCodexCase $case, SupportMessage $last, bool $supports_upgrade_agreement = false): ?string
    {
        if ($ticket->status !== 'open' || ($case !== null && $case->state === 'closed')
            || ! $ticket->claude_auto_reply || $this->has_unfinished_operation($ticket->id)) {
            return null;
        }
        if ($case !== null && $case->state === 'manual_review') {
            // La procedencia durable distingue pedir_revision de errores de envío, conciliación
            // o pausas técnicas. Un entrante no borra el motivo: solo permite que Martín lo lea.
            return ($this->is_human_reply_pause($case) || $case->is_agent_requested_review() || $this->is_dispatch_review($case)
                || $case->review_reason === self::CONFIRMATION_REVIEW_REASON) && $last->sender_type === 'user'
                && (int) $last->id > max((int) $case->expected_message_id, (int) $case->handled_message_id)
                ? 'inbound' : null;
        }
        if ($case !== null && $case->state === 'ready_to_confirm'
            && (int) $last->id === (int) $case->handled_message_id
            && $this->puede_entregar($ticket)) {
            return 'mission_completed';
        }
        if ($last->sender_type === 'user' && ($case === null || (int) $last->id > (int) $case->handled_message_id)) {
            return 'inbound';
        }
        if ($supports_upgrade_agreement && $case !== null
            && app(SupportCodexUpgradeAgreementService::class)->pending_offer($ticket, $case)
            && $this->puede_entregar($ticket)) {
            return 'fix_ready_for_upgrade';
        }
        if ($case !== null && (int) $last->id === (int) $case->last_reply_id
            && $this->is_follow_up($case, (int) $last->id)
            && $this->puede_entregar($ticket)) {
            return 'follow_up';
        }
        return null;
    }

    private function is_dispatch_review(SupportCodexCase $case): bool
    {
        // Solo un rechazo de apertura conocido: un envío desconocido sigue exigiendo conciliación.
        return $case->state === 'manual_review'
            && $case->review_reason === 'Disparo de conversación sin confirmar; conciliar en Codex';
    }

    private function is_human_reply_pause(SupportCodexCase $case): bool
    {
        return $case->is_human_reply_pause();
    }

    private function is_follow_up(SupportCodexCase $case, int $expected): bool
    {
        // Incluye los vencimientos guardados antes del cambio: tampoco generan otra pregunta.
        return false;
    }

    private function has_unfinished_operation(int $id): bool
    {
        return SupportCodexOperation::where('support_ticket_id', $id)
            ->whereNotIn('status', ['sent', 'reconciled_sent', 'reconciled_not_sent', 'cancelled'])->exists();
    }

    private function last_real_message(int $id): ?SupportMessage
    {
        $query = SupportMessage::where('support_ticket_id', $id);
        SupportMessage::apply_mensaje_real_conditions($query, 'support_messages');
        // Una notificación de otra corrida puede llegar después de la respuesta del dueño.
        // Queda visible en el hilo, pero no puede desplazar ese entrante ni invalidar su reserva.
        return $query->where('is_trial_import_notice', false)->orderByDesc('id')->first();
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

    private function assert_confirmation(SupportTicket $ticket, SupportCodexCase $case, array $input, bool $allow_thanks_only = true): void
    {
        if (! $this->confirms_resolution($ticket, $case, $this->confirmation_message($ticket, $input), $allow_thanks_only)) {
            $this->fail(422, 'confirmation_required', 'El mensaje no confirma de forma suficiente que el caso quedó resuelto.');
        }
    }

    private function confirmation_message(SupportTicket $ticket, array $input): SupportMessage
    {
        $message = $this->last_real_message($ticket->id);
        if ($message === null || $message->sender_type !== 'user'
            || (int) ($input['confirmation_message_id'] ?? 0) !== (int) $message->id) {
            $this->fail(422, 'confirmation_required', 'El cierre requiere el último mensaje de confirmación del cliente.');
        }
        return $message;
    }

    private function can_close_on_reply(SupportTicket $ticket, SupportCodexCase $case, array $input): bool
    {
        if ($input['action'] !== 'responder' || isset($input['mission']) || isset($input['review_reason'])
            || isset($input['confirmation_message_id']) || ! $this->is_closing_farewell($input['body'])
            || $case->is_agent_requested_review() || $case->last_reply_id === null
            || $this->mission_blocks_action($case, 'cerrar')) {
            return false;
        }

        $message = $this->last_real_message($ticket->id);
        if ($message === null || $message->sender_type !== 'user'
            || ($message->reply_to_message_id !== null && (int) $message->reply_to_message_id !== (int) $case->last_reply_id)) {
            return false;
        }
        $previous = $this->previous_conversation_message($ticket, $message);
        return $previous !== null && (int) $previous->id === (int) $case->last_reply_id
            && $this->confirms_resolution($ticket, $case, $message, false)
            && ! $this->upgrade_blocks_closure($ticket, $case)
            && app(PruebaImportacionService::class)->estado_en_ticket($ticket)['en_proceso'] === false;
    }

    private function is_closing_farewell(string $body): bool
    {
        return (bool) preg_match('/^de nada(?: [a-z]+)?[.! ]*$/D', Str::ascii(mb_strtolower(trim($body))));
    }

    private function confirms_resolution(SupportTicket $ticket, SupportCodexCase $case, SupportMessage $message, bool $allow_thanks_only = true): bool
    {
        $body = Str::ascii(mb_strtolower(trim((string) $message->body)));
        $negative = strpos($body, '?') !== false
            || preg_match('/\b(no|nunca|tampoco|todavia|aun|sigue|pero|falta|error|problema|dificultad|manana|luego|despues|probare|probar|voy|cuando|veremos|ojala|deberia|podria|creo|supongo|parece|quizas|parcialmente|parcial|incompleto|incompleta|algunos|algunas)\b/', $body)
            || preg_match('/\b(?:excepto|salvo|necesito|necesitamos|quiero|queremos|podes|podrias|mandame|pasame|enviame|pruebo|hare|haremos)\b|\b(?:otra|nueva) (?:consulta|duda|pregunta)\b|\b(?:te|les) aviso\b/', $body)
            || preg_match('/\b(a medias|una parte|solo pude|solamente pude)\b/', $body)
            || preg_match('/\bsi (funciona|anda|queda|(?:ya )?lo (arreglamos|arregle|solucionamos|solucione))\b/', $body);
        // «Todo normal» confirma el resultado si contesta una pregunta sobre la solución,
        // pero puede ser un saludo fuera de ese contexto. No alcanza con guardar el estado:
        // se comprueba también el mensaje que originó el pedido, dentro de este mismo ticket.
        $question = $case->last_reply_id !== null
            ? SupportMessage::where('support_ticket_id', $ticket->id)->where('id', $case->last_reply_id)
                ->where('sender_type', 'admin')->first()
            : null;
        if ($question !== null && ! $ticket->mensaje_confiable_para_codex($question)) {
            $question = null;
        }
        $question_body = $question === null ? '' : Str::ascii(mb_strtolower((string) $question->body));
        $answer_to_resolution = $case->state === 'awaiting_confirmation'
            && $question !== null && $question->id < $message->id
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
            || ($question !== null && $question->id < $message->id && preg_match('/\bsalio todo (?:bien|perfecto|de (?:10|diez))\b/', $body))
            || preg_match('/^(?:(?:ya|ahora)[,.!\s]+)?(?:anduvo|funciono)(?:[,.!\s]+(?:bien|perfecto|correctamente))?(?:[,.!\s]+(?:muchas\s+)?gracias)?[.!\s]*$/', $body)
            || preg_match('/^(si[,.! ]+)?(ya )?lo (arreglamos|arregle|solucionamos|solucione)([,.! ]+(muchas )?gracias)?[!. ]*$/', $body)
            || preg_match('/^(si[,.! ]+)?(ya )?(resuelt[oa]|solucionad[oa])[,.! ]*(muchas )?gracias[!. ]*$/', $body);
        $short_answer = $answer_to_resolution
            && preg_match('/^(si[,.! ]+)?(todo (normal|bien)|si)([,.! ]+(muchas )?gracias)?[!. ]*$/', $body);
        // Golden Brace #25: «pude verlos perfecto» ya informa el resultado de ver los cheques.
        // Se cruza la acción con la orientación anterior; un resultado de otra tarea no cierra esta.
        $task_completed = false;
        $task_question = false;
        if ($question !== null && $question->id < $message->id) {
            $tasks = [
                'ver' => '(?:ver(?:lo|la|los|las)?|vi|vimos)',
                'abr' => '(?:abrir(?:lo|la|los|las)?|abri|abrimos)',
                'ingres' => '(?:ingresar|ingrese|ingresamos)',
                'entr' => '(?:entrar|entre|entramos)',
                'vend' => '(?:vender|vendi|vendimos)',
                'carg' => '(?:cargar(?:lo|la|los|las)?|cargue|cargamos)',
                'emit' => '(?:emitir(?:lo|la|los|las)?|emiti|emitimos)',
                'imprim' => '(?:imprimir(?:lo|la|los|las)?|imprimi|imprimimos)',
                'guard' => '(?:guardar(?:lo|la|los|las)?|guarde|guardamos)',
                'encontr' => '(?:encontrar(?:lo|la|los|las)?|encontre|encontramos)',
            ];
            $present = [
                'ver' => '(?:veo|vemos)', 'abr' => '(?:abro|abrimos)',
                'ingres' => '(?:ingreso|ingresamos)', 'entr' => '(?:entro|entramos)',
                'vend' => '(?:vendo|vendemos)', 'carg' => '(?:cargo|cargamos)',
                'emit' => '(?:emito|emitimos)', 'imprim' => '(?:imprimo|imprimimos)',
                'guard' => '(?:guardo|guardamos)', 'encontr' => '(?:encuentro|encontramos)',
            ];
            foreach ($tasks as $stem => $completed) {
                $question_task = $stem === 'ver' ? '(?:ver(?:lo|la|los|las)?|ves|veo|vieron)' : $stem . '[a-z]*';
                if (! preg_match('/\b' . $question_task . '\b/', $question_body)) {
                    continue;
                }
                $task_question = $task_question || ($case->state === 'awaiting_confirmation'
                    && strpos($question_body, '?') !== false
                    && ! preg_match('/\b(manana|luego|despues|cuando|probar|dificultad|problema|no)\b/', $question_body)
                    && preg_match('/\b(pudiste|pudieron|lograste|ya|ahora)\b/', $question_body));
                if (preg_match('/\b(?:pude|pudimos|logre|logramos)\s+' . $completed . '\b/', $body)
                    || preg_match('/\b(?:ya|ahi|ahora)\s+(?:(?:lo|la|los|las)\s+)?(?:' . $completed . '|' . $present[$stem] . ')\b/', $body)
                    || preg_match('/\b(?:ya|ahi|ahora)\s+(?:puedo|podemos)\s+' . $completed . '\b/', $body)
                    || preg_match('/\bme dejo hacerlo\b/', $body)) {
                    $task_completed = true;
                    break;
                }
            }
        }
        $short_answer = $short_answer || ($task_question
            && preg_match('/^si([,.! ]+(muchas )?gracias)?[!. ]*$/', $body));
        $implicit_completed = ($task_question || $answer_to_resolution)
            && preg_match('/^(?:(?:listo|perfecto|si)[,.! ]+)?(?:ya )?pude(?: hacerlo)?(?:[,.! ]+(?:muchas )?gracias)?[!. ]*$/', $body);
        $own_error = $question !== null && $question->id < $message->id
            && ($this->acknowledges_own_error_after_guidance($body, $question_body)
                || $this->completed_after_identifying_error($ticket, $message, $question, $body, $question_body));
        $natural_thanks = $allow_thanks_only && $this->natural_thanks_can_close($ticket, $case, $message, $body);
        return ($own_error || ! $negative)
            && ($resolved || $short_answer || $task_completed || $implicit_completed || $own_error || $natural_thanks);
    }

    /**
     * Lucas cambió la política el 02/11/2026: «gracias» puede terminar la conversación
     * sin afirmar que se probó una solución. Una cortesía no resuelve tareas pendientes.
     */
    private function natural_thanks_can_close(SupportTicket $ticket, SupportCodexCase $case, SupportMessage $message, string $body): bool
    {
        if ($case->state === 'mission_pending' || trim((string) $case->mission) !== ''
            || ($case->review_requested_message_id !== null && trim((string) $case->review_reason) !== '')) {
            return false;
        }
        $words = trim(preg_replace('/[^a-z ]/', ' ', $body));
        $words = preg_replace('/\s+/', ' ', $words);
        // El agente elige cerrar por el sentido del mensaje. El servidor verifica el
        // agradecimiento y rechaza señales explícitas de otra consulta o trabajo futuro;
        // un elogio o un chiste no requieren reproducir una frase de un cliente concreto.
        $thanks = preg_match('/^(?:(?:hola|si|ah|ok|oka|dale|listo|perfecto|buenisimo|genial|barbaro|excelente|martin) )*(?:(?:muchas|muchisimas|mil) )?gracias\b/', $words);
        if (! $thanks || preg_match('/\b(?:consulta|duda|pregunta|necesito|quiero|podes|podrias|como|donde|cual|cuales|cuantos|mandame|pasame|enviame|revisame|fijate|tengo|ademas|tambien|otra|otro|proba[a-z]*|prueb[a-z]*|verific[a-z]*|hare|haremos|hago|haremos|esper[a-z]*|pendiente|viernes|lunes|martes|miercoles|jueves|sabado|domingo)\b|\b(?:te|les) aviso\b/', $words)) {
            return false;
        }
        $last = $this->previous_conversation_message($ticket, $message);
        if ($last === null) {
            return true; // Solo llegó una despedida; no hay consulta anterior que dejar abierta.
        }
        if ($last->sender_type !== 'admin' || ! $ticket->mensaje_confiable_para_codex($last)) {
            return false; // Hay otra consulta entrante que todavía nadie contestó.
        }
        // El «?» de un enlace al centro de recursos no es una pregunta pendiente.
        $guidance = preg_replace('~https?://\S+~', '', Str::ascii(mb_strtolower((string) $last->body)));
        if (preg_match('/\b(manana|proximamente|pendiente|revisando|investigando|escalado)\b|\b(?:voy|vamos) a\b|\b(?:te|lo) (?:aviso|avisamos|reviso|revisamos|confirmo|confirmamos)\b|\b(?:pasame|mandame|enviame|necesito|necesitamos)\b|\b(?:proba|probar|prueba|probarlo) (?:luego|despues)\b|\bcuando (?:puedas|puedan|podamos)\b|\b(?:dame|danos) un (?:minuto|momento)\b|\b(?:estoy|estamos|sigo|seguimos) (?:viendo|revisando|investigando)\b|\bque reviso\b/', $guidance)) {
            return false;
        }
        if (preg_match('/\b(?:no pude|no pudimos|no sabemos|no funciona|no anda|todavia|aun)\b/', $guidance)
            || (strpos($guidance, '?') !== false && preg_match('/\b(que|cual|cuales|como|donde|cuanto|cuantos|dificultad|problema)\b/', $guidance))) {
            return false;
        }
        // Una pregunta de datos o una tarea por coordinar siguen abiertas. Una pregunta
        // de cierre sí permite que el cliente termine con su agradecimiento natural.
        $greeting_only = preg_match('/^(?:(?:hola|buenas|buen dia|buenos dias|buenas tardes|buenas noches|soy martin|martin|todo bien|como estas)[,.! ?]*)+$/', trim($guidance));
        return ! $greeting_only && trim($guidance) !== '';
    }

    /**
     * Masquito #30: «error nuestro» confirma la explicación del campo oculto, no una falla
     * pendiente. Se exige la orientación concreta anterior de ESTE ticket y una admisión
     * completa: una duda, una prueba futura u otra dificultad no pasan por tener esas palabras.
     */
    private function acknowledges_own_error_after_guidance(string $body, string $guidance): bool
    {
        $admission = '(?:(?:(?:fue|era|es|habia sido)\s+)?(?:un\s+)?error\s+(?:nuestro|mio|de nuestra parte)|nos equivocamos|me equivoque)';
        $complete_admission = preg_match('/^(?:(?:si|claro|perfecto|ah|ok|listo)[,.!\s]+)*' . $admission
            . '(?:[,.!\s]+' . $admission . ')*(?:[,.!\s]+(?:muchas\s+)?gracias)?[.!\s]*$/', $body);
        return $complete_admission && $this->guidance_explains_cause($guidance);
    }

    private function completed_after_identifying_error(SupportTicket $ticket, SupportMessage $message, SupportMessage $guidance, string $body, string $guidance_body): bool
    {
        // FR Total #68: «Listo» y «Me di cuenta el error» confirman juntos la orientación del QR.
        if (! preg_match('/^(listo[,.!\s]+)?(?:ya\s+)?me di cuenta (?:el|del) error(?:[,.!\s]+gracias)?[.!\s]*$/D', $body, $parts)
            || ! $this->guidance_explains_cause($guidance_body)) {
            return false;
        }

        $previous = $this->previous_conversation_message($ticket, $message);
        $quoted = $message->reply_to_message_id === null ? null : (int) $message->reply_to_message_id;
        if (! empty($parts[1])) {
            return $previous !== null && $previous->is($guidance)
                && ($quoted === null || $quoted === (int) $guidance->id);
        }
        if ($previous === null || $previous->sender_type !== 'user' || ! $ticket->mensaje_confiable_para_codex($previous)
            || ($previous->reply_to_message_id !== null && (int) $previous->reply_to_message_id !== (int) $guidance->id)
            || ! preg_match('/^listo[.! ]*$/D', mb_strtolower(trim((string) $previous->body)))) {
            return false;
        }
        $before_listo = $this->previous_conversation_message($ticket, $previous);
        return $before_listo !== null && $before_listo->is($guidance)
            && ($quoted === null || in_array($quoted, [(int) $guidance->id, (int) $previous->id], true));
    }

    private function previous_conversation_message(SupportTicket $ticket, SupportMessage $message): ?SupportMessage
    {
        $query = SupportMessage::where('support_ticket_id', $ticket->id)->where('id', '<', $message->id);
        SupportMessage::apply_mensaje_real_conditions($query, 'support_messages');
        return $query->orderByDesc('id')->first();
    }

    private function guidance_explains_cause(string $guidance): bool
    {
        // Alcanza una causa concreta; reconocerla no exige haber editado ya la venta. Un
        // saludo o «lo verá Lucas» no son una explicación que pueda confirmar el cliente.
        return ! preg_match('/\b(?:no sabemos|no pude verificar|no pude comprobar|podria ser|puede que|quizas)\b/', $guidance)
            && preg_match('/\b(?:campo|opcion|dato|observaciones|filtro|fecha|caja|sucursal|cliente|remito|venta|cuota|permiso|configuracion|qr|cartel)\b[^.!?\n]{0,160}\b(?:esta|estan|quedo|quedaron|figura|aparece|es|son) (?:intern[oa]s?|ocult[oa]s?|vaci[oa]s?|guardad[oa]s?|cargad[oa]s?|seleccionad[oa]s?|marcad[oa]s?|tildad[oa]s?|activad[oa]s?|desactivad[oa]s?|apagad[oa]s?|encendid[oa]s?|incorrect[oa]s?|vinculad[oa]s?|en)\b/', $guidance);
    }

    private function fail(int $status, string $code, string $message): void
    {
        throw new HttpException($status, $code . ': ' . $message);
    }
}

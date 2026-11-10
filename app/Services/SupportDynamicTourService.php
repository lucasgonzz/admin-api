<?php

namespace App\Services;

use App\Models\SupportMessage;
use App\Models\SupportTicket;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SupportDynamicTourService
{
    private const RESERVA_SEGUNDOS = 90;

    public function preparar(int $ticket_id, int $expected_message_id, Closure $validar): array
    {
        $tours = app(SupportTourService::class);
        $reserva = DB::transaction(function () use ($ticket_id, $expected_message_id, $validar, $tours) {
            [$ticket, $entrante] = $this->contexto_vigente($ticket_id, $expected_message_id, $validar);
            $invitacion = $tours->solicitud_aceptada($ticket, $entrante);
            if ($invitacion === null) {
                return ['resultado' => $this->fallo(409, 'tour_consent_required', 'La persona todavía no aceptó una invitación enviada en este ticket.')];
            }

            $marcas = $invitacion->erp_auto_reply;
            if (isset($marcas['tour_dynamic_result'])) {
                return ['resultado' => $marcas['tour_dynamic_result']];
            }
            if (($marcas['tour_dynamic_until'] ?? 0) > now()->timestamp) {
                return ['resultado' => $this->fallo(409, 'tour_planning', 'Robotito ya está preparando ese recorrido.')];
            }

            $token = (string) Str::uuid();
            $invitacion->erp_auto_reply = array_replace($marcas, [
                'tour_acceptance_uuid' => $entrante->uuid,
                'tour_dynamic_attempt' => $token,
                'tour_dynamic_until' => now()->timestamp + self::RESERVA_SEGUNDOS,
            ]);
            $invitacion->save();

            return compact('ticket', 'entrante', 'invitacion', 'token');
        });
        if (isset($reserva['resultado'])) {
            return $reserva['resultado'];
        }

        // DeepSeek y la lectura del catálogo quedan fuera del bloqueo del ticket.
        $plan = app(SupportTourPlannerService::class)->planificar($reserva['ticket'], $reserva['entrante'], $reserva['invitacion']);

        return DB::transaction(function () use ($ticket_id, $expected_message_id, $validar, $reserva, $plan, $tours) {
            [$ticket, $entrante] = $this->contexto_vigente($ticket_id, $expected_message_id, $validar);
            $invitacion = $tours->solicitud_aceptada($ticket, $entrante);
            if ($invitacion === null || $invitacion->id !== $reserva['invitacion']->id
                || ($invitacion->erp_auto_reply['tour_dynamic_attempt'] ?? null) !== $reserva['token']) {
                return $this->fallo(409, 'tour_planning_stale', 'La conversación cambió mientras Robotito preparaba el recorrido.');
            }

            $marcas = $invitacion->erp_auto_reply;
            if ($plan === null) {
                $resultado = $this->fallo(422, 'tour_plan_unavailable', 'No pudimos preparar una guía interactiva para esta consulta.');
            } else {
                $marcas['tour_dynamic_plan'] = $plan;
                $invitacion->erp_auto_reply = $marcas;
                $invitacion->save();
                $resultado = $tours->ofrecer($ticket, $entrante, $plan);
            }
            $marcas['tour_dynamic_result'] = $resultado;
            unset($marcas['tour_dynamic_attempt'], $marcas['tour_dynamic_until']);
            $invitacion->erp_auto_reply = $marcas;
            $invitacion->save();

            return $resultado;
        });
    }

    private function contexto_vigente(int $ticket_id, int $expected_message_id, Closure $validar): array
    {
        $ticket = SupportTicket::lockForUpdate()->findOrFail($ticket_id);
        $validar($ticket);
        abort_if($ticket->requiere_verificacion_mensajes, 409, 'verification_required: El ticket exige aprobación humana.');
        $mensajes = SupportMessage::where('support_ticket_id', $ticket->id);
        SupportMessage::apply_mensaje_real_conditions($mensajes, 'support_messages');
        $entrante = $mensajes->latest('id')->first();
        abort_unless($entrante !== null && (int) $entrante->id === $expected_message_id && $entrante->sender_type === 'user',
            409, 'stale_message: La conversación cambió mientras Robotito preparaba el recorrido.');

        return [$ticket, $entrante];
    }

    private function fallo(int $status, string $code, string $message): array
    {
        return ['ok' => false, 'status' => $status, 'code' => $code, 'message' => $message];
    }
}

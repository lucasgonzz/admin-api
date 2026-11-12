<?php

namespace App\Services;

use App\Models\SupportMessage;
use App\Models\SupportTicket;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SupportDynamicTourService
{
    private const RESERVA_SEGUNDOS = 90;

    /**
     * Prepara el recorrido y deja rastro de por qué no salió (12/11/2026, ticket 107: el dueño aceptó dos veces,
     * el admin contestó tour_consent_required y no quedó nada en el log ni le llegó un motivo a Martín).
     * Un fallo se registra en el log, viaja en la respuesta (reason) y queda un día en el contexto del ticket
     * (last_tour_failure), que es lo que Martín lee aunque su programa solo le pase el código.
     */
    public function preparar(int $ticket_id, int $expected_message_id, Closure $validar): array
    {
        $resultado = $this->preparar_sin_rastro($ticket_id, $expected_message_id, $validar);
        if (($resultado['ok'] ?? false) === true) {
            Cache::forget(self::clave_de_fallo($ticket_id));
            return $resultado;
        }

        $resultado += ['reason' => self::MOTIVOS[$resultado['code'] ?? ''] ?? null];
        self::registrar_fallo($ticket_id, $expected_message_id, (string) ($resultado['code'] ?? 'desconocido'),
            (string) ($resultado['reason'] ?? $resultado['message'] ?? ''));

        return $resultado;
    }

    /** Motivo legible de los fallos que no traen uno propio. */
    private const MOTIVOS = [
        'tour_consent_required' => 'El último mensaje de la persona no se reconoció como un sí a la invitación ni como un pedido del recorrido.',
        'tour_planning' => 'Ya hay una preparación en curso para ese mismo mensaje; esperá unos segundos.',
        'tour_planning_stale' => 'Llegó otro mensaje mientras se preparaba el recorrido.',
        'tour_recipient_unresolved' => 'No se pudo identificar la cuenta del sistema de quien escribió.',
        'tour_invitation_mismatch' => 'El recorrido preparado no coincide con el que aceptó la persona.',
        'tour_unavailable' => 'El sistema del comercio no está conectado al admin (falta su URL o su clave).',
        'tour_unsupported' => 'La versión del sistema del comercio no tiene recorridos de soporte.',
        'tour_rejected' => 'El sistema del comercio rechazó el recorrido: un paso no está en su catálogo para esa persona, o el mensaje no salió de una sesión con Robotito.',
        'tour_unconfirmed' => 'El sistema del comercio no confirmó que recibió el recorrido.',
    ];

    public static function clave_de_fallo(int $ticket_id): string
    {
        return 'support_tour_last_failure:' . $ticket_id;
    }

    public static function ultimo_fallo(int $ticket_id): ?array
    {
        $fallo = Cache::get(self::clave_de_fallo($ticket_id));
        return is_array($fallo) ? $fallo : null;
    }

    public static function registrar_fallo(int $ticket_id, int $expected_message_id, string $code, string $reason): void
    {
        Log::warning('Robotito: tour/prepare no entregó el recorrido.', [
            'ticket_id' => $ticket_id, 'expected_message_id' => $expected_message_id, 'code' => $code, 'reason' => $reason,
        ]);
        Cache::put(self::clave_de_fallo($ticket_id), [
            'code' => $code, 'reason' => $reason, 'expected_message_id' => $expected_message_id, 'at' => now()->toIso8601String(),
        ], now()->addDay());
    }

    private function preparar_sin_rastro(int $ticket_id, int $expected_message_id, Closure $validar): array
    {
        $tours = app(SupportTourService::class);
        $reserva = DB::transaction(function () use ($ticket_id, $expected_message_id, $validar, $tours) {
            [$ticket, $entrante] = $this->contexto_vigente($ticket_id, $expected_message_id, $validar);
            $invitacion = $tours->solicitud_aceptada($ticket, $entrante);
            if ($invitacion === null) {
                return ['resultado' => $this->fallo(409, 'tour_consent_required', 'La persona todavía no aceptó una invitación enviada en este ticket.')];
            }

            $marcas = (array) $invitacion->erp_auto_reply;
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
        $planificador = app(SupportTourPlannerService::class);
        $plan = $planificador->planificar($reserva['ticket'], $reserva['entrante'], $reserva['invitacion']);
        $motivo = $planificador->motivo;
        if ($plan !== null && $motivo !== null) {
            Log::info('Robotito: el modelo no armó el recorrido y se entrega el de respaldo.', ['ticket_id' => $ticket_id, 'reason' => $motivo]);
        }

        return DB::transaction(function () use ($ticket_id, $expected_message_id, $validar, $reserva, $plan, $tours, $motivo) {
            [$ticket, $entrante] = $this->contexto_vigente($ticket_id, $expected_message_id, $validar);
            $invitacion = $tours->solicitud_aceptada($ticket, $entrante);
            if ($invitacion === null || $invitacion->id !== $reserva['invitacion']->id
                || ($invitacion->erp_auto_reply['tour_dynamic_attempt'] ?? null) !== $reserva['token']) {
                return $this->fallo(409, 'tour_planning_stale', 'La conversación cambió mientras Robotito preparaba el recorrido.');
            }

            $marcas = (array) $invitacion->erp_auto_reply;
            if ($plan === null) {
                $resultado = $this->fallo(422, 'tour_plan_unavailable', 'No pudimos preparar una guía interactiva para esta consulta.')
                    + ['reason' => self::motivo_legible($motivo)];
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

    private static function motivo_legible(?string $motivo): string
    {
        $detalle = '';
        if ($motivo !== null && strpos($motivo, ': ') !== false) {
            [$motivo, $detalle] = explode(': ', $motivo, 2);
        }
        $textos = [
            'sin_catalogo' => 'El sistema del comercio no entregó su catálogo de pasos (sin conexión, versión anterior o persona sin identificar).',
            'modelo_sin_clave' => 'El admin no tiene cargada la clave del modelo que arma el recorrido.',
            'modelo_sin_respuesta' => 'El modelo que arma el recorrido no respondió a tiempo.',
            'respuesta_invalida' => 'El modelo devolvió un recorrido con un formato inválido.',
            'tarea_no_clara' => 'El modelo no encontró en la conversación una tarea clara para guiar.',
            'clave_rechazada' => 'El modelo eligió un paso que el catálogo de ese sistema no habilita.',
        ];
        $texto = $textos[$motivo] ?? 'El planificador no pudo armar el recorrido.';
        if ($detalle !== '') {
            $texto .= ' (' . $detalle . ')';
        }

        return $motivo === 'sin_catalogo' ? $texto : $texto . ' Tampoco había un camino escrito con el que armar el recorrido mínimo.';
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

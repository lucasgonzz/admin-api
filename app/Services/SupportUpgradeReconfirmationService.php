<?php

namespace App\Services;

use App\Models\SupportCodexOperation;
use App\Models\SupportCodexScheduledUpgrade as Scheduled;
use App\Models\SupportCodexUpgradeAgreement;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** La aceptación del horario no es permiso para interrumpir una jornada sin volver a consultar. */
class SupportUpgradeReconfirmationService
{
    public static function available(): bool
    {
        return Schema::hasColumn('support_codex_scheduled_upgrades', 'overnight_notice_operation_id');
    }

    public static function next_overnight(?Carbon $from = null): Carbon
    {
        return ($from ?: now())->copy()->setTimezone(SupportMaintenancePolicy::TIMEZONE)->addDay()->startOfDay()->setHour(2);
    }

    public function context(int $ticket): ?array
    {
        if (! self::available()) { return null; }
        $row = Scheduled::where('support_ticket_id', $ticket)->where('status', Scheduled::SCHEDULED)
            ->whereIn('reason', ['esperando_reconfirmacion', 'aviso_madrugada_pendiente'])->oldest('id')->first();
        if ($row === null) { return null; }
        $this->sync($row);
        $operation = $this->operation($row, $row->overnight_fallback ? 'overnight' : 'confirm');
        $evidence = \App\Models\SupportCodexMission::evidence_for_agreement($row->agreement);
        $mission = $evidence === null ? null : \App\Models\SupportCodexMission::where('support_ticket_id', $ticket)
            ->where('mission_thread_id', $evidence->mission_thread_id)->where('mission_hash', $evidence->mission_hash)->first();
        return [
            'schedule_id' => (int) $row->id,
            'mission_id' => $mission === null ? null : (int) $mission->id,
            'status' => $row->overnight_fallback ? 'overnight_notice_pending' : ($row->reconfirmed_at ? 'confirmed' : 'awaiting_confirmation'),
            'requested_at' => $row->reconfirmation_requested_at,
            'expires_at' => $row->reconfirmation_expires_at,
            'proposal_operation_id' => $operation === null ? null : $operation->operation_id,
            'message_id' => $operation === null ? null : $operation->message_id,
            'operation_status' => $operation === null ? null : $operation->status,
            'confirmation_phrase' => $this->phrase(),
            'starts_at' => Carbon::parse($row->starts_at_utc, 'UTC')->setTimezone(SupportMaintenancePolicy::TIMEZONE)->toIso8601String(),
        ];
    }

    public function needs_attention(int $ticket): bool
    {
        $context = $this->context($ticket);
        if ($context === null) { return false; }
        // Un envío en aprobación o incierto conserva el freno del canal y nunca dispara otro mensaje.
        if ($context['operation_status'] === null) { return true; }
        $operation = SupportCodexOperation::where('operation_id', $context['proposal_operation_id'])->where('support_ticket_id', $ticket)->first();
        return $this->replaceable($operation);
    }

    /** Se llama con el candado de la tarea, antes de delegar al pipeline de sistema O de tienda. */
    public function may_start(Scheduled $row): bool
    {
        if (! self::available()) { return false; }
        $this->sync($row);
        if (Carbon::parse($row->starts_at_utc, 'UTC')->greaterThan(now())) { return false; }
        if ($row->overnight_fallback) {
            return $this->sent($this->operation($row, 'overnight'));
        }
        if ($row->agreement->maintenance_mode !== 'coordinated') { return true; }
        if ($row->reconfirmed_at !== null) { return true; }
        if ($row->reconfirmation_expires_at !== null
            && Carbon::parse($row->reconfirmation_expires_at, 'UTC')->lessThanOrEqualTo(now())) {
            $this->defer($row);
            return false;
        }
        if ($row->reason !== 'esperando_reconfirmacion') {
            $row->update(['reason' => 'esperando_reconfirmacion']);
        }
        return false;
    }

    public function request(int $ticket, array $input, string $kind): array
    {
        abort_unless(self::available(), 409, 'upgrade_reconfirmation_unavailable');
        $context = $this->context($ticket);
        $schedule = $input['schedule_id'] ?? ($context['schedule_id'] ?? null);
        $row = Scheduled::where('support_ticket_id', $ticket)->findOrFail($schedule);
        $this->assert_mission($row, $input);
        $marker = ['schedule_id' => (int) $row->id, 'kind' => $kind];
        $input['action'] = 'responder';
        $input['body'] = $this->body($row, $kind);
        $input['upgrade_reconfirmation'] = $marker;
        return app(SupportCodexService::class)->reply($ticket, $input);
    }

    /** Validación compartida por envío automático, aprobación editada y reintento, siempre antes de la red. */
    public function assert_reply(SupportTicket $ticket, array $input): void
    {
        if (! isset($input['upgrade_reconfirmation'])) { return; }
        $marker = $input['upgrade_reconfirmation'];
        $row = Scheduled::where('support_ticket_id', $ticket->id)->lockForUpdate()->findOrFail($marker['schedule_id']);
        $kind = $marker['kind'];
        abort_unless(in_array($kind, ['confirm', 'overnight'], true) && $ticket->source === 'whatsapp'
            && $ticket->client_employee_id === null && $ticket->status === 'open'
            && $row->status === Scheduled::SCHEDULED && $row->live_touched_at === null
            && $input['action'] === 'responder', 409, 'upgrade_reconfirmation_changed');
        if ($kind === 'confirm') {
            abort_unless(! $row->overnight_fallback && $row->agreement->maintenance_mode === 'coordinated'
                && $row->reconfirmed_at === null && Carbon::parse($row->starts_at_utc, 'UTC')->lessThanOrEqualTo(now())
                && ($row->reconfirmation_expires_at === null || Carbon::parse($row->reconfirmation_expires_at, 'UTC')->greaterThan(now())),
                409, 'upgrade_reconfirmation_changed');
        } else {
            abort_unless($row->agreement->maintenance_mode === 'coordinated' || $row->overnight_fallback, 409, 'upgrade_reconfirmation_changed');
            if (! $row->overnight_fallback) {
                // Esta alternativa es una decisión del dueño en el entrante vigente; el silencio lo procesa may_start.
                $inbound = SupportMessage::where('support_ticket_id', $ticket->id)->whereKey($input['expected_message_id'])
                    ->where('sender_type', 'user')->first();
                $body = $inbound === null ? '' : Str::ascii(mb_strtolower((string) $inbound->body));
                abort_unless(preg_match('/\b(no|madrugada|noche|manana|trabajando|usando|ocupad[oa])\b/u', $body), 422, 'upgrade_overnight_choice_required');
            }
        }
        abort_unless(hash_equals($this->body($row, $kind), trim((string) $input['body'])), 422, 'upgrade_reconfirmation_body_changed');
        $existing = $this->operation($row, $kind);
        abort_unless($existing === null || $existing->operation_id === $input['operation_id']
            || $this->replaceable($existing), 409, 'upgrade_reconfirmation_already_requested');
    }

    public function bind_operation(SupportCodexOperation $operation, array $input): void
    {
        if (! isset($input['upgrade_reconfirmation'])) { return; }
        $marker = $input['upgrade_reconfirmation'];
        $row = Scheduled::lockForUpdate()->findOrFail($marker['schedule_id']);
        $column = $marker['kind'] === 'overnight' ? 'overnight_notice_operation_id' : 'reconfirmation_operation_id';
        $prior = $row->$column === null ? null : SupportCodexOperation::find($row->$column);
        abort_unless($prior === null || (int) $prior->id === (int) $operation->id
            || $this->replaceable($prior), 409, 'upgrade_reconfirmation_operation_conflict');
        $row->update([$column => $operation->id]);
    }

    /** Devuelve null si proposal_operation_id pertenece a la propuesta inicial y no a la reconfirmación. */
    public function accept(SupportTicket $ticket, array $data): ?array
    {
        if (! self::available()) { return null; }
        $operation = SupportCodexOperation::where('support_ticket_id', $ticket->id)->where('operation_id', $data['proposal_operation_id'])->first();
        if ($operation === null) { return null; }
        $row = Scheduled::where('support_ticket_id', $ticket->id)->where('reconfirmation_operation_id', $operation->id)->lockForUpdate()->first();
        if ($row === null) { return null; }
        abort_unless(! isset($data['schedule_id']) || (int) $data['schedule_id'] === (int) $row->id, 409, 'upgrade_reconfirmation_changed');
        $this->sync($row);
        $this->assert_mission($row, $data);
        $inbound = SupportCodexUpgradeAgreementService::confirmation_message((int) $ticket->id, $data);
        abort_unless($row->status === Scheduled::SCHEDULED && ! $row->overnight_fallback && $this->sent($operation)
            && $row->reconfirmation_expires_at !== null && Carbon::parse($row->reconfirmation_expires_at, 'UTC')->greaterThan(now())
            && $inbound !== null && (int) $inbound->id > (int) $operation->message_id
            && $this->confirms($inbound, $operation), 422, 'upgrade_reconfirmation_required: No se pudo registrar la confirmación de la pausa vigente. No se autorizó el corte. Revisá el mensaje original del dueño y la propuesta de reconfirmación de esta misión; la aceptación del horario se conserva, pero hace falta una confirmación vigente de que nadie está usando el sistema.');
        if ($row->reconfirmed_at !== null) {
            abort_unless((int) $row->reconfirmation_message_id === (int) $inbound->id, 409, 'upgrade_reconfirmation_changed');
        } else {
            // El tiempo de espera no consume el corte prometido. Damos treinta minutos completos desde el sí.
            $start = now()->utc();
            $end = $start->copy()->addMinutes(30);
            $row->update(['reconfirmation_message_id' => $inbound->id, 'reconfirmed_at' => now(), 'reason' => null,
                'starts_at_utc' => $start->toDateTimeString(), 'ends_at_utc' => $end->toDateTimeString()]);
            $row->agreement->update(['starts_at_utc' => $start->toDateTimeString(), 'ends_at_utc' => $end->toDateTimeString()]);
        }
        return ['upgrade_reconfirmation' => ['schedule_id' => (int) $row->id, 'status' => 'confirmed', 'confirmation_message_id' => (int) $inbound->id],
            'upgrade_agreement' => ['status' => 'accepted', 'proposal_operation_id' => $operation->operation_id]];
    }

    public function valid_confirmation(Scheduled $row, ?SupportMessage $latest): bool
    {
        $this->sync($row);
        if ($row->overnight_fallback) {
            $operation = $this->operation($row, 'overnight');
            return $this->sent($operation) && $this->later_messages_allow((int) $row->support_ticket_id, (int) $operation->message_id);
        }
        $operation = $this->operation($row, 'confirm');
        $confirmation = SupportMessage::find($row->reconfirmation_message_id);
        return $row->reconfirmed_at !== null && $confirmation !== null && $latest !== null
            && (int) $latest->id >= (int) $confirmation->id && $this->sent($operation)
            && $this->confirms($confirmation, $operation)
            && $this->later_messages_allow((int) $row->support_ticket_id, (int) $confirmation->id);
    }

    /** Las cortesías no revocan un acuerdo. Cualquier otro entrante obliga a releer antes de escribir. */
    public function later_messages_allow(int $ticket, int $after): bool
    {
        $messages = SupportMessage::where('support_ticket_id', $ticket)->where('sender_type', 'user')->where('id', '>', $after);
        SupportMessage::apply_mensaje_real_conditions($messages, 'support_messages');
        foreach ($messages->get() as $message) {
            $body = Str::ascii(mb_strtolower((string) $message->body));
            $body = trim(preg_replace('/[^a-z0-9 ]+/', ' ', $body));
            if (! preg_match('/^(?:(?:ok|okay|dale|gracias|muchas|perfecto|listo|bueno|si|de|acuerdo|barbaro|genial)\s*)+$/D', $body)) { return false; }
        }
        return true;
    }

    public function sync_overnight_agreements(): void
    {
        if (! self::available()) { return; }
        foreach (SupportCodexUpgradeAgreement::where('maintenance_mode', 'overnight')->whereNull('accepted_at')->with('operation')->get() as $agreement) {
            if ($this->valid_overnight_agreement($agreement)) {
                $agreement->update(['accepted_at' => now(), 'confirmation_message_id' => $agreement->operation->expected_message_id]);
            }
        }
    }

    public function valid_overnight_agreement(SupportCodexUpgradeAgreement $agreement): bool
    {
        $operation = $agreement->operation;
        $message = $operation === null ? null : SupportMessage::find($operation->message_id);
        return $agreement->maintenance_mode === 'overnight' && $this->sent($operation) && $message !== null
            && (int) $message->support_ticket_id === (int) $agreement->support_ticket_id
            && hash_equals($agreement->proposal_body_hash, hash('sha256', (string) $message->body));
    }

    private function sync(Scheduled $row): void
    {
        $operation = $this->operation($row, 'confirm');
        if ($row->reconfirmation_requested_at === null && $this->sent($operation)) {
            $sent = $operation->updated_at->copy()->utc();
            $row->update(['reconfirmation_requested_at' => $sent->toDateTimeString(),
                'reconfirmation_expires_at' => $sent->copy()->addMinutes(15)->toDateTimeString()]);
        }
        $notice = $this->operation($row, 'overnight');
        if (! $row->overnight_fallback && $this->sent($notice)) { $this->defer($row); }
        if ($row->overnight_fallback && $this->sent($notice) && $row->reason === 'aviso_madrugada_pendiente') {
            $row->update(['reason' => null]);
        }
    }

    private function defer(Scheduled $row): void
    {
        abort_unless($row->live_touched_at === null && $row->status === Scheduled::SCHEDULED, 409, 'upgrade_already_started');
        $start = self::next_overnight();
        $end = $start->copy()->addHour();
        $row->update(['overnight_fallback' => true, 'starts_at_utc' => $start->copy()->utc()->toDateTimeString(),
            'ends_at_utc' => $end->copy()->utc()->toDateTimeString(), 'reason' => 'aviso_madrugada_pendiente']);
        $row->agreement->update(['starts_at_utc' => $row->starts_at_utc, 'ends_at_utc' => $row->ends_at_utc]);
    }

    private function operation(Scheduled $row, string $kind): ?SupportCodexOperation
    {
        $id = $kind === 'overnight' ? $row->overnight_notice_operation_id : $row->reconfirmation_operation_id;
        return $id === null ? null : SupportCodexOperation::find($id);
    }

    private function assert_mission(Scheduled $row, array $input): void
    {
        if (! isset($input['mission_id'])) { return; }
        $mission = \App\Models\SupportCodexMission::where('support_ticket_id', $row->support_ticket_id)->findOrFail($input['mission_id']);
        $evidence = \App\Models\SupportCodexMission::evidence_for_agreement($row->agreement);
        abort_unless($evidence !== null && $evidence->mission_thread_id === $mission->mission_thread_id
            && hash_equals($evidence->mission_hash, $mission->mission_hash), 409, 'upgrade_mission_changed');
    }

    private function sent(?SupportCodexOperation $operation): bool
    {
        return $operation !== null && in_array($operation->status, ['sent', 'reconciled_sent'], true);
    }

    private function replaceable(?SupportCodexOperation $operation): bool
    {
        return $operation !== null && in_array($operation->status, ['cancelled', 'reconciled_not_sent'], true)
            && strpos((string) $operation->detail, 'Lucas descartó') !== 0;
    }

    private function phrase(): string
    {
        return 'Confirmo que nadie está usando el sistema y pueden actualizar ahora';
    }

    private function confirms(SupportMessage $message, SupportCodexOperation $operation): bool
    {
        $body = SupportCodexUpgradeAgreementService::normalized_confirmation((string) $message->body);
        return ! empty($message->whatsapp_message_id)
            && ($message->reply_to_message_id === null || (int) $message->reply_to_message_id === (int) $operation->message_id)
            && ($body === SupportCodexUpgradeAgreementService::normalized_confirmation($this->phrase())
            || ((int) $message->reply_to_message_id === (int) $operation->message_id
                && in_array($body, ['si', 'confirmo', 'si, confirmo', 'dale', 'si, dale', 'pueden actualizar', 'nadie lo esta usando'], true)));
    }

    private function body(Scheduled $row, string $kind): string
    {
        if ($kind === 'overnight') {
            $start = $row->overnight_fallback ? Carbon::parse($row->starts_at_utc, 'UTC')->setTimezone(SupportMaintenancePolicy::TIMEZONE) : self::next_overnight();
            return 'Pasamos la actualización al ' . $start->format('d/m') . ' a las 02:00, hora argentina. Vamos a necesitar entre 15 y 30 minutos sin que usen el sistema ni la tienda afectada, para que mañana a primera hora lo tengas disponible. No hace falta que estés pendiente a esa hora.';
        }
        return 'Llegó el horario que acordamos para actualizar. El sistema y la tienda afectada no se van a poder usar durante unos 15 a 30 minutos. Confirmás que nadie los está usando y podemos empezar? Respondé citando este mensaje o escribí «' . $this->phrase() . '». Si no confirmás en 15 minutos, lo pasamos a las 02:00 de la próxima madrugada.';
    }
}

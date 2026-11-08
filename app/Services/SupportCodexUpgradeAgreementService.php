<?php

namespace App\Services;

use App\Models\SupportCodexCase;
use App\Models\SupportCodexMissionFixEvidence;
use App\Models\SupportCodexOperation;
use App\Models\SupportCodexUpgradeAgreement;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Guarda el acuerdo del negocio. La publicación y el despliegue tienen su propio circuito. */
class SupportCodexUpgradeAgreementService
{
    private const TIMEZONE = 'America/Argentina/Buenos_Aires';
    private const SENT = ['sent', 'reconciled_sent'];

    public function proposal_input(array $data): array
    {
        $start = Carbon::parse($data['starts_at'])->setTimezone(self::TIMEZONE);
        $end = Carbon::parse($data['ends_at'])->setTimezone(self::TIMEZONE);
        $date_and_time = $start->format('d/m/Y') . ' a las ' . $start->format('H:i');
        $phrase = 'Confirmo la actualización del ' . $date_and_time;
        $data['action'] = 'responder';
        $data['body'] = 'Ya encontramos y corregimos el problema. Para que el arreglo llegue a tu sistema, tenemos que actualizarlo. '
            . 'Podemos hacerlo el ' . $date_and_time . ', hora argentina, dentro del cierre que termina el '
            . $end->format('d/m/Y') . ' a las ' . $end->format('H:i') . '. Te sirve ese horario? '
            . 'Respondé citando este mensaje o escribí «' . $phrase . '».';
        $data['upgrade_proposal'] = [
            'starts_at_utc' => $start->copy()->utc()->toDateTimeString(),
            'ends_at_utc' => $end->copy()->utc()->toDateTimeString(),
            'minimum_minutes' => (int) $data['minimum_minutes'],
            'confirmation_phrase' => $phrase,
            'mission_thread_id' => $data['mission_thread_id'],
            'mission_hash' => $data['mission_hash'],
        ];
        unset($data['starts_at'], $data['ends_at'], $data['minimum_minutes'], $data['mission_thread_id'], $data['mission_hash']);

        return $data;
    }

    public function assert_valid_proposal(SupportTicket $ticket, SupportCodexCase $case, array $proposal): SupportCodexMissionFixEvidence
    {
        $this->assert_owner($ticket);
        $evidence = SupportCodexMissionFixEvidence::current_for($ticket, $case);
        if ($evidence === null || $evidence->mission_thread_id !== $proposal['mission_thread_id']
            || ! hash_equals($evidence->mission_hash, $proposal['mission_hash'])) {
            $this->fail(409, 'mission_evidence_changed', 'La corrección integrada no corresponde a la misión vigente.');
        }

        $start = Carbon::parse($proposal['starts_at_utc'], 'UTC');
        $end = Carbon::parse($proposal['ends_at_utc'], 'UTC');
        if ($start->lessThanOrEqualTo(now()) || $start->greaterThan(now()->addDays(7))) {
            $this->fail(409, 'upgrade_window_changed', 'Proponé una ventana futura dentro de los próximos siete días.');
        }
        $window = app(ClientScheduleResolver::class)->primera_ventana_cerrada($ticket->client, $start, (int) $proposal['minimum_minutes'], self::TIMEZONE)['window'];
        if ($window === null || $window['starts_at'] !== $start->setTimezone(self::TIMEZONE)->toIso8601String()
            || $window['ends_at'] !== $end->setTimezone(self::TIMEZONE)->toIso8601String()) {
            $this->fail(409, 'upgrade_window_changed', 'El horario ya no coincide con una ventana de cierre confirmable.');
        }

        return $evidence;
    }

    public function record_proposal(SupportCodexOperation $operation, SupportCodexMissionFixEvidence $evidence, array $proposal, string $body): void
    {
        $prior = SupportCodexUpgradeAgreement::where('mission_fix_evidence_id', $evidence->id)
            ->where('support_codex_operation_id', '!=', $operation->id);
        foreach ((clone $prior)->whereNotNull('accepted_at')->get() as $accepted) {
            if (! $this->can_replace_accepted($accepted)) {
                $this->fail(409, 'proposal_already_accepted', 'Ya se acordó una actualización para esta corrección.');
            }
            if ($proposal['starts_at_utc'] < $accepted->ends_at_utc) {
                $this->fail(409, 'upgrade_window_changed', 'La nueva propuesta debe empezar después de la ventana bloqueada.');
            }
        }
        if ($prior->where('starts_at_utc', $proposal['starts_at_utc'])->where('ends_at_utc', $proposal['ends_at_utc'])
            ->whereHas('operation', function ($query) { $query->whereIn('status', self::SENT); })->exists()) {
            $this->fail(409, 'proposal_already_sent', 'Esa ventana ya se propuso para esta corrección.');
        }
        $body_hash = hash('sha256', $body);
        $agreement = SupportCodexUpgradeAgreement::firstOrCreate(['support_codex_operation_id' => $operation->id], [
            'support_ticket_id' => $operation->support_ticket_id,
            'mission_fix_evidence_id' => $evidence->id,
            'starts_at_utc' => $proposal['starts_at_utc'],
            'ends_at_utc' => $proposal['ends_at_utc'],
            'minimum_minutes' => $proposal['minimum_minutes'],
            'proposal_body_hash' => $body_hash,
            'confirmation_phrase' => $proposal['confirmation_phrase'],
        ]);
        if ((int) $agreement->mission_fix_evidence_id !== (int) $evidence->id
            || (int) $agreement->minimum_minutes !== (int) $proposal['minimum_minutes']
            || ! hash_equals($agreement->proposal_body_hash, $body_hash)) {
            $this->fail(409, 'operation_conflict', 'La clave de operación ya corresponde a otra propuesta.');
        }
    }

    public function pending_offer(SupportTicket $ticket, SupportCodexCase $case): bool
    {
        if ($case->state !== 'mission_pending' || $ticket->source !== 'whatsapp' || $ticket->client_employee_id !== null || $ticket->client === null) {
            return false;
        }
        $evidence = SupportCodexMissionFixEvidence::current_for($ticket, $case);
        if ($evidence === null) {
            return false;
        }
        $proposals = SupportCodexUpgradeAgreement::where('mission_fix_evidence_id', $evidence->id);
        foreach ((clone $proposals)->whereNotNull('accepted_at')->get() as $accepted) {
            if (! $this->can_replace_accepted($accepted)) {
                return false;
            }
        }
        $previous = $proposals->latest('id')->with('operation')->first();

        return $previous === null || $previous->accepted_at !== null || ($previous->operation !== null
            && in_array($previous->operation->status, array_merge(self::SENT, ['cancelled', 'reconciled_not_sent']), true)
            && Carbon::parse($previous->starts_at_utc, 'UTC')->lessThanOrEqualTo(now()));
    }

    private function can_replace_accepted(SupportCodexUpgradeAgreement $agreement): bool
    {
        $scheduled = DB::table('support_codex_scheduled_upgrades')->where('agreement_id', $agreement->id)->first();
        if ($scheduled !== null) {
            return $scheduled->status === 'blocked' && $scheduled->live_touched_at === null;
        }

        return Carbon::parse($agreement->starts_at_utc, 'UTC')->lessThanOrEqualTo(now())
            && ! DB::table('support_codex_release_claims')->where('agreement_id', $agreement->id)->whereNotNull('active_key')->exists();
    }

    public function reoffer_after(SupportTicket $ticket, SupportCodexCase $case): ?Carbon
    {
        $evidence = SupportCodexMissionFixEvidence::current_for($ticket, $case);
        if ($evidence === null) {
            return null;
        }
        $previous = SupportCodexUpgradeAgreement::where('mission_fix_evidence_id', $evidence->id)
            ->whereNotNull('accepted_at')->latest('id')->first();

        return $previous !== null && $this->can_replace_accepted($previous) ? Carbon::parse($previous->ends_at_utc, 'UTC') : null;
    }

    public function context(?SupportCodexMissionFixEvidence $evidence): ?array
    {
        if ($evidence === null) {
            return null;
        }
        $proposal = SupportCodexUpgradeAgreement::where('mission_fix_evidence_id', $evidence->id)
            ->latest('id')->with('operation')->first();

        return $proposal === null || $proposal->operation === null ? null : $this->present($proposal);
    }

    public function accept(int $id, array $data, SupportCodexService $support): array
    {
        return DB::transaction(function () use ($id, $data, $support) {
            $ticket = SupportTicket::with('client')->lockForUpdate()->findOrFail($id);
            $this->assert_owner($ticket);
            $case = $support->assert_current($ticket, $data + ['action' => 'responder']);
            $evidence = SupportCodexMissionFixEvidence::current_for($ticket, $case);
            $proposal = $evidence === null ? null : SupportCodexUpgradeAgreement::where('mission_fix_evidence_id', $evidence->id)
                ->whereHas('operation', function ($query) { $query->whereIn('status', self::SENT); })
                ->with('operation')->latest('id')->lockForUpdate()->first();
            if ($proposal === null || $proposal->operation->operation_id !== $data['proposal_operation_id']) {
                $this->fail(409, 'proposal_not_current', 'La propuesta no es la última enviada para esta corrección.');
            }
            $outbound = SupportMessage::where('support_ticket_id', $id)->whereKey($proposal->operation->message_id)
                ->where('sender_type', 'admin')->first();
            $inbound = SupportMessage::where('support_ticket_id', $id)->whereKey($data['expected_message_id'])
                ->where('sender_type', 'user')->first();
            if (! $this->is_exact_confirmation($proposal, $inbound, $outbound)) {
                $this->fail(422, 'upgrade_confirmation_required', 'Hace falta que el dueño confirme esa propuesta exacta en el ticket.');
            }
            $this->assert_valid_proposal($ticket, $case, [
                'mission_thread_id' => $evidence->mission_thread_id, 'mission_hash' => $evidence->mission_hash,
                'starts_at_utc' => $proposal->starts_at_utc, 'ends_at_utc' => $proposal->ends_at_utc,
                'minimum_minutes' => $proposal->minimum_minutes,
            ]);
            if ($proposal->accepted_at !== null && (int) $proposal->confirmation_message_id !== (int) $inbound->id) {
                $this->fail(409, 'proposal_already_accepted', 'La propuesta ya tiene un acuerdo registrado.');
            }
            if ($proposal->accepted_at === null) {
                $proposal->confirmation_message_id = $inbound->id;
                $proposal->accepted_at = now();
                $proposal->save();
            }

            return ['upgrade_agreement' => $this->present($proposal)];
        });
    }

    public function is_exact_confirmation(SupportCodexUpgradeAgreement $proposal, ?SupportMessage $inbound, ?SupportMessage $outbound): bool
    {
        return $outbound !== null && $inbound !== null
            && $outbound->sender_type === 'admin' && $inbound->sender_type === 'user'
            && (int) $outbound->support_ticket_id === (int) $proposal->support_ticket_id
            && (int) $inbound->support_ticket_id === (int) $proposal->support_ticket_id
            && ! empty($outbound->whatsapp_message_id) && ! empty($inbound->whatsapp_message_id)
            && (int) $inbound->id > (int) $outbound->id
            && hash_equals($proposal->proposal_body_hash, hash('sha256', (string) $outbound->body))
            && $this->confirms($inbound, $outbound, $proposal);
    }

    private function assert_owner(SupportTicket $ticket): void
    {
        if ($ticket->source !== 'whatsapp' || $ticket->client_employee_id !== null || $ticket->client === null) {
            $this->fail(403, 'upgrade_owner_required', 'La actualización se coordina con el dueño del negocio.');
        }
    }

    private function confirms(SupportMessage $inbound, SupportMessage $outbound, SupportCodexUpgradeAgreement $proposal): bool
    {
        $body = $this->normalized_confirmation((string) $inbound->body);
        if ($inbound->reply_to_message_id === null) {
            return $body === $this->normalized_confirmation($proposal->confirmation_phrase);
        }
        if ((int) $inbound->reply_to_message_id !== (int) $outbound->id) {
            return false;
        }

        return in_array($body, ['si', 'si, confirmo', 'si, acepto', 'confirmo', 'acepto', 'dale', 'si, dale', 'de acuerdo', 'si, pueden actualizarlo'], true)
            || $body === $this->normalized_confirmation($proposal->confirmation_phrase);
    }

    private function normalized_confirmation(string $body): string
    {
        return Str::ascii(mb_strtolower(rtrim(trim($body), '.! ')));
    }

    private function present(SupportCodexUpgradeAgreement $proposal): array
    {
        $sent = in_array($proposal->operation->status, self::SENT, true);
        return [
            'proposal_operation_id' => $proposal->operation->operation_id,
            'proposal_message_id' => $sent ? (int) $proposal->operation->message_id : null,
            'status' => $proposal->accepted_at !== null ? 'accepted' : ($sent ? 'sent' : $proposal->operation->status),
            'starts_at' => Carbon::parse($proposal->starts_at_utc, 'UTC')->setTimezone(self::TIMEZONE)->toIso8601String(),
            'ends_at' => Carbon::parse($proposal->ends_at_utc, 'UTC')->setTimezone(self::TIMEZONE)->toIso8601String(),
            'timezone' => self::TIMEZONE,
            'confirmation_message_id' => $proposal->confirmation_message_id === null ? null : (int) $proposal->confirmation_message_id,
            'accepted_at' => $proposal->accepted_at === null ? null : $proposal->accepted_at->toIso8601String(),
        ];
    }

    private function fail(int $status, string $code, string $message): void
    {
        throw new HttpException($status, $code . ': ' . $message);
    }
}

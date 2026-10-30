<?php

namespace App\Services;

use App\Jobs\EnviarAvisoDeSoporteJob;
use App\Models\Admin;
use App\Models\AdminPushSubscription;
use App\Models\SupportAttentionNotification;
use App\Models\SupportTicket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Avisos de atención de Codex: revisión humana y misión por iniciar son motivos independientes. */
class SupportAttentionNotificationService
{
    public function registrar(int $ticket_id): void
    {
        DB::transaction(function () use ($ticket_id) {
            $ticket = SupportTicket::whereKey($ticket_id)->lockForUpdate()->first();
            if ($ticket === null) {
                return;
            }
            $ticket->load('support_codex_case');
            // El agente legacy conserva su aviso y respaldo WhatsApp existentes; no se duplica.
            if ($ticket->support_codex_case === null) {
                return;
            }
            $state = $this->estado($ticket);
            $notification = SupportAttentionNotification::firstOrCreate(['support_ticket_id' => $ticket_id]);
            $fingerprint = $state === null ? null : $state['fingerprint'];
            if ($notification->fingerprint === $fingerprint) {
                if ($fingerprint !== null && $notification->notified_at === null
                    && ($notification->processing_until === null || $notification->processing_until->isPast())
                    && ($notification->enqueued_at === null || $notification->enqueued_at->lte(now()->subMinutes(5)))) {
                    $this->encolar($notification);
                }
                return;
            }
            $notification->fingerprint = $fingerprint;
            $notification->generation = (int) $notification->generation + 1;
            $notification->dispatched_admin_ids = [];
            $notification->accepted_endpoint_hashes = [];
            $notification->processing_until = null;
            $notification->notified_at = null;
            $notification->enqueued_at = null;
            $notification->save();
            if ($fingerprint !== null) {
                $this->encolar($notification);
            }
        });
    }

    private function encolar(SupportAttentionNotification $notification): void
    {
        $ticket_id = (int) $notification->support_ticket_id;
        $generation = (int) $notification->generation;
        $notification->enqueued_at = now();
        $notification->save();
        DB::afterCommit(function () use ($ticket_id, $generation) {
            try {
                EnviarAvisoDeSoporteJob::dispatch($ticket_id, $generation);
            } catch (\Throwable $exception) {
                // La fila durable queda pendiente y el conciliador vuelve a encolarla.
                Log::channel('daily')->error('Soporte: el aviso push quedó pendiente de cola.', [
                    'ticket_id' => $ticket_id,
                ]);
            }
        });
    }

    /** Recupera fallas de registro/cola y pendientes que ya existían antes del despliegue. */
    public function conciliar(): void
    {
        SupportTicket::where('status', SupportTicket::STATUS_ABIERTO)->whereHas('support_codex_case')
            ->select('id')->chunkById(100, function ($tickets) {
                foreach ($tickets as $ticket) {
                    $this->registrar((int) $ticket->id);
                }
            });
    }

    /** Usa el mismo resumen que la bandeja; no interpreta escalated_at como misión por iniciar. */
    private function estado(SupportTicket $ticket): ?array
    {
        $attention = $ticket->support_attention;
        if ($attention === null || $ticket->status !== SupportTicket::STATUS_ABIERTO) {
            return null;
        }
        $human = (bool) $attention['needs_human'];
        $mission = $attention['mission'] !== null && $attention['mission']['status'] === 'pending';
        $approval = \App\Models\SupportCodexOperation::where('support_ticket_id', $ticket->id)
            ->where('status', 'awaiting_approval')->orderByDesc('id')->first();
        if (! $human && ! $mission && $approval === null) {
            return null;
        }
        $case = $ticket->support_codex_case;
        return [
            'human' => $human,
            'mission' => $mission,
            'approval' => $approval !== null,
            // El diagnóstico solo participa del hash; nunca sale en el push ni en un evento público.
            'fingerprint' => hash('sha256', json_encode([
                $human, $human ? $attention['reason'] : null,
                $mission, $mission ? $case->mission : null,
                $approval !== null ? $approval->operation_id : null,
                $ticket->assigned_admin_id,
            ])),
        ];
    }

    public function destinatarios(SupportTicket $ticket): array
    {
        return Admin::where(function ($query) use ($ticket) {
            $query->where('is_default_support_owner', true)
                ->orWhere('notify_support_escalation_whatsapp', true);
            if ($ticket->assigned_admin_id !== null) {
                $query->orWhere('id', $ticket->assigned_admin_id);
            }
        })->pluck('id')->map(function ($id) { return (int) $id; })->all();
    }

    public function enviar(int $ticket_id, int $generation): void
    {
        $ticket = SupportTicket::with('support_codex_case', 'client', 'client_employee')->find($ticket_id);
        if ($ticket === null) {
            return;
        }
        $state = $this->estado($ticket);
        $notification = DB::transaction(function () use ($ticket_id, $generation, $state) {
            $row = SupportAttentionNotification::where('support_ticket_id', $ticket_id)->lockForUpdate()->first();
            if ($row === null || $row->generation !== $generation || $row->notified_at !== null
                || $state === null || $row->fingerprint !== $state['fingerprint']
                || ($row->processing_until !== null && $row->processing_until->isFuture())) {
                return null;
            }
            // No se sostiene un lock durante la red: una push lenta no bloquea la conversación.
            $row->processing_until = now()->addMinutes(2);
            $row->save();
            return $row;
        });
        if ($notification === null) {
            return;
        }
        $ids = $this->destinatarios($ticket);
        $with_device = AdminPushSubscription::whereIn('admin_id', $ids)->distinct()->pluck('admin_id')->all();
        $dispatched = $notification->dispatched_admin_ids ?: [];
        $accepted_hashes = $notification->accepted_endpoint_hashes ?: [];
        $failed = false;
        $without_device = array_values(array_diff($ids, $with_device));
        $title = 'Soporte #' . $ticket_id . ': ' . $ticket->resolve_contact_display_name();
        $body = $state['approval'] ? 'Hay una respuesta lista que espera tu aprobación.' : ($state['human'] && $state['mission']
            ? 'Necesita tu revisión y tiene una misión pendiente de iniciar.'
            : ($state['human'] ? 'Este ticket necesita tu revisión.' : 'Hay una misión pendiente de iniciar.'));
        foreach ($ids as $admin_id) {
            if (in_array($admin_id, $dispatched, true) || ! in_array($admin_id, $with_device)) {
                continue;
            }
            // La misión pudo iniciarse o el ticket cerrarse desde que el job fue reservado.
            $current = SupportAttentionNotification::where('support_ticket_id', $ticket_id)->first();
            if ($current === null || $current->generation !== $generation) {
                return;
            }
            try {
                $result = $this->send_push($admin_id, $title, $body, [
                    'url' => '/soporte/ticket/' . $ticket_id,
                    'tag' => 'soporte-' . $ticket_id,
                    'ticket_id' => $ticket_id,
                    'needs_review' => $state['human'],
                    'mission_pending' => $state['mission'],
                    'approval_pending' => $state['approval'],
                ], $accepted_hashes);
                $accepted_hashes = array_values(array_unique(array_merge($accepted_hashes, $result['accepted_endpoint_hashes'])));
                if ($result['complete']) {
                    $dispatched[] = $admin_id;
                } else {
                    $failed = true;
                }
                SupportAttentionNotification::whereKey($notification->id)->where('generation', $generation)
                    ->update([
                        'dispatched_admin_ids' => json_encode($dispatched),
                        'accepted_endpoint_hashes' => json_encode($accepted_hashes),
                    ]);
            } catch (\Throwable $exception) {
                $failed = true;
                Log::channel('daily')->warning('Soporte: falló el despacho de la push.', [
                    'ticket_id' => $ticket_id, 'admin_id' => $admin_id,
                ]);
            }
        }
        SupportAttentionNotification::whereKey($notification->id)->where('generation', $generation)->update([
            'processing_until' => null,
            // Una suscripción que se recupere mientras el ticket sigue pendiente todavía debe avisar.
            'notified_at' => $failed || ! empty($without_device) || empty($ids) ? null : now(),
        ]);
        if ($failed) {
            throw new \RuntimeException('No se pudieron despachar todos los avisos push de soporte.');
        }
        Log::channel('daily')->info('Soporte: aviso push procesado.', [
            'ticket_id' => $ticket_id, 'admin_ids' => $dispatched,
            'sin_device' => $without_device,
        ]);
    }

    protected function send_push(int $admin_id, string $title, string $body, array $data, array $accepted_hashes = []): array
    {
        return app(AdminPushNotificationService::class)->send_to_admin_with_result($admin_id, $title, $body, $data, $accepted_hashes);
    }
}

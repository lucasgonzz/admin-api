<?php
namespace App\Services;
use App\Jobs\EnviarAvisoDeMensajeDeSoporteJob;
use App\Models\SupportMessage;
use App\Models\SupportMessageNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
/** La campanita es por operador y por ticket; el registro durable evita duplicar ecos/reintentos. */
class SupportMessageNotificationService
{
    public function registrar(SupportMessage $message): void
    {
        if (! $this->es_entrante($message)) { return; }
        $ids = DB::table('support_ticket_admin_notifications')->where('support_ticket_id', $message->support_ticket_id)->where('after_message_id', '<', $message->id)->pluck('admin_id');
        foreach ($ids as $id) {
            SupportMessageNotification::firstOrCreate(['support_message_id' => $message->id, 'admin_id' => $id]);
        }
        if ($ids->isNotEmpty()) {
            $id = (int) $message->id;
            DB::afterCommit(function () use ($id) { EnviarAvisoDeMensajeDeSoporteJob::dispatch($id); });
        }
    }
    /** El conciliador recupera caídas de worker o cola sin volver a avisar endpoints aceptados. */
    public function conciliar(): void
    {
        // Si el observer no llegó a crear la fila, recupera sólo mensajes posteriores al alta
        // de esa suscripción. Nunca se recorre el histórico para avisarle a alguien nuevo.
        SupportMessage::where('sender_type', 'user')->whereExists(function ($query) {
            $query->selectRaw('1')->from('support_ticket_admin_notifications as subscription')
                ->whereColumn('subscription.support_ticket_id', 'support_messages.support_ticket_id')
                ->whereColumn('subscription.after_message_id', '<', 'support_messages.id')
                ->whereNotExists(function ($notice) {
                    $notice->selectRaw('1')->from('support_message_notifications')
                        ->whereColumn('support_message_notifications.support_message_id', 'support_messages.id')
                        ->whereColumn('support_message_notifications.admin_id', 'subscription.admin_id');
                });
        })->where('is_ai_suggestion_draft', false)->where('is_trial_import_notice', false)->chunkById(100, function ($messages) {
            foreach ($messages as $message) { $this->registrar($message); }
        });
        SupportMessageNotification::whereNull('notified_at')->where(function ($query) {
            $query->whereNull('processing_until')->orWhere('processing_until', '<=', now());
        })->select('support_message_id')->distinct()->orderBy('support_message_id')->chunk(100, function ($rows) {
            foreach ($rows as $row) { EnviarAvisoDeMensajeDeSoporteJob::dispatch((int) $row->support_message_id); }
        });
    }
    private function es_entrante(SupportMessage $message): bool
    {
        return $message->sender_type === 'user' && SupportMessage::cuenta_para_respuesta($message)
            && $message->ticket !== null && ! $message->ticket->es_de_ensayo();
    }
    public function enviar(int $message_id): void
    {
        $message = SupportMessage::with('ticket.client', 'ticket.client_employee')->find($message_id);
        if ($message === null || ! $this->es_entrante($message)) {
            SupportMessageNotification::where('support_message_id', $message_id)->whereNull('notified_at')->update(['notified_at' => now(), 'processing_until' => null]);
            return;
        }
        $failed = false;
        foreach (SupportMessageNotification::where('support_message_id', $message_id)->whereNull('notified_at')->get() as $notice) {
            $notice = DB::transaction(function () use ($notice) {
                $row = SupportMessageNotification::whereKey($notice->id)->lockForUpdate()->first();
                if ($row->notified_at !== null || ($row->processing_until !== null && $row->processing_until->isFuture())) { return null; }
                $row->processing_until = now()->addMinutes(2);
                $row->save();
                return $row;
            });
            if ($notice === null) { continue; }
            $subscribed = DB::table('support_ticket_admin_notifications')->where('support_ticket_id', $message->support_ticket_id)->where('admin_id', $notice->admin_id)->where('after_message_id', '<', $message_id)->exists();
            if (! $subscribed) {
                $notice->update(['processing_until' => null, 'notified_at' => now()]);
                continue;
            }
            try {
                $preview = trim((string) $message->body);
                $result = $this->send_push((int) $notice->admin_id,
                    'Mensaje en soporte #' . $message->support_ticket_id . ': ' . $message->ticket->resolve_contact_display_name(),
                    mb_strimwidth($preview !== '' ? $preview : 'El cliente envió un archivo o audio.', 0, 160, '…'),
                    ['url' => '/soporte/ticket/' . $message->support_ticket_id, 'tag' => 'soporte-mensaje-' . $message_id, 'ticket_id' => (int) $message->support_ticket_id], $notice->accepted_endpoint_hashes ?: []);
                $notice->update(['accepted_endpoint_hashes' => array_values(array_unique(array_merge($notice->accepted_endpoint_hashes ?: [], $result['accepted_endpoint_hashes']))),
                    'processing_until' => null, 'notified_at' => $result['complete'] ? now() : null]);
                $failed = $failed || (! $result['complete'] && \App\Models\AdminPushSubscription::where('admin_id', $notice->admin_id)->exists());
            } catch (\Throwable $exception) {
                $notice->update(['processing_until' => null]);
                $failed = true;
                Log::warning('Soporte: aviso de mensaje pendiente.', ['message_id' => $message_id, 'admin_id' => $notice->admin_id]);
            }
        }
        if ($failed) { throw new \RuntimeException('Quedaron avisos de mensajes de soporte pendientes.'); }
    }
    protected function send_push(int $id, string $title, string $body, array $data, array $accepted): array
    {
        return AdminPushNotificationService::send_to_admin_with_result($id, $title, $body, $data, $accepted);
    }
}

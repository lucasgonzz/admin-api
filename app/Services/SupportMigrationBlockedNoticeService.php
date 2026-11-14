<?php
namespace App\Services;
use App\Mail\SupportMigrationBlockedMail;
use App\Models\SupportCodexOperation;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/** El correo pendiente es durable y se recupera sin reenviar el WhatsApp al negocio. */
class SupportMigrationBlockedNoticeService
{
    public function conciliar(): void
    {
        SupportCodexOperation::whereNotNull('migration_blocked_reason')->whereNull('migration_notice_sent_at')
            ->whereNull('migration_notice_state')->whereIn('status', ['sent', 'reconciled_sent'])->select('id')->chunkById(50, function ($rows) {
                foreach ($rows as $row) {
                    try {
                        $this->enviar((int) $row->id);
                    } catch (\Throwable $e) {
                        // No registrar el error SMTP: puede contener credenciales. El estado durable distingue pendiente de incierto.
                        Log::channel('daily')->warning('Migración bloqueada: revisar estado del correo de aviso.', ['operation_id' => $row->id]);
                    }
                }
            });
    }

    public function enviar(int $id): void
    {
        if (!app()->environment('testing') && config('mail.mailers.support_monitor.transport') !== 'smtp') {
            throw new \RuntimeException('El correo de soporte requiere el transporte SMTP configurado.');
        }
        $notice = DB::transaction(function () use ($id) {
            // La reserva se confirma antes de SMTP. Un proceso caído queda sending para conciliación humana.
            $operation = SupportCodexOperation::whereKey($id)->lockForUpdate()->first();
            if ($operation === null || $operation->migration_notice_state !== null || $operation->migration_notice_sent_at !== null
                || trim((string) $operation->migration_blocked_reason) === ''
                || !in_array($operation->status, ['sent', 'reconciled_sent'], true)) {
                return null;
            }
            $ticket = SupportTicket::with('client')->findOrFail($operation->support_ticket_id);
            $source = SupportMessage::whereKey($operation->expected_message_id)
                ->where('support_ticket_id', $ticket->id)->where('sender_type', 'user')->first();
            $operation->migration_notice_state = 'sending';
            $operation->save();
            return [
                'ticket_id' => $ticket->id,
                'client_id' => $ticket->client_id,
                'name' => $ticket->client === null ? $ticket->client_user_name : $ticket->client->company_name,
                'contact' => $ticket->client === null ? '' : $ticket->client->name,
                'reason' => $operation->migration_blocked_reason,
                'source' => ($source === null ? '(mensaje no disponible)' : $source->body) . "\n\nÚltimos mensajes del negocio:\n"
                    . SupportMessage::where('support_ticket_id', $ticket->id)->where('sender_type', 'user')
                        ->orderByDesc('id')->limit(5)->pluck('body')->implode("\n"),
                'url' => rtrim((string) config('services.admin_spa.url'), '/') . '/soporte?ticket_id=' . $ticket->id,
            ];
        });
        if ($notice === null) { return; }
        try {
            Mail::mailer('support_monitor')->to(SupportCodexHealthService::RECIPIENT)->send(new SupportMigrationBlockedMail($notice));
        } catch (\Throwable $e) {
            // Un timeout SMTP puede ocurrir DESPUÉS de aceptar DATA. Nunca duplicar a ciegas.
            SupportCodexOperation::whereKey($id)->update(['migration_notice_state' => 'uncertain']);
            throw $e;
        }
        SupportCodexOperation::whereKey($id)->update(['migration_notice_state' => 'sent', 'migration_notice_sent_at' => now()]);
    }
}

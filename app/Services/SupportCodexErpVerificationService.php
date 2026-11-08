<?php

namespace App\Services;

use App\Models\SupportMessage;
use App\Models\SupportTicket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Acreditación del entrante del ERP por cotejo del monitor con la instancia de Empresa. */
class SupportCodexErpVerificationService
{
    public const MAX_ADJUNTOS = 12;
    private const MAX_BYTES = 20 * 1024 * 1024;
    private const PAGINA = 20;

    private $codex;

    public function __construct(SupportCodexService $codex)
    {
        $this->codex = $codex;
    }

    public function candidates(int $despues): array
    {
        $config = SupportCodexSettings::to_array();
        if (! $config['enabled'] || $config['activated_at'] === null) {
            return ['candidates' => [], 'next_after_message_id' => null];
        }

        $query = SupportMessage::where('id', '>', $despues)->where('is_trial_import_notice', false)
            ->whereNull('erp_auto_reply->' . SupportMessage::MARCA_ERP_VERIFICADO_PARA_CODEX)
            ->whereHas('ticket', function ($ticket) {
                $ticket->where('source', 'erp')->where('agentes_erp', false)->where('status', 'open')
                    ->where('claude_auto_reply', true)->where('client_user_id', '>', 0);
            })->whereNotExists(function ($posterior) {
                $posterior->selectRaw('1')->from('support_messages as posterior')
                    ->whereColumn('posterior.support_ticket_id', 'support_messages.support_ticket_id')
                    ->whereColumn('posterior.id', '>', 'support_messages.id')->where('posterior.is_trial_import_notice', false);
                SupportMessage::apply_mensaje_real_conditions($posterior, 'posterior');
            });
        SupportCodexSettings::entrantes_desde_el_corte($query, $config['activated_at']);
        $encontrados = $query->with(['ticket.support_codex_case', 'attachments'])->orderBy('id')->limit(self::PAGINA + 1)->get();
        $pagina = $encontrados->take(self::PAGINA);
        $candidatos = [];
        foreach ($pagina as $mensaje) {
            $ticket = $mensaje->ticket;
            if ($ticket === null || ! Str::isUuid((string) $ticket->uuid) || ! Str::isUuid((string) $mensaje->uuid)
                || ! $this->codex->puede_cotejar_entrante_erp($ticket, $mensaje)) {
                continue;
            }
            $adjuntos = $this->hashes_adjuntos($mensaje);
            if ($adjuntos === null) {
                continue;
            }
            $candidatos[] = [
                'ticket_id' => (int) $ticket->id, 'client_id' => (int) $ticket->client_id,
                'client_user_id' => (int) $ticket->client_user_id, 'ticket_uuid' => (string) $ticket->uuid,
                'message_id' => (int) $mensaje->id, 'message_uuid' => (string) $mensaje->uuid,
                'kind' => (string) $mensaje->kind, 'body_sha256' => hash('sha256', (string) $mensaje->body),
                'attachments' => $adjuntos,
            ];
        }

        return ['candidates' => $candidatos, 'next_after_message_id' => $encontrados->count() > self::PAGINA
            ? (int) $pagina->last()->id : null];
    }

    public function confirm(array $data): void
    {
        DB::transaction(function () use ($data) {
            // Comparte el candado con el ingreso del ERP, la toma de Codex y la respuesta humana.
            $ticket = SupportTicket::lockForUpdate()->findOrFail($data['ticket_id']);
            $query = $ticket->messages()->reorder('id', 'desc');
            SupportMessage::apply_mensaje_real_conditions($query, 'support_messages');
            $mensaje = $query->where('is_trial_import_notice', false)->with('attachments')->lockForUpdate()->first();
            $activado = SupportCodexSettings::activated_at();
            abort_unless($activado !== null && $mensaje !== null
                && (int) $ticket->client_id === (int) $data['client_id']
                && (int) $ticket->client_user_id === (int) $data['client_user_id'] && $ticket->uuid === $data['ticket_uuid']
                && (int) $mensaje->id === (int) $data['message_id'] && $mensaje->uuid === $data['message_uuid']
                && $mensaje->kind === $data['kind']
                && SupportCodexSettings::entrantes_desde_el_corte($ticket->messages()->whereKey($mensaje->id), $activado)->exists()
                && $this->codex->puede_cotejar_entrante_erp($ticket, $mensaje), 409, 'El entrante ya no está pendiente de cotejo.');

            $adjuntos = $this->hashes_adjuntos($mensaje);
            abort_unless($adjuntos !== null && $adjuntos === $data['attachments']
                && hash_equals(hash('sha256', (string) $mensaje->body), $data['body_sha256']),
                409, 'El cuerpo o los adjuntos cambiaron desde el cotejo.');

            $marcas = $mensaje->erp_auto_reply ?? [];
            if (($marcas[SupportMessage::MARCA_ERP_VERIFICADO_PARA_CODEX] ?? false) === true) {
                return;
            }
            $marcas[SupportMessage::MARCA_ERP_VERIFICADO_PARA_CODEX] = true;
            $mensaje->erp_auto_reply = $marcas;
            $mensaje->save();
        });
    }

    private function hashes_adjuntos(SupportMessage $mensaje): ?array
    {
        if ($mensaje->attachments->count() > self::MAX_ADJUNTOS) {
            return null;
        }
        $restan = self::MAX_BYTES;
        $hashes = [];
        foreach ($mensaje->attachments as $adjunto) {
            // La metadata del ERP antiguo puede traer un path sin subir el binario. Solo
            // abrimos copias del disco público con el formato que genera UploadedFile::store.
            $patron = '~\Asupport_messages/' . (int) $mensaje->support_ticket_id . '/[A-Za-z0-9]{40}(?:\.[A-Za-z0-9]{1,16})?\z~';
            if ($adjunto->disk !== 'public' || ! preg_match($patron, (string) $adjunto->path)) {
                return null;
            }
            try {
                $stream = Storage::disk('public')->readStream($adjunto->path);
            } catch (\Throwable $exception) {
                return null;
            }
            if (! is_resource($stream)) {
                return null;
            }
            try {
                $contexto = hash_init('sha256');
                $bytes = hash_update_stream($contexto, $stream, $restan + 1);
            } finally {
                fclose($stream);
            }
            if ($bytes > $restan) {
                return null;
            }
            $restan -= $bytes;
            $hashes[] = hash_final($contexto);
        }
        sort($hashes, SORT_STRING);
        return $hashes;
    }
}

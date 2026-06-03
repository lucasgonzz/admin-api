<?php

namespace App\Console\Commands;

use App\Models\LeadMessage;
use App\Models\LeadMessageAttachment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Diagnóstico rápido de mensajes de audio en leads (migraciones, adjuntos, kinds).
 */
class DiagnoseLeadAudioMessagesCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'lead:diagnose-audio {--limit=10 : Cantidad de mensajes recientes a listar}';

    /**
     * @var string
     */
    protected $description = 'Lista mensajes de lead recientes y verifica columnas kind / adjuntos';

    /**
     * @return int
     */
    public function handle(): int
    {
        if (! Schema::hasColumn('lead_messages', 'kind')) {
            $this->error('Falta columna lead_messages.kind — ejecutá: php artisan migrate');

            return 1;
        }

        if (! Schema::hasTable('lead_message_attachments')) {
            $this->error('Falta tabla lead_message_attachments — ejecutá: php artisan migrate');

            return 1;
        }

        $limit = (int) $this->option('limit');
        if ($limit < 1) {
            $limit = 10;
        }

        $audio_count = LeadMessage::query()->whereIn('kind', ['audio', 'ptt', 'voice'])->count();
        $attachment_count = LeadMessageAttachment::query()->count();

        $this->info('Mensajes kind audio/ptt/voice: '.$audio_count);
        $this->info('Adjuntos en lead_message_attachments: '.$attachment_count);

        $rows = LeadMessage::query()
            ->where('sender', 'lead')
            ->orderBy('id', 'desc')
            ->limit($limit)
            ->with('attachments')
            ->get();

        $this->table(
            ['id', 'lead_id', 'kind', 'attachments', 'content (inicio)'],
            $rows->map(function (LeadMessage $message) {
                return [
                    $message->id,
                    $message->lead_id,
                    $message->kind,
                    $message->attachments->count(),
                    mb_substr((string) $message->content, 0, 50),
                ];
            })->all()
        );

        return 0;
    }
}

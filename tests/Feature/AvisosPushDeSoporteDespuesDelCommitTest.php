<?php

namespace Tests\Feature;

use App\Jobs\EnviarAvisoDeSoporteJob;
use App\Models\Client;
use App\Models\SupportAttentionNotification;
use App\Models\SupportCodexCase;
use App\Models\SupportTicket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Commit real en la base exclusiva del slot; limpia solo sus propias fixtures en finally. */
class AvisosPushDeSoporteDespuesDelCommitTest extends TestCase
{
    public function test_el_job_se_encola_solo_despues_del_commit_real(): void
    {
        Queue::fake();
        $client = null;
        $ticket = null;
        DB::beginTransaction();
        try {
            $client = Client::create(['name' => 'Fixture push commit', 'is_active' => true]);
            $ticket = SupportTicket::create([
                'client_id' => $client->id, 'client_user_id' => 0,
                'status' => 'open', 'source' => 'whatsapp', 'opened_at' => now(),
            ]);
            SupportCodexCase::create([
                'support_ticket_id' => $ticket->id, 'state' => 'manual_review', 'review_reason' => 'Revisar acceso.',
            ]);
            Queue::assertNothingPushed();
            $this->assertNotNull(SupportAttentionNotification::where('support_ticket_id', $ticket->id)->firstOrFail()->fingerprint);
            DB::commit();
            Queue::assertPushed(EnviarAvisoDeSoporteJob::class, function ($job) use ($ticket) {
                return $job->ticket_id === (int) $ticket->id && $job->generation === 1 && $job->connection === 'database';
            });
            Queue::assertPushed(EnviarAvisoDeSoporteJob::class, 1);
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($ticket !== null) {
                SupportCodexCase::where('support_ticket_id', $ticket->id)->delete();
                SupportTicket::whereKey($ticket->id)->delete();
            }
            if ($client !== null) {
                Client::whereKey($client->id)->delete();
            }
        }
    }
}

<?php
namespace Tests\Feature;
use App\Jobs\EnviarAvisoDeMensajeDeSoporteJob;
use App\Models\Admin;
use App\Models\Client;
use App\Models\SupportMessage;
use App\Models\SupportMessageNotification;
use App\Models\SupportTicket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
/** Commit y rollback reales en base exclusiva: la prueba limpia sólo sus fixtures. */
class AvisosDeMensajesDespuesDelCommitTest extends TestCase
{
    public function test_el_rollback_no_notifica_y_el_commit_despacha_una_vez(): void
    {
        Queue::fake();
        $admin = null; $client = null; $ticket = null;
        try {
            $admin = Admin::create(['name' => 'Fixture commit', 'email' => uniqid() . '@test.local', 'password' => bcrypt('prueba')]);
            $client = Client::create(['name' => 'Fixture mensaje commit', 'is_active' => true]);
            $ticket = SupportTicket::create(['client_id' => $client->id, 'client_user_id' => 0, 'source' => 'whatsapp', 'status' => 'open']);
            DB::table('support_ticket_admin_notifications')->insert(['support_ticket_id' => $ticket->id, 'admin_id' => $admin->id, 'created_at' => now()->subMinute(), 'updated_at' => now()]);
            DB::beginTransaction();
            $rolled = SupportMessage::create(['support_ticket_id' => $ticket->id, 'sender_type' => 'user', 'body' => 'Se revierte']);
            $this->assertTrue(SupportMessageNotification::where('support_message_id', $rolled->id)->exists());
            Queue::assertNotPushed(EnviarAvisoDeMensajeDeSoporteJob::class);
            DB::rollBack();
            $this->assertFalse(SupportMessageNotification::where('support_message_id', $rolled->id)->exists());
            Queue::assertNotPushed(EnviarAvisoDeMensajeDeSoporteJob::class);
            DB::beginTransaction();
            $message = SupportMessage::create(['support_ticket_id' => $ticket->id, 'sender_type' => 'user', 'body' => 'Se confirma']);
            Queue::assertNotPushed(EnviarAvisoDeMensajeDeSoporteJob::class);
            DB::commit();
            Queue::assertPushed(EnviarAvisoDeMensajeDeSoporteJob::class, function ($job) use ($message) { return $job->message_id === (int) $message->id && $job->afterCommit; });
            Queue::assertPushed(EnviarAvisoDeMensajeDeSoporteJob::class, 1);
        } finally {
            while (DB::transactionLevel() > 0) { DB::rollBack(); }
            if ($ticket) {
                $ids = SupportMessage::where('support_ticket_id', $ticket->id)->pluck('id');
                SupportMessageNotification::whereIn('support_message_id', $ids)->delete();
                SupportMessage::where('support_ticket_id', $ticket->id)->delete();
                DB::table('support_ticket_admin_notifications')->where('support_ticket_id', $ticket->id)->delete();
                \App\Models\SupportAttentionNotification::where('support_ticket_id', $ticket->id)->delete();
                SupportTicket::whereKey($ticket->id)->delete();
            }
            if ($client) { Client::whereKey($client->id)->delete(); }
            if ($admin) { Admin::whereKey($admin->id)->delete(); }
        }
    }
}

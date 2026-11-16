<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AdminPushSubscription;
use App\Models\SupportTicket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class SupportMessageNotificationController extends Controller
{
    public function show(Request $request, int $id)
    {
        $this->ticket($request, $id);
        return response()->json($this->estado($id, (int) $request->user()->id));
    }
    public function update(Request $request, int $id)
    {
        $this->ticket($request, $id);
        $data = $request->validate(['subscribed' => 'required|boolean']);
        $key = ['support_ticket_id' => $id, 'admin_id' => (int) $request->user()->id];
        if ($data['subscribed']) {
            DB::table('support_ticket_admin_notifications')->insertOrIgnore($key + ['after_message_id' => (int) DB::table('support_messages')->where('support_ticket_id', $id)->max('id'), 'created_at' => now(), 'updated_at' => now()]);
        } else {
            DB::table('support_ticket_admin_notifications')->where($key)->delete();
        }
        return response()->json($this->estado($id, $key['admin_id']));
    }
    private function ticket(Request $request, int $id): void
    {
        abort_unless($request->user() instanceof Admin, 403);
        abort_unless(SupportTicket::sinEnsayo()->whereKey($id)->exists(), 404);
    }
    private function estado(int $id, int $admin_id): array
    {
        return ['subscribed' => DB::table('support_ticket_admin_notifications')->where('support_ticket_id', $id)->where('admin_id', $admin_id)->exists(),
            'push_device_registered' => AdminPushSubscription::where('admin_id', $admin_id)->exists()];
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminPushSubscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Alta/baja de suscripciones Web Push del admin autenticado.
 */
class AdminPushSubscriptionController extends Controller
{
    /**
     * Devuelve la public key VAPID que el frontend necesita para suscribirse.
     */
    public function vapid_public_key_json()
    {
        return response()->json(['public_key' => config('services.vapid.public_key')]);
    }

    /**
     * Registra (o actualiza, si el endpoint ya existía) la suscripción del device actual.
     */
    public function store_json(Request $request)
    {
        $data = $request->validate([
            'endpoint' => 'required|string',
            'keys.p256dh' => 'required|string',
            'keys.auth' => 'required|string',
        ]);

        AdminPushSubscription::updateOrCreate(
            ['endpoint' => $data['endpoint']],
            [
                'admin_id'     => Auth::id(),
                'p256dh'       => $data['keys']['p256dh'],
                'auth'         => $data['keys']['auth'],
                'last_used_at' => now(),
            ]
        );

        return response()->json(['ok' => true]);
    }

    /**
     * Elimina la suscripción (el admin desactivó notificaciones en ese device).
     */
    public function destroy_json(Request $request)
    {
        $data = $request->validate(['endpoint' => 'required|string']);

        AdminPushSubscription::where('admin_id', Auth::id())
            ->where('endpoint', $data['endpoint'])
            ->delete();

        return response()->json(['ok' => true]);
    }
}

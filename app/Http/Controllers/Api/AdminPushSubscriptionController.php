<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminPushSubscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Alta/baja/consulta de suscripciones Web Push del admin autenticado.
 *
 * La identidad de una suscripción es `endpoint_hash` (SHA-256 del endpoint), no el
 * endpoint: ver el comentario de la migración 2026_08_11_120000 para el porqué.
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
     *
     * Es idempotente por `endpoint_hash`: repetir el POST con el mismo endpoint refresca
     * `last_used_at` en vez de crear una fila nueva. De eso depende el re-registro
     * automático que el front dispara en cada arranque.
     */
    public function store_json(Request $request)
    {
        $data = $request->validate([
            'endpoint'    => 'required|string',
            'keys.p256dh' => 'required|string',
            'keys.auth'   => 'required|string',
        ]);

        $endpoint = (string) $data['endpoint'];

        try {
            AdminPushSubscription::updateOrCreate(
                ['endpoint_hash' => AdminPushSubscription::hash_endpoint($endpoint)],
                [
                    'admin_id'     => Auth::id(),
                    'endpoint'     => $endpoint,
                    'p256dh'       => $data['keys']['p256dh'],
                    'auth'         => $data['keys']['auth'],
                    'last_used_at' => now(),
                ]
            );
        } catch (\Throwable $e) {
            /* El error real se loguea acá y no en ningún otro lado: del lado del usuario
             * esto es un cartel genérico, y sin esta línea un fallo de base (columna corta,
             * conexión caída, índice duplicado) sale como un 500 pelado imposible de
             * diagnosticar. Fue exactamente lo que hizo que el bug del endpoint truncado
             * viviera semanas sin que nadie supiera qué estaba fallando. */
            Log::error('AdminPushSubscriptionController: no se pudo guardar la suscripción push.', [
                'admin_id'        => Auth::id(),
                'endpoint_length' => mb_strlen($endpoint),
                'error'           => $e->getMessage(),
            ]);

            return response()->json([
                'ok'      => false,
                'message' => 'No se pudo guardar la suscripción en el servidor.',
            ], 500);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Elimina la suscripción (el admin desactivó notificaciones en ese device).
     */
    public function destroy_json(Request $request)
    {
        $data = $request->validate(['endpoint' => 'required|string']);

        try {
            AdminPushSubscription::where('admin_id', Auth::id())
                ->where('endpoint_hash', AdminPushSubscription::hash_endpoint((string) $data['endpoint']))
                ->delete();
        } catch (\Throwable $e) {
            Log::error('AdminPushSubscriptionController: no se pudo borrar la suscripción push.', [
                'admin_id' => Auth::id(),
                'error'    => $e->getMessage(),
            ]);

            return response()->json([
                'ok'      => false,
                'message' => 'No se pudo borrar la suscripción en el servidor.',
            ], 500);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Informa si este device está efectivamente registrado para el admin autenticado.
     *
     * Existe porque la interfaz no puede seguir deduciendo "estoy suscripto" del permiso
     * del navegador: el permiso es un proxy, y cuando el guardado falla el proxy afirma lo
     * contrario del hecho. Esto devuelve el hecho.
     *
     * Va por POST y no por GET a propósito: el endpoint es una URL larga y no tiene por
     * qué viajar en la query string (queda en logs de acceso, historial y proxies).
     */
    public function subscription_status_json(Request $request)
    {
        $data = $request->validate(['endpoint' => 'required|string']);

        $registered = AdminPushSubscription::where('admin_id', Auth::id())
            ->where('endpoint_hash', AdminPushSubscription::hash_endpoint((string) $data['endpoint']))
            ->exists();

        return response()->json(['registered' => $registered]);
    }
}

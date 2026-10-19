<?php

namespace App\Http\Middleware;

use App\Models\Client;
use Closure;
use Illuminate\Http\Request;

class StrictAdminInboundKey
{
    public function handle(Request $request, Closure $next)
    {
        // El catálogo público compartido y la atribución del gasto nunca aceptan client_uuid
        // como identidad, aun si la integración legacy funciona sin clave por compatibilidad.
        $key = $request->header('X-Admin-Api-Key');
        if (!is_string($key) || trim($key) === '') {
            return response()->json(['message' => 'Falta la credencial del negocio.'], 401);
        }
        $client = Client::where('is_active', true)->where('inbound_api_key', $key)->first();
        if (!$client || !hash_equals((string) $client->inbound_api_key, $key)) {
            return response()->json(['message' => 'La credencial del negocio no es válida.'], 401);
        }
        $request->attributes->set('sync_client', $client);
        return $next($request);
    }
}

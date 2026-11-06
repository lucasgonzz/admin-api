<?php

namespace App\Http\Middleware;

use App\Models\Client;
use App\Models\Lead;
use App\Services\Prueba\PruebaChatAcceso;
use Closure;
use Illuminate\Http\Request;

class PruebaChatKey
{
    public function handle(Request $request, Closure $next)
    {
        $client = $request->attributes->get('sync_client');
        $token = (string) $request->header('X-Demo-Eventos-Key');
        if (! $client instanceof Client || trim($token) === '') {
            return response()->json(['error' => 'no autorizado'], 401);
        }

        // Un lead puede conservar el token de su demo. La credencial de la instancia identifica
        // el negocio y nunca se acepta un id elegido por el navegador.
        $lead = Lead::where('demo_eventos_token', $token)->where('promoted_client_id', $client->id)->first();
        if ($lead === null || ! hash_equals((string) $lead->demo_eventos_token, $token)
            || ! PruebaChatAcceso::vigente($lead, $client)) {
            return response()->json(['error' => 'no autorizado'], 401);
        }

        $request->attributes->set('prueba_chat_lead', $lead);

        return $next($request);
    }
}

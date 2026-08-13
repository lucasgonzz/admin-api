<?php

namespace App\Http\Middleware;

use App\Models\Lead;
use Closure;
use Illuminate\Http\Request;

/**
 * Autentica el canal por el que una instancia de demo reporta lo que el lead va haciendo
 * (misión 48, pieza 4). Calcado de {@see AdminInboundKey}, con una diferencia de fondo.
 *
 * El token ES el identificador del lead: se lee de `X-Demo-Eventos-Key`, se busca el lead por
 * `demo_eventos_token` y se adjunta a la request. A propósito NO se acepta un `lead_id` ni un
 * `lead_uuid` en el body: si el emisor pudiera nombrar al lead por su cuenta habría dos fuentes
 * para la misma cosa, y una podría contradecir a la otra (o apuntar al lead de otro).
 *
 * Ante cualquier falla devuelve 401 sin decir por qué. Distinguir "falta el header" de "el token
 * no existe" le daría a quien prueba tokens al azar una señal de progreso.
 */
class DemoEventosKey
{
    /**
     * Valida `X-Demo-Eventos-Key` y adjunta el Lead a la request.
     *
     * @param Request $request Request entrante (JSON).
     * @param Closure $next    Siguiente middleware o controlador.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next)
    {
        $token = (string) $request->header('X-Demo-Eventos-Key');

        if (trim($token) === '') {
            return response()->json(['error' => 'no autorizado'], 401);
        }

        $lead = Lead::where('demo_eventos_token', $token)->first();

        if (is_null($lead)) {
            return response()->json(['error' => 'no autorizado'], 401);
        }

        $request->attributes->set('demo_eventos_lead', $lead);

        return $next($request);
    }
}

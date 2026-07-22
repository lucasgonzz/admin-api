<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Protege el bloque de rutas `claude/*` (ingesta de tareas creadas por Claude
 * desde la conversación, grupo 180) mediante una clave fija en el header
 * X-Claude-Task-Key. No usa Sanctum: es un proceso externo sin sesión de admin.
 *
 * Diseño fail-closed a propósito: si `CLAUDE_TASK_INGEST_KEY` no está definida
 * en el .env, el endpoint rechaza TODA request en vez de quedar abierto. No
 * reutiliza AdminInboundKey porque ese middleware resuelve un Client por api
 * key (integración empresa-api) y acá no hay ningún Client involucrado.
 */
class ClaudeTaskKey
{
    /**
     * Valida X-Claude-Task-Key contra la clave configurada en services.claude_task_ingest.key.
     *
     * @param  Request $request Request entrante (JSON).
     * @param  Closure $next    Siguiente middleware o controlador.
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next)
    {
        // Clave configurada en el .env del entorno. Puede ser null o string vacío
        // si nunca se definió CLAUDE_TASK_INGEST_KEY.
        $configured_key = config('services.claude_task_ingest.key');

        // Fail-closed: sin clave configurada, el endpoint no acepta ninguna request,
        // nunca queda "abierto" por omisión.
        if (empty($configured_key)) {
            $this->log_rejection($request, 'clave no configurada (CLAUDE_TASK_INGEST_KEY vacía)');

            return response()->json(['error' => 'ingest disabled'], 401);
        }

        // Clave recibida en el header de la request.
        $received_key = $request->header('X-Claude-Task-Key');

        if (empty($received_key)) {
            $this->log_rejection($request, 'header X-Claude-Task-Key ausente');

            return response()->json(['error' => 'missing key'], 401);
        }

        // Comparación en tiempo constante (hash_equals) para no filtrar la clave
        // por timing attack; nunca usar === acá.
        if (!hash_equals((string) $configured_key, (string) $received_key)) {
            $this->log_rejection($request, 'clave incorrecta');

            return response()->json(['error' => 'invalid key'], 401);
        }

        return $next($request);
    }

    /**
     * Loguea un rechazo de autenticación sin exponer nunca la clave (ni la
     * configurada ni la recibida), solo IP y motivo.
     *
     * @param  Request $request Request rechazada.
     * @param  string  $reason  Motivo legible del rechazo.
     * @return void
     */
    protected function log_rejection(Request $request, string $reason): void
    {
        Log::channel('daily')->warning('ClaudeTaskKey: request rechazada.', [
            'ip'     => $request->ip(),
            'path'   => $request->path(),
            'reason' => $reason,
        ]);
    }
}

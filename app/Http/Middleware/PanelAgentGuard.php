<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use App\Models\PanelAgentAuditLog;
use App\Services\PanelAgent\PanelAgentAuditLogger;
use App\Services\PanelAgent\PanelAgentConfirmation;
use App\Services\PanelAgent\PanelAgentOperationClassifier;
use App\Services\PanelAgent\PanelAgentPayloadRedactor;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Auditoría y frenos de los agentes (Claude Code, OpenClaw) que usan el panel con un token de Sanctum
 * (misión admin-control-total).
 *
 * Va al final del grupo `api` y corre después de `auth:sanctum` (ver `Kernel`). Si el usuario no es un
 * `Admin` con `es_agente`, no hace nada: los admins humanos y las rutas públicas pasan sin ningún cambio y sin
 * consultas extra. Con un agente: aplica su cubeta de throttle, abre la fila de auditoría ANTES de ejecutar
 * (si no puede y el pedido no es un GET, responde 503: fail-closed), y según la categoría de la operación
 * responde 403 (excluida), 200 con el preview (dry run), 428 con el preview (frenada sin código válido) o
 * ejecuta y cierra la fila con cómo terminó.
 */
class PanelAgentGuard
{
    /** @var PanelAgentOperationClassifier Única fuente de categorías de las operaciones. */
    private $classifier;

    /** @var PanelAgentConfirmation Código de confirmación ligado al pedido exacto. */
    private $confirmation;

    /** @var PanelAgentAuditLogger Escritura de la auditoría. */
    private $logger;

    /** @var PanelAgentPayloadRedactor Redacción de secretos antes de guardar. */
    private $redactor;

    /**
     * @param PanelAgentOperationClassifier $classifier   Clasificador.
     * @param PanelAgentConfirmation        $confirmation Códigos de confirmación.
     * @param PanelAgentAuditLogger         $logger       Auditoría.
     * @param PanelAgentPayloadRedactor     $redactor     Redactor.
     */
    public function __construct(
        PanelAgentOperationClassifier $classifier,
        PanelAgentConfirmation $confirmation,
        PanelAgentAuditLogger $logger,
        PanelAgentPayloadRedactor $redactor
    ) {
        $this->classifier   = $classifier;
        $this->confirmation = $confirmation;
        $this->logger       = $logger;
        $this->redactor     = $redactor;
    }

    /**
     * Audita y frena los pedidos de un agente; deja pasar tal cual todo lo demás.
     *
     * @param  Request $request Pedido entrante (ya autenticado por `auth:sanctum`).
     * @param  Closure $next    Siguiente eslabón.
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next)
    {
        // Usuario del pedido: sólo los admins marcados como agente entran a este flujo.
        $user = $request->user();
        if (! $user instanceof Admin || ! (bool) $user->es_agente) {
            return $next($request);
        }

        // Ruta resuelta; sin ella no hay nada que clasificar.
        $route = $request->route();
        if (! $route instanceof Route) {
            return $next($request);
        }

        // Datos del pedido que sirven para clasificar, auditar y calcular el código de confirmación.
        $method   = strtoupper($request->method());
        $category = $this->classifier->classify($method, $route->uri());
        $braked   = $this->classifier->is_braked($category);
        $payload  = $request->all();
        $started  = microtime(true);

        // Throttle propio del agente: se mide antes de abrir la auditoría, que igual registra el corte.
        $throttle_key = 'panel-agent:' . $user->id;
        $throttled    = RateLimiter::tooManyAttempts($throttle_key, max(1, (int) config('panel_agent.rate_limit_per_minute', 120)));
        if (! $throttled) {
            RateLimiter::hit($throttle_key, 60);
        }

        // Headers con los que el agente declara quién es y por qué hace el pedido.
        $dry_run      = strtolower(trim((string) $request->header('X-Agente-Dry-Run'))) === 'si';
        $confirm_code = $request->header('X-Agente-Confirmar');

        // Fila de auditoría abierta ANTES de ejecutar. Si no se puede: un GET sigue, el resto se cierra (fail-closed).
        $log = $this->open_audit($request, $route, $user, $method, $category, $braked, $dry_run, $payload);
        if ($log === false) {
            if ($method !== 'GET') {
                return response()->json([
                    'code'    => 'agente_auditoria_no_disponible',
                    'mensaje' => 'No se pudo abrir la auditoría; las operaciones que escriben quedan cerradas hasta que vuelva.',
                ], 503);
            }
            $log = null;
        }

        if ($throttled) {
            $retry_in = RateLimiter::availableIn($throttle_key);
            $this->close_audit($log, 'frenada', 429, '{"code":"agente_throttle"}', $started);

            return response()->json([
                'code'          => 'agente_throttle',
                'mensaje'       => 'Demasiados pedidos del agente en el último minuto.',
                'reintentar_en' => $retry_in,
            ], 429)->header('Retry-After', (string) $retry_in);
        }

        // Una operación excluida nunca se ejecuta con un token de agente.
        if ($category === 'excluida') {
            $preview = $this->preview($request, $route, $category, true, 'agente_excluida', $payload, false);
            $this->close_audit($log, 'excluida', 403, $this->redactor->to_json_excerpt($preview, $this->excerpt_limit()), $started);

            return response()->json($preview, 403);
        }

        // Dry run (cualquier categoría): se muestra qué haría, sin ejecutar.
        if ($dry_run) {
            $preview = $this->preview($request, $route, $category, $braked, 'agente_dry_run', $payload, $braked);
            $this->close_audit($log, 'dry_run', 200, $this->redactor->to_json_excerpt($preview, $this->excerpt_limit()), $started);

            return response()->json($preview, 200);
        }

        // Categoría frenada: sólo se ejecuta con el código del preview de ESTE pedido.
        $confirmed = false;
        if ($braked) {
            $confirmed = $this->confirmation->matches($confirm_code, (int) $user->id, $method, $request->path(), $payload);
            if (! $confirmed) {
                $preview = $this->preview($request, $route, $category, true, 'agente_confirmacion_requerida', $payload, true);
                $this->close_audit($log, 'frenada', 428, $this->redactor->to_json_excerpt($preview, $this->excerpt_limit()), $started);

                return response()->json($preview, 428);
            }
        }

        // Ejecuta el pedido y cierra la fila con cómo terminó (también si el controlador lanza una excepción).
        try {
            $response = $next($request);
        } catch (\Throwable $exception) {
            $this->close_audit($log, 'ejecutada', 500, '[excepción: ' . get_class($exception) . ']', $started, $confirmed);
            throw $exception;
        }

        $this->close_audit($log, 'ejecutada', (int) $response->getStatusCode(), $this->response_excerpt($response), $started, $confirmed);

        return $response;
    }

    /**
     * Abre la fila de auditoría del pedido.
     *
     * @param  Request $request  Pedido.
     * @param  Route   $route    Ruta resuelta.
     * @param  Admin   $user     Agente.
     * @param  string  $method   Verbo.
     * @param  string  $category Categoría de la operación.
     * @param  bool    $braked   Si la categoría frena.
     * @param  bool    $dry_run  Si el agente pidió dry run.
     * @param  array<string, mixed> $payload Query + body sin redactar.
     * @return PanelAgentAuditLog|false La fila abierta, o false si no se pudo abrir.
     */
    private function open_audit(Request $request, Route $route, Admin $user, string $method, string $category, bool $braked, bool $dry_run, array $payload)
    {
        try {
            return $this->logger->open([
                'admin_id'        => (int) $user->id,
                'agent_name'      => $this->redactor->clean_text($user->name, 120),
                'agent_client'    => $this->header_or_null($request, 'X-Agente-Cliente', 40),
                'method'          => $method,
                'route_uri'       => $this->redactor->clean_text($this->classifier->panel_path($route->uri()), 255),
                // El path viene del pedido: puede traer bytes que no son UTF-8, y la columna es utf8mb4 estricta.
                'path'            => $this->redactor->clean_text($request->path(), 500),
                'route_name'      => $route->getName() ? $this->redactor->clean_text($route->getName(), 150) : null,
                'action'          => $this->redactor->clean_text($this->classifier->action_label($route), 200),
                'category'        => $category,
                'braked'          => $braked,
                'dry_run'         => $dry_run,
                'reason'          => $this->header_or_null($request, 'X-Agente-Motivo', 500),
                'request_payload' => $this->redactor->to_json_excerpt($payload, (int) config('panel_agent.payload_max_chars', 20000)),
                'ip'              => $request->ip(),
            ]);
        } catch (\Throwable $exception) {
            Log::warning('PanelAgentGuard: no se pudo abrir la auditoría del agente: ' . $exception->getMessage());

            return false;
        }
    }

    /**
     * Cierra la fila de auditoría. Si falla no rompe la respuesta (a esa altura el pedido ya se resolvió).
     *
     * @param  PanelAgentAuditLog|null $log         Fila abierta (null si no hubo auditoría en un GET).
     * @param  string                  $result      ejecutada|frenada|excluida|dry_run.
     * @param  int                     $status_code Código HTTP devuelto.
     * @param  string|null             $excerpt     Extracto redactado de la respuesta.
     * @param  float                   $started     microtime(true) del inicio.
     * @param  bool                    $confirmed   Si se ejecutó con código de confirmación.
     * @return void
     */
    private function close_audit($log, string $result, int $status_code, $excerpt, float $started, bool $confirmed = false): void
    {
        if ($log === null) {
            return;
        }

        try {
            $this->logger->close($log, $result, $status_code, $excerpt, (int) round((microtime(true) - $started) * 1000), $confirmed);
        } catch (\Throwable $exception) {
            Log::warning('PanelAgentGuard: no se pudo cerrar la auditoría del agente: ' . $exception->getMessage());
        }
    }

    /**
     * Preview del pedido: lo que se devuelve en 428, 403 y dry run.
     *
     * @param  Request $request       Pedido.
     * @param  Route   $route         Ruta resuelta.
     * @param  string  $category      Categoría.
     * @param  bool    $blocked       Si el pedido NO se ejecutó por un freno.
     * @param  string  $code          Código del cuerpo (`agente_confirmacion_requerida`, `agente_excluida`, `agente_dry_run`).
     * @param  array<string, mixed> $payload Query + body sin redactar.
     * @param  bool    $with_code     Si se incluye el código de confirmación.
     * @return array<string, mixed>
     */
    private function preview(Request $request, Route $route, string $category, bool $blocked, string $code, array $payload, bool $with_code): array
    {
        $preview = [
            'frenada'    => $blocked,
            'code'       => $code,
            'categoria'  => $category,
            'motivo'     => config('panel_agent.reasons.' . $category),
            'metodo'     => strtoupper($request->method()),
            'ruta'       => $this->classifier->panel_path($route->uri()),
            'path'       => $request->path(),
            'nombre_ruta' => $route->getName(),
            'accion'     => $this->classifier->action_label($route),
            'payload'    => $this->redactor->redact($payload),
        ];

        if ($with_code) {
            $preview['codigo_confirmacion'] = $this->confirmation->code_for(
                (int) $request->user()->id,
                $request->method(),
                $request->path(),
                $payload
            );
            $preview['como_confirmar'] = 'Repetí el MISMO pedido con el header X-Agente-Confirmar: <codigo_confirmacion>. '
                . 'Vale entre 15 y 30 minutos y sólo para este método, ruta y cuerpo.';
        }

        return $preview;
    }

    /**
     * Extracto redactado de la respuesta (si no es JSON o es enorme, sólo una nota con el tamaño).
     *
     * @param  \Symfony\Component\HttpFoundation\Response $response Respuesta del controlador.
     * @return string
     */
    private function response_excerpt($response): string
    {
        $content = method_exists($response, 'getContent') ? $response->getContent() : false;
        $bytes   = is_string($content) ? strlen($content) : 0;

        // Hasta 2 MB se decodifica para redactar por clave; más que eso no se intenta.
        if ($bytes > 0 && $bytes <= 2 * 1024 * 1024) {
            $decoded = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $this->redactor->to_json_excerpt($decoded, $this->excerpt_limit());
            }
        }

        return '[respuesta no JSON, ' . $bytes . ' bytes]';
    }

    /**
     * Máximo de caracteres del extracto de respuesta.
     *
     * @return int
     */
    private function excerpt_limit(): int
    {
        return (int) config('panel_agent.response_excerpt_chars', 2000);
    }

    /**
     * Header saneado para la auditoría, o null si no vino o quedó vacío.
     *
     * Contrato: el cliente manda el texto UTF-8 percent-encoded (así un acento o una raya viajan en un header
     * ASCII); se decodifica, se convierte desde latin-1 si no es UTF-8 válido (cliente viejo), se sacan los
     * controles y se recorta por caracteres. Ver `PanelAgentPayloadRedactor::clean_text()`.
     *
     * @param  Request $request Pedido.
     * @param  string  $name    Nombre del header.
     * @param  int     $max     Largo máximo en caracteres.
     * @return string|null
     */
    private function header_or_null(Request $request, string $name, int $max)
    {
        $value = $this->redactor->clean_text($request->header($name), $max, true);

        return $value === '' ? null : $value;
    }
}

<?php

namespace App\Services\PanelAgent;

use App\Models\PanelAgentAuditLog;
use Carbon\Carbon;

/**
 * Escribe y lee la auditoría de los pedidos de los agentes al panel.
 *
 * Se resuelve por el contenedor (el middleware lo pide con `app()`), así un test puede reemplazarlo por uno
 * que falle y comprobar que, sin auditoría, las escrituras quedan cerradas.
 */
class PanelAgentAuditLogger
{
    /** Máximo de filas que devuelve una lectura. */
    const MAX_LIMIT = 200;

    /** Filas por defecto de una lectura. */
    const DEFAULT_LIMIT = 50;

    /**
     * Abre la fila de un pedido, en estado `en_curso`, ANTES de ejecutarlo.
     *
     * @param  array<string, mixed> $attributes Columnas de `panel_agent_audit_logs` (admin_id, agent_name, method, route_uri, path, category…).
     * @return PanelAgentAuditLog
     */
    public function open(array $attributes): PanelAgentAuditLog
    {
        return PanelAgentAuditLog::create($attributes + ['result' => 'en_curso']);
    }

    /**
     * Completa la fila con cómo terminó el pedido.
     *
     * @param  PanelAgentAuditLog $log         Fila abierta con open().
     * @param  string             $result      ejecutada|frenada|excluida|dry_run.
     * @param  int                $status_code Código HTTP devuelto al agente.
     * @param  string|null        $excerpt     Extracto ya redactado de la respuesta.
     * @param  int                $duration_ms Duración del pedido.
     * @param  bool               $confirmed   Si se ejecutó con un código de confirmación válido.
     * @return void
     */
    public function close(PanelAgentAuditLog $log, string $result, int $status_code, $excerpt, int $duration_ms, bool $confirmed = false): void
    {
        $log->update([
            'result'           => $result,
            'status_code'      => $status_code,
            'response_excerpt' => $excerpt,
            'duration_ms'      => max(0, $duration_ms),
            'confirmed'        => $confirmed,
        ]);
    }

    /**
     * Lee la auditoría con filtros, de la más nueva a la más vieja.
     *
     * @param  array<string, mixed> $filters admin_id, resultado, categoria, desde (fecha), limite (≤ 200).
     * @return array<int, array<string, mixed>> Filas en el formato que consume el MCP.
     */
    public function search(array $filters): array
    {
        $query = PanelAgentAuditLog::query()->orderBy('id', 'desc');

        if (! empty($filters['admin_id'])) {
            $query->where('admin_id', (int) $filters['admin_id']);
        }
        if (! empty($filters['resultado'])) {
            $query->where('result', (string) $filters['resultado']);
        }
        if (! empty($filters['categoria'])) {
            $query->where('category', (string) $filters['categoria']);
        }
        if (! empty($filters['desde'])) {
            try {
                $query->where('created_at', '>=', Carbon::parse((string) $filters['desde'])->startOfDay());
            } catch (\Exception $exception) {
                // Una fecha ilegible se ignora: el filtro es opcional.
            }
        }

        // Límite pedido, acotado entre 1 y MAX_LIMIT.
        $limit = (int) ($filters['limite'] ?? self::DEFAULT_LIMIT);
        $limit = min(self::MAX_LIMIT, max(1, $limit > 0 ? $limit : self::DEFAULT_LIMIT));

        $rows = [];
        foreach ($query->limit($limit)->get() as $log) {
            $rows[] = $this->present($log);
        }

        return $rows;
    }

    /**
     * Forma de una fila de auditoría para la API.
     *
     * @param  PanelAgentAuditLog $log Fila.
     * @return array<string, mixed>
     */
    private function present(PanelAgentAuditLog $log): array
    {
        return [
            'id'          => (int) $log->id,
            'admin_id'    => (int) $log->admin_id,
            'agente'      => $log->agent_name,
            'cliente'     => $log->agent_client,
            'metodo'      => $log->method,
            'ruta'        => $log->route_uri,
            'path'        => $log->path,
            'accion'      => $log->action,
            'categoria'   => $log->category,
            'frenada'     => (bool) $log->braked,
            'confirmada'  => (bool) $log->confirmed,
            'dry_run'     => (bool) $log->dry_run,
            'resultado'   => $log->result,
            'status_code' => $log->status_code,
            'motivo'      => $log->reason,
            'payload'     => $log->request_payload,
            'respuesta'   => $log->response_excerpt,
            'duracion_ms' => $log->duration_ms,
            'created_at'  => $log->created_at ? $log->created_at->toIso8601String() : null,
        ];
    }
}

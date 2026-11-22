<?php

namespace App\Services\PanelAgent;

use Illuminate\Routing\Route;

/**
 * Catálogo de operaciones del panel que ve un agente, derivado del router (no se mantiene a mano).
 *
 * Toda ruta `api/admin/*` protegida con `auth:sanctum` es una operación; su categoría la decide
 * `PanelAgentOperationClassifier`, la misma que usa el middleware. Una ruta nueva del panel aparece sola.
 */
class PanelAgentCatalog
{
    /**
     * Clasificador de operaciones (única fuente de categorías).
     *
     * @var PanelAgentOperationClassifier
     */
    private $classifier;

    /**
     * @param PanelAgentOperationClassifier $classifier Clasificador.
     */
    public function __construct(PanelAgentOperationClassifier $classifier)
    {
        $this->classifier = $classifier;
    }

    /**
     * Operaciones del panel, filtradas, más el resumen y cómo confirmar.
     *
     * @param  array<string, mixed> $filters buscar (texto en ruta o acción), categoria, metodo.
     * @return array<string, mixed> {operaciones: [...], resumen: {total, por_categoria}, como_confirmar}
     */
    public function build(array $filters = []): array
    {
        $search   = isset($filters['buscar']) ? strtolower(trim((string) $filters['buscar'])) : '';
        $category = isset($filters['categoria']) ? trim((string) $filters['categoria']) : '';
        $method   = isset($filters['metodo']) ? strtoupper(trim((string) $filters['metodo'])) : '';

        $operations = [];
        foreach ($this->all_operations() as $operation) {
            if ($category !== '' && $operation['categoria'] !== $category) {
                continue;
            }
            if ($method !== '' && $operation['metodo'] !== $method) {
                continue;
            }
            if ($search !== '' && strpos(strtolower($operation['ruta'] . ' ' . $operation['accion']), $search) === false) {
                continue;
            }
            $operations[] = $operation;
        }

        // Conteo por categoría de lo que se está devolviendo.
        $by_category = [];
        foreach ($operations as $operation) {
            $by_category[$operation['categoria']] = ($by_category[$operation['categoria']] ?? 0) + 1;
        }

        return [
            'operaciones'    => $operations,
            'resumen'        => ['total' => count($operations), 'por_categoria' => $by_category],
            'como_confirmar' => 'Las operaciones con frenada=true devuelven 428 con un codigo_confirmacion. Repetí el MISMO pedido '
                . '(método, ruta y cuerpo) con el header X-Agente-Confirmar: <codigo>; el código vale una sola vez y 15 minutos. Con X-Agente-Dry-Run: si se ve el preview sin ejecutar.',
        ];
    }

    /**
     * Todas las operaciones del panel (un par método + ruta por fila), ordenadas por ruta y método.
     *
     * @return array<int, array<string, mixed>>
     */
    private function all_operations(): array
    {
        $notes = (array) config('panel_agent.notes', []);

        // Operaciones por clave «MÉTODO uri» (así una ruta repetida no duplica filas).
        $unique = [];

        foreach (app('router')->getRoutes() as $route) {
            if (! $this->is_panel_route($route)) {
                continue;
            }

            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                $category  = $this->classifier->classify($method, $route->uri());
                $braked    = $this->classifier->is_braked($category);
                $panel_uri = $this->classifier->panel_path($route->uri());

                $unique[$method . ' ' . $route->uri()] = [
                    'metodo'       => $method,
                    'ruta'         => $panel_uri,
                    'uri'          => $route->uri(),
                    'nombre'       => $route->getName(),
                    'accion'       => $this->classifier->action_label($route),
                    'categoria'    => $category,
                    'frenada'      => $braked,
                    'motivo_freno' => ($braked || $category === 'excluida') ? config('panel_agent.reasons.' . $category) : null,
                    'nota'         => $notes[$method . ' ' . $panel_uri] ?? null,
                ];
            }
        }

        $operations = array_values($unique);
        usort($operations, function ($a, $b) {
            return strcmp($a['ruta'] . ' ' . $a['metodo'], $b['ruta'] . ' ' . $b['metodo']);
        });

        return $operations;
    }

    /**
     * Indica si la ruta es del panel autenticado: `api/admin/*` con `auth:sanctum`.
     *
     * @param  Route $route Ruta del router.
     * @return bool
     */
    private function is_panel_route(Route $route): bool
    {
        return strpos($route->uri(), PanelAgentOperationClassifier::PANEL_PREFIX) === 0
            && in_array('auth:sanctum', $route->gatherMiddleware(), true);
    }
}

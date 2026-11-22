<?php

namespace App\Services\PanelAgent;

use Illuminate\Routing\Route;

/**
 * Clasifica cada operación del panel (método + ruta) en una categoría de riesgo.
 *
 * Es la ÚNICA fuente de clasificación: la usan el middleware `PanelAgentGuard` (para frenar) y el catálogo
 * (para informar), así que lo que el catálogo dice que frena es exactamente lo que devuelve 428.
 * Los patrones viven en `config/panel_agent.php`.
 */
class PanelAgentOperationClassifier
{
    /** Prefijo de las rutas del panel; lo que no lo tiene no es del panel y queda excluido. */
    const PANEL_PREFIX = 'api/admin/';

    /**
     * Categoría de una operación.
     *
     * @param  string $method    Verbo HTTP (cualquier capitalización).
     * @param  string $route_uri URI completa del router, con `api/admin/` (ej. `api/admin/client/{id}`).
     * @return string lectura|escritura|credenciales|borrado|masiva|plata|terceros|excluida
     */
    public function classify(string $method, string $route_uri): string
    {
        // Cerrado por defecto: lo que no es del panel no se ejecuta con un token de agente.
        if (strpos($route_uri, self::PANEL_PREFIX) !== 0) {
            return 'excluida';
        }

        // Clave «MÉTODO ruta» sobre la que se evalúan los patrones.
        $method = strtoupper($method);
        $key = $method . ' ' . $this->panel_path($route_uri);

        // Gana la primera categoría (en el orden del config) cuyo patrón coincide y que no tiene una excepción.
        foreach ((array) config('panel_agent.categories', []) as $category => $rules) {
            if ($this->matches_any($key, (array) ($rules['patterns'] ?? []))
                && ! $this->matches_any($key, (array) ($rules['except'] ?? []))) {
                return (string) $category;
            }
        }

        return $method === 'GET' ? 'lectura' : 'escritura';
    }

    /**
     * Indica si la categoría exige código de confirmación.
     *
     * @param  string $category Categoría devuelta por classify().
     * @return bool
     */
    public function is_braked(string $category): bool
    {
        return in_array($category, (array) config('panel_agent.braked_categories', []), true);
    }

    /**
     * Ruta sin el prefijo del panel (ej. `client/{id}`).
     *
     * @param  string $route_uri URI completa (`api/admin/client/{id}`).
     * @return string
     */
    public function panel_path(string $route_uri): string
    {
        if (strpos($route_uri, self::PANEL_PREFIX) === 0) {
            return (string) substr($route_uri, strlen(self::PANEL_PREFIX));
        }

        return $route_uri;
    }

    /**
     * Etiqueta corta de la acción de una ruta: `Controlador@metodo` (sin namespace).
     *
     * @param  Route $route Ruta del router.
     * @return string `Closure` si la ruta no apunta a un controlador.
     */
    public function action_label(Route $route): string
    {
        $action = $route->getActionName();

        if (strpos($action, '@') === false) {
            return $action;
        }

        list($class_name, $method_name) = explode('@', $action, 2);

        return class_basename($class_name) . '@' . $method_name;
    }

    /**
     * Indica si la clave coincide con alguno de los patrones.
     *
     * @param  string        $key      Clave «MÉTODO ruta».
     * @param  array<string> $patterns Expresiones regulares completas (con delimitador).
     * @return bool
     */
    private function matches_any(string $key, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $key) === 1) {
                return true;
            }
        }

        return false;
    }
}

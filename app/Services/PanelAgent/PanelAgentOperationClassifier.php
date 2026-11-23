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
     * @param  array<string, mixed> $payload Query + body del pedido: si trae un campo de `field_brakes` de esa ruta,
     *                                       la categoría sube a la que indica ese campo. Sin payload (catálogo) se
     *                                       devuelve la categoría base.
     * @return string lectura|escritura|credenciales|borrado|masiva|plata|terceros|excluida
     */
    public function classify(string $method, string $route_uri, array $payload = []): string
    {
        // Cerrado por defecto: lo que no es del panel no se ejecuta con un token de agente.
        if (strpos($route_uri, self::PANEL_PREFIX) !== 0) {
            return 'excluida';
        }

        // Clave «MÉTODO ruta» sobre la que se evalúan los patrones.
        $method = strtoupper($method);
        $key = $method . ' ' . $this->panel_path($route_uri);

        // Gana la primera categoría (en el orden del config) cuyo patrón coincide y que no tiene una excepción.
        $base_category = $method === 'GET' ? 'lectura' : 'escritura';
        foreach ((array) config('panel_agent.categories', []) as $category => $rules) {
            if ($this->matches_any($key, (array) ($rules['patterns'] ?? []))
                && ! $this->matches_any($key, (array) ($rules['except'] ?? []))) {
                $base_category = (string) $category;
                break;
            }
        }

        // Freno por campo: una operación libre sube de categoría si el cuerpo toca un campo con efecto delicado.
        // Lo que ya frena por su ruta (o está excluida) no se rebaja ni se cambia.
        if (! $this->is_braked($base_category) && $base_category !== 'excluida') {
            foreach ($this->field_brakes($method, $route_uri) as $field => $field_category) {
                if (array_key_exists($field, $payload)) {
                    return (string) $field_category;
                }
            }
        }

        // Freno por VALOR de un campo (`panel_agent.field_value_brakes`): sólo sube si el campo trae uno de los valores listados.
        if (! $this->is_braked($base_category) && $base_category !== 'excluida') {
            $por_valor = (array) (config('panel_agent.field_value_brakes', [])[$key] ?? []);
            foreach ($por_valor as $field => $valores) {
                if (isset($payload[$field]) && is_scalar($payload[$field]) && isset($valores[(string) $payload[$field]])) {
                    return (string) $valores[(string) $payload[$field]];
                }
            }
        }

        return $base_category;
    }

    /**
     * Campos que frenan una operación aunque su ruta sea libre (`panel_agent.field_brakes`).
     *
     * @param  string $method    Verbo HTTP (cualquier capitalización).
     * @param  string $route_uri URI completa del router, con `api/admin/`.
     * @return array<string, string> campo => categoría a la que sube el pedido; vacío si la operación no tiene.
     */
    public function field_brakes(string $method, string $route_uri): array
    {
        $key = strtoupper($method) . ' ' . $this->panel_path($route_uri);

        return (array) (config('panel_agent.field_brakes', [])[$key] ?? []);
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

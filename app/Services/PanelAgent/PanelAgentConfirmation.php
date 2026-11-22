<?php

namespace App\Services\PanelAgent;

use Carbon\Carbon;

/**
 * Código de confirmación de las operaciones frenadas del agente.
 *
 * El código es un HMAC del agente, el método, la ruta REAL y el cuerpo exacto, más una ventana de tiempo.
 * Por eso sólo vale para ese pedido y obliga a haber visto el preview que lo entrega: cambiar la ruta, el
 * cuerpo o esperar demasiado lo invalida.
 */
class PanelAgentConfirmation
{
    /**
     * Código de 16 caracteres para un pedido.
     *
     * @param  int                  $admin_id Agente que pide.
     * @param  string               $method   Verbo HTTP.
     * @param  string               $path     Camino real pedido (ej. `api/admin/message-variant/5`).
     * @param  array<string, mixed> $payload  Query + body SIN redactar (si no, dos cuerpos que sólo difieren en un secreto darían el mismo código).
     * @param  int|null             $window   Ventana de tiempo; null = la actual.
     * @return string
     */
    public function code_for(int $admin_id, string $method, string $path, array $payload, $window = null): string
    {
        $window = $window === null ? $this->current_window() : (int) $window;

        $material = implode('|', [$admin_id, strtoupper($method), $path, $this->canonical($payload), $window]);

        return substr(hash_hmac('sha256', $material, (string) config('app.key')), 0, 16);
    }

    /**
     * Indica si el código recibido corresponde al pedido (ventana actual o la anterior).
     *
     * @param  string|null          $code     Código recibido en `X-Agente-Confirmar`.
     * @param  int                  $admin_id Agente que pide.
     * @param  string               $method   Verbo HTTP.
     * @param  string               $path     Camino real pedido.
     * @param  array<string, mixed> $payload  Query + body sin redactar.
     * @return bool
     */
    public function matches($code, int $admin_id, string $method, string $path, array $payload): bool
    {
        if (! is_string($code) || $code === '') {
            return false;
        }

        $current = $this->current_window();

        foreach ([$current, $current - 1] as $window) {
            if (hash_equals($this->code_for($admin_id, $method, $path, $payload, $window), $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Número de la ventana de tiempo actual (cada `confirmation_ttl_minutes` minutos).
     *
     * @return int
     */
    private function current_window(): int
    {
        $seconds = max(1, (int) config('panel_agent.confirmation_ttl_minutes', 15)) * 60;

        return intdiv(Carbon::now()->getTimestamp(), $seconds);
    }

    /**
     * JSON canónico del cuerpo: claves ordenadas, así el orden en que llegan no cambia el código.
     *
     * @param  mixed $value Cuerpo o parte de él.
     * @return string
     */
    private function canonical($value): string
    {
        return (string) json_encode($this->sort_recursive($value));
    }

    /**
     * Ordena por clave los arrays asociativos (recursivo) y reemplaza archivos subidos por nombre y tamaño.
     *
     * @param  mixed $value Valor a normalizar.
     * @return mixed
     */
    private function sort_recursive($value)
    {
        if ($value instanceof \Illuminate\Http\UploadedFile) {
            return ['archivo' => $value->getClientOriginalName(), 'bytes' => $value->getSize()];
        }

        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->sort_recursive($item);
        }

        // Sólo se ordenan los arrays con claves de texto; las listas conservan su orden.
        if (array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value);
        }

        return $value;
    }
}

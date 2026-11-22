<?php

namespace App\Services\PanelAgent;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Código de confirmación de las operaciones frenadas del agente: de UN solo uso.
 *
 * Cada preview (428 o dry run de una categoría frenada) emite un código NUEVO, aleatorio. El servidor guarda en
 * el cache, bajo ese código, el HMAC(código | agente | método | ruta real | cuerpo exacto, app.key) y el
 * vencimiento (`confirmation_ttl_minutes`, 15). Al confirmar se recalcula el HMAC con lo que llegó, se compara
 * con `hash_equals` y el código se CONSUME con una marca atómica (`Cache::add`): reusarlo da «usado» y el guard
 * responde 428 con un preview nuevo. Así un reintento ciego del agente (por ejemplo tras un timeout del MCP)
 * nunca duplica un pago, un mensaje ni un borrado.
 *
 * El cache de producción (`CACHE_DRIVER`, `file` en `.env.example`) alcanza: si se vacía, los códigos pendientes
 * dejan de valer y el agente pide un preview nuevo (falla cerrado).
 */
class PanelAgentConfirmation
{
    /** Resultado de consume(): el código era válido y quedó consumido; se puede ejecutar. */
    const OK = 'ok';

    /** Resultado de consume(): el código ya se había usado. */
    const USED = 'usado';

    /** Resultado de consume(): código ausente, inventado, vencido o de otro pedido. */
    const INVALID = 'invalido';

    /** Minutos que el cache conserva el registro: más que la ventana, para distinguir «vencido» y «usado». */
    const RETENTION_MINUTES = 120;

    /**
     * Emite un código nuevo de un solo uso para un pedido y lo deja registrado.
     *
     * @param  int                  $admin_id Agente que pide.
     * @param  string               $method   Verbo HTTP.
     * @param  string               $path     Camino real pedido (ej. `api/admin/message-variant/5`).
     * @param  array<string, mixed> $payload  Query + body SIN redactar (si no, dos cuerpos que sólo difieren en un secreto darían el mismo vínculo).
     * @return string Código de 16 caracteres hexadecimales.
     */
    public function issue_code(int $admin_id, string $method, string $path, array $payload): string
    {
        // Nonce aleatorio: es el código que ve el agente.
        $code = bin2hex(random_bytes(8));

        // Vínculo del código con el pedido exacto y vencimiento lógico (se verifica con Carbon, no con el TTL del cache).
        $record = [
            'binding'    => $this->binding_for($code, $admin_id, $method, $path, $payload),
            'expires_at' => Carbon::now()->getTimestamp() + $this->ttl_seconds(),
        ];

        Cache::put($this->issued_key($admin_id, $code), $record, Carbon::now()->addMinutes(self::RETENTION_MINUTES));

        return $code;
    }

    /**
     * Valida el código recibido contra el pedido y, si corresponde, lo consume de forma atómica.
     *
     * Un código que no corresponde al pedido (otra ruta, otro cuerpo) NO se consume: sigue sirviendo para el suyo.
     *
     * @param  string|null          $code     Código recibido en `X-Agente-Confirmar`.
     * @param  int                  $admin_id Agente que pide.
     * @param  string               $method   Verbo HTTP.
     * @param  string               $path     Camino real pedido.
     * @param  array<string, mixed> $payload  Query + body sin redactar.
     * @return string OK (ejecutar), USED (ya se usó) o INVALID.
     */
    public function consume($code, int $admin_id, string $method, string $path, array $payload): string
    {
        // Sólo se aceptan los 16 hexadecimales que emite issue_code().
        if (! is_string($code) || preg_match('/^[0-9a-f]{16}$/', $code) !== 1) {
            return self::INVALID;
        }

        // Registro emitido para ese código; si no está puede ser un código ya consumido o uno inventado.
        $record = Cache::get($this->issued_key($admin_id, $code));
        if (! is_array($record)) {
            return Cache::has($this->used_key($admin_id, $code)) ? self::USED : self::INVALID;
        }

        // Vencido (más de `confirmation_ttl_minutes` desde el preview).
        if (Carbon::now()->getTimestamp() > (int) ($record['expires_at'] ?? 0)) {
            return self::INVALID;
        }

        // Ligado al pedido exacto: otra ruta u otro cuerpo no coinciden.
        $expected = $this->binding_for($code, $admin_id, $method, $path, $payload);
        if (! hash_equals((string) ($record['binding'] ?? ''), $expected)) {
            return self::INVALID;
        }

        // Consumo atómico: sólo el primero que logra agregar la marca ejecuta; el resto ve «usado».
        if (! Cache::add($this->used_key($admin_id, $code), 1, Carbon::now()->addMinutes(self::RETENTION_MINUTES))) {
            return self::USED;
        }
        Cache::forget($this->issued_key($admin_id, $code));

        return self::OK;
    }

    /**
     * HMAC que liga un código con el pedido exacto.
     *
     * @param  string               $code     Nonce del código.
     * @param  int                  $admin_id Agente.
     * @param  string               $method   Verbo HTTP.
     * @param  string               $path     Camino real pedido.
     * @param  array<string, mixed> $payload  Query + body sin redactar.
     * @return string
     */
    private function binding_for(string $code, int $admin_id, string $method, string $path, array $payload): string
    {
        $material = implode('|', [$code, $admin_id, strtoupper($method), $path, $this->canonical($payload)]);

        return hash_hmac('sha256', $material, (string) config('app.key'));
    }

    /**
     * Clave de cache del registro de un código emitido.
     *
     * @param  int    $admin_id Agente.
     * @param  string $code     Código.
     * @return string
     */
    private function issued_key(int $admin_id, string $code): string
    {
        return 'panel_agent_confirm:' . $admin_id . ':' . $code;
    }

    /**
     * Clave de cache de la marca «código usado».
     *
     * @param  int    $admin_id Agente.
     * @param  string $code     Código.
     * @return string
     */
    private function used_key(int $admin_id, string $code): string
    {
        return 'panel_agent_confirm_used:' . $admin_id . ':' . $code;
    }

    /**
     * Segundos de vida de un código (`confirmation_ttl_minutes`, 15 por defecto).
     *
     * @return int
     */
    private function ttl_seconds(): int
    {
        return max(1, (int) config('panel_agent.confirmation_ttl_minutes', 15)) * 60;
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

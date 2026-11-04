<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

/** Un respaldo por turno, compartido entre las llamadas que lo componen. */
class RespaldoDeepSeek
{
    private const FLASH = 'deepseek-flash';
    private const PRO = 'deepseek-v4-pro';
    private const ESTADOS_TRANSITORIOS = [408, 429, 500, 502, 503, 504, 529];

    private string $uso;
    private bool $usado = false;
    private ?string $modelo_efectivo = null;
    private ?string $respaldo_activo = null;

    public function __construct(string $uso)
    {
        $this->uso = $uso;
    }

    public function modelo_efectivo(): ?string
    {
        return $this->modelo_efectivo;
    }

    public function enviar(PendingRequest $http, string $url, array $payload, int $timeout): Response
    {
        $modelo = $payload['model'];
        $this->modelo_efectivo = $modelo;
        $alternativo = $this->modelo_alternativo($payload);
        if ($alternativo !== null && $alternativo === $this->respaldo_activo) {
            // Las rondas siguientes conservan el modelo que respondió en este turno.
            $payload['model'] = $alternativo;
            $this->modelo_efectivo = $alternativo;
        }
        if ($alternativo === null || $this->usado) {
            return $http->timeout($timeout)->post($url, $payload);
        }

        // Reservamos un cuarto del tiempo para el segundo modelo. Nunca empieza un plazo nuevo.
        $inicio = hrtime(true);
        $presupuesto_inicial = $timeout * 0.75;
        $respuesta = null;
        $conexion = null;
        try {
            $respuesta = $http->withOptions(['timeout' => $presupuesto_inicial])->post($url, $payload);
            if (! in_array($respuesta->status(), self::ESTADOS_TRANSITORIOS, true)) {
                return $respuesta;
            }
        } catch (ConnectionException $e) {
            if (! $this->es_conexion_transitoria($e)) {
                throw $e;
            }
            $conexion = $e;
        }

        $restante = $timeout - (hrtime(true) - $inicio) / 1e9;
        if ($restante < 0.01) {
            if ($conexion !== null) {
                throw $conexion;
            }
            return $respuesta;
        }

        $this->usado = true;
        $this->modelo_efectivo = $alternativo;
        $payload['model'] = $alternativo;
        $estado_alternativo = null;
        try {
            $segunda_respuesta = $http->withOptions(['timeout' => $restante])->post($url, $payload);
            $estado_alternativo = $segunda_respuesta->status();
            if ($segunda_respuesta->successful()) {
                $this->respaldo_activo = $alternativo;
            }
            return $segunda_respuesta;
        } finally {
            Log::warning('DeepSeek: se usó el modelo de respaldo.', [
                'uso' => $this->uso,
                'modelo_inicial' => $modelo,
                'modelo_alternativo' => $alternativo,
                'estado_inicial' => $respuesta !== null ? $respuesta->status() : null,
                'estado_alternativo' => $estado_alternativo,
                'motivo' => $conexion === null ? 'http_transitorio' : (
                    ReintentosDeHttpSaliente::es_timeout($conexion) ? 'timeout' : 'conexion_transitoria'
                ),
            ]);
        }
    }

    private function modelo_alternativo(array $payload): ?string
    {
        if (! empty($payload['stream'])) {
            return null;
        }

        // Estas rutas usan funciones de cliente. Una herramienta del proveedor podría haberse ejecutado.
        foreach ($payload['tools'] ?? [] as $tool) {
            if (isset($tool['type'])) {
                return null;
            }
        }

        if ($payload['model'] === self::PRO) {
            return self::FLASH;
        }

        $es_flash = in_array($payload['model'], [self::FLASH, 'deepseek-v4-flash', 'deepseek-v4-flash-vision-exp'], true);
        return $es_flash && ! $this->contiene_imagen($payload['messages'] ?? []) ? self::PRO : null;
    }

    private function contiene_imagen(array $bloques): bool
    {
        foreach ($bloques as $bloque) {
            if (! is_array($bloque)) {
                continue;
            }
            if (($bloque['type'] ?? null) === 'image') {
                return true;
            }
            if (is_array($bloque['content'] ?? null) && $this->contiene_imagen($bloque['content'])) {
                return true;
            }
        }

        return false;
    }

    private function es_conexion_transitoria(ConnectionException $e): bool
    {
        // cURL distingue transporte de fallas permanentes como URL inválida o certificado inválido.
        if (preg_match('/^cURL error (\d+)\b/i', $e->getMessage(), $codigo)) {
            return in_array((int) $codigo[1], [5, 6, 7, 18, 28, 52, 55, 56, 92], true);
        }

        return ReintentosDeHttpSaliente::es_timeout($e);
    }
}

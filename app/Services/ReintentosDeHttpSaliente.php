<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

/**
 * Criterios de "¿conviene reintentar?" para las llamadas salientes del admin a la instancia de un
 * cliente, pensados para el tercer parámetro de `->retry($veces, $espera, $cuando)` de Laravel 8.
 *
 * Nace en la misión modelos-ia-por-cliente (30/9/2026) para que el mismo criterio no viva copiado
 * en cada service: `ClientModelosIaSyncService` lo usa para la lectura en vivo y
 * `ImplementationImportService` para el análisis de Excel, que es el caso caro.
 *
 * 🔴 Recordatorio de Laravel 8 que explica por qué esto importa: con `retry()` en más de un intento,
 * CUALQUIER respuesta no-2xx se convierte en `RequestException` antes de volver al llamador. Si el
 * callback devuelve false, la excepción sube tal cual: el llamador tiene que atraparla y recuperar
 * `$e->response` para poder leer el código y el cuerpo reales.
 */
class ReintentosDeHttpSaliente
{
    /**
     * Si una falla de transporte fue un timeout.
     *
     * Se reconoce por el mensaje, que es lo único que trae la `ConnectionException` de Laravel 8:
     * cURL lo reporta como "cURL error 28" (operation timed out), y "timed out" cubre las variantes
     * que no pasan por cURL.
     *
     * @param \Throwable $exception
     *
     * @return bool
     */
    public static function es_timeout($exception)
    {
        $mensaje = strtolower((string) $exception->getMessage());

        return strpos($mensaje, 'curl error 28') !== false
            || strpos($mensaje, 'timed out') !== false;
    }

    /**
     * Criterio de una LECTURA EN VIVO (alguien mirando la pantalla), para un pedido idempotente.
     *
     * - Un 4xx no se arregla insistiendo (404 versión vieja, 401 clave, 409 USER_ID, 422 datos).
     * - Un timeout tampoco: duplicaría la espera para enterarse casi siempre de lo mismo.
     * - Un 5xx o una falla RÁPIDA de conexión sí: cuestan medio segundo y pueden ser un parpadeo.
     *
     * @param \Throwable $exception Excepción que levantó el cliente HTTP.
     *
     * @return bool
     */
    public static function para_lectura_en_vivo($exception)
    {
        if (! ($exception instanceof RequestException)) {
            // ConnectionException o similar: sin respuesta HTTP. Se reintenta salvo que sea un timeout.
            return ! self::es_timeout($exception);
        }

        if ($exception->response === null) {
            return true;
        }

        $status = (int) $exception->response->status();

        return $status < 400 || $status >= 500;
    }

    /**
     * Criterio de un pedido CARO y NO idempotente del otro lado: solo se reintenta cuando es seguro
     * que el pedido nunca llegó a procesarse.
     *
     * Es el del análisis de Excel: cada llamada es una consulta paga a la IA (DeepSeek Pro
     * razonando) y deja un `excel_path` nuevo en el cliente. Por eso:
     *
     * - 🔴 **Un timeout NO**: el cliente probablemente sigue analizando el primero, y reintentar
     *   dispara un segundo análisis pago en paralelo.
     * - 🔴 **Ninguna respuesta HTTP, ni 4xx ni 5xx**: si hubo respuesta, el pedido llegó y pudo
     *   haberse cobrado. Un 500 al final de un análisis es exactamente ese caso.
     * - **Una falla rápida de conexión sí** (conexión rechazada, DNS, no se pudo conectar): el
     *   pedido no salió, reintentar no cuesta nada.
     *
     * @param \Throwable $exception Excepción que levantó el cliente HTTP.
     *
     * @return bool
     */
    public static function solo_si_no_llego($exception)
    {
        if (! ($exception instanceof ConnectionException)) {
            return false;
        }

        return ! self::es_timeout($exception);
    }
}

<?php

namespace App\Services\IaApp;

use App\Models\Client;
use App\Services\ClientCatalogoPuenteService;
use App\Services\ClientEmpresaApiUrlResolver;
use App\Services\Concerns\TapaLaClaveDelCliente;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * La llamada saliente del admin al `empresa-api` de un cliente para todo lo de la app del agente del dueño
 * (misión cruzada `app-ia-y-memoria`, 29/10/2026): el token de la app (C1.1), el dueño para el directorio
 * (C1.2), crear la memoria (C1.3) y entregar una imagen (C1.4).
 *
 * Molde: `ClientModelosIaSyncService` / `ClientAgentesIaService`. Las reglas que se copian:
 *
 *  1. **Nunca lanza.** Todo termina en `{status, json, error, url}`; quien llama traduce el status.
 *  2. 🔴 **Sin seguir redirecciones.** Guzzle, al redirigir a otro host, saca `Authorization` pero NO un
 *     header propio como `X-Admin-Api-Key`: la clave del cliente viajaría a donde apunte la redirección.
 *     Un 3xx vuelve como un status más.
 *  3. 🔴 **Sin `->retry()`.** El login lleva una contraseña: un reintento automático es un segundo intento
 *     de login que nadie pidió. Los que necesitan reintentar (la entrega de una imagen) lo hacen por la cola.
 *  4. 🔴 **Nunca se loguea ni se devuelve el cuerpo de lo que contestó el cliente** en el camino del login:
 *     una instancia con APP_DEBUG prendido devuelve una página de error con el request volcado, y ahí iría
 *     la contraseña. Este cliente solo loguea el status y el error de transporte, con la clave tapada.
 *
 * PHP 7.4: sin `?->`, `match`, `str_contains`, argumentos nombrados, union types, promoción en constructor.
 */
class IaEmpresaCliente
{
    use TapaLaClaveDelCliente;

    /** @var ClientEmpresaApiUrlResolver */
    protected $resolver;

    /**
     * @param ClientEmpresaApiUrlResolver|null $resolver Inyectable para los tests.
     */
    public function __construct(?ClientEmpresaApiUrlResolver $resolver = null)
    {
        $this->resolver = $resolver !== null ? $resolver : new ClientEmpresaApiUrlResolver();
    }

    /**
     * La URL base del `empresa-api` del cliente (con `/public` en shared), sin barra final; '' si no hay.
     *
     * @param Client $client
     *
     * @return string
     */
    public function base_url(Client $client)
    {
        return rtrim($this->resolver->resolve_base_url($client), '/');
    }

    /**
     * Hace el pedido.
     *
     * @param Client                    $client  Cliente.
     * @param string                    $metodo  GET | POST | PUT.
     * @param string                    $ruta    Ruta relativa sin barra inicial (`api/admin-sync/...`).
     * @param array<string, mixed>      $cuerpo  Body (JSON, o campos del multipart si hay adjunto).
     * @param int                       $timeout Segundos.
     * @param array<string, mixed>|null $adjunto `{campo, contenido, nombre}` para mandar multipart con un
     *                                           archivo; `[]` para multipart sin archivo; null para JSON.
     *
     * @return array{status: int|null, json: array|null, error: string|null, url: string, motivo: string|null}
     *         `motivo` = `sin_clave` | `sin_frente` | `sin_conexion` cuando no hubo respuesta HTTP.
     */
    public function pedir(Client $client, $metodo, $ruta, array $cuerpo = [], $timeout = 15, $adjunto = null)
    {
        $clave = trim((string) $client->api_key);

        if ($clave === '') {
            return $this->sin_respuesta('sin_clave', '', 'El cliente no tiene clave de API cargada en el admin.');
        }

        $base = $this->base_url($client);

        if ($base === '') {
            return $this->sin_respuesta('sin_frente', '', 'El cliente no tiene una API activa con una URL válida.');
        }

        $url = $base . '/' . ltrim($ruta, '/');

        $response = null;

        try {
            $pedido = Http::withHeaders([
                    'X-Admin-Api-Key' => $clave,
                    'Accept'          => 'application/json',
                ])
                ->withOptions(['allow_redirects' => false])
                ->timeout((int) $timeout);

            if (is_array($adjunto)) {
                // Multipart. Sin `campo` (una entrega de fallo, sin imagen) va multipart igual: el contrato C1.4 lo es.
                $pedido = isset($adjunto['campo'])
                    ? $pedido->attach((string) $adjunto['campo'], $adjunto['contenido'], (string) $adjunto['nombre'])
                    : $pedido->asMultipart();
                $response = $pedido->post($url, $cuerpo);
            } elseif ($metodo === 'GET') {
                $response = $pedido->get($url);
            } elseif ($metodo === 'PUT') {
                $response = $pedido->asJson()->put($url, $cuerpo);
            } else {
                $response = $pedido->asJson()->post($url, $cuerpo);
            }
        } catch (RequestException $e) {
            // Sin `->retry()` no debería pasar, pero si alguien lo agrega, la respuesta real no se pierde.
            $response = $e->response;
        } catch (\Throwable $e) {
            $error = $this->texto_seguro(
                ClientCatalogoPuenteService::sin_la_query_de_las_urls($e->getMessage()),
                $clave
            );

            Log::warning('IaEmpresaCliente: no se pudo contactar al empresa-api del cliente.', [
                'client_id' => (int) $client->id,
                'ruta'      => $ruta,
                'error'     => $error,
            ]);

            return $this->sin_respuesta('sin_conexion', $url, $error);
        }

        if ($response === null) {
            return $this->sin_respuesta('sin_conexion', $url, 'Sin respuesta del empresa-api.');
        }

        $json = json_decode((string) $response->body(), true);

        return [
            'status' => (int) $response->status(),
            'json'   => is_array($json) ? $json : null,
            'error'  => null,
            'url'    => $url,
            'motivo' => null,
        ];
    }

    /**
     * El `code` de un cuerpo de error del contrato (`{code, message}`), o '' si no trae.
     *
     * @param array|null $json
     *
     * @return string
     */
    public static function codigo($json)
    {
        if (is_array($json) && isset($json['code']) && is_scalar($json['code'])) {
            return (string) $json['code'];
        }

        return '';
    }

    /**
     * @param string $motivo
     * @param string $url
     * @param string $error
     *
     * @return array{status: null, json: null, error: string, url: string, motivo: string}
     */
    protected function sin_respuesta($motivo, $url, $error)
    {
        return ['status' => null, 'json' => null, 'error' => $error, 'url' => $url, 'motivo' => $motivo];
    }
}

<?php

namespace App\Services;

use App\Models\Client;
use App\Services\Concerns\TapaLaClaveDelCliente;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pide al empresa-api de UN cliente un acceso de un solo uso al configurador de extensiones
 * (misión `extensiones-desde-el-admin`, 15/10/2026) y arma la URL con la que el navegador lo canjea.
 *
 * Contrato (empresa-api): `POST {frente activo}/api/admin-sync/extensiones/acceso`, header `X-Admin-Api-Key` =
 * `clients.api_key`, sin cuerpo. Responde 200 `{ok, acceso: <64 hex>, expira_en, ruta}`. Un 404/405 es un cliente con
 * versión vieja (la ruta no existe todavía).
 *
 * 🔴 Lo que NO sale nunca: la clave del cliente (ni en la respuesta, ni en el log, ni en un mensaje de error) y el
 * token de acceso (solo viaja dentro de `url`; no se loguea).
 *
 * 🔴 Sin seguir redirecciones: Guzzle no saca un header propio como `X-Admin-Api-Key` al redirigir a otro host.
 *
 * Devuelve SIEMPRE un array `{estado, mensaje, url}`; el controlador lo responde con HTTP 200.
 *
 * PHP 7.4: sin `?->`, `match`, `str_contains`, argumentos nombrados, union types, promoción en constructor, etc.
 */
class ClientExtensionesAccesoService
{
    use TapaLaClaveDelCliente;

    /** Ruta del empresa-api que emite el acceso. */
    const RUTA = 'api/admin-sync/extensiones/acceso';

    /** Segundos de espera de la llamada. */
    const TIMEOUT = 15;

    const ESTADO_OK              = 'ok';
    const ESTADO_VERSION_VIEJA   = 'version_vieja';
    const ESTADO_SIN_CLAVE       = 'sin_clave';
    const ESTADO_CLAVE_RECHAZADA = 'clave_rechazada';
    const ESTADO_SIN_FRENTE      = 'sin_frente';
    const ESTADO_SIN_CONEXION    = 'sin_conexion';

    /** @var ClientEmpresaApiUrlResolver */
    protected $api_url_resolver;

    /**
     * @param ClientEmpresaApiUrlResolver|null $api_url_resolver Resolver del frente activo.
     */
    public function __construct(?ClientEmpresaApiUrlResolver $api_url_resolver = null)
    {
        $this->api_url_resolver = $api_url_resolver ?: new ClientEmpresaApiUrlResolver();
    }

    /**
     * Pide el acceso y devuelve el resultado para el botón del admin.
     *
     * @param Client $client Cliente al que se le abre el configurador.
     *
     * @return array{estado: string, mensaje: string, url: string|null}
     */
    public function pedir_acceso(Client $client)
    {
        $clave = trim((string) $client->api_key);

        if ($clave === '') {
            return $this->resultado(
                self::ESTADO_SIN_CLAVE,
                'Este cliente no tiene clave de API cargada en el admin, así que no se puede pedir el acceso.'
            );
        }

        $base = $this->api_url_resolver->resolve_base_url($client);

        if ($base === '') {
            return $this->resultado(
                self::ESTADO_SIN_FRENTE,
                'Este cliente no tiene una API activa con una URL válida.'
            );
        }

        $base = rtrim($base, '/');

        try {
            $response = Http::withHeaders([
                    'X-Admin-Api-Key' => $clave,
                    'Accept'          => 'application/json',
                ])
                ->withOptions(['allow_redirects' => false])
                ->timeout(self::TIMEOUT)
                ->post($base . '/' . self::RUTA);
        } catch (\Throwable $e) {
            // Sin la query de las URLs ni la clave: el mensaje de Guzzle repite la URL entera.
            Log::warning('ClientExtensionesAccesoService: no se pudo contactar al empresa-api del cliente.', [
                'client_id' => (int) $client->id,
                'error'     => $this->texto_seguro(ClientCatalogoPuenteService::sin_la_query_de_las_urls($e->getMessage()), $clave),
            ]);

            return $this->sin_conexion();
        }

        $status = (int) $response->status();

        Log::info('ClientExtensionesAccesoService: pedido de acceso al configurador.', [
            'client_id' => (int) $client->id,
            'status'    => $status,
        ]);

        if ($status === 404 || $status === 405) {
            return $this->resultado(
                self::ESTADO_VERSION_VIEJA,
                'Este cliente todavía no tiene la actualización que habilita el acceso desde el admin.'
            );
        }

        if ($status === 401) {
            return $this->resultado(
                self::ESTADO_CLAVE_RECHAZADA,
                'El sistema del cliente rechazó la clave de API que tiene el admin. Hay que volver a sincronizarla.'
            );
        }

        if ($status !== 200) {
            return $this->sin_conexion();
        }

        $json   = json_decode((string) $response->body(), true);
        $acceso = is_array($json) && isset($json['acceso']) ? $json['acceso'] : null;

        // El token tiene que ser exactamente 64 hex: si no, no se arma ninguna URL con lo que haya venido.
        if (!is_string($acceso) || !preg_match('/\A[0-9a-f]{64}\z/i', $acceso)) {
            return $this->sin_conexion();
        }

        return $this->resultado(
            self::ESTADO_OK,
            'Abriendo el configurador de extensiones...',
            $base . '/extensiones?acceso=' . urlencode($acceso)
        );
    }

    /**
     * Resultado genérico de "no se pudo hablar con el cliente" (red, timeout, 5xx, respuesta inválida).
     *
     * @return array{estado: string, mensaje: string, url: string|null}
     */
    protected function sin_conexion()
    {
        return $this->resultado(
            self::ESTADO_SIN_CONEXION,
            'No se pudo contactar al sistema del cliente. Probá de nuevo en un momento.'
        );
    }

    /**
     * @param string      $estado  Uno de los ESTADO_*.
     * @param string      $mensaje Texto para mostrar.
     * @param string|null $url     URL de canje (solo en `ok`).
     *
     * @return array{estado: string, mensaje: string, url: string|null}
     */
    protected function resultado($estado, $mensaje, $url = null)
    {
        return ['estado' => $estado, 'mensaje' => $mensaje, 'url' => $url];
    }
}

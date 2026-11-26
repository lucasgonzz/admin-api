<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientVersionUpgrade;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Habla con el aviso "estamos actualizando el sistema" del empresa-api de un cliente
 * (`api/admin-sync/actualizacion-del-sistema`, misión aviso-actualizacion-sistema, 25/11/2026).
 *
 * Es el único lugar del admin que sabe cómo se le pide algo a ese endpoint. Lo usan dos
 * consumidores, y los dos esperan exactamente el mismo resultado:
 *
 *  - `DeploymentService` (ganchos G1 a G5): prende, renueva y libera el aviso alrededor del
 *    despliegue en el lugar;
 *  - `ClaudeAvisoDeActualizacionController` y `ClaudeUpgradeOpsController::deploy_start_json()`:
 *    el pedido de OK a los usuarios, el estado y la exigencia del OK antes de un `force`.
 *
 * 🔴 NUNCA LANZA. Es la regla de fondo, la misma de `ClientSessionLockSyncService` y de
 * `ClientScheduleSyncService`: cuando esto corre, el deploy (o el endpoint de la skill) ya está en
 * marcha, y un empresa-api caído, con otra clave o con una versión vieja no puede convertirse en un
 * error del admin. Todo desenlace —404, 401, 500, un corte de red, una URL que no se pudo resolver,
 * una respuesta que no es JSON— vuelve como datos y nunca como excepción.
 *
 * 🔴 EL 404 ES UN CASO ESPERADO. Mientras el cliente no haya recibido la versión de empresa que trae
 * el endpoint, contesta 404: se lee como `soportado = false` y se degrada limpio (el deploy sigue
 * como antes, el endpoint de la skill contesta `version_sin_aviso`). Sólo el 404 significa "esta
 * versión no lo tiene": un 401 (clave que no coincide) o un 500 NO son "versión vieja".
 *
 * Resultado común de todos los métodos públicos (siempre estas cuatro claves):
 *
 *   'soportado' => bool         false SOLO con 404; true en cualquier otro desenlace.
 *   'http'      => int|null     código HTTP de empresa-api, o null si no hubo respuesta.
 *   'aviso'     => array|null   cuerpo `AvisoEstado` SIN la clave `ok` (estado, vence_at, pedido, …);
 *                               null si la llamada no terminó en 2xx con un cuerpo válido.
 *   'error'     => string|null  null si salió bien; si no, el código que dijo empresa-api
 *                               (`ya_actualizando`, `tabla_pendiente`, …), `no_soportado` (404),
 *                               `sin_url`, `sin_respuesta` o `respuesta_invalida`.
 *
 * Compatible con PHP 7.4.
 */
class ClientAvisoDeActualizacionService
{
    /** Ruta del admin-sync de empresa-api, sin barra inicial: `PUT`/`GET` del estado. */
    const PATH = 'api/admin-sync/actualizacion-del-sistema';

    /** Ruta del pedido de OK (`POST` lo crea, `DELETE` lo cancela). */
    const PATH_PEDIDO = self::PATH . '/pedido';

    /** Estado que se le pide a empresa-api para bloquear el sistema. */
    const ESTADO_ACTUALIZANDO = 'actualizando';

    /** Estado que se le pide a empresa-api para dejar entrar de nuevo. */
    const ESTADO_LIBRE = 'libre';

    /** Caracteres del mensaje de un error de transporte que se guardan en el resultado. */
    const CHARS_DE_ERROR = 200;

    /**
     * Resuelve la URL base del empresa-api del cliente (frente destino del upgrade).
     *
     * @var ClientEmpresaApiUrlResolver
     */
    protected $api_url_resolver;

    /**
     * @param ClientEmpresaApiUrlResolver|null $api_url_resolver Inyectable para tests.
     */
    public function __construct(?ClientEmpresaApiUrlResolver $api_url_resolver = null)
    {
        $this->api_url_resolver = $api_url_resolver === null ? new ClientEmpresaApiUrlResolver() : $api_url_resolver;
    }

    /**
     * ¿Está prendido el interruptor general del aviso?
     *
     * Apagado (el default), nadie le habla a ningún cliente por este tema: los consumidores tienen que
     * preguntar ACÁ antes de llamar a cualquier otro método, y no hacer nada si da falso.
     *
     * @return bool
     */
    public function habilitado(): bool
    {
        return (bool) config('aviso_de_actualizacion.habilitado', false);
    }

    /**
     * Lee el estado del aviso del cliente (`GET admin-sync/actualizacion-del-sistema`). Empresa-api
     * evalúa ahí las transiciones perezosas (todos aceptaron, venció el plazo, venció el bloqueo).
     *
     * @param Client               $client  Cliente dueño del sistema.
     * @param ClientVersionUpgrade $upgrade Upgrade en curso (de él sale el frente al que se le habla).
     *
     * @return array{soportado: bool, http: int|null, aviso: array|null, error: string|null}
     */
    public function leer(Client $client, ClientVersionUpgrade $upgrade): array
    {
        return $this->enviar('GET', $client, $upgrade, self::PATH, []);
    }

    /**
     * Prende el aviso: `PUT … {estado: "actualizando"}`. El sistema del cliente cierra las sesiones, no
     * deja ingresar y muestra "estamos actualizando" hasta que se libere o venza.
     *
     * @param Client               $client           Cliente dueño del sistema.
     * @param ClientVersionUpgrade $upgrade          Upgrade en curso (viaja su id y la versión destino).
     * @param int|null             $vence_en_minutos Cuánto dura sin renovarse (5..240); null = el default del config.
     *
     * @return array{soportado: bool, http: int|null, aviso: array|null, error: string|null}
     */
    public function prender(Client $client, ClientVersionUpgrade $upgrade, $vence_en_minutos = null): array
    {
        $minutos = $vence_en_minutos === null
            ? (int) config('aviso_de_actualizacion.vence_en_minutos', 120)
            : (int) $vence_en_minutos;

        return $this->enviar('PUT', $client, $upgrade, self::PATH, array_merge(
            $this->datos_del_upgrade($upgrade),
            ['estado' => self::ESTADO_ACTUALIZANDO, 'vence_en_minutos' => $minutos]
        ));
    }

    /**
     * Suelta el aviso: `PUT … {estado: "libre"}`. Los usuarios vuelven a poder ingresar.
     *
     * @param Client               $client  Cliente dueño del sistema.
     * @param ClientVersionUpgrade $upgrade Upgrade en curso.
     *
     * @return array{soportado: bool, http: int|null, aviso: array|null, error: string|null}
     */
    public function liberar(Client $client, ClientVersionUpgrade $upgrade): array
    {
        return $this->enviar('PUT', $client, $upgrade, self::PATH, array_merge(
            $this->datos_del_upgrade($upgrade),
            ['estado' => self::ESTADO_LIBRE]
        ));
    }

    /**
     * Pide el OK a las computadoras conectadas: `POST …/pedido`. Idempotente del lado de empresa-api
     * (si ya hay un pedido abierto o el sistema ya está actualizando, devuelve el estado actual).
     *
     * @param Client               $client                 Cliente dueño del sistema.
     * @param ClientVersionUpgrade $upgrade                Upgrade que se va a desplegar.
     * @param int|null             $minutos_para_responder Plazo (1..30); null = el default del config.
     *
     * @return array{soportado: bool, http: int|null, aviso: array|null, error: string|null}
     */
    public function pedir_ok(Client $client, ClientVersionUpgrade $upgrade, $minutos_para_responder = null): array
    {
        $minutos = $minutos_para_responder === null
            ? (int) config('aviso_de_actualizacion.minutos_para_responder', 10)
            : (int) $minutos_para_responder;

        return $this->enviar('POST', $client, $upgrade, self::PATH_PEDIDO, array_merge(
            $this->datos_del_upgrade($upgrade),
            ['minutos_para_responder' => $minutos]
        ));
    }

    /**
     * Cancela el pedido de OK: `DELETE …/pedido`. Empresa-api contesta 409 `ya_actualizando` si el
     * pedido ya pasó a bloquear el sistema (para soltar ese estado se usa `liberar()`).
     *
     * @param Client               $client  Cliente dueño del sistema.
     * @param ClientVersionUpgrade $upgrade Upgrade en curso.
     *
     * @return array{soportado: bool, http: int|null, aviso: array|null, error: string|null}
     */
    public function cancelar_pedido(Client $client, ClientVersionUpgrade $upgrade): array
    {
        return $this->enviar('DELETE', $client, $upgrade, self::PATH_PEDIDO, []);
    }

    /**
     * Lo que empresa-api necesita saber del upgrade: su id y la versión a la que se actualiza.
     * Ninguno de los dos es obligatorio del otro lado (sólo se muestran en el aviso y en los logs).
     *
     * @param ClientVersionUpgrade $upgrade Upgrade en curso.
     *
     * @return array<string, mixed>
     */
    protected function datos_del_upgrade(ClientVersionUpgrade $upgrade): array
    {
        $datos = ['upgrade_id' => (int) $upgrade->id];

        $version = $upgrade->to_version;
        if ($version !== null && trim((string) $version->version) !== '') {
            $datos['version'] = (string) $version->version;
        }

        return $datos;
    }

    /**
     * Hace la llamada HTTP y la traduce al resultado común. 🔴 No lanza nunca.
     *
     * @param string               $metodo  GET | PUT | POST | DELETE.
     * @param Client               $client  Cliente dueño del sistema.
     * @param ClientVersionUpgrade $upgrade Upgrade en curso (resuelve el frente destino).
     * @param string               $path    Ruta de admin-sync, sin barra inicial.
     * @param array<string, mixed> $datos   Cuerpo JSON (vacío en GET y DELETE).
     *
     * @return array{soportado: bool, http: int|null, aviso: array|null, error: string|null}
     */
    protected function enviar(string $metodo, Client $client, ClientVersionUpgrade $upgrade, string $path, array $datos): array
    {
        try {
            $url = $this->api_url_resolver->admin_sync_url($client, $path, $upgrade);
            if ($url === '') {
                return $this->resultado(true, null, null, 'sin_url');
            }

            $headers = ['Accept' => 'application/json'];
            if (! empty($client->api_key)) {
                $headers['X-Admin-Api-Key'] = $client->api_key;
            }

            $pedido = Http::withHeaders($headers)
                ->timeout((int) config('aviso_de_actualizacion.timeout', 10))
                ->retry(1, 500);

            /* Respuesta HTTP real (si la hubo) y error de transporte (si no). */
            $respuesta         = null;
            $error_transporte = '';

            try {
                switch ($metodo) {
                    case 'PUT':
                        $respuesta = $pedido->put($url, $datos);
                        break;
                    case 'POST':
                        $respuesta = $pedido->post($url, $datos);
                        break;
                    case 'DELETE':
                        $respuesta = $pedido->delete($url);
                        break;
                    default:
                        $respuesta = $pedido->get($url);
                        break;
                }
            } catch (RequestException $e) {
                /* Con más de un intento, Laravel 8 convierte el no-2xx en excepción antes de devolver
                   nada: se recupera la respuesta real para poder distinguir el 404 del resto. */
                $respuesta = $e->response;
            } catch (\Throwable $e) {
                /* ConnectionException, timeout, DNS: no hay respuesta HTTP asociada. */
                $error_transporte = $e->getMessage();
            }

            if ($respuesta === null) {
                Log::warning('ClientAvisoDeActualizacionService: no se pudo contactar al empresa-api del cliente.', [
                    'client_id'  => $client->id,
                    'upgrade_id' => $upgrade->id,
                    'metodo'     => $metodo,
                    'error'      => substr($error_transporte, 0, self::CHARS_DE_ERROR),
                ]);

                return $this->resultado(true, null, null, 'sin_respuesta');
            }

            return $this->interpretar($respuesta);
        } catch (\Throwable $e) {
            Log::warning('ClientAvisoDeActualizacionService: falló antes de llamar al empresa-api del cliente.', [
                'client_id'  => $client->id,
                'upgrade_id' => $upgrade->id,
                'metodo'     => $metodo,
                'error'      => substr($e->getMessage(), 0, self::CHARS_DE_ERROR),
            ]);

            return $this->resultado(true, null, null, 'sin_respuesta');
        }
    }

    /**
     * Traduce la respuesta HTTP de empresa-api al resultado común.
     *
     * @param \Illuminate\Http\Client\Response $respuesta Respuesta recibida.
     *
     * @return array{soportado: bool, http: int|null, aviso: array|null, error: string|null}
     */
    protected function interpretar($respuesta): array
    {
        $http = (int) $respuesta->status();

        /* 404: la versión instalada del cliente todavía no tiene el endpoint. */
        if ($http === 404) {
            return $this->resultado(false, $http, null, 'no_soportado');
        }

        $cuerpo = $respuesta->json();

        if ($respuesta->successful()) {
            if (! is_array($cuerpo) || ! isset($cuerpo['estado'])) {
                return $this->resultado(true, $http, null, 'respuesta_invalida');
            }

            unset($cuerpo['ok']);

            return $this->resultado(true, $http, $cuerpo, null);
        }

        /* Otro HTTP de error: el código propio de empresa-api si lo trae (`ya_actualizando`, …). */
        $codigo = is_array($cuerpo) && isset($cuerpo['error']) && is_string($cuerpo['error']) && $cuerpo['error'] !== ''
            ? $cuerpo['error']
            : 'http_' . $http;

        return $this->resultado(true, $http, null, $codigo);
    }

    /**
     * Arma el resultado común.
     *
     * @param bool        $soportado Falso sólo con 404.
     * @param int|null    $http      Código HTTP, o null sin respuesta.
     * @param array|null  $aviso     `AvisoEstado` sin `ok`, o null.
     * @param string|null $error     Código del error, o null si salió bien.
     *
     * @return array{soportado: bool, http: int|null, aviso: array|null, error: string|null}
     */
    protected function resultado(bool $soportado, $http, $aviso, $error): array
    {
        return [
            'soportado' => $soportado,
            'http'      => $http,
            'aviso'     => $aviso,
            'error'     => $error,
        ];
    }
}

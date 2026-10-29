<?php

namespace App\Services\IaApp;

use App\Models\Client;
use App\Models\ClientApi;
use App\Services\RecursosSesionService;
use Illuminate\Support\Facades\Log;

/**
 * El login de la app `ia.comerciocity.com` (contrato C4.1 de la misión cruzada `app-ia-y-memoria`,
 * 29/10/2026): con el usuario y la contraseña del sistema del dueño, encuentra su sistema y le pide un token
 * de la app (`POST api/admin-sync/ia-app/token`, contrato C1.1).
 *
 * Cómo se elige el sistema:
 *  - con `negocio` (slug, o un host como `ferretotal.comerciocity.com`): ese cliente y nada más. El host se
 *    compara contra los frentes que el admin YA tiene cargados (`client_apis.url` / `spa_url`, y
 *    `clients.api_url` legacy); 🔴 nunca se le pega a una URL que mandó el navegador (mismo criterio que
 *    `RecursosSesionService`: si no, cualquiera apuntaría el admin a un servidor propio y se llevaría la
 *    `api_key` del cliente en el header).
 *  - sin `negocio`: los clientes del directorio cuyo dueño tiene ese usuario (hasta 5).
 *  Se prueba uno por uno; el primero que da 200 gana.
 *
 * 🔴 LA CONTRASEÑA: viaja solo en el body del POST al sistema del cliente. No se guarda, no se loguea, no va
 * en ninguna URL ni en ningún mensaje de error, y del cuerpo de lo que contesta el cliente no se loguea ni se
 * devuelve nada salvo los campos del contrato (una instancia con APP_DEBUG devolvería el request volcado).
 *
 * Desenlaces (`{http, cuerpo}` listos para la respuesta): ver `resolver()`.
 */
class IaLoginService
{
    /** Ruta del contrato C1.1 en el `empresa-api` del cliente. */
    const RUTA_TOKEN = 'api/admin-sync/ia-app/token';

    /** @var IaEmpresaCliente */
    protected $empresa;

    /** @var IaDirectorioService */
    protected $directorio;

    /**
     * @param IaEmpresaCliente|null    $empresa
     * @param IaDirectorioService|null $directorio
     */
    public function __construct(?IaEmpresaCliente $empresa = null, ?IaDirectorioService $directorio = null)
    {
        $this->empresa    = $empresa !== null ? $empresa : new IaEmpresaCliente();
        $this->directorio = $directorio !== null ? $directorio : new IaDirectorioService($this->empresa);
    }

    /**
     * Resuelve el login.
     *
     * @param string      $usuario
     * @param CredencialDeLogin $credencial 🔴 La contraseña, envuelta: solo se abre para el body al sistema del
     *                                       cliente (ver `CredencialDeLogin`).
     * @param string|null $negocio
     * @param string      $dispositivo Nombre corto del dispositivo (para el nombre del token).
     *
     * @return array{http: int, cuerpo: array<string, mixed>}
     */
    public function resolver($usuario, CredencialDeLogin $credencial, $negocio, $dispositivo)
    {
        $por_negocio = is_string($negocio) && trim($negocio) !== '';
        $maximo      = (int) config('ia_app.login.max_candidatos', 5);

        if ($por_negocio) {
            $candidatos = $this->clientes_por_negocio(trim($negocio), $maximo);

            if ($candidatos === []) {
                return $this->error(404, 'negocio_no_encontrado', 'No encontramos ese sistema. Revisá el nombre (por ejemplo: ferretotal).');
            }
        } else {
            $candidatos = $this->directorio->candidatos($usuario, $maximo)->all();

            if ($candidatos === []) {
                return $this->error(422, 'negocio_requerido', 'Decime cuál es tu sistema (por ejemplo: ferretotal).');
            }
        }

        /** @var array<string, int> Cuántos candidatos terminaron en cada desenlace. */
        $desenlaces = [];

        /* Hasta 5 sistemas con 15 s cada uno son 75 s: más que el max_execution_time de un pedido web. Se sube
           el tope del proceso y, además, se corta la vuelta si el próximo intento ya no entra en el presupuesto
           (lo que no se probó cuenta como «no disponible», nunca como contraseña mala). */
        $timeout     = (int) config('ia_app.login.timeout', 15);
        $presupuesto = (int) config('ia_app.login.presupuesto_segundos', 45);
        $limite      = microtime(true) + max($timeout, $presupuesto);

        /* 🔴 Solo se SUBE un tope que ya existe. Con max_execution_time en 0 (CLI, tests, workers) no hay tope, y
           un set_time_limit() acá le pondría uno a todo el proceso: medido el 29/10/2026, la suite de phpunit
           murió a los 75 s de un test del login, en medio de un test de otra área. */
        $tope_actual = (int) ini_get('max_execution_time');
        $necesario   = max($timeout, $presupuesto) + 30;

        if ($tope_actual > 0 && $tope_actual < $necesario && function_exists('set_time_limit')) {
            @set_time_limit($necesario);
        }

        foreach ($candidatos as $indice => $client) {
            if ($indice > 0 && microtime(true) + $timeout > $limite) {
                $desenlaces['falla'] = (isset($desenlaces['falla']) ? $desenlaces['falla'] : 0) + 1;

                break;
            }

            $intento = $this->probar($client, $usuario, $credencial, $dispositivo);

            if ($intento['desenlace'] === 'ok') {
                if ($por_negocio) {
                    $this->aprender_sin_romper($client, $usuario, $intento['cuerpo']['usuario']['nombre']);
                }

                return ['http' => 200, 'cuerpo' => $intento['cuerpo']];
            }

            $desenlaces[$intento['desenlace']] = isset($desenlaces[$intento['desenlace']])
                ? $desenlaces[$intento['desenlace']] + 1
                : 1;
        }

        return $this->sin_ganador($desenlaces, count($candidatos));
    }

    /**
     * Prueba el login contra UN sistema.
     *
     * @param Client $client
     * @param string $usuario
     * @param CredencialDeLogin $credencial
     * @param string $dispositivo
     *
     * @return array{desenlace: string, cuerpo: array<string, mixed>|null}
     *         desenlace: ok | credenciales | no_es_dueno | sin_extension | configuracion | version_vieja | falla
     */
    protected function probar(Client $client, $usuario, CredencialDeLogin $credencial, $dispositivo)
    {
        $respuesta = $this->empresa->pedir(
            $client,
            'POST',
            self::RUTA_TOKEN,
            // Único lugar donde la contraseña sale en claro: el body del POST al sistema del cliente.
            ['doc_number' => (string) $usuario, 'password' => $credencial->valor(), 'dispositivo' => (string) $dispositivo],
            (int) config('ia_app.login.timeout', 15)
        );

        $status = $respuesta['status'];
        $json   = $respuesta['json'];
        $codigo = IaEmpresaCliente::codigo($json);

        // Solo el status y el code del contrato: ni el cuerpo ni nada de lo que se mandó.
        Log::info('IaLoginService: intento contra el sistema de un cliente.', [
            'client_id' => (int) $client->id,
            'status'    => $status,
            'code'      => $codigo === '' ? null : mb_substr($codigo, 0, 40),
            'motivo'    => $respuesta['motivo'],
        ]);

        if ($status === 200) {
            $cuerpo = $this->cuerpo_de_exito($client, $json);

            return $cuerpo === null
                ? ['desenlace' => 'falla', 'cuerpo' => null]
                : ['desenlace' => 'ok', 'cuerpo' => $cuerpo];
        }

        if ($status === 401 && $codigo === 'credenciales_invalidas') {
            return ['desenlace' => 'credenciales', 'cuerpo' => null];
        }

        /* La instalación del cliente está mal configurada (no es la contraseña): la clave del admin no es la
           que tiene su .env, o la base es compartida y le falta USER_ID para saber quién es el dueño. */
        if (($status === 401 && $codigo === 'clave_invalida') || ($status === 409 && $codigo === 'dueno_no_resoluble')) {
            return ['desenlace' => 'configuracion', 'cuerpo' => null];
        }

        if ($status === 422) {
            // El sistema del cliente rechazó el formato del usuario o la contraseña: para la persona es lo mismo.
            return ['desenlace' => 'credenciales', 'cuerpo' => null];
        }

        if ($status === 403 && ($codigo === 'no_es_dueno' || $codigo === 'sin_extension')) {
            return ['desenlace' => $codigo, 'cuerpo' => null];
        }

        if ($status === 404 || $status === 405) {
            return ['desenlace' => 'version_vieja', 'cuerpo' => null];
        }

        // 401 de la clave del admin (`{"error":"unauthorized"}`), 409 (USER_ID), 5xx, 3xx, sin conexión.
        return ['desenlace' => 'falla', 'cuerpo' => null];
    }

    /**
     * La respuesta 200 del login (contrato C4.1, paso 3), o null si el 200 del cliente no trae el contrato.
     *
     * @param Client     $client
     * @param array|null $json
     *
     * @return array<string, mixed>|null
     */
    protected function cuerpo_de_exito(Client $client, $json)
    {
        if (! is_array($json) || ! isset($json['token']) || ! is_string($json['token']) || trim($json['token']) === '') {
            return null;
        }

        $usuario   = isset($json['usuario']) && is_array($json['usuario']) ? $json['usuario'] : [];
        $negocio   = isset($json['negocio']) && is_array($json['negocio']) ? $json['negocio'] : [];
        $capacidad = isset($json['capacidades']) && is_array($json['capacidades']) ? $json['capacidades'] : null;

        $nombre_negocio = isset($negocio['nombre']) && is_scalar($negocio['nombre']) && trim((string) $negocio['nombre']) !== ''
            ? trim((string) $negocio['nombre'])
            : $client->resolve_display_name();

        return [
            'token'       => (string) $json['token'],
            // 🔴 La misma base a la que se le pidió el token: el token vive en la base de ESA instancia.
            'api_url'     => $this->empresa->base_url($client),
            'negocio'     => [
                'nombre'   => $nombre_negocio,
                'slug'     => $client->slug,
                'logo_url' => isset($negocio['logo_url']) && is_string($negocio['logo_url']) && $negocio['logo_url'] !== ''
                    ? $negocio['logo_url']
                    : null,
            ],
            'usuario'     => [
                'id'     => isset($usuario['id']) && is_numeric($usuario['id']) ? (int) $usuario['id'] : null,
                'nombre' => isset($usuario['nombre']) && is_scalar($usuario['nombre']) ? (string) $usuario['nombre'] : null,
            ],
            'capacidades' => $capacidad !== null ? $capacidad : ['version_api' => '1', 'funciones' => []],
        ];
    }

    /**
     * Ningún sistema aceptó: qué se le dice a la persona.
     *
     * Orden:
     *  1. `no_es_dueno` y `sin_extension` (403): los dos solo salen DESPUÉS de que el sistema validó la
     *     contraseña, así que son la respuesta verdadera.
     *  2. 🔴 Si algún candidato NO respondió (red, timeout, 5xx, 3xx, clave del admin rechazada, o quedó afuera
     *     por el presupuesto de tiempo): `502 sistema_no_disponible`, aunque otro haya dado 401. Su sistema
     *     puede ser justo el que no contestó, y decirle «contraseña incorrecta» lo mandaría a cambiarla.
     *  3. `401 credenciales_invalidas` solo si TODOS los candidatos dijeron credenciales inválidas.
     *  4. Si alguno contestó que su instalación está mal configurada (401 `clave_invalida`: la clave del admin
     *     no es la de su .env; 409 `dueno_no_resoluble`: base compartida sin USER_ID): `503
     *     sistema_no_configurado`. No es la contraseña ni la red: lo arregla ComercioCity.
     *  5. Lo demás (todos 404, o 404 mezclados con 401): `409 version_vieja`; uno de sus sistemas no tiene la
     *     ruta y puede ser el suyo.
     *
     * @param array<string, int> $desenlaces
     * @param int                $total
     *
     * @return array{http: int, cuerpo: array<string, mixed>}
     */
    protected function sin_ganador(array $desenlaces, $total)
    {
        if (isset($desenlaces['no_es_dueno'])) {
            return $this->error(403, 'no_es_dueno', 'La app del agente es solo para el dueño del sistema.');
        }

        if (isset($desenlaces['sin_extension'])) {
            return $this->error(403, 'sin_extension', 'Tu sistema todavía no tiene Agentes de IA activo.');
        }

        if (isset($desenlaces['falla'])) {
            return $this->sistema_no_disponible();
        }

        if (isset($desenlaces['credenciales']) && $desenlaces['credenciales'] === (int) $total) {
            return $this->credenciales_invalidas();
        }

        if (isset($desenlaces['configuracion'])) {
            return $this->error(503, 'sistema_no_configurado', 'Tu sistema todavía no está listo para la app. Avisale a ComercioCity.');
        }

        if (isset($desenlaces['version_vieja'])) {
            return $this->error(409, 'version_vieja', 'Tu sistema necesita actualizarse para usar la app del agente.');
        }

        return $this->sistema_no_disponible();
    }

    /**
     * El 502 de «no pudimos hablar con tu sistema».
     *
     * @return array{http: int, cuerpo: array<string, mixed>}
     */
    public function sistema_no_disponible()
    {
        return $this->error(502, 'sistema_no_disponible', 'No pudimos conectarnos con tu sistema. Probá de nuevo en un momento.');
    }

    /**
     * El 401 genérico: mismo texto haya o no candidatos (contrato C4.1, paso 4).
     *
     * @return array{http: int, cuerpo: array<string, mixed>}
     */
    public function credenciales_invalidas()
    {
        return $this->error(401, 'credenciales_invalidas', 'El usuario o la contraseña no son correctos.');
    }

    /**
     * Los clientes que corresponden a lo que escribió la persona en «¿Cuál es tu sistema?».
     *
     * Primero por slug exacto (sin mayúsculas). Si parece un host (tiene un punto o `://`), por el host
     * contra `client_apis.url`, `client_apis.spa_url` y `clients.api_url`; si no, también se prueba
     * `{slug}.comerciocity.com` como host del sistema.
     *
     * @param string $negocio
     * @param int    $maximo
     *
     * @return array<int, Client>
     */
    public function clientes_por_negocio($negocio, $maximo = 5)
    {
        $texto = mb_strtolower(trim($negocio), 'UTF-8');

        if ($texto === '' || mb_strlen($texto) > 255) {
            return [];
        }

        $es_host = strpos($texto, '.') !== false || strpos($texto, '://') !== false;

        if (! $es_host) {
            $por_slug = Client::where('is_active', true)->whereRaw('LOWER(slug) = ?', [$texto])->first();

            if ($por_slug !== null) {
                return [$por_slug];
            }

            // `ferretotal` sin slug cargado: el host de su sistema.
            $texto = $texto . '.comerciocity.com';
        }

        $host = $this->host_de($texto);

        if ($host === null) {
            return [];
        }

        return $this->clientes_por_host($host, $maximo);
    }

    /**
     * El host (minúsculas, sin `www.`) de lo que escribió la persona, o null si no es un host válido.
     *
     * @param string $texto
     *
     * @return string|null
     */
    protected function host_de($texto)
    {
        $url = strpos($texto, '://') === false ? 'https://' . $texto : $texto;

        $partes = RecursosSesionService::partes_de_url($url);

        return $partes === null ? null : $partes['host'];
    }

    /**
     * Clientes activos con algún frente (API o SPA) en ese host.
     *
     * @param string $host
     * @param int    $maximo
     *
     * @return array<int, Client>
     */
    protected function clientes_por_host($host, $maximo)
    {
        $patron = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $host) . '%';
        $ids    = [];

        $frentes = ClientApi::where(function ($q) use ($patron) {
            $q->where('url', 'like', $patron)->orWhere('spa_url', 'like', $patron);
        })->orderBy('id')->get(['id', 'client_id', 'url', 'spa_url']);

        foreach ($frentes as $frente) {
            foreach ([$frente->url, $frente->spa_url] as $url) {
                $partes = RecursosSesionService::partes_de_url((string) $url);

                if ($partes !== null && $partes['host'] === $host) {
                    $ids[(int) $frente->client_id] = true;
                }
            }
        }

        foreach (Client::where('api_url', 'like', $patron)->get(['id', 'api_url']) as $legacy) {
            $partes = RecursosSesionService::partes_de_url((string) $legacy->api_url);

            if ($partes !== null && $partes['host'] === $host) {
                $ids[(int) $legacy->id] = true;
            }
        }

        if ($ids === []) {
            return [];
        }

        return Client::whereIn('id', array_keys($ids))->where('is_active', true)
            ->orderBy('id')->limit($maximo)->get()->all();
    }

    /**
     * Anota en el directorio que el usuario es el dueño de ese cliente, sin romper el login si falla.
     *
     * @param Client      $client
     * @param string      $usuario
     * @param string|null $nombre
     *
     * @return void
     */
    protected function aprender_sin_romper(Client $client, $usuario, $nombre)
    {
        try {
            $this->directorio->aprender($client, $usuario, $nombre);
        } catch (\Throwable $e) {
            Log::warning('IaLoginService: no se pudo anotar el directorio.', [
                'client_id' => (int) $client->id,
                'error'     => mb_substr(get_class($e), 0, 120),
            ]);
        }
    }

    /**
     * @param int    $http
     * @param string $codigo
     * @param string $mensaje
     *
     * @return array{http: int, cuerpo: array<string, mixed>}
     */
    protected function error($http, $codigo, $mensaje)
    {
        return ['http' => $http, 'cuerpo' => ['code' => $codigo, 'message' => $mensaje]];
    }
}

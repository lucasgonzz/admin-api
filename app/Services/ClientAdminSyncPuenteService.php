<?php

namespace App\Services;

use App\Models\Client;
use App\Services\Concerns\TapaLaClaveDelCliente;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * El PUENTE genérico hacia un puñado de rutas `admin-sync/*` del `empresa-api` de un cliente (misión cruzada
 * `cerrar-admin-sync`, 10/10/2026, tarea A3: `POST claude/clients/{id}/admin-sync/puente`).
 *
 * 🔴 POR QUÉ EXISTE. Las rutas `admin-sync/*` de un cliente se autentican con `X-Admin-Api-Key` = `clients.api_key`, y la
 * misión las cierra de a una instancia (`exigir-clave`). Quedaban dos llamadores que le pegaban DERECHO al cliente, sin
 * la clave (que no tiene por qué viajar a la máquina de Lucas): la skill `/mostrador` (`mostrador.php`, las seis rutas
 * `admin-sync/mostrador/*`) y los dos pasos manuales de `update-default-version` de `/implementar` e `/instalar-cliente`.
 * Con este puente le hablan al admin en modo normal —con la clave de ingesta de `claude/*`— y el admin reenvía el pedido al
 * cliente con la clave del cliente. Es el mismo molde que `ClientCatalogoPuenteService` y copia TODAS sus defensas.
 *
 * 🔴 QUÉ REENVÍA: SOLO lo que está en `LISTA_BLANCA` (método + ruta, con la regex anclada; `{n}` son dígitos). No es un proxy
 * general: no se puede llegar a ninguna otra ruta del cliente, ni con `..`, ni con una barra de más, ni con una query que
 * cambie el destino. Es la única defensa que importa acá, porque el admin lleva la clave de cada cliente: un puente abierto
 * sería una puerta a toda la API de un negocio. Acá la lista es CORTA a propósito (siete entradas) y NO incluye ninguna de
 * las rutas que escriben datos del negocio (`sistema-query`, `ai-excel-import`, `mensualidad-update`...): esas tienen su
 * servicio propio en el admin.
 *
 * 🔴 QUÉ DEVUELVE. Cuando el admin LLEGÓ al cliente, el resultado trae `estado = respondio` y el HTTP del cliente adentro
 * (`status`, `cuerpo`, `cuerpo_crudo`): el controlador contesta SIEMPRE 200 con `puente: true`. Un 401, un 404 o un 422 del
 * cliente son información que quien llama tiene que leer (un 404 es "su versión todavía no tiene la ruta"), y si el puente
 * los devolviera como propios se confundirían con los del admin. Cuando el admin NO pudo llegar (sin clave, sin URL, timeout
 * o conexión) el estado lo dice y el controlador contesta 409 o 502.
 *
 * 🔴 NUNCA lleva la clave del cliente ni en el resultado ni en el log. El cuerpo que devuelve el cliente pasa por
 * `tapar_clave()` ANTES de decodificarse, y las excepciones de HTTP también. El log lleva método, ruta SIN query, status y
 * milisegundos: nunca el cuerpo. Y las URLs que cita el mensaje de una falla de red salen SIN su query.
 *
 * 🔴 SIN REDIRECCIONES. Guzzle, al redirigir a otro host, saca `Authorization` pero NO un header propio como
 * `X-Admin-Api-Key`: un 3xx del cliente vuelve como una respuesta más, con su `status`, y no se sigue.
 *
 * 🔴 TIMEOUT. 600 s por llamada (`services.client_api.admin_sync_puente_timeout`), el mismo techo que usa hoy
 * `mostrador.php` contra el cliente (`CURLOPT_TIMEOUT => 600`): `POST mostrador/hechos` recorre todo el catálogo del dueño y
 * puede tardar (el `empresa-api` contesta 202 y quien llama hace polling con `GET mostrador/reportes/{id}`, que es corto). El
 * puente no hace ese polling: reenvía UN pedido.
 *
 * No reintenta: ni un 5xx ni un timeout. Un POST/PUT de este puente no es idempotente del otro lado (`mostrador/hechos`
 * dispara un cálculo, `update-default-version` escribe a dónde manda el login), y quien sabe cuál pedido se puede repetir es
 * quien llama, no el puente.
 *
 * Las defensas de forma (`_method` por query o por cuerpo, la query fuera de las URLs de los mensajes) son las de
 * `ClientCatalogoPuenteService`, y se llaman de ahí para que haya UNA sola copia de cada regla.
 *
 * PHP 7.4: sin `?->`, `match`, `str_contains`, argumentos nombrados, union types, promoción en constructor, `readonly`,
 * `enum`, atributos, `mixed` ni `never`.
 */
class ClientAdminSyncPuenteService
{
    use TapaLaClaveDelCliente;

    /**
     * Ruta base de admin-sync en el `empresa-api` del cliente (se le agrega la ruta del pedido).
     *
     * @var string
     */
    const BASE_PATH = 'api/admin-sync';

    /**
     * 🔴 LA LISTA BLANCA: método → rutas permitidas, relativas a `BASE_PATH`. `{n}` = uno o más dígitos. Es lo ÚNICO que el
     * puente deja pasar; cualquier cambio acá es un cambio de contrato con `mostrador.php`, con las skills y con
     * `config/claude_catalog.php`.
     *
     * @var array<string, array<int, string>>
     */
    const LISTA_BLANCA = [
        'GET' => [
            '/mostrador/duenos',
            '/mostrador/reportes/{n}',
            '/mostrador/contexto/{n}',
        ],
        'POST' => [
            '/mostrador/hechos',
        ],
        'PUT' => [
            '/mostrador/reportes/{n}',
            '/mostrador/memoria/{n}',
            '/update-default-version',
        ],
    ];

    /**
     * Los métodos que llevan cuerpo.
     *
     * @var array<int, string>
     */
    const METODOS_CON_CUERPO = ['POST', 'PUT'];

    /**
     * Caracteres del cuerpo NO JSON del cliente que viajan en `cuerpo_crudo`.
     *
     * @var int
     */
    const MAX_CRUDO = 2000;

    /**
     * Segundos de espera por defecto de UNA llamada, si `services.client_api.admin_sync_puente_timeout` no está.
     *
     * @var int
     */
    const TIMEOUT_POR_DEFECTO = 600;

    /** El admin llegó al cliente: el HTTP del cliente viaja en el resultado. */
    const ESTADO_RESPONDIO = 'respondio';

    /** El cliente no tiene `api_key` en el admin: no hay con qué autenticar el pedido. */
    const ESTADO_SIN_API_KEY = 'sin_api_key';

    /** No hay una URL válida de `empresa-api` para el cliente. */
    const ESTADO_SIN_URL = 'sin_url';

    /** Timeout o conexión: el admin no llegó al cliente. */
    const ESTADO_NO_RESPONDE = 'no_responde';

    /**
     * @var ClientEmpresaApiUrlResolver Resuelve la URL base del `empresa-api` del cliente.
     */
    protected $api_url_resolver;

    /**
     * @param ClientEmpresaApiUrlResolver|null $api_url_resolver Inyectable para las pruebas.
     */
    public function __construct(?ClientEmpresaApiUrlResolver $api_url_resolver = null)
    {
        $this->api_url_resolver = $api_url_resolver === null
            ? new ClientEmpresaApiUrlResolver()
            : $api_url_resolver;
    }

    /**
     * ¿Está esta combinación de método y ruta en la lista blanca?
     *
     * Evalúa SOLO el path: la query se separa antes (`ClientCatalogoPuenteService::separar_la_ruta()`). La regex es anclada y
     * con `D` (el `$` no acepta un salto de línea final), y `{n}` es `[0-9]+`: no hay forma de que un `..`, una barra doble
     * o un segmento de más pase por un `{n}`.
     *
     * @param string $metodo GET | POST | PUT (mayúsculas).
     * @param string $path   Path relativo a `BASE_PATH`, SIN query (`/mostrador/reportes/12`).
     *
     * @return bool
     */
    public static function ruta_permitida($metodo, $path)
    {
        if (! isset(self::LISTA_BLANCA[$metodo])) {
            return false;
        }

        foreach (self::LISTA_BLANCA[$metodo] as $plantilla) {
            $regex = '#^' . str_replace('\{n\}', '[0-9]+', preg_quote($plantilla, '#')) . '$#D';

            if (preg_match($regex, (string) $path) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * La lista blanca en forma legible (`GET /mostrador/duenos`, `PUT /mostrador/reportes/{n}`...), para los mensajes de error
     * y el catálogo.
     *
     * @return array<int, string>
     */
    public static function rutas_permitidas()
    {
        $lista = [];

        foreach (self::LISTA_BLANCA as $metodo => $plantillas) {
            foreach ($plantillas as $plantilla) {
                $lista[] = $metodo . ' ' . $plantilla;
            }
        }

        return $lista;
    }

    /**
     * Reenvía un pedido de la lista blanca al `empresa-api` del cliente y devuelve lo que contestó.
     *
     * El llamador YA validó método y ruta contra la lista blanca (`ruta_permitida()`); acá no se vuelve a decidir, pero un
     * método que no es POST ni PUT se trata como GET sin cuerpo, nunca como otra cosa.
     *
     * @param Client               $client Cliente al que se le habla.
     * @param string               $metodo GET | POST | PUT.
     * @param string               $ruta   Ruta relativa a `BASE_PATH`, con su query si la trae (`/mostrador/reportes/12`).
     * @param array|\stdClass|null $cuerpo Cuerpo del POST/PUT tal como lo mandó quien llama (objetos como `stdClass`); null = `{}`.
     *
     * @return array{estado: string, status: int|null, cuerpo: mixed, cuerpo_crudo: string|null, mensaje: string|null}
     */
    public function reenviar(Client $client, $metodo, $ruta, $cuerpo = null)
    {
        $clave = trim((string) $client->api_key);

        // Corte 1: sin api_key. Sin credencial no hay a quién preguntarle.
        if ($clave === '') {
            return $this->resultado(
                self::ESTADO_SIN_API_KEY,
                null,
                null,
                null,
                'El cliente no tiene api_key en el admin: es la clave con la que el admin le habla a su empresa-api. '
                . 'Corré POST claude/clients/{id}/catalogo/clave con dry_run=false para generarla y escribirla en sus .env.'
            );
        }

        // Corte 2: sin URL resoluble. Es configuración faltante del admin, no del cliente.
        $url = $this->api_url_resolver->admin_sync_url($client, self::BASE_PATH . $ruta);

        if ($url === '') {
            return $this->resultado(
                self::ESTADO_SIN_URL,
                null,
                null,
                null,
                'Este cliente no tiene una URL válida de empresa-api configurada (ClientApi activa o api_url legacy).'
            );
        }

        $metodo       = in_array($metodo, self::METODOS_CON_CUERPO, true) ? $metodo : 'GET';
        $timeout      = $this->timeout_en_segundos();
        $path_del_log = ClientCatalogoPuenteService::separar_la_ruta($ruta)[0];
        $inicio       = microtime(true);

        $this->levantar_limite_de_tiempo($timeout);

        try {
            $pedido = Http::withHeaders([
                    'X-Admin-Api-Key' => $clave,
                    'Accept'          => 'application/json',
                ])
                /* 🔴 Sin seguir redirecciones (mismo motivo que `ClientCatalogoPuenteService`): Guzzle, al redirigir a otro
                 * host, saca `Authorization` pero NO un header propio como `X-Admin-Api-Key`, y la clave del cliente
                 * viajaría a donde apunte la redirección. Un 3xx vuelve como una respuesta más, con su `status`. */
                ->withOptions(['allow_redirects' => false])
                ->timeout($timeout);

            if ($metodo === 'POST') {
                $response = $pedido
                    ->withBody($this->json_del_cuerpo($cuerpo), 'application/json')
                    ->post($url);
            } elseif ($metodo === 'PUT') {
                $response = $pedido
                    ->withBody($this->json_del_cuerpo($cuerpo), 'application/json')
                    ->put($url);
            } else {
                $response = $pedido->get($url);
            }
        } catch (\Throwable $e) {
            // ConnectionException, timeout, DNS: no hay respuesta HTTP asociada.
            $ms = $this->milisegundos_desde($inicio);

            /* 🔴 El mensaje de Guzzle/cURL repite la URL ENTERA: sin la query, que es dato del negocio del cliente. Va antes
               de tapar la clave y de recortar. */
            $error = ClientCatalogoPuenteService::sin_la_query_de_las_urls($e->getMessage());

            Log::warning('ClientAdminSyncPuenteService: no se pudo contactar al empresa-api del cliente.', [
                'client_id' => (int) $client->id,
                'metodo'    => $metodo,
                'ruta'      => $path_del_log,
                'status'    => null,
                'ms'        => $ms,
                'error'     => $this->texto_seguro($error, $clave),
            ]);

            return $this->resultado(
                self::ESTADO_NO_RESPONDE,
                null,
                null,
                null,
                $this->texto_seguro(
                    'No se pudo contactar al empresa-api del cliente (timeout de ' . $timeout . ' s o falla de conexión): ' . $error,
                    $clave
                )
            );
        }

        $status = (int) $response->status();

        Log::info('ClientAdminSyncPuenteService: llamada a admin-sync del cliente.', [
            'client_id' => (int) $client->id,
            'metodo'    => $metodo,
            'ruta'      => $path_del_log,
            'status'    => $status,
            'ms'        => $this->milisegundos_desde($inicio),
        ]);

        return $this->resultado_del_cliente($status, (string) $response->body(), $clave);
    }

    /**
     * Arma el resultado a partir de lo que contestó el cliente.
     *
     * La clave se tapa sobre el cuerpo ENTERO y ANTES de decodificar: así ni el JSON decodificado ni el texto crudo la llevan,
     * y una clave partida en el borde del recorte no sale a medias.
     *
     * @param int    $status HTTP del cliente.
     * @param string $cuerpo Cuerpo crudo de su respuesta.
     * @param string $clave  Clave del cliente (a tapar).
     *
     * @return array{estado: string, status: int|null, cuerpo: mixed, cuerpo_crudo: string|null, mensaje: string|null}
     */
    protected function resultado_del_cliente($status, $cuerpo, $clave)
    {
        $tapado = $this->tapar_clave($cuerpo, $clave);

        if (trim($tapado) !== '') {
            /* Sin `assoc`: un objeto es un objeto, y un `{}` vuelve como `{}`. Los enteros que no caben en un int de PHP
               quedan como texto en vez de perder precisión. */
            $decodificado = json_decode($tapado, false, 512, JSON_BIGINT_AS_STRING);

            if (json_last_error() === JSON_ERROR_NONE) {
                return $this->resultado(self::ESTADO_RESPONDIO, $status, $decodificado, null, null);
            }
        }

        /* No era JSON (o venía vacío): hasta MAX_CRUDO caracteres de texto, en UTF-8 válido y sin la clave. */
        return $this->resultado(
            self::ESTADO_RESPONDIO,
            $status,
            null,
            $this->texto_seguro($tapado, $clave, self::MAX_CRUDO),
            null
        );
    }

    /**
     * El cuerpo del POST/PUT como JSON, tal como lo mandó quien llama.
     *
     * Un cuerpo ausente o vacío viaja como `{}`. Sin escapar unicode ni barras: es lo que se mandó, no hay motivo para
     * reescribirlo. Con `withBody()` el JSON sale idéntico (ver el docblock de `ClientCatalogoPuenteService`, "FIDELIDAD").
     *
     * @param array|\stdClass|null $cuerpo Cuerpo del pedido.
     *
     * @return string
     */
    protected function json_del_cuerpo($cuerpo)
    {
        if ($cuerpo === null) {
            return '{}';
        }

        $json = json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);

        return is_string($json) ? $json : '{}';
    }

    /**
     * Techo de una llamada, en segundos. Un valor ausente o menor que uno cae al default.
     *
     * @return int
     */
    protected function timeout_en_segundos()
    {
        $segundos = (int) config('services.client_api.admin_sync_puente_timeout', self::TIMEOUT_POR_DEFECTO);

        return $segundos >= 1 ? $segundos : self::TIMEOUT_POR_DEFECTO;
    }

    /**
     * Sube el techo de tiempo de PHP para que alcance a esperar al cliente: sin esto, un `max_execution_time` de 30 s cortaría
     * el request antes que el timeout de la llamada.
     *
     * @param int $timeout Segundos de espera de la llamada.
     *
     * @return void
     */
    protected function levantar_limite_de_tiempo($timeout)
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit($timeout + 30);
        }
    }

    /**
     * Milisegundos desde un instante de `microtime(true)`.
     *
     * @param float $inicio Instante de arranque.
     *
     * @return int
     */
    protected function milisegundos_desde($inicio)
    {
        return (int) round((microtime(true) - $inicio) * 1000);
    }

    /**
     * Arma el resultado común.
     *
     * @param string      $estado       respondio | sin_api_key | sin_url | no_responde.
     * @param int|null    $status       HTTP del cliente, si respondió.
     * @param mixed       $cuerpo       JSON decodificado (objetos como `stdClass`), o null.
     * @param string|null $cuerpo_crudo Texto, si la respuesta no era JSON.
     * @param string|null $mensaje      Motivo, cuando el admin no llegó al cliente.
     *
     * @return array{estado: string, status: int|null, cuerpo: mixed, cuerpo_crudo: string|null, mensaje: string|null}
     */
    protected function resultado($estado, $status, $cuerpo, $cuerpo_crudo, $mensaje)
    {
        return [
            'estado'       => $estado,
            'status'       => $status,
            'cuerpo'       => $cuerpo,
            'cuerpo_crudo' => $cuerpo_crudo,
            'mensaje'      => $mensaje,
        ];
    }
}

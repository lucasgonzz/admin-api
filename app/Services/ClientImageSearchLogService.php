<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientAiTokenUsage;
use Carbon\Carbon;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Trae del `empresa-api` de un cliente el registro de TODAS las consultas del circuito de imágenes
 * de artículos —cada búsqueda de imágenes (Serper / Google) y cada validación con IA de "¿esta
 * imagen es este artículo?"— y le pone la plata con las tablas de precios del admin.
 *
 * Es la otra punta de la misión imagenes-catalogo-completo (§12 del plan): el pedido de Lucas fue
 * *"que quede registro de todas las consultas que se hacen tanto a esta nueva API para obtener las
 * imágenes como a la IA para chequear si la imagen pertenece al artículo, y poder verlo desde el
 * admin en cada cliente"*. Del lado del cliente cada llamada deja una fila en
 * `image_service_calls`; los dos endpoints `admin-sync/imagenes/{resumen,consultas}` la exponen, y
 * este servicio es el único que les habla.
 *
 * Molde: `ClientAiTokensSyncService` (la llamada saliente). De ahí se toma lo que ya está
 * aprendido, y cada regla tiene el mismo porqué que allá:
 *
 *  1. **Nunca lanza por lo que conteste (o no conteste) el cliente.** Todos los desenlaces
 *     —los dos cortes previos, cada código HTTP, el timeout, un cuerpo irreconocible— terminan en
 *     `{estado, mensaje, datos}`. Lo único que lanza, y a propósito, es la validación de la ENTRADA
 *     (`resolver_rango()` y `filtros_de_consultas()`): un rango mal pedido es un 422 del admin, no
 *     algo que haya que ir a preguntarle al cliente.
 *
 *  2. 🔴 **El `catch (RequestException $e) { $response = $e->response; }` NO es opcional.** En
 *     Laravel 8, `->retry()` convierte cualquier no-2xx en excepción antes de que `get()` devuelva
 *     algo. Sin recuperar la respuesta real, la rama del 404 de abajo sería código muerto y un
 *     cliente viejo se vería como "se cayó la conexión".
 *
 *  3. **Cada código nombra su causa.** El 404 es el caso ESPERADO durante semanas —el cliente
 *     todavía no tiene la versión que registra las consultas—: se devuelve `no_soportado`, no se
 *     reintenta y no hace ruido. El 401/403 nombra la `api_key`, el 409 nombra el `USER_ID` que le
 *     falta al `.env` de ese frente, y el 422 trae el motivo que dio el cliente. Un "falló" a secas
 *     manda a nadie a ningún lado.
 *
 * Y tres diferencias con el molde, las tres a propósito:
 *
 *  - 🔴 **No espeja nada.** Tokens guarda un agregado por día porque lo mira todos los días y lo
 *    suma entre cuarenta y cinco clientes. Esto es un REGISTRO, fila por fila: una sola asignación
 *    de catálogo de 5.000 artículos deja ~7.500 búsquedas y ~6.000 validaciones, el cliente ya lo
 *    guarda 180 días, y del lado del admin se mira de vez en cuando, un cliente por vez. Copiarlo
 *    sería multiplicar la base del admin por algo que se puede pedir en vivo. Por eso tampoco hay
 *    columnas de estado en `clients` ni recolección nocturna: se consulta al abrir la solapa.
 *  - **La plata se pone acá, al leer, y nunca se guarda**: búsquedas con `config('busquedas_precios')`
 *    (dólares cada 1.000) e IA con `config('ia_precios')` a través de `ClientAiTokenUsage::costo_usd()`,
 *    la misma cuenta que la solapa Tokens. Lo que no tiene precio sale `null` y se NOMBRA; nunca se
 *    completa con un precio por defecto (ver el docblock de cualquiera de las dos tablas).
 *  - 🔴 **Ninguna clave viaja en un mensaje.** Todo texto que sale de acá y que se armó con algo que
 *    devolvió el cliente pasa por `texto_seguro()`, que tapa la `api_key` si el cliente la hubiera
 *    devuelto en un cuerpo de error (tal cual y escapada como en un JSON), y limpia el UTF-8 roto de
 *    un cuerpo cortado (un byte suelto haría fallar el `json_encode` de la respuesta del admin y el
 *    operador vería un 500 en vez del motivo). Los cuerpos se tapan ENTEROS antes de recortarlos.
 *
 * Y dos más que salieron de la revisión del 27/9/2026:
 *
 *  - 🔴 **No se siguen redirecciones** (`allow_redirects` en false): Guzzle, al saltar de host, no
 *    saca un header propio como `X-Admin-Api-Key`, y una URL mal cargada le regalaría la clave del
 *    cliente al destino. Un 3xx es un error más, con el destino nombrado.
 *  - **Un timeout no se reintenta**: esta lectura es en vivo, con alguien esperando (ver
 *    `conviene_reintentar()`).
 */
class ClientImageSearchLogService
{
    /**
     * Ruta relativa del resumen del registro en el `empresa-api` del cliente.
     */
    const RESUMEN_PATH = 'api/admin-sync/imagenes/resumen';

    /**
     * Ruta relativa del registro paginado, consulta por consulta.
     */
    const CONSULTAS_PATH = 'api/admin-sync/imagenes/consultas';

    /** El cliente contestó y la respuesta tiene la forma esperada. */
    const ESTADO_OK = 'ok';

    /** 404: su versión de `empresa-api` todavía no registra las consultas. Es lo esperado, no un fallo. */
    const ESTADO_NO_SOPORTADO = 'no_soportado';

    /** 401/403, 409, 422, 5xx, timeout, cuerpo irreconocible, o configuración faltante del lado del admin. */
    const ESTADO_ERROR = 'error';

    /** Caracteres del cuerpo de la respuesta del cliente que se copian al mensaje de error. */
    const CHARS_DE_CUERPO = 300;

    /**
     * 🔴 Techo de días que se le pueden pedir al `empresa-api` en UNA llamada.
     *
     * Es el MISMO 62 que valida el endpoint del otro lado (igual que `consumo-ia`): pedirle más
     * devuelve 422. Se valida acá antes de salir a la red para que el operador reciba el motivo en
     * castellano y al toque, en vez de esperar una vuelta al cliente para enterarse de lo mismo.
     */
    const MAX_DIAS_POR_PEDIDO = 62;

    /** Días del rango cuando el pedido no trae fechas: el mismo default que el endpoint del cliente. */
    const DIAS_POR_DEFECTO = 30;

    /** Filas por página del registro cuando no se pide otra cosa (el default del contrato). */
    const POR_PAGINA_POR_DEFECTO = 50;

    /** Mínimo de filas por página que acepta el endpoint del cliente. */
    const POR_PAGINA_MINIMO = 10;

    /** Máximo de filas por página que acepta el endpoint del cliente. */
    const POR_PAGINA_MAXIMO = 200;

    /**
     * Divisor de `config('busquedas_precios')`: los precios son cada MIL búsquedas, no por búsqueda.
     * Está como constante porque la cuenta se escribe en un solo lugar y quien lea el número quiere
     * saber de dónde sale.
     */
    const BUSQUEDAS_POR_UNIDAD_DE_PRECIO = 1000;

    /** Tipo de consulta: una búsqueda de imágenes en un proveedor (Serper, Google). */
    const TIPO_BUSQUEDA = 'busqueda';

    /** Tipo de consulta: una llamada a la IA para validar candidatas. */
    const TIPO_VALIDACION_IA = 'validacion_ia';

    /**
     * Proveedores que el contrato abre por día como `dias[].busquedas_<proveedor>`.
     *
     * A esta lista se le suman, al costear, los que aparezcan en `totales.busquedas_por_proveedor`:
     * si el día de mañana el cliente agrega un tercer proveedor y lo informa con el mismo patrón,
     * el costo por día lo toma solo, sin tocar este archivo.
     *
     * @var array<int, string>
     */
    const PROVEEDORES_DEL_CONTRATO = ['serper', 'google'];

    /**
     * Los contadores de tokens del contrato del cliente, mapeados a las columnas que entiende
     * `ClientAiTokenUsage::costo_usd()` (las claves de `ClientAiTokenUsage::PUNTAS`).
     *
     * El contrato de imágenes los nombra en castellano (`tokens_entrada`…) y el de consumo de IA en
     * inglés (`input_tokens`…). Se traduce en UN lugar para reusar la cuenta del costo tal cual,
     * con su búsqueda por prefijo y todo, en vez de escribir una segunda multiplicación que el día
     * que cambie la primera se quede vieja.
     *
     * @var array<string, string>
     */
    const TOKENS_DEL_CONTRATO = [
        'tokens_entrada'         => 'input_tokens',
        'tokens_salida'          => 'output_tokens',
        'tokens_cache_escritura' => 'cache_creation_input_tokens',
        'tokens_cache_lectura'   => 'cache_read_input_tokens',
    ];

    /**
     * Claves del paginador de Laravel que apuntan al `empresa-api` del cliente.
     *
     * Se sacan de la respuesta porque del lado del admin no sirven para nada —son URLs del cliente
     * que el navegador no puede seguir sin la clave— y dejarlas invitaría a que algún día alguien
     * las use en el front. La navegación entre páginas la hace la solapa con `page`, contra el admin.
     *
     * @var array<int, string>
     */
    const CLAVES_DE_URL_DEL_PAGINADOR = [
        'first_page_url',
        'last_page_url',
        'next_page_url',
        'prev_page_url',
        'path',
        'links',
    ];

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
     * Resuelve el rango de días del pedido, con los últimos 30 días como valor por defecto.
     *
     * 🔴 Lanza `ValidationException` (un 422 del admin) si el rango es inválido: fechas mal
     * formadas, `desde` posterior a `hasta`, o más de 62 días. Es el único lugar, junto con
     * `filtros_de_consultas()`, donde este servicio lanza: es la entrada del operador, no la
     * respuesta del cliente, y un "rango inválido" no tiene que viajar hasta el cliente para
     * enterarse.
     *
     * El "hoy" sale de la zona de la app (`America/Argentina/Buenos_Aires`), la misma con la que el
     * cliente agrupa sus días: usar la del servidor correría el rango a la medianoche UTC.
     *
     * @param string|null $desde Primer día pedido, AAAA-MM-DD, o null/vacío para el default.
     * @param string|null $hasta Último día pedido, AAAA-MM-DD, o null/vacío para hoy.
     *
     * @return array{desde: string, hasta: string}
     *
     * @throws ValidationException
     */
    public function resolver_rango($desde, $hasta)
    {
        Validator::make(
            ['desde' => $desde, 'hasta' => $hasta],
            [
                'desde' => 'nullable|date_format:Y-m-d',
                'hasta' => 'nullable|date_format:Y-m-d',
            ],
            [
                'desde.date_format' => 'La fecha "desde" tiene que venir como AAAA-MM-DD.',
                'hasta.date_format' => 'La fecha "hasta" tiene que venir como AAAA-MM-DD.',
            ]
        )->validate();

        $hasta = $this->vacio($hasta)
            ? Carbon::now(config('app.timezone'))->format('Y-m-d')
            : (string) $hasta;

        $desde = $this->vacio($desde)
            ? Carbon::parse($hasta)->subDays(self::DIAS_POR_DEFECTO - 1)->format('Y-m-d')
            : (string) $desde;

        /* Comparación de strings: AAAA-MM-DD ordena igual como texto que como fecha, y el formato
         * ya quedó validado arriba. */
        if ($desde > $hasta) {
            throw ValidationException::withMessages([
                'desde' => 'La fecha "desde" no puede ser posterior a la fecha "hasta".',
            ]);
        }

        $dias = Carbon::parse($desde)->startOfDay()->diffInDays(Carbon::parse($hasta)->startOfDay()) + 1;

        if ($dias > self::MAX_DIAS_POR_PEDIDO) {
            throw ValidationException::withMessages([
                'desde' => 'El rango pedido es de ' . $dias . ' días y el sistema del cliente acepta hasta '
                    . self::MAX_DIAS_POR_PEDIDO . ' por consulta. Achicá el período.',
            ]);
        }

        return ['desde' => $desde, 'hasta' => $hasta];
    }

    /**
     * Valida y normaliza los filtros del registro de consultas, dejando SOLO los que entiende el
     * endpoint del cliente.
     *
     * 🔴 Es una lista blanca, no un pasamanos: lo que llegue en el pedido y no esté acá no viaja al
     * cliente. El `empresa-api` es de otro equipo de versiones —cada cliente tiene la suya— y no
     * hay por qué mandarle parámetros que no pidió el contrato.
     *
     * `per_page` fuera de 10–200 se ACOTA en vez de rechazarse (mismo criterio que el endpoint del
     * cliente): pedir 500 no es un error del operador, es pedir "todo lo que se pueda".
     *
     * @param array<string, mixed> $entrada Parámetros del pedido (la query del request).
     *
     * @return array<string, int|string> Filtros listos para la query del cliente.
     *
     * @throws ValidationException
     */
    public function filtros_de_consultas(array $entrada)
    {
        Validator::make(
            $entrada,
            [
                'tipo'         => 'nullable|in:' . self::TIPO_BUSQUEDA . ',' . self::TIPO_VALIDACION_IA,
                'asignacion'   => 'nullable|integer|min:1',
                'solo_errores' => 'nullable|in:0,1,true,false',
                'page'         => 'nullable|integer|min:1',
                'per_page'     => 'nullable|integer',
            ],
            [
                'tipo.in'             => 'El tipo tiene que ser "busqueda" o "validacion_ia".',
                'asignacion.integer'  => 'La asignación tiene que ser el número de la asignación.',
                'asignacion.min'      => 'La asignación tiene que ser el número de la asignación.',
                'solo_errores.in'     => '"Solo errores" tiene que ser 0 o 1.',
                'page.integer'        => 'La página tiene que ser un número.',
                'page.min'            => 'La página tiene que ser 1 o más.',
                'per_page.integer'    => 'La cantidad por página tiene que ser un número.',
            ]
        )->validate();

        $rango = $this->resolver_rango(
            isset($entrada['desde']) ? $entrada['desde'] : null,
            isset($entrada['hasta']) ? $entrada['hasta'] : null
        );

        $por_pagina = $this->vacio(isset($entrada['per_page']) ? $entrada['per_page'] : null)
            ? self::POR_PAGINA_POR_DEFECTO
            : (int) $entrada['per_page'];

        $por_pagina = max(self::POR_PAGINA_MINIMO, min(self::POR_PAGINA_MAXIMO, $por_pagina));

        $pagina = $this->vacio(isset($entrada['page']) ? $entrada['page'] : null)
            ? 1
            : (int) $entrada['page'];

        $solo_errores = isset($entrada['solo_errores'])
            && in_array(strtolower(trim((string) $entrada['solo_errores'])), ['1', 'true'], true);

        $filtros = [
            'desde'        => $rango['desde'],
            'hasta'        => $rango['hasta'],
            /* Viaja siempre, en 0 o 1: es explícito y no depende de cuál sea el default del otro lado. */
            'solo_errores' => $solo_errores ? 1 : 0,
            'page'         => $pagina,
            'per_page'     => $por_pagina,
        ];

        // Los dos filtros opcionales viajan solo si vinieron: vacío es "todos", no "ninguno".
        if (! $this->vacio(isset($entrada['tipo']) ? $entrada['tipo'] : null)) {
            $filtros['tipo'] = (string) $entrada['tipo'];
        }

        if (! $this->vacio(isset($entrada['asignacion']) ? $entrada['asignacion'] : null)) {
            $filtros['asignacion'] = (int) $entrada['asignacion'];
        }

        return $filtros;
    }

    /**
     * El resumen del registro en un rango de días, ya costeado.
     *
     * Con `ok`, `datos` es la respuesta del cliente tal cual (totales, días, modelos, asignaciones)
     * más los costos: `totales.costo_usd`, `totales.costo_busquedas_usd`, `totales.costo_ia_usd`,
     * `dias[].costo_busquedas_usd` y `modelos[].costo_usd` (con `tiene_precio`). Ver
     * `costear_resumen()` para qué significa cada null.
     *
     * @param Client $client Cliente a consultar.
     * @param string $desde  Primer día, AAAA-MM-DD (ya resuelto por `resolver_rango()`).
     * @param string $hasta  Último día, AAAA-MM-DD (inclusive).
     *
     * @return array{estado: string, mensaje: string|null, datos: array|null}
     */
    public function resumen(Client $client, $desde, $hasta)
    {
        $resultado = $this->pedir($client, self::RESUMEN_PATH, [
            'desde' => (string) $desde,
            'hasta' => (string) $hasta,
        ]);

        if ($resultado['estado'] !== self::ESTADO_OK) {
            return $resultado;
        }

        $datos = $resultado['datos'];

        /* 🔴 Un 2xx NO alcanza para dar el dato por bueno: hay que reconocer la FORMA del payload.
         * El shared hosting de Hostinger sirve su página genérica CON HTTP 200 cuando la cuenta está
         * saturada, y sin este corte esa página se vería como "un cliente sin ninguna consulta",
         * que es exactamente indistinguible de un cliente que no buscó nada. Se exigen los dos
         * bloques que la pantalla no puede dibujar sin ellos; `modelos` y `asignaciones` son
         * opcionales (se completan vacíos). */
        if (! is_array($datos)
            || ! array_key_exists('totales', $datos) || ! is_array($datos['totales'])
            || ! array_key_exists('dias', $datos) || ! is_array($datos['dias'])
        ) {
            return $this->resultado(
                self::ESTADO_ERROR,
                $this->texto_seguro(
                    'El cliente respondió HTTP ' . $resultado['status'] . ' pero el cuerpo no es el resumen del registro de '
                    . 'imágenes (faltan los bloques `totales` o `dias`). Suele ser la página genérica '
                    . 'del hosting cuando la cuenta está saturada. Empieza así: '
                    . $resultado['cuerpo'],
                    $client
                )
            );
        }

        try {
            $datos = $this->costear_resumen($datos);
        } catch (\Throwable $e) {
            // Un valor con una forma rarísima no puede convertirse en un 500 del admin.
            return $this->resultado(
                self::ESTADO_ERROR,
                $this->texto_seguro(
                    'El cliente contestó bien pero no se pudo leer su resumen: ' . $e->getMessage(),
                    $client
                )
            );
        }

        return $this->resultado(self::ESTADO_OK, null, $datos);
    }

    /**
     * Una página del registro de consultas, con el costo de cada fila.
     *
     * Con `ok`, `datos` es la respuesta del cliente (`{models: paginador}`) con dos claves más por
     * fila —`costo_usd` y `tiene_precio`— y sin las URLs del paginador (ver
     * `CLAVES_DE_URL_DEL_PAGINADOR`).
     *
     * @param Client                    $client  Cliente a consultar.
     * @param array<string, int|string> $filtros Salida de `filtros_de_consultas()`.
     *
     * @return array{estado: string, mensaje: string|null, datos: array|null}
     */
    public function consultas(Client $client, array $filtros)
    {
        $resultado = $this->pedir($client, self::CONSULTAS_PATH, $filtros);

        if ($resultado['estado'] !== self::ESTADO_OK) {
            return $resultado;
        }

        $datos = $resultado['datos'];

        // Mismo corte que en el resumen: un 2xx que no es un paginador no es "cero consultas".
        if (! is_array($datos)
            || ! isset($datos['models']) || ! is_array($datos['models'])
            || ! array_key_exists('data', $datos['models']) || ! is_array($datos['models']['data'])
        ) {
            return $this->resultado(
                self::ESTADO_ERROR,
                $this->texto_seguro(
                    'El cliente respondió HTTP ' . $resultado['status'] . ' pero el cuerpo no es el registro de consultas de '
                    . 'imágenes (falta `models.data`). Suele ser la página genérica del hosting cuando '
                    . 'la cuenta está saturada. Empieza así: ' . $resultado['cuerpo'],
                    $client
                )
            );
        }

        try {
            $datos = $this->costear_consultas($datos);
        } catch (\Throwable $e) {
            return $this->resultado(
                self::ESTADO_ERROR,
                $this->texto_seguro(
                    'El cliente contestó bien pero no se pudo leer su registro: ' . $e->getMessage(),
                    $client
                )
            );
        }

        return $this->resultado(self::ESTADO_OK, null, $datos);
    }

    /**
     * La llamada saliente, común a los dos endpoints: cortes previos, HTTP y cada desenlace.
     *
     * Devuelve el resultado ya armado cuando NO es `ok`. Cuando es `ok`, `datos` trae el JSON
     * decodificado tal cual (el llamador valida la forma), `cuerpo` el principio del cuerpo crudo
     * (ya con la clave tapada), para poder citarlo si la forma no es la esperada, y `status` el
     * código HTTP real, para que ese mensaje no diga "200" cuando el cliente contestó otro 2xx.
     *
     * @param Client               $client Cliente a consultar.
     * @param string               $path   Ruta relativa del endpoint (una de las dos constantes).
     * @param array<string, mixed> $query  Parámetros de la query.
     *
     * @return array{estado: string, mensaje: string|null, datos: mixed, cuerpo?: string, status?: int}
     */
    protected function pedir(Client $client, $path, array $query)
    {
        $url = $this->api_url_resolver->admin_sync_url($client, $path);

        // Corte 1: sin URL resoluble. Es configuración faltante del admin, no del cliente.
        if ($url === '') {
            return $this->resultado(
                self::ESTADO_ERROR,
                'Este cliente no tiene una URL válida de empresa-api configurada (ClientApi activa '
                . 'o api_url legacy).'
            );
        }

        // Corte 2: sin api_key. Mismo motivo: sin credencial no hay a quién preguntarle.
        if (trim((string) $client->api_key) === '') {
            return $this->resultado(
                self::ESTADO_ERROR,
                'El cliente no tiene api_key configurada (tiene que coincidir con '
                . 'ADMIN_API_INBOUND_KEY del empresa-api del cliente).'
            );
        }

        // Nombre corto del endpoint para los mensajes ("admin-sync/imagenes/resumen").
        $endpoint = preg_replace('#^api/#', '', $path);

        // Respuesta HTTP real (si se pudo obtener) y error de transporte (si no hubo respuesta).
        $response        = null;
        $transport_error = '';

        try {
            $response = Http::withHeaders([
                    'X-Admin-Api-Key' => (string) $client->api_key,
                    'Accept'          => 'application/json',
                ])
                /* 🔴 Sin seguir redirecciones. Guzzle las sigue solo (hasta cinco) y, cuando la
                 * redirección cambia de host, saca `Authorization` y las cookies pero NO un header
                 * propio como `X-Admin-Api-Key`: la clave del cliente viajaría tal cual a la dirección
                 * de destino. Alcanza con una URL mal cargada (http en vez de https, un dominio
                 * vencido que redirige a una página de estacionamiento) para regalarla. Con esto un
                 * 3xx es una respuesta más, que cae abajo en "respondió HTTP 30x" con el destino a la
                 * vista para que alguien corrija la URL. */
                ->withOptions(['allow_redirects' => false])
                ->timeout((int) config('services.client_api.timeout', 15))
                /* 🔴 El tercer parámetro NO es adorno: decide qué se reintenta (ver
                 * `conviene_reintentar()`). Un 4xx no se arregla insistiendo y un timeout duplicaría
                 * la espera de quien está mirando la solapa; un 5xx o una falla rápida de conexión
                 * sí pueden ser pasajeros. */
                ->retry(
                    (int) config('services.client_api.retries', 2),
                    500,
                    function ($exception) {
                        return $this->conviene_reintentar($exception);
                    }
                )
                ->get($url, $query);
        } catch (RequestException $e) {
            // 🔴 Regla 2 del docblock de la clase: sin esto, la rama del 404 es código muerto.
            $response = $e->response;
        } catch (\Throwable $e) {
            // ConnectionException, timeout, DNS: no hay respuesta HTTP asociada.
            $transport_error = $e->getMessage();
        }

        if ($response !== null && $response->status() === 404) {
            /* 🔴 Regla 3: el 404 es la versión vieja del cliente. No se reintentó (ver
             * `conviene_reintentar()`) y se dice con un texto que no suena a falla, porque no lo es. */
            return $this->resultado(
                self::ESTADO_NO_SOPORTADO,
                'Este cliente todavía no tiene la versión que registra las consultas de imágenes '
                . '(su empresa-api no conoce ' . $endpoint . '). Aparece sola cuando se actualice.'
            );
        }

        if ($response !== null && $response->status() === 409) {
            /* El 409 tiene mensaje propio aunque el estado sea `error`: el cliente vive en una base
             * compartida y su `.env` no tiene `USER_ID`, así que no sabe de qué comercio hablar y
             * se niega a adivinar. No es versión vieja ni problema de red: está MAL CONFIGURADO y
             * se arregla con una línea. Un "falló" genérico no mandaría a nadie a mirar ese `.env`. */
            return $this->resultado(
                self::ESTADO_ERROR,
                $this->texto_seguro(
                    'A este cliente le falta `USER_ID` en el .env de su frente activo. Su empresa-api '
                    . 'comparte la base con otros comercios y sin esa variable no sabe de cuál '
                    . 'informar, así que se niega a adivinar (HTTP 409). No es una versión vieja ni '
                    . 'un problema de red: se resuelve cargando USER_ID en ese .env. Respuesta del '
                    . 'cliente: ' . $this->extracto_del_cuerpo($response, $client),
                    $client
                )
            );
        }

        if ($response !== null && $response->status() === 422) {
            /* El cliente rechazó el pedido (rango, filtros). No debería pasar —el admin valida lo
             * mismo antes de salir— así que si pasa es un desacople entre las dos puntas, y lo que
             * sirve es el motivo que dio el otro lado (ver `motivo_del_422()`), no el cuerpo entero. */
            return $this->resultado(
                self::ESTADO_ERROR,
                $this->texto_seguro(
                    'El empresa-api del cliente rechazó el pedido (HTTP 422): '
                    . $this->motivo_del_422($response, $client),
                    $client
                )
            );
        }

        if ($response !== null && ! $response->successful()) {
            $status  = (int) $response->status();
            $mensaje = 'El empresa-api del cliente respondió HTTP ' . $status . ': '
                . $this->extracto_del_cuerpo($response, $client);

            if ($status === 401 || $status === 403) {
                $mensaje .= ' Probablemente la api_key del cliente no coincide con '
                    . 'ADMIN_API_INBOUND_KEY del empresa-api.';
            }

            if ($status >= 300 && $status < 400) {
                /* Una redirección que no se siguió (ver `allow_redirects` arriba). El destino se
                 * nombra porque es justo lo que hace falta para arreglar la URL cargada.
                 *
                 * 🔴 Primero se tapa la clave y DESPUÉS se recorta, por lo mismo que en
                 * `extracto_del_cuerpo()`: un destino que trajera la clave justo en el borde de los
                 * 200 caracteres la dejaría partida, y la mitad que entró en el recorte ya no
                 * coincidiría con nada al taparla. */
                $destino  = trim((string) $response->header('Location'));
                $mensaje .= ' Es una redirección'
                    . ($destino !== '' ? ' hacia ' . mb_substr($this->tapar_clave($destino, $client), 0, 200) : '')
                    . ': el admin no la sigue, para no mandarle la clave del cliente a otra '
                    . 'dirección. Revisá la URL de la API cargada en el cliente (http o https, /public).';
            }

            return $this->resultado(self::ESTADO_ERROR, $this->texto_seguro($mensaje, $client));
        }

        if ($response === null) {
            // Sin la clave en el log: la URL y el error de transporte alcanzan para diagnosticar.
            Log::warning('ClientImageSearchLogService: no se pudo contactar al empresa-api del cliente.', [
                'client_id' => $client->id,
                'url'       => $url,
                'error'     => $this->tapar_clave($transport_error, $client),
            ]);

            return $this->resultado(
                self::ESTADO_ERROR,
                $this->texto_seguro(
                    'No se pudo contactar al empresa-api del cliente en ' . $url . ': ' . $transport_error,
                    $client
                )
            );
        }

        $resultado           = $this->resultado(self::ESTADO_OK, null, $response->json());
        $resultado['cuerpo'] = $this->extracto_del_cuerpo($response, $client);
        $resultado['status'] = (int) $response->status();

        return $resultado;
    }

    /**
     * Le pone la plata al resumen del cliente.
     *
     * Agrega, sin tocar nada de lo que vino:
     *
     *   - `totales.costo_busquedas_usd`      → lo que se pagó por las búsquedas del período (de dónde
     *                                          sale, en `costear_busquedas()`), o null si ninguna
     *                                          búsqueda cobrada tiene precio cargado.
     *   - `totales.costo_busquedas_es_techo` → true cuando el número no se puede saber exacto y es un
     *                                          techo (nunca un piso).
     *   - `totales.proveedores_sin_precio`   → los proveedores que quedaron afuera de la cuenta.
     *   - `totales.costo_ia_usd`             → la suma de `modelos[].costo_usd`, o null si ningún
     *                                          modelo con tokens tiene precio.
     *   - `totales.modelos_sin_precio`       → los modelos que quedaron afuera de la cuenta.
     *   - `totales.costo_usd`                → búsquedas + IA (el null, en `sumar_costos()`).
     *   - `totales.tokens`                   → la suma de las cuatro puntas (el número grande).
     *   - `dias[].costo_busquedas_usd` (+ `costo_busquedas_es_techo`) → lo mismo, día por día.
     *   - `modelos[].costo_usd` + `modelos[].tiene_precio`.
     *
     * 🔴 El costo de la IA sale de `modelos[]` y no de `totales.tokens_*`: el precio depende del
     * modelo, y un total que ya sumó Haiku con Sonnet perdió lo que hace falta para multiplicar.
     *
     * @param array<string, mixed> $datos Respuesta del cliente, con la forma ya validada.
     *
     * @return array<string, mixed>
     */
    protected function costear_resumen(array $datos)
    {
        /* Las dos tablas se leen UNA vez: adentro de los bucles serían N lecturas de config. */
        $precios_busqueda = config('busquedas_precios', []);
        $precios_ia       = config('ia_precios', []);

        $totales = $datos['totales'];

        // Solo las filas que son filas: una entrada rota del bloque no puede tumbar el resto.
        $dias = array_values(array_filter($datos['dias'], 'is_array'));

        $busquedas = $this->costear_busquedas($totales, $dias, $precios_busqueda);

        // ---- IA, modelo por modelo ------------------------------------------------------------
        $modelos = [];

        if (isset($datos['modelos']) && is_array($datos['modelos'])) {
            foreach ($datos['modelos'] as $fila) {
                if (is_array($fila)) {
                    $modelos[] = $fila;
                }
            }
        }

        $costo_ia           = 0.0;
        $modelos_costeados  = 0;
        $modelos_sin_precio = [];

        foreach ($modelos as $indice => $fila) {
            $modelo = isset($fila['modelo']) ? $fila['modelo'] : null;
            $costo  = self::costo_de_tokens($modelo, $fila, $precios_ia);

            $modelos[$indice]['costo_usd']    = $costo;
            $modelos[$indice]['tiene_precio'] = ClientAiTokenUsage::tiene_precio($modelo, $precios_ia);

            if ($costo === null) {
                /* Sin precio cargado: no suma y se nombra, para que la pantalla diga "no incluye X"
                 * en vez de mostrar un número corto como si fuera completo. */
                $nombre = trim((string) $modelo);

                if (! in_array($nombre, $modelos_sin_precio, true)) {
                    $modelos_sin_precio[] = $nombre;
                }

                continue;
            }

            $costo_ia += $costo;

            // Solo cuenta como "costeado" un modelo que tuvo tokens: sin tokens, cero es cero con o sin precio.
            if (self::suma_de_tokens($fila) > 0) {
                $modelos_costeados++;
            }
        }

        /* 🔴 Acá se decide el null de la IA. Ni un modelo con tokens tuvo precio: el total no es
         * cero, es "no sé". Con la lista de faltantes vacía (sin consumo) el cero SÍ es cero. */
        if ($modelos_costeados === 0 && $modelos_sin_precio !== []) {
            $costo_ia = null;
        }

        $totales['tokens']                   = self::suma_de_tokens($totales);
        $totales['costo_busquedas_usd']      = $busquedas['total']['costo_usd'];
        $totales['costo_busquedas_es_techo'] = $busquedas['total']['es_techo'];
        $totales['proveedores_sin_precio']   = $busquedas['total']['proveedores_sin_precio'];
        $totales['costo_ia_usd']             = $costo_ia;
        $totales['modelos_sin_precio']       = $modelos_sin_precio;
        $totales['costo_usd']                = self::sumar_costos($busquedas['total']['costo_usd'], $costo_ia);

        // ---- Búsquedas, día por día (ya calculadas en `costear_busquedas()`) ------------------
        foreach ($dias as $indice => $dia) {
            $dias[$indice]['costo_busquedas_usd']      = $busquedas['dias'][$indice]['costo_usd'];
            $dias[$indice]['costo_busquedas_es_techo'] = $busquedas['dias'][$indice]['es_techo'];
        }

        $datos['totales']      = $totales;
        $datos['dias']         = $dias;
        $datos['modelos']      = $modelos;
        $datos['asignaciones'] = isset($datos['asignaciones']) && is_array($datos['asignaciones'])
            ? array_values($datos['asignaciones'])
            : [];

        return $datos;
    }

    /**
     * El costo de las búsquedas del resumen: el de cada día y el del período.
     *
     * 🔴 **El total de la tarjeta y la tabla por día tienen que contar la MISMA historia.** Hasta la
     * revisión del 27/9/2026 el total se calculaba sobre los totales y los días cada uno por su
     * lado, y no daban lo mismo: con 200 búsquedas de Google rechazadas un día y 1.000 de Serper
     * cobradas otro, el total solo ve "1.200 hechas, 1.000 cobradas", no puede saber de quién eran
     * las rechazadas, y su techo (las cobradas primero al más caro) decía US$ 1,80; cada día, con un
     * solo proveedor, es exacto —0 y 1,00— y la tabla sumaba 1,00. Un total que sobreestima al lado
     * de una tabla que no lo suma es el total que miente.
     *
     * Cada DÍA se costea así (`costo_de_busquedas_del_dia()`):
     *   - con `busquedas_<proveedor>_cobradas` (§13 del plan: el cliente abre las cobradas por
     *     proveedor): exacto;
     *   - sin ese corte (un cliente con la versión anterior): con las hechas por proveedor y el total
     *     de cobradas del día, que es exacto salvo que el día mezcle proveedores con rechazos.
     *
     * El PERÍODO, en este orden de preferencia:
     *   1. `totales.busquedas_cobradas_por_proveedor` (§13): exacto.
     *   2. La SUMA de los costos por día, cuando los días cubren el período entero (ver
     *      `los_dias_cubren_el_periodo()`): `es_techo` si algún día lo es y los proveedores sin
     *      precio de todos los días juntos. El día sabe más que el total: le gana.
     *   3. La cuenta sobre los totales (`costo_de_busquedas()`), solo cuando no hay días o cuando los
     *      días no alcanzan para saber de qué proveedor es cada búsqueda.
     *
     * @param array<string, mixed>             $totales Bloque `totales` del resumen.
     * @param array<int, array<string, mixed>> $dias    Filas del bloque `dias` (solo las que son arrays).
     * @param array<string, mixed>             $precios Tabla de `config('busquedas_precios')`.
     *
     * @return array{total: array, dias: array<int, array>} Resultados con la forma de `costo_de_busquedas()`.
     */
    protected function costear_busquedas(array $totales, array $dias, $precios)
    {
        $proveedores = $this->proveedores_del_resumen($totales);

        /** @var array<int, array> Costo de cada día, en el mismo orden que `$dias`. */
        $por_dia = [];

        foreach ($dias as $indice => $dia) {
            $por_dia[$indice] = self::costo_de_busquedas_del_dia($dia, $proveedores, $precios);
        }

        $cobradas_del_periodo = isset($totales['busquedas_cobradas']) ? (int) $totales['busquedas_cobradas'] : null;

        if (isset($totales['busquedas_cobradas_por_proveedor']) && is_array($totales['busquedas_cobradas_por_proveedor'])) {
            // 1. El cliente dice cuántas se cobraron de cada proveedor: no hay nada que deducir.
            $total = self::costo_de_busquedas_cobradas(
                $totales['busquedas_cobradas_por_proveedor'],
                $cobradas_del_periodo,
                $precios
            );
        } elseif ($this->los_dias_cubren_el_periodo($totales, $dias, $proveedores)) {
            // 2. Los días suman el período entero y cada uno sabe más que el total.
            $total = self::sumar_costos_de_busquedas($por_dia);
        } else {
            // 3. Lo único que queda es la cuenta sobre los totales.
            $total = self::costo_de_busquedas(
                isset($totales['busquedas_por_proveedor']) && is_array($totales['busquedas_por_proveedor'])
                    ? $totales['busquedas_por_proveedor']
                    : [],
                isset($totales['busquedas']) ? (int) $totales['busquedas'] : null,
                $cobradas_del_periodo,
                $precios
            );
        }

        return ['total' => $total, 'dias' => $por_dia];
    }

    /**
     * Los proveedores de búsqueda que aparecen en el resumen: los dos del contrato más cualquiera que
     * el cliente nombre en los totales (hechas o cobradas). Un tercero que el cliente agregue mañana
     * entra solo, sin tocar este archivo.
     *
     * @param array<string, mixed> $totales Bloque `totales` del resumen.
     *
     * @return array<int, string> Nombres en minúsculas, sin repetir.
     */
    protected function proveedores_del_resumen(array $totales)
    {
        $proveedores = self::PROVEEDORES_DEL_CONTRATO;

        foreach (['busquedas_por_proveedor', 'busquedas_cobradas_por_proveedor'] as $bloque) {
            if (! isset($totales[$bloque]) || ! is_array($totales[$bloque])) {
                continue;
            }

            foreach (array_keys($totales[$bloque]) as $proveedor) {
                $proveedor = strtolower(trim((string) $proveedor));

                if ($proveedor !== '' && ! in_array($proveedor, $proveedores, true)) {
                    $proveedores[] = $proveedor;
                }
            }
        }

        return $proveedores;
    }

    /**
     * Si los días alcanzan para costear el período sumándolos.
     *
     * Hacen falta tres cosas, y si falla una se cae a la cuenta sobre los totales:
     *
     *   1. Que haya días.
     *   2. Que en cada día las búsquedas por proveedor que el corte abre (`busquedas_serper` +
     *      `busquedas_google` + las de cualquier otro proveedor que abra) sumen al menos el total
     *      del día. Si suman menos, ese día tuvo búsquedas de un proveedor que el corte por día no
     *      nombra, y el total del período —que sí lo nombra en `busquedas_por_proveedor`— sabe más.
     *   3. Que los días sumen las mismas búsquedas COBRADAS que el total. No debería fallar nunca
     *      —las dos cosas salen de la misma tabla del cliente—, y si falla es que falta algún día con
     *      búsquedas pagas: la suma saldría CORTA, que es peor que un techo. Se miran las cobradas y
     *      no las hechas a propósito: si lo único que falta son búsquedas rechazadas, esas no costaron
     *      nada y la suma de los días sigue siendo exacta (exigir también las hechas la cambiaría por
     *      el techo del total, que en ese caso es peor).
     *
     * @param array<string, mixed>             $totales     Bloque `totales` del resumen.
     * @param array<int, array<string, mixed>> $dias        Filas del bloque `dias`.
     * @param array<int, string>               $proveedores Proveedores del resumen.
     *
     * @return bool
     */
    protected function los_dias_cubren_el_periodo(array $totales, array $dias, array $proveedores)
    {
        if ($dias === []) {
            return false;
        }

        /** Cobradas de todos los días juntos, para compararlas con las del total. */
        $suma_de_cobradas = 0;

        foreach ($dias as $dia) {
            $busquedas_del_dia = isset($dia['busquedas']) ? (int) $dia['busquedas'] : 0;

            /** Búsquedas del día que el corte por proveedor sí atribuye. */
            $atribuidas = 0;

            foreach ($proveedores as $proveedor) {
                $campo = 'busquedas_' . $proveedor;

                if (isset($dia[$campo])) {
                    $atribuidas += (int) $dia[$campo];
                }
            }

            if ($atribuidas < $busquedas_del_dia) {
                return false;
            }

            $suma_de_cobradas += isset($dia['busquedas_cobradas']) ? (int) $dia['busquedas_cobradas'] : 0;
        }

        if (isset($totales['busquedas_cobradas']) && (int) $totales['busquedas_cobradas'] !== $suma_de_cobradas) {
            return false;
        }

        return true;
    }

    /**
     * Le pone la plata a cada fila de una página del registro y le saca al paginador las URLs del
     * cliente.
     *
     * @param array<string, mixed> $datos Respuesta del cliente, con la forma ya validada.
     *
     * @return array<string, mixed>
     */
    protected function costear_consultas(array $datos)
    {
        $precios_busqueda = config('busquedas_precios', []);
        $precios_ia       = config('ia_precios', []);

        $pagina = $datos['models'];

        $filas = [];

        foreach ($pagina['data'] as $fila) {
            if (! is_array($fila)) {
                continue;
            }

            $costo = self::costo_de_la_consulta($fila, $precios_busqueda, $precios_ia);

            $fila['costo_usd']    = $costo['costo_usd'];
            $fila['tiene_precio'] = $costo['tiene_precio'];

            $filas[] = $fila;
        }

        $pagina['data'] = $filas;

        foreach (self::CLAVES_DE_URL_DEL_PAGINADOR as $clave) {
            unset($pagina[$clave]);
        }

        $datos['models'] = $pagina;

        return $datos;
    }

    /**
     * Costo de UNA consulta del registro.
     *
     *   - Búsqueda **no cobrada** (el proveedor respondió con error) → 0: no se pagó, se sepa o no
     *     el precio. Es la regla del cliente: una búsqueda cuenta solo si el proveedor respondió bien.
     *   - Búsqueda cobrada → el precio del proveedor dividido 1.000, o null si no tiene precio.
     *   - Validación con IA → sus tokens por el precio del modelo (`ClientAiTokenUsage::costo_usd()`),
     *     o null si el modelo no tiene precio y la llamada gastó tokens.
     *   - Un tipo desconocido → null: no se sabe qué es, así que tampoco cuánto costó.
     *
     * `tiene_precio` dice si hay tarifa cargada para ese proveedor o modelo, para que la pantalla
     * distinga "costó cero" de "no sé cuánto costó".
     *
     * @param array<string, mixed>      $fila             Fila del registro.
     * @param array<string, mixed>|null $precios_busqueda Tabla de búsquedas; por defecto la config.
     * @param array<string, mixed>|null $precios_ia       Tabla de IA; por defecto la config.
     *
     * @return array{costo_usd: float|null, tiene_precio: bool}
     */
    public static function costo_de_la_consulta(array $fila, $precios_busqueda = null, $precios_ia = null)
    {
        $tipo = isset($fila['tipo']) ? (string) $fila['tipo'] : '';

        if ($tipo === self::TIPO_BUSQUEDA) {
            $precio = self::precio_de_busqueda(
                isset($fila['proveedor']) ? $fila['proveedor'] : null,
                $precios_busqueda
            );

            /* `cobrada` es la columna del contrato. Si una versión rara del cliente no la mandara,
             * se cae a `ok`, que es exactamente la misma regla del otro lado ("cuenta si respondió bien"). */
            $cobrada = array_key_exists('cobrada', $fila)
                ? ! empty($fila['cobrada'])
                : ! empty($fila['ok']);

            if (! $cobrada) {
                return ['costo_usd' => 0.0, 'tiene_precio' => $precio !== null];
            }

            if ($precio === null) {
                return ['costo_usd' => null, 'tiene_precio' => false];
            }

            return [
                'costo_usd'    => $precio / self::BUSQUEDAS_POR_UNIDAD_DE_PRECIO,
                'tiene_precio' => true,
            ];
        }

        if ($tipo === self::TIPO_VALIDACION_IA) {
            $modelo = isset($fila['modelo']) ? $fila['modelo'] : null;

            return [
                'costo_usd'    => self::costo_de_tokens($modelo, $fila, $precios_ia),
                'tiene_precio' => ClientAiTokenUsage::tiene_precio($modelo, $precios_ia),
            ];
        }

        return ['costo_usd' => null, 'tiene_precio' => false];
    }

    /**
     * Costo en dólares de un puñado de búsquedas cuando se sabe cuántas hizo cada proveedor pero
     * solo cuántas se cobraron EN TOTAL (el caso de un cliente sin el corte de §13).
     *
     * Se paga solo la cobrada, así que hay que saber cuántas cobradas son de cada proveedor. Eso se
     * acota entre dos repartos extremos de las cobradas:
     *
     *   - el TECHO: las cobradas van primero al proveedor más caro (los sin precio, al final);
     *   - el PISO: las cobradas van primero al más barato (los sin precio, primero).
     *
     * Si los dos dan lo mismo, el número es EXACTO. Pasa cuando se cobraron todas, cuando hubo un
     * solo proveedor, cuando todos cuestan lo mismo y cuando no se cobró ninguna. Si difieren, se
     * muestra el techo y se marca `es_techo`. 🔴 No se inventa una proporción: un techo honesto y
     * dicho es mejor que un promedio que parece medido.
     *
     * 🔴 **Sin búsquedas cobradas el costo es cero EXACTO**, sin "techo" y sin faltantes que
     * nombrar: lo que no se cobró no cuesta nada, tenga precio o no. (Antes de la revisión del
     * 27/9/2026, un día con dos proveedores y ninguna cobrada salía marcado como techo.)
     *
     * Búsquedas que el total cuenta y ningún proveedor reclama (un cliente que informara el total sin
     * abrirlo) van bajo el proveedor `''`, que no tiene precio: no se costean y se nombran. Si no, el
     * costo saldría corto sin que nada lo diga.
     *
     * Misma regla del null que el resto del admin: si ninguna cobrada pudo ir a un proveedor con
     * precio y hay proveedores sin precio, el costo es `null` ("no sé"), no cero.
     *
     * @param array<string, int>        $por_proveedor Búsquedas hechas por cada proveedor.
     * @param int|null                  $total         Total de búsquedas hechas (null = la suma de arriba).
     * @param int|null                  $cobradas      Cuántas se cobraron (null = todas: el techo).
     * @param array<string, mixed>|null $precios       Tabla de precios; por defecto config('busquedas_precios').
     *
     * @return array{costo_usd: float|null, es_techo: bool, proveedores_sin_precio: array<int, string>}
     */
    public static function costo_de_busquedas(array $por_proveedor, $total, $cobradas, $precios = null)
    {
        if ($precios === null) {
            $precios = config('busquedas_precios', []);
        }

        $hechas     = self::normalizar_conteos($por_proveedor);
        $atribuidas = array_sum($hechas);
        $total      = $total === null ? $atribuidas : max((int) $total, $atribuidas);

        if ($total > $atribuidas) {
            $hechas[''] = (isset($hechas['']) ? $hechas[''] : 0) + ($total - $atribuidas);
        }

        // Sin el dato de cobradas se asume que se cobraron todas: es la lectura que da el techo.
        $cobradas = $cobradas === null ? $total : min(max((int) $cobradas, 0), $total);

        if ($total === 0 || $cobradas === 0) {
            return ['costo_usd' => 0.0, 'es_techo' => false, 'proveedores_sin_precio' => []];
        }

        $techo = self::valorizar_cobradas(self::repartir_cobradas($hechas, $cobradas, $precios, true), $precios);
        $piso  = self::valorizar_cobradas(self::repartir_cobradas($hechas, $cobradas, $precios, false), $precios);

        /* Un proveedor sin precio se nombra si pudo haber tenido alguna búsqueda cobrada, y acá eso
         * vale para todos: tienen búsquedas hechas y hubo cobradas, así que cualquiera pudo llevarse
         * alguna. */
        $sin_precio = [];

        foreach (array_keys($hechas) as $proveedor) {
            if (self::precio_de_busqueda($proveedor, $precios) === null) {
                $sin_precio[] = (string) $proveedor;
            }
        }

        // Tolerancia de redondeo: son sumas de enteros por precios con decimales.
        $es_techo = abs($techo['costo'] - $piso['costo']) > 0.0000001;

        if ($techo['costeadas'] === 0 && $sin_precio !== []) {
            return ['costo_usd' => null, 'es_techo' => $es_techo, 'proveedores_sin_precio' => $sin_precio];
        }

        return ['costo_usd' => $techo['costo'], 'es_techo' => $es_techo, 'proveedores_sin_precio' => $sin_precio];
    }

    /**
     * Costo EXACTO de las búsquedas cuando el cliente dice cuántas se cobraron de cada proveedor
     * (el corte de §13: `totales.busquedas_cobradas_por_proveedor` y
     * `dias[].busquedas_<proveedor>_cobradas`). No hay nada que deducir: cada una por su precio.
     *
     * Si el total de cobradas es mayor que la suma por proveedor (un proveedor que el corte no
     * abre), la diferencia va bajo el proveedor `''`, sin precio, y se nombra: mejor un faltante a la
     * vista que un número corto que parece completo.
     *
     * @param array<string, int>        $cobradas_por_proveedor Búsquedas cobradas de cada proveedor.
     * @param int|null                  $cobradas_en_total      Total de cobradas (null = la suma de arriba).
     * @param array<string, mixed>|null $precios                Tabla de precios; por defecto la config.
     *
     * @return array{costo_usd: float|null, es_techo: bool, proveedores_sin_precio: array<int, string>}
     */
    public static function costo_de_busquedas_cobradas(array $cobradas_por_proveedor, $cobradas_en_total = null, $precios = null)
    {
        if ($precios === null) {
            $precios = config('busquedas_precios', []);
        }

        $cobradas   = self::normalizar_conteos($cobradas_por_proveedor);
        $atribuidas = array_sum($cobradas);

        if ($cobradas_en_total !== null && (int) $cobradas_en_total > $atribuidas) {
            $cobradas[''] = (isset($cobradas['']) ? $cobradas[''] : 0) + ((int) $cobradas_en_total - $atribuidas);
        }

        $valor = self::valorizar_cobradas($cobradas, $precios);

        $sin_precio = [];

        foreach (array_keys($cobradas) as $proveedor) {
            if (self::precio_de_busqueda($proveedor, $precios) === null) {
                $sin_precio[] = (string) $proveedor;
            }
        }

        if ($valor['costeadas'] === 0 && $sin_precio !== []) {
            return ['costo_usd' => null, 'es_techo' => false, 'proveedores_sin_precio' => $sin_precio];
        }

        return ['costo_usd' => $valor['costo'], 'es_techo' => false, 'proveedores_sin_precio' => $sin_precio];
    }

    /**
     * Costo de las búsquedas de UN día del resumen.
     *
     * Con el corte de §13 (`busquedas_serper_cobradas`, `busquedas_google_cobradas`, y el mismo
     * patrón para cualquier otro proveedor), exacto. Sin él, con las hechas por proveedor
     * (`busquedas_<proveedor>`) y el total de cobradas del día.
     *
     * @param array<string, mixed>      $dia         Fila del bloque `dias`.
     * @param array<int, string>        $proveedores Proveedores del resumen.
     * @param array<string, mixed>|null $precios     Tabla de precios; por defecto la config.
     *
     * @return array{costo_usd: float|null, es_techo: bool, proveedores_sin_precio: array<int, string>}
     */
    public static function costo_de_busquedas_del_dia(array $dia, array $proveedores, $precios = null)
    {
        $cobradas_del_dia = isset($dia['busquedas_cobradas']) ? (int) $dia['busquedas_cobradas'] : null;

        /** @var array<string, int> Cobradas por proveedor, si el cliente las abre por día. */
        $cobradas = [];

        foreach ($proveedores as $proveedor) {
            $campo = 'busquedas_' . $proveedor . '_cobradas';

            if (isset($dia[$campo])) {
                $cobradas[$proveedor] = (int) $dia[$campo];
            }
        }

        if ($cobradas !== []) {
            return self::costo_de_busquedas_cobradas($cobradas, $cobradas_del_dia, $precios);
        }

        /** @var array<string, int> Hechas por proveedor, con el patrón `busquedas_<proveedor>`. */
        $hechas = [];

        foreach ($proveedores as $proveedor) {
            $campo = 'busquedas_' . $proveedor;

            if (isset($dia[$campo])) {
                $hechas[$proveedor] = (int) $dia[$campo];
            }
        }

        return self::costo_de_busquedas(
            $hechas,
            isset($dia['busquedas']) ? (int) $dia['busquedas'] : null,
            $cobradas_del_dia,
            $precios
        );
    }

    /**
     * El costo del período como la suma de los costos de sus días.
     *
     * `es_techo` si algún día lo es (con uno solo que sea techo, la suma también lo es), y los
     * proveedores sin precio de todos los días juntos, sin repetir. Misma regla del null que
     * `sumar_costos()`: si lo único que se sabe es cero y hay algo sin precio, la suma es "no sé".
     *
     * @param array<int, array> $por_dia Resultados de `costo_de_busquedas_del_dia()`.
     *
     * @return array{costo_usd: float|null, es_techo: bool, proveedores_sin_precio: array<int, string>}
     */
    public static function sumar_costos_de_busquedas(array $por_dia)
    {
        $costo      = 0.0;
        $es_techo   = false;
        $sin_precio = [];

        foreach ($por_dia as $resultado) {
            if ($resultado['costo_usd'] !== null) {
                $costo += (float) $resultado['costo_usd'];
            }

            if ($resultado['es_techo']) {
                $es_techo = true;
            }

            foreach ($resultado['proveedores_sin_precio'] as $proveedor) {
                if (! in_array($proveedor, $sin_precio, true)) {
                    $sin_precio[] = $proveedor;
                }
            }
        }

        if ($costo <= 0 && $sin_precio !== []) {
            return ['costo_usd' => null, 'es_techo' => $es_techo, 'proveedores_sin_precio' => $sin_precio];
        }

        return ['costo_usd' => $costo, 'es_techo' => $es_techo, 'proveedores_sin_precio' => $sin_precio];
    }

    /**
     * Reparte las búsquedas cobradas entre los proveedores, de a una punta de la lista de precios.
     *
     * @param array<string, int>   $hechas        Búsquedas hechas por proveedor (el máximo de cada uno).
     * @param int                  $cobradas      Búsquedas cobradas a repartir.
     * @param array<string, mixed> $precios       Tabla de precios.
     * @param bool                 $caros_primero true: el más caro primero y los sin precio al final
     *                                            (techo). false: al revés (piso).
     *
     * @return array<string, int> Cobradas asignadas a cada proveedor.
     */
    protected static function repartir_cobradas(array $hechas, $cobradas, $precios, $caros_primero)
    {
        $orden = array_keys($hechas);

        usort($orden, function ($a, $b) use ($precios, $caros_primero) {
            $precio_a = self::precio_de_busqueda($a, $precios);
            $precio_b = self::precio_de_busqueda($b, $precios);
            $precio_a = $precio_a === null ? -1.0 : $precio_a;
            $precio_b = $precio_b === null ? -1.0 : $precio_b;

            // A igual precio, por nombre: el resultado no puede depender del orden en que vino el JSON.
            if ($precio_a === $precio_b) {
                return strcmp((string) $a, (string) $b);
            }

            if ($caros_primero) {
                return $precio_a < $precio_b ? 1 : -1;
            }

            return $precio_a > $precio_b ? 1 : -1;
        });

        $restantes = (int) $cobradas;
        $reparto   = [];

        foreach ($orden as $proveedor) {
            $asignadas            = min($hechas[$proveedor], $restantes);
            $reparto[$proveedor]  = $asignadas;
            $restantes           -= $asignadas;
        }

        return $reparto;
    }

    /**
     * Pone precio a un reparto de búsquedas cobradas.
     *
     * @param array<string, int>   $cobradas Cobradas por proveedor.
     * @param array<string, mixed> $precios  Tabla de precios.
     *
     * @return array{costo: float, costeadas: int} Dólares de las que tienen precio, y cuántas son.
     */
    protected static function valorizar_cobradas(array $cobradas, $precios)
    {
        $costo     = 0.0;
        $costeadas = 0;

        foreach ($cobradas as $proveedor => $cantidad) {
            $precio = self::precio_de_busqueda($proveedor, $precios);

            if ($precio === null || $cantidad <= 0) {
                continue;
            }

            $costo     += ($cantidad * $precio) / self::BUSQUEDAS_POR_UNIDAD_DE_PRECIO;
            $costeadas += $cantidad;
        }

        return ['costo' => $costo, 'costeadas' => $costeadas];
    }

    /**
     * Normaliza un conteo por proveedor: nombres en minúsculas y sin espacios, repetidos sumados, y
     * sin ceros ni negativos (un proveedor sin búsquedas no participa de ninguna cuenta).
     *
     * @param array<string, mixed> $conteos Cantidad por proveedor, tal como vino.
     *
     * @return array<string, int>
     */
    protected static function normalizar_conteos(array $conteos)
    {
        $normalizados = [];

        foreach ($conteos as $proveedor => $cantidad) {
            $cantidad = (int) $cantidad;

            if ($cantidad <= 0) {
                continue;
            }

            $clave                = strtolower(trim((string) $proveedor));
            $normalizados[$clave] = (isset($normalizados[$clave]) ? $normalizados[$clave] : 0) + $cantidad;
        }

        return $normalizados;
    }

    /**
     * Costo en dólares de los tokens de una fila del contrato de imágenes, para un modelo dado.
     *
     * Traduce los nombres del contrato (`tokens_entrada`…) a los de `ClientAiTokenUsage::PUNTAS` y
     * delega la cuenta: una sola multiplicación para todo el admin, con su búsqueda por prefijo
     * (`claude-haiku-4-5-20251001` encuentra su tarifa aunque el proveedor cambie el sufijo).
     *
     * Una fila SIN tokens cuesta cero aunque el modelo no tenga precio: no hay nada que multiplicar,
     * así que "no sé" sería mentir para el otro lado. Pasa con las validaciones que fallaron antes de
     * que la IA procesara nada.
     *
     * @param string|null               $modelo  Modelo tal como lo informó el cliente.
     * @param array<string, mixed>      $fila    Fila con los cuatro contadores del contrato.
     * @param array<string, mixed>|null $precios Tabla de precios; por defecto config('ia_precios').
     *
     * @return float|null Dólares, o null si hubo tokens y el modelo no tiene precio cargado.
     */
    public static function costo_de_tokens($modelo, array $fila, $precios = null)
    {
        $tokens = self::tokens_de($fila);

        if (array_sum($tokens) <= 0) {
            return 0.0;
        }

        return ClientAiTokenUsage::costo_usd($modelo, $tokens, $precios);
    }

    /**
     * Precio cada MIL búsquedas de un proveedor, o null si no tiene tarifa cargada.
     *
     * Búsqueda exacta por nombre (en minúsculas). Acá no hay prefijos como en los modelos: los
     * proveedores son nombres cortos y cerrados, y un "serper-algo" que apareciera mañana es un
     * producto distinto que merece su propio renglón en la tabla, no heredar el precio del otro.
     *
     * @param string|null               $proveedor Proveedor tal como lo informó el cliente.
     * @param array<string, mixed>|null $precios   Tabla de precios; por defecto config('busquedas_precios').
     *
     * @return float|null Dólares cada 1.000 búsquedas.
     */
    public static function precio_de_busqueda($proveedor, $precios = null)
    {
        if ($precios === null) {
            $precios = config('busquedas_precios', []);
        }

        if (! is_array($precios)) {
            return null;
        }

        $clave = strtolower(trim((string) $proveedor));

        if ($clave === '' || ! array_key_exists($clave, $precios) || ! is_numeric($precios[$clave])) {
            return null;
        }

        return (float) $precios[$clave];
    }

    /**
     * Los cuatro contadores de una fila del contrato, con las claves de `ClientAiTokenUsage::PUNTAS`.
     *
     * @param array<string, mixed> $fila Fila con `tokens_entrada`, `tokens_salida`, etc.
     *
     * @return array<string, int>
     */
    protected static function tokens_de(array $fila)
    {
        $tokens = [];

        foreach (self::TOKENS_DEL_CONTRATO as $campo => $columna) {
            $cantidad         = isset($fila[$campo]) ? (int) $fila[$campo] : 0;
            $tokens[$columna] = $cantidad > 0 ? $cantidad : 0;
        }

        return $tokens;
    }

    /**
     * La suma de las cuatro puntas de tokens de una fila del contrato.
     *
     * @param array<string, mixed> $fila Fila con los cuatro contadores.
     *
     * @return int
     */
    protected static function suma_de_tokens(array $fila)
    {
        return (int) array_sum(self::tokens_de($fila));
    }

    /**
     * Suma dos costos respetando el null, con la misma regla que `ClientAiTokenUsage::totalizar()`:
     *
     *   - null + null → null ("no sé nada").
     *   - null + X, con X > 0 → X: lo que se sabe es un PISO, y la parte desconocida la nombra
     *     aparte quien llama (`modelos_sin_precio`, `proveedores_sin_precio`).
     *   - 🔴 null + 0 → null. Si lo único que se sabe es que una parte costó cero (no hubo
     *     búsquedas) y la otra no tiene precio, el total NO es cero dólares: es "no sé". Mostrar
     *     US$ 0,00 ahí sería el total que miente.
     *   - X + Y → X + Y.
     *
     * @param float|null $a
     * @param float|null $b
     *
     * @return float|null
     */
    protected static function sumar_costos($a, $b)
    {
        if ($a === null && $b === null) {
            return null;
        }

        if ($a === null) {
            return (float) $b > 0 ? (float) $b : null;
        }

        if ($b === null) {
            return (float) $a > 0 ? (float) $a : null;
        }

        return (float) $a + (float) $b;
    }

    /**
     * Si conviene reintentar una llamada que falló.
     *
     * - Un 4xx no se arregla insistiendo: el 404 es la versión vieja del cliente y el 401 es una
     *   api_key que no coincide. Los dos dan lo mismo en el segundo intento, y el 404 es el caso
     *   MAYORITARIO mientras el parque se actualiza.
     * - 🔴 Un TIMEOUT tampoco se reintenta. Esta lectura es EN VIVO, con alguien mirando la solapa, y
     *   el reintento duplicaría la espera (dos veces `services.client_api.timeout`: 30 segundos con
     *   el default) para enterarse casi siempre de lo mismo: una instancia que no contestó en 15
     *   segundos no suele contestar en los 15 siguientes. (El molde, `ClientAiTokensSyncService`, sí
     *   lo reintenta, pero corre de noche y sin nadie esperando.)
     * - Un 5xx o una falla RÁPIDA de conexión (conexión rechazada, DNS) sí se reintentan: cuestan
     *   medio segundo y pueden ser un parpadeo.
     *
     * @param \Throwable $exception Excepción que levantó el cliente HTTP.
     *
     * @return bool
     */
    protected function conviene_reintentar($exception)
    {
        if (! ($exception instanceof RequestException)) {
            // ConnectionException o similar: sin respuesta HTTP. Se reintenta salvo que sea un timeout.
            return ! $this->es_timeout($exception);
        }

        if ($exception->response === null) {
            return true;
        }

        $status = (int) $exception->response->status();

        return $status < 400 || $status >= 500;
    }

    /**
     * Si una falla de transporte fue un timeout.
     *
     * Se reconoce por el mensaje, que es lo único que trae la `ConnectionException` de Laravel 8:
     * cURL lo reporta como "cURL error 28" (operation timed out), y el texto "timed out" cubre
     * también las variantes que no pasan por cURL.
     *
     * @param \Throwable $exception
     *
     * @return bool
     */
    protected function es_timeout($exception)
    {
        $mensaje = strtolower((string) $exception->getMessage());

        return strpos($mensaje, 'curl error 28') !== false
            || strpos($mensaje, 'timed out') !== false;
    }

    /**
     * El principio del cuerpo de una respuesta, para citarlo en un mensaje, con la clave del
     * cliente ya tapada.
     *
     * 🔴 La clave se tapa sobre el cuerpo ENTERO y recién después se recorta. Al revés, una clave que
     * quedara partida justo en el borde de los 300 caracteres (una mitad adentro, la otra afuera) no
     * coincidiría con nada al taparla y saldría a la vista a medias.
     *
     * `mb_substr` y no `substr`: cortar un cuerpo en UTF-8 por bytes puede partir un carácter a la
     * mitad, y un byte suelto hace fallar el `json_encode` de la respuesta del admin (ver
     * `texto_seguro()`, que además limpia lo que ya viniera roto).
     *
     * @param \Illuminate\Http\Client\Response $response Respuesta del cliente.
     * @param Client                           $client   Cliente dueño de la clave.
     *
     * @return string
     */
    protected function extracto_del_cuerpo($response, Client $client)
    {
        $cuerpo = $this->tapar_clave(trim((string) $response->body()), $client);

        return mb_substr($cuerpo, 0, self::CHARS_DE_CUERPO);
    }

    /**
     * El motivo de un 422 del cliente, en castellano y sin el mensaje genérico de Laravel.
     *
     * Un 422 de validación de Laravel trae `message` ("The given data was invalid.", que no dice
     * nada) y `errors` con los motivos de verdad, campo por campo. Por eso manda `errors`: se juntan
     * todos sus mensajes. `message` se usa solo si no hay `errors` (el 422 propio del endpoint, el
     * del rango de más de 62 días, trae solo `message` y ese sí dice qué pasó). Si no hay ninguno de
     * los dos, el principio del cuerpo.
     *
     * @param \Illuminate\Http\Client\Response $response Respuesta 422 del cliente.
     * @param Client                           $client   Cliente dueño de la clave.
     *
     * @return string
     */
    protected function motivo_del_422($response, Client $client)
    {
        $cuerpo = $response->json();

        if (is_array($cuerpo)) {
            /** @var array<int, string> Mensajes de `errors`, sin repetir. */
            $mensajes = [];

            if (isset($cuerpo['errors']) && is_array($cuerpo['errors'])) {
                array_walk_recursive($cuerpo['errors'], function ($valor) use (&$mensajes) {
                    if (is_scalar($valor) && trim((string) $valor) !== '' && ! in_array(trim((string) $valor), $mensajes, true)) {
                        $mensajes[] = trim((string) $valor);
                    }
                });
            }

            if ($mensajes !== []) {
                return mb_substr($this->tapar_clave(implode(' ', $mensajes), $client), 0, self::CHARS_DE_CUERPO);
            }

            if (isset($cuerpo['message']) && is_scalar($cuerpo['message']) && trim((string) $cuerpo['message']) !== '') {
                return mb_substr($this->tapar_clave(trim((string) $cuerpo['message']), $client), 0, self::CHARS_DE_CUERPO);
            }
        }

        return $this->extracto_del_cuerpo($response, $client);
    }

    /**
     * Tapa la clave del cliente en un texto: tal cual, como la escribe `json_encode` y como viaja
     * codificada adentro de una URL.
     *
     * 🔴 La segunda forma no es paranoia. Si el cliente devolviera la clave adentro de un JSON,
     * `json_encode` le escapa las barras (`/` → `\/`) y, según la clave, comillas, barras invertidas
     * o caracteres no ASCII: la clave tal cual no aparecería en el cuerpo y el reemplazo no taparía
     * nada. Se exige un largo mínimo para no hacer destrozos con una clave de prueba de dos letras.
     *
     * @param string $texto  Texto a limpiar.
     * @param Client $client Cliente dueño de la clave.
     *
     * @return string
     */
    protected function tapar_clave($texto, Client $client)
    {
        $texto = (string) $texto;
        $clave = trim((string) $client->api_key);

        if (strlen($clave) < 6) {
            return $texto;
        }

        /** @var array<int, string> Las formas en que la clave puede aparecer en el texto. */
        $formas = [$clave];

        $en_json = json_encode($clave);

        if (is_string($en_json) && strlen($en_json) >= 2) {
            // Sin las comillas del string JSON.
            $escapada = substr($en_json, 1, -1);

            if ($escapada !== $clave) {
                $formas[] = $escapada;
            }
        }

        /* Y como viaja adentro de una URL —el `Location` de una redirección es exactamente eso—:
         * codificada con `rawurlencode` (`/` → `%2F`, espacio → `%20`) o con `urlencode` (espacio →
         * `+`). Para una clave de letras, números y guiones las tres formas son la misma. */
        $formas[] = rawurlencode($clave);
        $formas[] = urlencode($clave);

        $formas = array_values(array_unique($formas));

        /* La más larga primero: si una forma estuviera adentro de otra, reemplazar antes la corta
         * dejaría pedazos de la larga a la vista. */
        usort($formas, function ($a, $b) {
            return strlen($b) - strlen($a);
        });

        return str_replace($formas, '[clave oculta]', $texto);
    }

    /**
     * Un texto listo para salir en la respuesta del admin: sin la clave del cliente y en UTF-8 válido.
     *
     * 🔴 Tapa la `api_key` del cliente si aparece en el texto (ver `tapar_clave()`). No debería
     * —ningún endpoint del cliente la devuelve—, pero los mensajes de error copian cuerpos que no
     * controlamos, y una clave en la pantalla (o en una captura que alguien manda por WhatsApp) no
     * se puede des-mostrar. Los extractos de cuerpo ya llegan tapados (sobre el cuerpo entero, antes
     * de recortar); esto es la red de seguridad para todo lo demás que se suma al mensaje.
     *
     * @param string $texto  Texto armado con cosas que devolvió el cliente.
     * @param Client $client Cliente dueño de la clave.
     *
     * @return string
     */
    protected function texto_seguro($texto, Client $client)
    {
        $texto = $this->tapar_clave($texto, $client);

        // Reemplaza cualquier secuencia UTF-8 inválida por '?': un cuerpo en latin1 o cortado no rompe nada.
        return mb_convert_encoding($texto, 'UTF-8', 'UTF-8');
    }

    /**
     * Arma el resultado común de los dos endpoints.
     *
     * @param string      $estado  ok | no_soportado | error.
     * @param string|null $mensaje Motivo, cuando no es ok.
     * @param mixed       $datos   Respuesta del cliente ya costeada, cuando es ok.
     *
     * @return array{estado: string, mensaje: string|null, datos: mixed}
     */
    protected function resultado($estado, $mensaje = null, $datos = null)
    {
        return [
            'estado'  => $estado,
            'mensaje' => $mensaje,
            'datos'   => $datos,
        ];
    }

    /**
     * Si un valor de la entrada cuenta como "no vino": null, o texto vacío después de recortarlo.
     *
     * @param mixed $valor
     *
     * @return bool
     */
    protected function vacio($valor)
    {
        return $valor === null || (is_string($valor) && trim($valor) === '');
    }
}

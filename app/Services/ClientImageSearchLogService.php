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
 *    devuelto en un cuerpo de error, y limpia el UTF-8 roto de un cuerpo cortado (un byte suelto
 *    haría fallar el `json_encode` de la respuesta del admin y el operador vería un 500 en vez del
 *    motivo).
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

        /* 🔴 Un 200 NO alcanza para dar el dato por bueno: hay que reconocer la FORMA del payload.
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
                    'El cliente respondió HTTP 200 pero el cuerpo no es el resumen del registro de '
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

        // Mismo corte que en el resumen: un 200 que no es un paginador no es "cero consultas".
        if (! is_array($datos)
            || ! isset($datos['models']) || ! is_array($datos['models'])
            || ! array_key_exists('data', $datos['models']) || ! is_array($datos['models']['data'])
        ) {
            return $this->resultado(
                self::ESTADO_ERROR,
                $this->texto_seguro(
                    'El cliente respondió HTTP 200 pero el cuerpo no es el registro de consultas de '
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
     * decodificado tal cual (el llamador valida la forma) y `cuerpo` el principio del cuerpo crudo,
     * para poder citarlo si la forma no es la esperada.
     *
     * @param Client               $client Cliente a consultar.
     * @param string               $path   Ruta relativa del endpoint (una de las dos constantes).
     * @param array<string, mixed> $query  Parámetros de la query.
     *
     * @return array{estado: string, mensaje: string|null, datos: mixed, cuerpo?: string}
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
                ->timeout((int) config('services.client_api.timeout', 15))
                /* 🔴 El tercer parámetro NO es adorno: sin él se reintenta cualquier no-2xx,
                 * incluido el 404, que es el caso esperado mientras el parque se actualiza. Un 4xx
                 * no se arregla insistiendo; un 5xx o un corte de conexión sí pueden ser pasajeros. */
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
                    . 'cliente: ' . $this->extracto_del_cuerpo($response),
                    $client
                )
            );
        }

        if ($response !== null && $response->status() === 422) {
            /* El cliente rechazó el pedido (rango, filtros). No debería pasar —el admin valida lo
             * mismo antes de salir— así que si pasa es un desacople entre las dos puntas, y lo que
             * sirve es el motivo que dio el otro lado, no el cuerpo entero. */
            $cuerpo  = $response->json();
            $motivo  = is_array($cuerpo) && isset($cuerpo['message']) && ! is_array($cuerpo['message'])
                ? (string) $cuerpo['message']
                : $this->extracto_del_cuerpo($response);

            return $this->resultado(
                self::ESTADO_ERROR,
                $this->texto_seguro(
                    'El empresa-api del cliente rechazó el pedido (HTTP 422): ' . $motivo,
                    $client
                )
            );
        }

        if ($response !== null && ! $response->successful()) {
            $mensaje = 'El empresa-api del cliente respondió HTTP ' . $response->status() . ': '
                . $this->extracto_del_cuerpo($response);

            if ($response->status() === 401 || $response->status() === 403) {
                $mensaje .= ' Probablemente la api_key del cliente no coincide con '
                    . 'ADMIN_API_INBOUND_KEY del empresa-api.';
            }

            return $this->resultado(self::ESTADO_ERROR, $this->texto_seguro($mensaje, $client));
        }

        if ($response === null) {
            // Sin la clave en el log: la URL y el error de transporte alcanzan para diagnosticar.
            Log::warning('ClientImageSearchLogService: no se pudo contactar al empresa-api del cliente.', [
                'client_id' => $client->id,
                'url'       => $url,
                'error'     => $transport_error,
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
        $resultado['cuerpo'] = $this->extracto_del_cuerpo($response);

        return $resultado;
    }

    /**
     * Le pone la plata al resumen del cliente.
     *
     * Agrega, sin tocar nada de lo que vino:
     *
     *   - `totales.costo_busquedas_usd`      → las búsquedas COBRADAS de cada proveedor por su precio
     *                                          (ver `costo_de_busquedas()`), o null si ninguna tiene
     *                                          precio cargado.
     *   - `totales.costo_busquedas_es_techo` → true cuando el reparto de las cobradas entre
     *                                          proveedores no se puede saber y el número es un techo.
     *   - `totales.proveedores_sin_precio`   → los proveedores que quedaron afuera de la cuenta.
     *   - `totales.costo_ia_usd`             → la suma de `modelos[].costo_usd`, o null si ningún
     *                                          modelo con tokens tiene precio.
     *   - `totales.modelos_sin_precio`       → los modelos que quedaron afuera de la cuenta.
     *   - `totales.costo_usd`                → búsquedas + IA; null solo si las dos son null.
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

        $por_proveedor = isset($totales['busquedas_por_proveedor']) && is_array($totales['busquedas_por_proveedor'])
            ? $totales['busquedas_por_proveedor']
            : [];

        $busquedas = self::costo_de_busquedas(
            $por_proveedor,
            isset($totales['busquedas']) ? (int) $totales['busquedas'] : null,
            isset($totales['busquedas_cobradas']) ? (int) $totales['busquedas_cobradas'] : null,
            $precios_busqueda
        );

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
        $totales['costo_busquedas_usd']      = $busquedas['costo_usd'];
        $totales['costo_busquedas_es_techo'] = $busquedas['es_techo'];
        $totales['proveedores_sin_precio']   = $busquedas['proveedores_sin_precio'];
        $totales['costo_ia_usd']             = $costo_ia;
        $totales['modelos_sin_precio']       = $modelos_sin_precio;
        $totales['costo_usd']                = self::sumar_costos($busquedas['costo_usd'], $costo_ia);

        // ---- Búsquedas, día por día -----------------------------------------------------------
        $proveedores = self::PROVEEDORES_DEL_CONTRATO;

        foreach (array_keys($por_proveedor) as $proveedor) {
            $proveedor = strtolower(trim((string) $proveedor));

            if ($proveedor !== '' && ! in_array($proveedor, $proveedores, true)) {
                $proveedores[] = $proveedor;
            }
        }

        $dias = [];

        foreach ($datos['dias'] as $dia) {
            if (! is_array($dia)) {
                continue;
            }

            /** @var array<string, int> Búsquedas del día por proveedor, con el patrón `busquedas_<proveedor>`. */
            $del_dia = [];

            foreach ($proveedores as $proveedor) {
                $campo = 'busquedas_' . $proveedor;

                if (isset($dia[$campo])) {
                    $del_dia[$proveedor] = (int) $dia[$campo];
                }
            }

            $costo_del_dia = self::costo_de_busquedas(
                $del_dia,
                isset($dia['busquedas']) ? (int) $dia['busquedas'] : null,
                isset($dia['busquedas_cobradas']) ? (int) $dia['busquedas_cobradas'] : null,
                $precios_busqueda
            );

            $dia['costo_busquedas_usd']      = $costo_del_dia['costo_usd'];
            $dia['costo_busquedas_es_techo'] = $costo_del_dia['es_techo'];

            $dias[] = $dia;
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
     * Costo en dólares de un puñado de búsquedas, abiertas por proveedor.
     *
     * El dato que manda el cliente es: cuántas búsquedas hizo cada proveedor (todas, se hayan
     * cobrado o no) y cuántas se cobraron EN TOTAL. Se paga solo la cobrada, así que hay que saber
     * cuántas cobradas son de cada proveedor, y eso se resuelve así:
     *
     *   1. Si se cobraron todas → las de cada proveedor, tal cual. Exacto.
     *   2. Si hubo un solo proveedor → todas las cobradas son suyas. Exacto.
     *   3. Si hubo dos o más proveedores Y alguna no se cobró → el reparto real no se puede saber
     *      desde acá (¿fallaron las de Google o las de Serper?). 🔴 No se inventa una proporción:
     *      se calcula el TECHO —las cobradas se asignan primero al proveedor más caro— y se marca
     *      `es_techo`. Un techo honesto y dicho es mejor que un promedio que parece medido.
     *
     * Búsquedas que el total cuenta y ningún proveedor reclama (un cliente que informara el total
     * sin abrirlo) van bajo el proveedor `''`, que no tiene precio: no se costean y se nombran. Si
     * no, el costo saldría corto sin que nada lo diga.
     *
     * Misma regla del null que el resto del admin: si ninguna búsqueda cobrada pudo costearse y hay
     * proveedores sin precio, el costo es `null` ("no sé"), no cero. Sin búsquedas, es cero.
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

        /** @var array<string, int> Búsquedas hechas por proveedor, sin ceros ni negativos. */
        $hechas = [];

        foreach ($por_proveedor as $proveedor => $cantidad) {
            $cantidad = (int) $cantidad;

            if ($cantidad <= 0) {
                continue;
            }

            $clave          = strtolower(trim((string) $proveedor));
            $hechas[$clave] = (isset($hechas[$clave]) ? $hechas[$clave] : 0) + $cantidad;
        }

        $atribuidas = array_sum($hechas);
        $total      = $total === null ? $atribuidas : max((int) $total, $atribuidas);

        if ($total > $atribuidas) {
            $hechas[''] = (isset($hechas['']) ? $hechas[''] : 0) + ($total - $atribuidas);
        }

        if ($total === 0) {
            return ['costo_usd' => 0.0, 'es_techo' => false, 'proveedores_sin_precio' => []];
        }

        // Sin el dato de cobradas se asume que se cobraron todas: es la lectura que da el techo.
        $cobradas = $cobradas === null ? $total : min(max((int) $cobradas, 0), $total);

        $es_techo = false;

        /** @var array<string, int> Búsquedas cobradas atribuidas a cada proveedor. */
        $cobradas_de = [];

        if ($cobradas === $total) {
            $cobradas_de = $hechas;
        } elseif (count($hechas) === 1) {
            foreach (array_keys($hechas) as $proveedor) {
                $cobradas_de[$proveedor] = $cobradas;
            }
        } else {
            $es_techo = true;

            /* El más caro primero; los sin precio al final (no suman al costo y así lo que sí tiene
             * precio se lleva todas las cobradas que puede: eso es el techo). A igual precio, por
             * nombre, para que el resultado no dependa del orden en que vino el JSON. */
            $orden = array_keys($hechas);

            usort($orden, function ($a, $b) use ($precios) {
                $precio_a = self::precio_de_busqueda($a, $precios);
                $precio_b = self::precio_de_busqueda($b, $precios);
                $precio_a = $precio_a === null ? -1.0 : $precio_a;
                $precio_b = $precio_b === null ? -1.0 : $precio_b;

                if ($precio_a === $precio_b) {
                    return strcmp((string) $a, (string) $b);
                }

                return $precio_a < $precio_b ? 1 : -1;
            });

            $restantes = $cobradas;

            foreach ($orden as $proveedor) {
                $asignadas               = min($hechas[$proveedor], $restantes);
                $cobradas_de[$proveedor] = $asignadas;
                $restantes              -= $asignadas;
            }
        }

        $costo      = 0.0;
        $costeados  = 0;
        $sin_precio = [];

        foreach ($hechas as $proveedor => $cantidad) {
            $proveedor = (string) $proveedor;
            $precio    = self::precio_de_busqueda($proveedor, $precios);
            $pagadas   = isset($cobradas_de[$proveedor]) ? $cobradas_de[$proveedor] : 0;

            if ($precio === null) {
                /* En el reparto exacto se nombra solo si tuvo búsquedas cobradas (las que no se
                 * cobraron no cuestan nada, con o sin precio). En el techo se nombra siempre: el
                 * reparto no sabe cuántas le tocaron de verdad. */
                if ($pagadas > 0 || $es_techo) {
                    $sin_precio[] = $proveedor;
                }

                continue;
            }

            $costo += ($pagadas * $precio) / self::BUSQUEDAS_POR_UNIDAD_DE_PRECIO;

            if ($pagadas > 0) {
                $costeados++;
            }
        }

        if ($costeados === 0 && $sin_precio !== []) {
            return ['costo_usd' => null, 'es_techo' => $es_techo, 'proveedores_sin_precio' => $sin_precio];
        }

        return ['costo_usd' => $costo, 'es_techo' => $es_techo, 'proveedores_sin_precio' => $sin_precio];
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
     * Un 4xx no se arregla insistiendo: el 404 es la versión vieja del cliente y el 401 es una
     * api_key que no coincide. Los dos dan lo mismo en el segundo intento, y el 404 es el caso
     * MAYORITARIO mientras el parque se actualiza. Un 5xx o un corte de conexión sí pueden ser
     * pasajeros.
     *
     * @param \Throwable $exception Excepción que levantó el cliente HTTP.
     *
     * @return bool
     */
    protected function conviene_reintentar($exception)
    {
        if (! ($exception instanceof RequestException)) {
            // ConnectionException, timeout, DNS: puede ser pasajero.
            return true;
        }

        if ($exception->response === null) {
            return true;
        }

        $status = (int) $exception->response->status();

        return $status < 400 || $status >= 500;
    }

    /**
     * El principio del cuerpo de una respuesta, para citarlo en un mensaje.
     *
     * `mb_substr` y no `substr`: cortar un cuerpo en UTF-8 por bytes puede partir un carácter a la
     * mitad, y un byte suelto hace fallar el `json_encode` de la respuesta del admin (ver
     * `texto_seguro()`, que además limpia lo que ya viniera roto).
     *
     * @param \Illuminate\Http\Client\Response $response Respuesta del cliente.
     *
     * @return string
     */
    protected function extracto_del_cuerpo($response)
    {
        return mb_substr(trim((string) $response->body()), 0, self::CHARS_DE_CUERPO);
    }

    /**
     * Un texto listo para salir en la respuesta del admin: sin la clave del cliente y en UTF-8 válido.
     *
     * 🔴 Tapa la `api_key` del cliente si aparece en el texto. No debería —ningún endpoint del
     * cliente la devuelve—, pero los mensajes de error copian cuerpos que no controlamos, y una
     * clave en la pantalla (o en una captura que alguien manda por WhatsApp) no se puede des-mostrar.
     * Se exige un largo mínimo para no hacer destrozos con una clave de prueba de dos letras.
     *
     * @param string $texto  Texto armado con cosas que devolvió el cliente.
     * @param Client $client Cliente dueño de la clave.
     *
     * @return string
     */
    protected function texto_seguro($texto, Client $client)
    {
        $texto = (string) $texto;
        $clave = trim((string) $client->api_key);

        if (strlen($clave) >= 6) {
            $texto = str_replace($clave, '[clave oculta]', $texto);
        }

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

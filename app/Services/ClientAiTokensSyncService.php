<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientAiTokenUsage;
use App\Models\ClientAiTokenUsagePerson;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Trae del `empresa-api` de un cliente cuántos tokens de IA gastó en un rango de días y los deja
 * espejados en `client_ai_token_usages`.
 *
 * Molde: `ClientMensualidadSyncService` (la llamada saliente) y `ClientScheduleSyncService` (el
 * registro del desenlace en columnas del cliente). De cada uno se toma lo que ya está aprendido:
 *
 * 🔴 **CUATRO REGLAS QUE GOBIERNAN ESTE SERVICIO:**
 *
 *  1. **Nunca lanza.** Esto corre adentro de un barrido de cuarenta y cinco clientes a las 3 de la
 *     mañana: un cliente caído no puede cortar la corrida de los demás. Todos los caminos —los tres
 *     cortes previos y todos los desenlaces del HTTP— terminan escribiendo `ai_tokens_sync_*` en el
 *     cliente y devolviendo.
 *
 *  2. 🔴 **El `catch (RequestException $e) { $response = $e->response; }` NO es opcional.** En
 *     Laravel 8, `->retry()` convierte cualquier respuesta no-2xx en excepción ANTES de que `get()`
 *     devuelva algo (`PendingRequest::send()` llama a `$response->throw()` adentro del retry cuando
 *     `$tries > 1`). Sin recuperar la respuesta real acá, la rama del 404 de más abajo sería código
 *     muerto — el mismo pozo que ya está documentado en `ClientScheduleSyncService.php:146-152` y
 *     en `DeploymentService::step_update_default_version()`.
 *
 *  3. **Cada código de respuesta nombra su causa.** El 404 es el caso ESPERADO —la instancia del
 *     cliente todavía no tiene la ruta y va a seguir así durante semanas—, se registra
 *     `no_soportado`, no se reintenta y no se avisa a nadie: confundirlo con un fallo llenaría la
 *     pestaña de rojos que no hay que arreglar. El 401/403 nombra la `api_key`, y el 409 nombra el
 *     `USER_ID` que le falta al `.env` de ese frente. Los tres son `failed` o `no_soportado`, pero
 *     el estado dice QUÉ pasó y el mensaje dice a DÓNDE ir: un "falló" a secas se lee como "se cayó
 *     la conexión" y manda a nadie a ningún lado.
 *
 *  4. **El upsert reescribe, no acumula.** Es la propiedad que hace que este dato sea reconstruible
 *     desde la fuente en cualquier momento: volver a pedir el mismo rango deja exactamente el mismo
 *     resultado. Se apoya en el unique `(client_id, fecha, proceso, modelo)` de la tabla, o sea que
 *     no depende de que nadie se acuerde de nada.
 */
class ClientAiTokensSyncService
{
    /**
     * Ruta relativa del endpoint de consumo de IA en el `empresa-api` del cliente.
     */
    const CONSUMO_IA_PATH = 'api/admin-sync/consumo-ia';

    /** El cliente contestó y las filas quedaron guardadas. */
    const ESTADO_SUCCESS = 'success';

    /** 404: su versión de `empresa-api` todavía no tiene el endpoint. Es lo esperado, no un fallo. */
    const ESTADO_NO_SOPORTADO = 'no_soportado';

    /** 401/403, 5xx, timeout, o configuración faltante del lado del admin. */
    const ESTADO_FAILED = 'failed';

    /** Caracteres del cuerpo de la respuesta del cliente que se guardan en el mensaje. */
    const CHARS_DE_CUERPO = 300;

    /**
     * 🔴 Techo de días que se le pueden pedir al `empresa-api` en UNA llamada.
     *
     * Es el mismo 62 que valida el endpoint del otro lado: pedirle más devuelve 422 y la
     * recolección queda en `failed`. Vive acá y no en cada llamador porque los dos —el botón
     * "Traer ahora" del controlador y el `--dias` del comando— tienen que respetar el mismo número,
     * y con dos copias una se queda vieja. Ya pasó: el controlador recortaba y el comando no, así
     * que un `--dias=90` dejaba a los cuarenta y cinco clientes en rojo de una sola pasada.
     */
    const MAX_DIAS_POR_PEDIDO = 62;

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
     * Trae el consumo de IA del cliente en el rango pedido y lo espeja localmente.
     *
     * @param Client $client Cliente a consultar.
     * @param string $desde  Primer día del rango, AAAA-MM-DD.
     * @param string $hasta  Último día del rango, AAAA-MM-DD (inclusive).
     *
     * @return array{estado: string, mensaje: string|null, filas: int, sincronizado_at: string|null}
     */
    public function traer_del_cliente(Client $client, $desde, $hasta)
    {
        $url = $this->api_url_resolver->admin_sync_url($client, self::CONSUMO_IA_PATH);

        // Corte 1: sin URL resoluble. Es configuración faltante del admin, no del cliente.
        if ($url === '') {
            return $this->registrar(
                $client,
                self::ESTADO_FAILED,
                'Este cliente no tiene una URL válida de empresa-api configurada (ClientApi activa '
                . 'o api_url legacy).'
            );
        }

        // Corte 2: sin api_key. Mismo motivo.
        if (trim((string) $client->api_key) === '') {
            return $this->registrar(
                $client,
                self::ESTADO_FAILED,
                'El cliente no tiene api_key configurada (tiene que coincidir con '
                . 'ADMIN_API_INBOUND_KEY del empresa-api del cliente).'
            );
        }

        // Respuesta HTTP real (si se pudo obtener) y error de transporte (si no hubo respuesta).
        $response        = null;
        $transport_error = '';

        try {
            $response = Http::withHeaders([
                    'X-Admin-Api-Key' => (string) $client->api_key,
                    'Accept'          => 'application/json',
                ])
                ->timeout((int) config('services.client_api.timeout', 15))
                /* 🔴 El tercer parámetro NO es adorno: sin él se reintenta CUALQUIER no-2xx,
                 * incluido el 404. Y el 404 es el caso esperado durante las semanas en que casi
                 * ningún cliente tiene todavía el endpoint, así que el barrido nocturno haría
                 * noventa requests en vez de cuarenta y cinco para enterarse de lo mismo. Un 4xx
                 * no se arregla insistiendo. */
                ->retry(
                    (int) config('services.client_api.retries', 2),
                    500,
                    function ($exception) {
                        return $this->conviene_reintentar($exception);
                    }
                )
                ->get($url, [
                    'desde' => (string) $desde,
                    'hasta' => (string) $hasta,
                ]);
        } catch (\Illuminate\Http\Client\RequestException $e) {
            // 🔴 Regla 2 del docblock de la clase: sin esto, la rama del 404 es código muerto.
            $response = $e->response;
        } catch (\Throwable $e) {
            // ConnectionException, timeout, DNS: no hay respuesta HTTP asociada.
            $transport_error = $e->getMessage();
        }

        if ($response !== null && $response->status() === 404) {
            /* 🔴 Regla 3: el 404 es la versión vieja del cliente. No se reintenta y NO se toca
             * `ai_tokens_synced_at`: lo que ya se haya traído alguna vez sigue siendo válido. */
            return $this->registrar(
                $client,
                self::ESTADO_NO_SOPORTADO,
                'La versión instalada del empresa-api de este cliente todavía no tiene el endpoint '
                . 'admin-sync/consumo-ia. Los tokens van a empezar a viajar solos cuando el cliente '
                . 'se actualice.'
            );
        }

        if ($response !== null && $response->status() === 409) {
            /* 🔴 El 409 tiene mensaje propio y no cae en el cajón genérico, aunque el estado siga
             * siendo `failed`.
             *
             * El proveedor lo devuelve cuando el `.env` del frente de ese cliente no tiene
             * `USER_ID` y la base tiene más de un comercio adentro (`u767360347_empresa` tiene 51).
             * O sea: NO está viejo y NO se cayó la conexión — está MAL CONFIGURADO, y se arregla
             * cargando una línea en un archivo. Un `failed` genérico se lee como "no se pudo
             * conectar" y nadie va a ir a mirar el `.env` de ese frente; el estado dice qué pasó,
             * pero el que manda a alguien a arreglarlo es el mensaje.
             *
             * No lleva un cuarto estado porque para el admin el desenlace es el mismo que cualquier
             * otro fallo —no hay dato y hay que volver— y un estado más obliga a tocar la solapa,
             * los filtros y el comando para distinguir algo que ya distingue el texto. */
            return $this->registrar(
                $client,
                self::ESTADO_FAILED,
                'A este cliente le falta `USER_ID` en el .env de su frente activo. Su empresa-api '
                . 'comparte la base con otros comercios y sin esa variable no sabe de cuál informar, '
                . 'así que se niega a adivinar (HTTP 409). No es una versión vieja ni un problema de '
                . 'red: se resuelve cargando USER_ID en ese .env y volviendo a traer. Respuesta del '
                . 'cliente: ' . substr(trim((string) $response->body()), 0, self::CHARS_DE_CUERPO)
            );
        }

        if ($response !== null && ! $response->successful()) {
            $mensaje = 'El empresa-api del cliente respondió HTTP ' . $response->status() . ': '
                . substr((string) $response->body(), 0, self::CHARS_DE_CUERPO);

            if ($response->status() === 401 || $response->status() === 403) {
                $mensaje .= ' Probablemente la api_key del cliente no coincide con '
                    . 'ADMIN_API_INBOUND_KEY del empresa-api.';
            }

            return $this->registrar($client, self::ESTADO_FAILED, $mensaje);
        }

        if ($response === null) {
            Log::warning('ClientAiTokensSyncService: no se pudo contactar al empresa-api del cliente.', [
                'client_id' => $client->id,
                'url'       => $url,
                'error'     => $transport_error,
            ]);

            return $this->registrar(
                $client,
                self::ESTADO_FAILED,
                'No se pudo contactar al empresa-api del cliente en ' . $url . ': ' . $transport_error
            );
        }

        $data = $response->json();

        /* 🔴 Un 200 NO alcanza para dar el dato por bueno: hay que reconocer la FORMA del payload.
         *
         * `Response::json()` en Laravel 8 es un `json_decode` pelado, así que un cuerpo que no
         * parsea devuelve null. Y el 200 con un cuerpo que no es el nuestro no es hipotético: el
         * shared hosting de Hostinger sirve su página genérica CON HTTP 200 cuando la cuenta está
         * saturada, cosa que ya nos pasó y está documentada. Sin este corte, ese HTML quedaría
         * como `success` con la fecha estampada, cero filas escritas, y la solapa diría "Traído el
         * 18/09 03:15" mostrando cero tokens — que es EXACTAMENTE indistinguible de un cliente que
         * no gastó nada. La columna de estado existe para que esas dos cosas no se parezcan.
         *
         * Se exige `dias` con `array_key_exists` y no con `isset`: un `"dias": null` también es un
         * payload que no sirve, e `isset` lo dejaría pasar como si no estuviera. */
        if (! is_array($data) || ! array_key_exists('dias', $data) || ! is_array($data['dias'])) {
            return $this->registrar(
                $client,
                self::ESTADO_FAILED,
                'El cliente respondió HTTP ' . $response->status() . ' pero el cuerpo no es el '
                . 'payload de consumo (falta el bloque `dias`). Suele ser la página genérica del '
                . 'hosting cuando la cuenta está saturada. Empieza así: '
                . substr(trim((string) $response->body()), 0, self::CHARS_DE_CUERPO)
            );
        }

        $dias = $data['dias'];

        /* 🔴 Un payload sin el bloque `personas` SÍ es aceptable, a diferencia de `dias`: es un
         * cliente con una versión intermedia, que informa los días y todavía no informa quién los
         * gastó. Se guardan los días y listo — mismo criterio que el 404 para el endpoint entero. */
        $personas = isset($data['personas']) && is_array($data['personas']) ? $data['personas'] : [];

        try {
            $filas = $this->espejar($client, $dias, $personas, $desde, $hasta);
        } catch (\Throwable $e) {
            /* Que una fila venga mal formada no puede tumbar el barrido. La transacción de
             * `espejar()` ya hizo rollback, así que el cliente queda como estaba. */
            Log::warning('ClientAiTokensSyncService: falló el guardado del consumo del cliente.', [
                'client_id' => $client->id,
                'error'     => $e->getMessage(),
            ]);

            return $this->registrar(
                $client,
                self::ESTADO_FAILED,
                'El cliente contestó bien pero no se pudo guardar su consumo: ' . $e->getMessage()
            );
        }

        return $this->registrar($client, self::ESTADO_SUCCESS, null, true, $filas);
    }

    /**
     * Vuelca las filas del payload a `client_ai_token_usages`, reescribiendo lo que ya estuviera.
     *
     * 🔴 `updateOrCreate` sobre la clave única y NADA de borrar el rango antes. No hace falta y
     * sería peligroso: `ai_token_usages` del lado del cliente es append-only, así que el agregado
     * de un día solo puede crecer — nunca encogerse ni desaparecer. Borrar primero dejaría al
     * cliente sin datos durante la transacción y, si el payload llegara recortado por cualquier
     * motivo, perdería lo que ya estaba bien.
     *
     * Todo adentro de UNA transacción por cliente: un rango se guarda entero o no se guarda. Los
     * dos cortes —por acción y por persona— viajan en la misma transacción a propósito: son dos
     * vistas del mismo hecho y no puede quedar una escrita y la otra no.
     *
     * 🔴 **Una fila con una `fecha` fuera del rango pedido se descarta.** Sin este corte, un
     * cliente que contesta de más (por un bug propio o por una fecha mal calculada) deja una fila
     * que NINGUNA corrida futura vuelve a tocar: el upsert solo pisa lo que la fuente vuelve a
     * informar, y el admin nunca pide ese día. Esa fila queda para siempre y el espejo deja de ser
     * reconstruible desde la fuente, que es lo único que justifica que esta tabla exista.
     *
     * @param Client                           $client   Cliente dueño del consumo.
     * @param array<int, array<string, mixed>> $dias     Bloque `dias` del payload del cliente.
     * @param array<int, array<string, mixed>> $personas Bloque `personas`; vacío si el cliente no lo manda.
     * @param string                           $desde    Primer día pedido, AAAA-MM-DD.
     * @param string                           $hasta    Último día pedido, AAAA-MM-DD.
     *
     * @return int Cantidad de filas efectivamente escritas, sumando los dos cortes.
     */
    protected function espejar(Client $client, array $dias, array $personas, $desde, $hasta)
    {
        $escritas = 0;

        DB::transaction(function () use ($client, $dias, $personas, $desde, $hasta, &$escritas) {
            /* 🔴 Se cuentan las CLAVES distintas que se escribieron, no las vueltas del bucle. Con
             * un contador por iteración, dos filas del payload que caen en la misma clave informan
             * "2 filas" cuando en la base entró una sola — y ese número es lo que ve el operador
             * cuando aprieta "Traer ahora". */
            $claves_escritas = [];

            foreach ($dias as $fila) {
                if (! is_array($fila)) {
                    continue;
                }

                $fecha = $this->fecha_usable($fila, $desde, $hasta);

                if ($fecha === null) {
                    continue;
                }

                /* 🔴 Las cinco dimensiones, las mismas por las que agrupa el origen. `proveedor`
                 * TIENE que estar en la clave: si viaja en los valores, dos filas del mismo payload
                 * con el mismo modelo y distinto proveedor colapsan en una, y la que queda tiene los
                 * contadores de la última en vez de la suma.
                 *
                 * Ninguna viaja como null hacia la base: en MySQL un NULL no colisiona con otro
                 * NULL dentro de un índice único, y eso solo alcanzaría para que el upsert deje de
                 * ser idempotente sin que nada avise. */
                $clave = [
                    'client_id' => (int) $client->id,
                    'fecha'     => $fecha,
                    'proceso'   => (string) (isset($fila['proceso']) ? $fila['proceso'] : ''),
                    'proveedor' => (string) (isset($fila['proveedor']) && $fila['proveedor'] !== null ? $fila['proveedor'] : 'anthropic'),
                    'modelo'    => (string) (isset($fila['modelo']) ? $fila['modelo'] : ''),
                ];

                ClientAiTokenUsage::updateOrCreate($clave, [
                    'llamadas'                    => (int) (isset($fila['llamadas']) ? $fila['llamadas'] : 0),
                    'input_tokens'                => (int) (isset($fila['input_tokens']) ? $fila['input_tokens'] : 0),
                    'output_tokens'               => (int) (isset($fila['output_tokens']) ? $fila['output_tokens'] : 0),
                    'cache_creation_input_tokens' => (int) (isset($fila['cache_creation_input_tokens']) ? $fila['cache_creation_input_tokens'] : 0),
                    'cache_read_input_tokens'     => (int) (isset($fila['cache_read_input_tokens']) ? $fila['cache_read_input_tokens'] : 0),
                ]);

                $claves_escritas[implode('|', $clave)] = true;
            }

            $escritas = count($claves_escritas) + $this->espejar_personas($client, $personas, $desde, $hasta);
        });

        return $escritas;
    }

    /**
     * La fecha de una fila del payload, ya normalizada, o null si no sirve.
     *
     * Dos motivos para descartar, y los dos terminan igual —se saltea la fila y el resto del
     * payload se guarda—: que no traiga fecha (no se puede ubicar en el tiempo) o que caiga fuera
     * del rango pedido (ver el docblock de `espejar()`).
     *
     * @param array<string, mixed> $fila  Fila del payload.
     * @param string               $desde Primer día pedido, AAAA-MM-DD.
     * @param string               $hasta Último día pedido, AAAA-MM-DD.
     *
     * @return string|null Fecha AAAA-MM-DD, o null si la fila se descarta.
     */
    protected function fecha_usable(array $fila, $desde, $hasta)
    {
        $fecha = trim((string) (isset($fila['fecha']) ? $fila['fecha'] : ''));

        if ($fecha === '') {
            return null;
        }

        $fecha = substr($fecha, 0, 10);

        /* Comparación de strings y no de fechas: el formato AAAA-MM-DD ordena igual como texto que
         * como fecha, y así una fecha basura ('0000-00-00', 'ayer') queda afuera sola en vez de
         * hacer explotar un parser. */
        if ($fecha < (string) $desde || $fecha > (string) $hasta) {
            return null;
        }

        return $fecha;
    }

    /**
     * Si conviene reintentar una llamada que falló.
     *
     * Un 4xx no se arregla insistiendo: el 404 es la versión vieja del cliente y el 401 es una
     * api_key que no coincide. Los dos van a dar exactamente lo mismo en el segundo intento, y el
     * 404 es el caso MAYORITARIO mientras el parque se actualiza. Un 5xx o un corte de conexión sí
     * pueden ser pasajeros.
     *
     * @param \Throwable $exception Excepción que levantó el cliente HTTP.
     *
     * @return bool
     */
    protected function conviene_reintentar($exception)
    {
        if (! ($exception instanceof \Illuminate\Http\Client\RequestException)) {
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
     * Vuelca el bloque `personas` a `client_ai_token_usage_people`, con el mismo upsert idempotente.
     *
     * 🔴 **Una fila sin `fecha`, o con una fecha fuera del rango pedido, se descarta.** Sin día no
     * hay dónde ubicarla, y el `fecha` es parte de la clave única: meterla con una fecha inventada
     * (la de hoy, la del extremo del rango) haría que la corrida siguiente, con otro rango, la pise
     * con un agregado distinto — o sea, el acumulador desincronizado en silencio que esta parte
     * entera existe para no tener. Y una fila fuera del rango queda para siempre, porque ninguna
     * corrida futura la vuelve a pedir. Se descartan y el resto del payload se guarda igual.
     *
     * 🔴 **`auth_user_id` nulo se guarda como 0**, el centinela de "procesos automáticos". Ver la
     * migración: un NULL no colisiona consigo mismo adentro de un índice único, y esa fila es
     * justamente la que se repite todos los días.
     *
     * Corre adentro de la transacción que abrió `espejar()`: no abre una propia.
     *
     * @param Client                           $client   Cliente dueño del consumo.
     * @param array<int, array<string, mixed>> $personas Bloque `personas` del payload.
     * @param string                           $desde    Primer día pedido, AAAA-MM-DD.
     * @param string                           $hasta    Último día pedido, AAAA-MM-DD.
     *
     * @return int Cantidad de claves distintas escritas.
     */
    protected function espejar_personas(Client $client, array $personas, $desde, $hasta)
    {
        // Mismo criterio que en `espejar()`: se cuentan claves escritas, no vueltas del bucle.
        $claves_escritas = [];

        foreach ($personas as $fila) {
            if (! is_array($fila)) {
                continue;
            }

            $fecha = $this->fecha_usable($fila, $desde, $hasta);

            if ($fecha === null) {
                continue;
            }

            /* Nulo, vacío o ausente son todos lo mismo acá: no hubo persona detrás. `(int) null`
             * da 0, que es exactamente el centinela, pero se escribe explícito para que se lea. */
            $auth_user_id = isset($fila['auth_user_id']) && $fila['auth_user_id'] !== null
                ? (int) $fila['auth_user_id']
                : ClientAiTokenUsagePerson::AUTOMATICO;

            $clave = [
                'client_id'    => (int) $client->id,
                'fecha'        => $fecha,
                'auth_user_id' => $auth_user_id,
            ];

            $nombre = trim((string) (isset($fila['nombre']) ? $fila['nombre'] : ''));

            ClientAiTokenUsagePerson::updateOrCreate($clave, [
                // Vacío se guarda como null: "el cliente no informó el nombre" no es "se llama ''".
                'nombre'                      => $nombre === '' ? null : mb_substr($nombre, 0, 120),
                'llamadas'                    => (int) (isset($fila['llamadas']) ? $fila['llamadas'] : 0),
                'input_tokens'                => (int) (isset($fila['input_tokens']) ? $fila['input_tokens'] : 0),
                'output_tokens'               => (int) (isset($fila['output_tokens']) ? $fila['output_tokens'] : 0),
                'cache_creation_input_tokens' => (int) (isset($fila['cache_creation_input_tokens']) ? $fila['cache_creation_input_tokens'] : 0),
                'cache_read_input_tokens'     => (int) (isset($fila['cache_read_input_tokens']) ? $fila['cache_read_input_tokens'] : 0),
            ]);

            $claves_escritas[implode('|', $clave)] = true;
        }

        return count($claves_escritas);
    }

    /**
     * Persiste el desenlace en el cliente y lo devuelve.
     *
     * `ai_tokens_synced_at` SOLO se toca cuando hubo éxito: un fallo posterior no puede borrar la
     * información de hasta cuándo el dato es confiable.
     *
     * @param Client      $client  Cliente a marcar.
     * @param string      $estado  success | no_soportado | failed.
     * @param string|null $mensaje Motivo, cuando no es success.
     * @param bool        $exitoso Si además hay que estampar `ai_tokens_synced_at`.
     * @param int         $filas   Filas escritas, para que el llamador lo muestre.
     *
     * @return array{estado: string, mensaje: string|null, filas: int, sincronizado_at: string|null}
     */
    protected function registrar(Client $client, $estado, $mensaje = null, $exitoso = false, $filas = 0)
    {
        $cambios = [
            'ai_tokens_sync_status'  => $estado,
            'ai_tokens_sync_message' => $mensaje,
        ];

        if ($exitoso) {
            $cambios['ai_tokens_synced_at'] = Carbon::now();
        }

        $client->update($cambios);

        return [
            'estado'          => $estado,
            'mensaje'         => $mensaje,
            'filas'           => (int) $filas,
            'sincronizado_at' => $client->ai_tokens_synced_at === null
                ? null
                : (string) $client->ai_tokens_synced_at,
        ];
    }
}

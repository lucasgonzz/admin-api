<?php

namespace App\Services;

use App\Models\Client;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Lee y cambia EN VIVO qué modelo de IA usa cada tarea en el `empresa-api` de un cliente (misión
 * modelos-ia-por-cliente, 30/9/2026): el asistente del dueño, el bot de WhatsApp que atiende a sus
 * clientes, la verificación de imágenes y la importación de Excel.
 *
 * Molde: `ClientAiPlanSyncService` (URL, header, timeout/retry y el `catch` de Laravel 8) y la
 * lectura en vivo de `ClientImageSearchLogService` (resultado `{estado, mensaje, datos}`, sin
 * seguir redirecciones, sin reintentar un timeout y con la clave tapada en los mensajes).
 *
 * 🔴 **El admin NO guarda nada de esto** (decisión 2 de Lucas: "los dos, gana el último"). El dueño
 * sigue pudiendo cambiar su asistente desde "Configurá tu asistente" en su sistema, y lo que
 * quedó escrito allá es la verdad. Un espejo local en el admin quedaría viejo la primera vez que el
 * dueño toque el modal, y la solapa mostraría una elección que ya no corre. Por eso este servicio
 * no tiene columnas `*_sync_*` ni `registrar()`: cada apertura de la solapa pregunta, y cada
 * guardado manda y muestra lo que el cliente devolvió.
 *
 * 🔴 **CUATRO REGLAS QUE GOBIERNAN ESTE SERVICIO** (las mismas del molde):
 *
 *  1. **Nunca lanza.** Todos los desenlaces —los dos cortes previos y todo lo del HTTP— terminan en
 *     un resultado `{estado, mensaje, datos}`. La solapa lo dibuja; no hay 500 que ver.
 *
 *  2. 🔴 **El `catch (RequestException $e) { $response = $e->response; }` NO es opcional.** En
 *     Laravel 8, `->retry()` convierte cualquier no-2xx en excepción ANTES de que `get()`/`put()`
 *     devuelvan algo. Sin recuperar la respuesta real acá, las ramas del 404, 401, 409 y 422 de
 *     más abajo serían código muerto y todo saldría como "no se pudo contactar".
 *
 *  3. **Cada código nombra su causa.** El 404 es el caso ESPERADO mientras el parque se actualiza
 *     (la instancia todavía no tiene `admin-sync/modelos-ia`) y es `no_soportado`, no un fallo. El
 *     401/403 nombra la `api_key`, el 409 nombra el `USER_ID` que falta en el `.env` del frente, y el
 *     422 trae el motivo que dio el cliente (una opción que no existe o no vale para esa tarea).
 *
 *  4. **Un 200 no alcanza.** El shared de Hostinger sirve su página genérica CON 200 cuando la cuenta
 *     está saturada. Sin exigir `ok:true` y el bloque `tareas`, ese HTML se mostraría como "cargado"
 *     y la solapa quedaría vacía sin decir por qué.
 */
class ClientModelosIaSyncService
{
    /**
     * Ruta relativa del endpoint del contrato en el `empresa-api` del cliente (GET y PUT).
     */
    const MODELOS_IA_PATH = 'api/admin-sync/modelos-ia';

    /**
     * Las cuatro tareas del contrato, con los nombres EXACTOS que lee el otro lado.
     *
     * 🔴 Son claves del contrato: el `empresa-api` las lee del body del PUT con estos nombres y las
     * devuelve bajo `tareas.<nombre>`. Renombrar una acá es exactamente el pozo donde este proyecto
     * ya se quemó (`manual_tasks` vs `tareas`): el otro lado no la ve, no toca nada y contesta 200.
     */
    const TAREAS = ['asistente', 'whatsapp', 'imagenes', 'excel'];

    /** El cliente contestó 200 con el payload del contrato. */
    const ESTADO_SUCCESS = 'success';

    /** 404: su versión de `empresa-api` todavía no tiene la ruta. Es lo esperado, no un fallo. */
    const ESTADO_NO_SOPORTADO = 'no_soportado';

    /** 401/403, 409, 422, 5xx, timeout, 200 sin el payload, o configuración faltante del admin. */
    const ESTADO_FAILED = 'failed';

    /** Caracteres del cuerpo de la respuesta del cliente que se citan en un mensaje. */
    const CHARS_DE_CUERPO = 300;

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
     * Trae del cliente qué opción tiene elegida cada tarea y qué corre de verdad.
     *
     * @param Client $client Cliente a consultar.
     *
     * @return array{estado: string, mensaje: string|null, datos: array|null, errores: array|null}
     */
    public function traer(Client $client)
    {
        return $this->pedir($client, 'GET', []);
    }

    /**
     * Manda al cliente las tareas que cambiaron y devuelve cómo quedaron todas.
     *
     * 🔴 Viajan SOLO las claves de `self::TAREAS` que vinieron en `$cambios`, y nada más. Por
     * contrato, una clave ausente no se toca del otro lado: es lo que hace que guardar el WhatsApp
     * desde el admin no le pise al dueño el asistente que acaba de cambiar desde su modal ("gana el
     * último" vale tarea por tarea, no para las cuatro juntas). Una clave desconocida no viaja: el
     * catálogo de opciones y sus reglas viven en el `empresa-api` (que contesta 422 si una opción no
     * vale), y el admin no las duplica para no quedar desincronizado el día que se sume un modelo.
     *
     * @param Client               $client   Cliente a cambiar.
     * @param array<string, mixed> $cambios  Opción elegida por tarea (`['whatsapp' => 'deepseek_flash']`).
     *
     * @return array{estado: string, mensaje: string|null, datos: array|null, errores: array|null}
     */
    public function enviar(Client $client, array $cambios)
    {
        $cuerpo = [];

        foreach (self::TAREAS as $tarea) {
            if (array_key_exists($tarea, $cambios) && $cambios[$tarea] !== null) {
                $cuerpo[$tarea] = (string) $cambios[$tarea];
            }
        }

        return $this->pedir($client, 'PUT', $cuerpo);
    }

    /**
     * La llamada saliente, común al GET y al PUT: cortes previos, HTTP y cada desenlace.
     *
     * @param Client               $client Cliente.
     * @param string               $metodo GET | PUT.
     * @param array<string, mixed> $cuerpo Body del PUT (ignorado en el GET).
     *
     * @return array{estado: string, mensaje: string|null, datos: array|null, errores: array|null}
     */
    protected function pedir(Client $client, $metodo, array $cuerpo)
    {
        $url = $this->api_url_resolver->admin_sync_url($client, self::MODELOS_IA_PATH);

        // Corte 1: sin URL resoluble. Es configuración faltante del admin, no del cliente.
        if ($url === '') {
            return $this->resultado(
                self::ESTADO_FAILED,
                'Este cliente no tiene una URL válida de empresa-api configurada (ClientApi activa '
                . 'o api_url legacy).'
            );
        }

        // Corte 2: sin api_key. Mismo motivo: sin credencial no hay a quién preguntarle.
        if (trim((string) $client->api_key) === '') {
            return $this->resultado(
                self::ESTADO_FAILED,
                'El cliente no tiene api_key configurada (tiene que coincidir con '
                . 'ADMIN_API_INBOUND_KEY del empresa-api del cliente).'
            );
        }

        // Respuesta HTTP real (si se pudo obtener) y error de transporte (si no hubo respuesta).
        $response        = null;
        $transport_error = '';

        try {
            $pedido = Http::withHeaders([
                    'X-Admin-Api-Key' => (string) $client->api_key,
                    'Accept'          => 'application/json',
                ])
                /* 🔴 Sin seguir redirecciones (mismo motivo que `ClientImageSearchLogService`):
                 * Guzzle, al redirigir a otro host, saca `Authorization` pero NO un header propio
                 * como `X-Admin-Api-Key`, y la clave del cliente viajaría a donde apunte la
                 * redirección. Un 3xx cae abajo como una respuesta no exitosa más. */
                ->withOptions(['allow_redirects' => false])
                ->timeout((int) config('services.client_api.timeout', 15))
                /* 🔴 El tercer parámetro decide qué se reintenta (ver `conviene_reintentar()`): ni
                 * un 4xx ni un timeout. El PUT es idempotente (mandar la misma opción dos veces deja
                 * al cliente igual), así que reintentar un 5xx no duplica nada. */
                ->retry(
                    (int) config('services.client_api.retries', 2),
                    500,
                    function ($exception) {
                        return $this->conviene_reintentar($exception);
                    }
                );

            $response = $metodo === 'PUT'
                ? $pedido->put($url, $cuerpo)
                : $pedido->get($url);
        } catch (RequestException $e) {
            // 🔴 Regla 2 del docblock de la clase: sin esto, las ramas de abajo son código muerto.
            $response = $e->response;
        } catch (\Throwable $e) {
            // ConnectionException, timeout, DNS: no hay respuesta HTTP asociada.
            $transport_error = $e->getMessage();
        }

        if ($response === null) {
            // Sin la clave en el log: la URL y el error de transporte alcanzan para diagnosticar.
            Log::warning('ClientModelosIaSyncService: no se pudo contactar al empresa-api del cliente.', [
                'client_id' => $client->id,
                'metodo'    => $metodo,
                'url'       => $url,
                'error'     => $this->tapar_clave($transport_error, $client),
            ]);

            return $this->resultado(
                self::ESTADO_FAILED,
                $this->texto_seguro(
                    'No se pudo contactar al empresa-api del cliente en ' . $url . ': ' . $transport_error,
                    $client
                )
            );
        }

        $status = (int) $response->status();

        if ($status === 404) {
            /* 🔴 Regla 3: el 404 es la versión vieja del cliente. No se reintentó y se dice con un
             * texto que no suena a falla, porque no lo es: se arregla actualizando el cliente. */
            return $this->resultado(
                self::ESTADO_NO_SOPORTADO,
                'Este cliente tiene una versión anterior del sistema: su empresa-api todavía no '
                . 'conoce admin-sync/modelos-ia. Se va a poder elegir después de actualizarlo.'
            );
        }

        if ($status === 409) {
            /* El 409 tiene mensaje propio aunque sea `failed`: el cliente vive en una base compartida
             * y su `.env` no tiene `USER_ID`, así que no sabe de qué dueño leer o escribir los modelos
             * y se niega a adivinar. No es versión vieja ni red: es una línea en un archivo. */
            return $this->resultado(
                self::ESTADO_FAILED,
                $this->texto_seguro(
                    'A este cliente le falta `USER_ID` en el .env de su frente activo. Su empresa-api '
                    . 'comparte la base con otros comercios y sin esa variable no sabe a qué dueño '
                    . 'leerle o guardarle los modelos de IA, así que se niega a adivinar (HTTP 409). '
                    . 'Se resuelve cargando USER_ID en ese .env. Respuesta del cliente: '
                    . $this->mensaje_del_cuerpo($response, $client),
                    $client
                )
            );
        }

        if ($status === 422) {
            /* El cliente rechazó una opción (no existe, o no vale para esa tarea: DeepSeek Pro no ve
             * imágenes). Su catálogo es la fuente de verdad, así que su motivo es el mensaje, y los
             * errores por tarea viajan aparte para que la solapa marque la fila que corresponde. */
            $errores = $this->errores_por_tarea($response);

            return $this->resultado(
                self::ESTADO_FAILED,
                $this->texto_seguro(
                    'El sistema del cliente rechazó el cambio (HTTP 422): ' . $this->motivo_del_422($response, $client),
                    $client
                ),
                null,
                $errores
            );
        }

        if (! $response->successful()) {
            $mensaje = 'El empresa-api del cliente respondió HTTP ' . $status . ': '
                . $this->extracto_del_cuerpo($response, $client);

            if ($status === 401 || $status === 403) {
                $mensaje .= ' Probablemente la api_key del cliente no coincide con '
                    . 'ADMIN_API_INBOUND_KEY del empresa-api.';
            }

            if ($status >= 300 && $status < 400) {
                $mensaje .= ' Es una redirección: el admin no la sigue, para no mandarle la clave del '
                    . 'cliente a otra dirección. Revisá la URL de la API cargada en el cliente.';
            }

            return $this->resultado(self::ESTADO_FAILED, $this->texto_seguro($mensaje, $client));
        }

        /* 🔴 Regla 4: un 200 tiene que traer `ok:true` y el bloque `tareas` del contrato. Si no, es
         * la página genérica del hosting (o un endpoint que no es este), y se dice. */
        $datos = $response->json();

        if (! is_array($datos)
            || ! array_key_exists('ok', $datos)
            || ! $datos['ok']
            || ! isset($datos['tareas'])
            || ! is_array($datos['tareas'])
        ) {
            return $this->resultado(
                self::ESTADO_FAILED,
                $this->texto_seguro(
                    'El cliente respondió HTTP ' . $status . ' pero el cuerpo no es el de los modelos '
                    . 'de IA (falta `ok:true` o `tareas`). Suele ser la página genérica del hosting '
                    . 'cuando la cuenta está saturada. Empieza así: '
                    . $this->extracto_del_cuerpo($response, $client),
                    $client
                )
            );
        }

        return $this->resultado(self::ESTADO_SUCCESS, null, [
            'opciones' => isset($datos['opciones']) && is_array($datos['opciones']) ? $datos['opciones'] : [],
            'tareas'   => $datos['tareas'],
        ]);
    }

    /**
     * Si conviene reintentar una llamada que falló.
     *
     * - Un 4xx no se arregla insistiendo (404 versión vieja, 401 clave, 409 USER_ID, 422 opción).
     * - 🔴 Un TIMEOUT tampoco: la lectura es en vivo, con Lucas mirando la solapa, y el reintento
     *   duplicaría la espera para enterarse casi siempre de lo mismo.
     * - Un 5xx o una falla RÁPIDA de conexión sí: cuestan medio segundo y pueden ser un parpadeo.
     *
     * El criterio vive en `ReintentosDeHttpSaliente::para_lectura_en_vivo()`, compartido con otros
     * services; acá solo se delega.
     *
     * @param \Throwable $exception Excepción que levantó el cliente HTTP.
     *
     * @return bool
     */
    protected function conviene_reintentar($exception)
    {
        return ReintentosDeHttpSaliente::para_lectura_en_vivo($exception);
    }

    /**
     * Los errores de un 422 agrupados por tarea (`{whatsapp: ['...']}`), tal cual los mandó el
     * cliente pero solo para las claves del contrato.
     *
     * @param \Illuminate\Http\Client\Response $response Respuesta 422 del cliente.
     *
     * @return array<string, array<int, string>>|null Null si el cuerpo no trae `errors` por tarea.
     */
    protected function errores_por_tarea($response)
    {
        $cuerpo = $response->json();

        if (! is_array($cuerpo) || ! isset($cuerpo['errors']) || ! is_array($cuerpo['errors'])) {
            return null;
        }

        $errores = [];

        foreach (self::TAREAS as $tarea) {
            if (! isset($cuerpo['errors'][$tarea])) {
                continue;
            }

            $mensajes = is_array($cuerpo['errors'][$tarea])
                ? $cuerpo['errors'][$tarea]
                : [$cuerpo['errors'][$tarea]];

            foreach ($mensajes as $mensaje) {
                if (is_scalar($mensaje) && trim((string) $mensaje) !== '') {
                    $errores[$tarea][] = trim((string) $mensaje);
                }
            }
        }

        return $errores === [] ? null : $errores;
    }

    /**
     * El motivo de un 422 del cliente: los mensajes de `errors` (el `message` genérico de Laravel no
     * dice nada), o `message` si no hay `errors`, o el principio del cuerpo.
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
     * El `message` de un cuerpo JSON (`{message}` es la forma del 409 por contrato), legible y con la
     * clave tapada; si no hay, el principio del cuerpo crudo.
     *
     * Existe porque citar el JSON crudo deja los acentos como `ñ` ("dueño"): el mensaje
     * del cliente es para leerlo en la solapa, no para parsearlo.
     *
     * @param \Illuminate\Http\Client\Response $response Respuesta del cliente.
     * @param Client                           $client   Cliente dueño de la clave.
     *
     * @return string
     */
    protected function mensaje_del_cuerpo($response, Client $client)
    {
        $cuerpo = $response->json();

        if (is_array($cuerpo) && isset($cuerpo['message']) && is_scalar($cuerpo['message']) && trim((string) $cuerpo['message']) !== '') {
            return mb_substr($this->tapar_clave(trim((string) $cuerpo['message']), $client), 0, self::CHARS_DE_CUERPO);
        }

        return $this->extracto_del_cuerpo($response, $client);
    }

    /**
     * El principio del cuerpo de una respuesta, con la clave del cliente ya tapada.
     *
     * Se tapa sobre el cuerpo ENTERO y después se recorta: al revés, una clave partida en el borde
     * del recorte no coincidiría con nada y saldría a la vista a medias. `mb_substr` para no partir
     * un carácter UTF-8 al medio.
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
     * Tapa la clave del cliente en un texto (tal cual, escapada como JSON y codificada como URL).
     *
     * Ningún endpoint del cliente la devuelve, pero los mensajes citan cuerpos que no controlamos y
     * una clave en la pantalla no se puede des-mostrar. Mismo criterio que `ClientImageSearchLogService`.
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

        // Una clave de prueba de dos letras no se tapa: haría destrozos en cualquier texto.
        if (strlen($clave) < 6) {
            return $texto;
        }

        $formas = [$clave];

        $en_json = json_encode($clave);

        if (is_string($en_json) && strlen($en_json) >= 2) {
            $formas[] = substr($en_json, 1, -1);
        }

        $formas[] = rawurlencode($clave);
        $formas[] = urlencode($clave);

        $formas = array_values(array_unique($formas));

        // La más larga primero, para no dejar pedazos de una forma que contiene a otra.
        usort($formas, function ($a, $b) {
            return strlen($b) - strlen($a);
        });

        return str_replace($formas, '[clave oculta]', $texto);
    }

    /**
     * Un texto listo para la respuesta del admin: sin la clave y en UTF-8 válido (un byte suelto
     * de un cuerpo en latin1 haría fallar el `json_encode` de la respuesta).
     *
     * @param string $texto  Texto armado con cosas que devolvió el cliente.
     * @param Client $client Cliente dueño de la clave.
     *
     * @return string
     */
    protected function texto_seguro($texto, Client $client)
    {
        return mb_convert_encoding($this->tapar_clave($texto, $client), 'UTF-8', 'UTF-8');
    }

    /**
     * Arma el resultado común del GET y del PUT.
     *
     * @param string                                  $estado  success | no_soportado | failed.
     * @param string|null                             $mensaje Motivo, cuando no es success.
     * @param array|null                              $datos   `{opciones, tareas}` del cliente, cuando es success.
     * @param array<string, array<int, string>>|null  $errores Errores del 422, por tarea.
     *
     * @return array{estado: string, mensaje: string|null, datos: array|null, errores: array|null}
     */
    protected function resultado($estado, $mensaje = null, $datos = null, $errores = null)
    {
        return [
            'estado'  => $estado,
            'mensaje' => $mensaje,
            'datos'   => $datos,
            'errores' => $errores,
        ];
    }
}

<?php

namespace App\Services;

use App\Models\Client;
use App\Models\WhatsappConfig;
use App\Services\Concerns\TapaLaClaveDelCliente;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Lee y provisiona la CONEXIÓN de WhatsApp (Kapso) del empresa-api de UN cliente, para que las credenciales las ponga el admin
 * y no pasen nunca por el navegador del dueño (misión `kapso-secretos-fuera-del-navegador`, 17/11/2026, contrato A del plan).
 *
 * Contrato (empresa-api), puerta `admin-sync` con `admin.api.key:siempre`:
 *
 *  - `GET {frente activo}/api/admin-sync/whatsapp-bot/conexion`
 *  - `PUT {frente activo}/api/admin-sync/whatsapp-bot/conexion` body `{phone_number_id?, kapso_api_key?, webhook_secret?, is_active?}`
 *
 * Los dos con el header `X-Admin-Api-Key` = `clients.api_key` y la misma respuesta 200, SIN secretos jamás:
 * `{ok: true, conexion: {existe, phone_number_id, kapso_api_key_configurada, webhook_secret_configurado, conexion_configurada, is_active}}`.
 * Códigos: 401 = clave rechazada (o instancia sin clave cargada), 409 = la instancia no sabe quién es su dueño, 422 = el guard del
 * cliente rechazó el pedido (p. ej. activar sin las tres credenciales), 404/405 = empresa con versión vieja (la ruta no existe todavía).
 *
 * Molde: `ClientAgentesIaService` (mismos estados, mismo trait, sin seguir redirecciones). Estados: `ok`, `version_vieja`, `sin_clave`,
 * `clave_rechazada`, `sin_frente`, `sin_conexion`, y dos propios: `sin_kapso` (el admin no tiene UNA clave de Kapso inequívoca para
 * inyectar) y `rechazada` (el cliente contestó 409/422: su mensaje, ya limpio de secretos, viaja en `mensaje`). El controlador contesta
 * SIEMPRE HTTP 200 y el estado dice qué pasó.
 *
 * 🔴 DE DÓNDE SALE LA CLAVE DE KAPSO. La clave de Kapso es del PROYECTO (un mismo proyecto de Kapso tiene varios números), no del
 * cliente: el admin la toma SERVER-SIDE de `whatsapp_config` y la inyecta en el PUT; nunca viene de quien llama y nunca sale.
 * Regla (`clave_de_kapso_del_proyecto()`): se miran las filas ACTIVAS de `whatsapp_config` (cualquier propósito: `principal` y
 * `asistente` cuelgan del mismo proyecto de Kapso) con `kapso_api_key` no vacía y se juntan los valores DISTINTOS. Si hay exactamente
 * uno, ése es la clave del proyecto. Si no hay ninguno o hay más de uno, NO se adivina: estado `sin_kapso` con un mensaje claro (que
 * nunca dice los valores). No hay respaldo en `config('services.kapso.api_key')`: ése es el valor del .env que el seeder vuelca en la
 * fila, y usarlo por separado podría mandar al cliente una clave distinta de la que está en uso.
 *
 * 🔴 Lo que NO sale nunca: la clave del cliente, la clave de Kapso y el `webhook_secret` (ni en la respuesta, ni en el log, ni en un
 * mensaje de error). Cada texto que viene de afuera pasa por `limpiar()`, que los tapa a los tres.
 *
 * 🔴 Sin seguir redirecciones: Guzzle no saca un header propio como `X-Admin-Api-Key` al redirigir a otro host, y acá además viajaría
 * la clave de Kapso en el cuerpo.
 *
 * 🔴 Nunca lanza: cualquier excepción de transporte termina en `sin_conexion`.
 *
 * PHP 7.4: sin `?->`, `match`, `str_contains`, argumentos nombrados, union types, propiedades tipadas, promoción en constructor, etc.
 */
class ClientWhatsappConexionService
{
    use TapaLaClaveDelCliente;

    /** Ruta del empresa-api que lee y provisiona la conexión. */
    const RUTA = 'api/admin-sync/whatsapp-bot/conexion';

    /** Segundos de espera de la llamada. */
    const TIMEOUT = 15;

    const ESTADO_OK              = 'ok';
    const ESTADO_VERSION_VIEJA   = 'version_vieja';
    const ESTADO_SIN_CLAVE       = 'sin_clave';
    const ESTADO_CLAVE_RECHAZADA = 'clave_rechazada';
    const ESTADO_SIN_FRENTE      = 'sin_frente';
    const ESTADO_SIN_CONEXION    = 'sin_conexion';
    const ESTADO_SIN_KAPSO       = 'sin_kapso';
    const ESTADO_RECHAZADA       = 'rechazada';

    /** Las claves de `conexion` que se devuelven (lista blanca: lo demás que mande el cliente se descarta). */
    const CAMPOS_DE_LA_CONEXION = [
        'existe',
        'phone_number_id',
        'kapso_api_key_configurada',
        'webhook_secret_configurado',
        'conexion_configurada',
        'is_active',
    ];

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
     * Pregunta al cliente cómo está su conexión de WhatsApp (sin secretos).
     *
     * @param Client $client Cliente a consultar.
     *
     * @return array{estado: string, mensaje: string, conexion: array|null}
     */
    public function consultar(Client $client)
    {
        return $this->llamar($client, 'GET', null, '', '');
    }

    /**
     * Lo que hace falta para poder provisionar, sin llamar al cliente: clave del cliente, frente activo y clave de Kapso del
     * proyecto. Lo usa el `dry_run` del controlador y el propio `provisionar()`.
     *
     * @param Client $client Cliente.
     *
     * @return array{estado: string, mensaje: string, host: string|null}
     */
    public function verificar_que_se_puede_provisionar(Client $client)
    {
        if (trim((string) $client->api_key) === '') {
            return [
                'estado'  => self::ESTADO_SIN_CLAVE,
                'mensaje' => 'Este cliente no tiene clave de API cargada en el admin, así que no se puede hablar con su sistema.',
                'host'    => null,
            ];
        }

        $base = $this->api_url_resolver->resolve_base_url($client);

        if ($base === '') {
            return [
                'estado'  => self::ESTADO_SIN_FRENTE,
                'mensaje' => 'Este cliente no tiene una API activa con una URL válida.',
                'host'    => null,
            ];
        }

        $host  = parse_url($base, PHP_URL_HOST);
        $kapso = $this->clave_de_kapso_del_proyecto();

        if ($kapso['clave'] === null) {
            return [
                'estado'  => self::ESTADO_SIN_KAPSO,
                'mensaje' => $kapso['mensaje'],
                'host'    => is_string($host) ? $host : null,
            ];
        }

        return [
            'estado'  => self::ESTADO_OK,
            'mensaje' => 'Está todo para provisionar la conexión de WhatsApp de este cliente.',
            'host'    => is_string($host) ? $host : null,
        ];
    }

    /**
     * Provisiona la conexión en el cliente, inyectando la clave de Kapso del proyecto (que toma el servicio, no quien llama).
     *
     * @param Client      $client          Cliente a modificar.
     * @param string|null $phone_number_id Número de Meta (null/'' = no se toca).
     * @param string|null $webhook_secret  Secreto del webhook (null/'' = no se toca).
     * @param bool|null   $is_active       Prender o apagar el bot (null = no se toca).
     *
     * @return array{estado: string, mensaje: string, conexion: array|null}
     */
    public function provisionar(Client $client, $phone_number_id, $webhook_secret, $is_active)
    {
        $previo = $this->verificar_que_se_puede_provisionar($client);

        if ($previo['estado'] !== self::ESTADO_OK) {
            return $this->resultado($previo['estado'], $previo['mensaje']);
        }

        $kapso = $this->clave_de_kapso_del_proyecto();

        $cuerpo = ['kapso_api_key' => $kapso['clave']];

        if ($phone_number_id !== null && trim((string) $phone_number_id) !== '') {
            $cuerpo['phone_number_id'] = trim((string) $phone_number_id);
        }

        if ($webhook_secret !== null && trim((string) $webhook_secret) !== '') {
            $cuerpo['webhook_secret'] = (string) $webhook_secret;
        }

        if ($is_active !== null) {
            $cuerpo['is_active'] = (bool) $is_active;
        }

        return $this->llamar($client, 'PUT', $cuerpo, $kapso['clave'], isset($cuerpo['webhook_secret']) ? $cuerpo['webhook_secret'] : '');
    }

    /**
     * La clave de Kapso del PROYECTO, tomada de `whatsapp_config`. Ver la regla en el docblock de la clase.
     *
     * Nunca devuelve ni registra el valor en un mensaje: solo lo entrega en `clave`.
     *
     * @return array{clave: string|null, mensaje: string}
     */
    protected function clave_de_kapso_del_proyecto()
    {
        $claves = [];

        $filas = WhatsappConfig::query()->where('is_active', true)->get(['id', 'kapso_api_key']);

        foreach ($filas as $fila) {
            $valor = trim((string) $fila->kapso_api_key);

            if ($valor !== '') {
                $claves[$valor] = true;
            }
        }

        if (count($claves) === 0) {
            return [
                'clave'   => null,
                'mensaje' => 'El admin no tiene cargada una clave de Kapso en una configuración de WhatsApp activa, así que no hay clave de proyecto para entregarle al cliente.',
            ];
        }

        if (count($claves) > 1) {
            return [
                'clave'   => null,
                'mensaje' => 'El admin tiene más de una clave de Kapso distinta en sus configuraciones de WhatsApp activas: no se adivina cuál es la del proyecto. Hay que dejar una sola.',
            ];
        }

        $unica = array_keys($claves);

        return ['clave' => (string) $unica[0], 'mensaje' => ''];
    }

    /**
     * Hace la llamada al empresa-api del cliente y traduce la respuesta a un estado.
     *
     * @param Client                    $client        Cliente.
     * @param string                    $metodo        `GET` o `PUT`.
     * @param array<string, mixed>|null $cuerpo        Cuerpo del PUT (null en el GET).
     * @param string                    $clave_kapso   Clave de Kapso enviada (para taparla en textos; '' en el GET).
     * @param string                    $webhook_secret Secreto enviado (para taparlo en textos; '' si no se mandó).
     *
     * @return array{estado: string, mensaje: string, conexion: array|null}
     */
    protected function llamar(Client $client, $metodo, $cuerpo, $clave_kapso, $webhook_secret)
    {
        $clave = trim((string) $client->api_key);

        if ($clave === '') {
            return $this->resultado(
                self::ESTADO_SIN_CLAVE,
                'Este cliente no tiene clave de API cargada en el admin, así que no se puede hablar con su sistema.'
            );
        }

        $base = $this->api_url_resolver->resolve_base_url($client);

        if ($base === '') {
            return $this->resultado(
                self::ESTADO_SIN_FRENTE,
                'Este cliente no tiene una API activa con una URL válida.'
            );
        }

        $secretos = [$clave, (string) $clave_kapso, (string) $webhook_secret];
        $url      = rtrim($base, '/') . '/' . self::RUTA;

        try {
            $pedido = Http::withHeaders([
                    'X-Admin-Api-Key' => $clave,
                    'Accept'          => 'application/json',
                ])
                ->withOptions(['allow_redirects' => false])
                ->timeout(self::TIMEOUT);

            $response = $metodo === 'PUT'
                ? $pedido->put($url, (array) $cuerpo)
                : $pedido->get($url);
        } catch (\Throwable $e) {
            Log::warning('ClientWhatsappConexionService: no se pudo contactar al empresa-api del cliente.', [
                'client_id' => (int) $client->id,
                'metodo'    => $metodo,
                'error'     => $this->limpiar(ClientCatalogoPuenteService::sin_la_query_de_las_urls($e->getMessage()), $secretos),
            ]);

            return $this->sin_conexion();
        }

        $status = (int) $response->status();

        Log::info('ClientWhatsappConexionService: llamada a la puerta whatsapp-bot/conexion del cliente.', [
            'client_id' => (int) $client->id,
            'metodo'    => $metodo,
            'status'    => $status,
        ]);

        if ($status === 404 || $status === 405) {
            return $this->resultado(
                self::ESTADO_VERSION_VIEJA,
                'Este cliente todavía no tiene la actualización que permite configurar la conexión de WhatsApp desde el admin.'
            );
        }

        if ($status === 401) {
            return $this->resultado(
                self::ESTADO_CLAVE_RECHAZADA,
                'El sistema del cliente rechazó la clave de API que tiene el admin. Hay que volver a sincronizarla.'
            );
        }

        if ($status === 409 || $status === 422) {
            return $this->resultado(self::ESTADO_RECHAZADA, $this->mensaje_del_cliente($response, $status, $secretos));
        }

        if ($status !== 200) {
            return $this->sin_conexion();
        }

        $json = json_decode((string) $response->body(), true);

        // Un 200 solo no alcanza (el hosting sirve su página genérica con 200 cuando está saturado): el cuerpo tiene que
        // confirmar con `ok: true` y traer `conexion` como objeto.
        if (!is_array($json) || empty($json['ok']) || !isset($json['conexion']) || !is_array($json['conexion'])) {
            return $this->sin_conexion();
        }

        $conexion = $this->conexion_sin_secretos($json['conexion']);

        return $this->resultado(
            self::ESTADO_OK,
            $metodo === 'PUT'
                ? 'La conexión de WhatsApp quedó provisionada en el sistema del cliente.'
                : ($conexion['conexion_configurada']
                    ? 'El cliente tiene la conexión de WhatsApp configurada.'
                    : 'El cliente todavía no tiene la conexión de WhatsApp configurada.'),
            $conexion
        );
    }

    /**
     * La `conexion` del cliente reducida a la lista blanca y con tipos fijos: nada de lo que el cliente agregue sale.
     *
     * @param array<string, mixed> $cruda Lo que devolvió el cliente.
     *
     * @return array{existe: bool, phone_number_id: string|null, kapso_api_key_configurada: bool, webhook_secret_configurado: bool, conexion_configurada: bool, is_active: bool}
     */
    protected function conexion_sin_secretos(array $cruda)
    {
        $phone = isset($cruda['phone_number_id']) && is_scalar($cruda['phone_number_id']) ? trim((string) $cruda['phone_number_id']) : '';

        return [
            'existe'                     => !empty($cruda['existe']),
            'phone_number_id'            => $phone === '' ? null : mb_substr($phone, 0, 191),
            'kapso_api_key_configurada'  => !empty($cruda['kapso_api_key_configurada']),
            'webhook_secret_configurado' => !empty($cruda['webhook_secret_configurado']),
            'conexion_configurada'       => !empty($cruda['conexion_configurada']),
            'is_active'                  => !empty($cruda['is_active']),
        ];
    }

    /**
     * El `message` que dio el cliente en un 409/422, limpio de secretos; o un texto propio si no vino.
     *
     * @param \Illuminate\Http\Client\Response $response Respuesta del cliente.
     * @param int                              $status   409 o 422.
     * @param array<int, string>               $secretos Los secretos que pueden haberse repetido.
     *
     * @return string
     */
    protected function mensaje_del_cliente($response, $status, array $secretos)
    {
        $json  = json_decode((string) $response->body(), true);
        $texto = is_array($json) && isset($json['message']) && is_string($json['message']) ? trim($json['message']) : '';

        if ($texto !== '') {
            return 'El sistema del cliente no aceptó el pedido: ' . $this->limpiar($texto, $secretos);
        }

        return $status === 409
            ? 'El sistema del cliente no sabe quién es su dueño (falta USER_ID en el .env de su frente activo). Hay que cargarlo y volver a intentar.'
            : 'El sistema del cliente no aceptó el pedido (validación).';
    }

    /**
     * Un texto que vino de afuera, sin NINGUNO de los secretos y listo para salir.
     *
     * @param string             $texto    Texto.
     * @param array<int, string> $secretos Los secretos a tapar.
     *
     * @return string
     */
    protected function limpiar($texto, array $secretos)
    {
        $texto = (string) $texto;

        foreach ($secretos as $secreto) {
            $texto = $this->tapar_clave($texto, $secreto);
        }

        return $this->texto_seguro($texto, '');
    }

    /**
     * Resultado genérico de "no se pudo hablar con el cliente" (red, timeout, 5xx, respuesta inválida).
     *
     * @return array{estado: string, mensaje: string, conexion: array|null}
     */
    protected function sin_conexion()
    {
        return $this->resultado(
            self::ESTADO_SIN_CONEXION,
            'No se pudo contactar al sistema del cliente. Probá de nuevo en un momento.'
        );
    }

    /**
     * @param string     $estado   Uno de los ESTADO_*.
     * @param string     $mensaje  Texto para mostrar.
     * @param array|null $conexion La conexión sin secretos (solo en `ok`).
     *
     * @return array{estado: string, mensaje: string, conexion: array|null}
     */
    protected function resultado($estado, $mensaje, $conexion = null)
    {
        return ['estado' => $estado, 'mensaje' => $mensaje, 'conexion' => $conexion];
    }
}

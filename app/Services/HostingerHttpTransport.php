<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Transporte HTTP hacia la API pública de Hostinger: arma la URL, manda el header de
 * autenticación, decodifica el JSON y traduce un HTTP no-2xx a una excepción clasificable.
 *
 * No conoce ni un endpoint. Los endpoints viven en HostingerApiClient, que extiende esta clase.
 *
 * 🔴 Por qué está partido en dos archivos. La regla R2 del plan (§9) fija un techo de 450 líneas
 * para todo archivo nuevo de app/Services/. Con los endpoints y el transporte juntos el cliente
 * daba 630, así que se partió por la única costura que importa: de un lado lo que habla con la red
 * (esto), del otro lo que sabe qué endpoints existen. Es también la costura exacta que usa el fake
 * de los tests, que sobreescribe request() y nada más.
 *
 * 🔴 El token no se loguea NUNCA, ni entero ni parcial, y no viaja jamás por query string: Guzzle
 * copia la URI completa adentro del mensaje de sus excepciones de transporte, así que un token en
 * la URL termina escrito en laravel.log en el primer timeout.
 *
 * PHP 7.4: sin `?->`, `match`, `str_contains`, argumentos nombrados, union types, promoción en
 * constructor, `readonly`, `enum`, atributos, `mixed` ni `never`.
 */
abstract class HostingerHttpTransport
{
    /**
     * El proveedor dijo, de forma reconocible, que el recurso ya existía.
     *
     * @var string
     */
    const CLASIFICACION_YA_EXISTE = 'ya_existe';

    /**
     * El error es reconociblemente OTRO (token inválido, permisos, 5xx, caída de red).
     * Seguro NO es un "ya existe".
     *
     * @var string
     */
    const CLASIFICACION_OTRO_ERROR = 'otro_error';

    /**
     * 🔴 No se sabe qué es. El llamador TIENE que verificar contra el proveedor o fallar.
     *
     * @var string
     */
    const CLASIFICACION_DESCONOCIDA = 'desconocida';

    /**
     * Mensaje exacto para el operador cuando falta el token. Se usa en dos lugares (la sonda y el
     * request), así que vive en una sola constante para que no se desincronicen.
     *
     * @var string
     */
    const MENSAJE_SIN_TOKEN = 'Falta HOSTINGER_API_TOKEN en el .env de admin-api. Generalo en '
        . 'hPanel → API → Generate API token, agregalo al .env y reiniciá el worker de cola.';

    /**
     * Mensaje para el operador cuando el token existe pero la API lo rechaza.
     *
     * @var string
     */
    const MENSAJE_TOKEN_RECHAZADO = 'La API de Hostinger rechazó el HOSTINGER_API_TOKEN configurado '
        . '(respondió 401). Generá uno nuevo en hPanel → API → Generate API token, actualizalo en el '
        . '.env de admin-api y reiniciá el worker de cola.';

    /**
     * Fragmentos, en minúscula, con los que un error de la API se reconoce como "el recurso ya
     * existía".
     *
     * 🔴 Esta lista NO está verificada contra la API real (§10.1 del plan: el token todavía no
     * existe). Por eso lo que no matchea acá se clasifica DESCONOCIDA y hace fallar al llamador, en
     * vez de asumir que ya existía: asumirlo de más significaría seguir de largo creyendo que la
     * base está creada cuando en realidad no se creó nunca.
     *
     * @var array<int, string>
     */
    const PATRONES_YA_EXISTE = [
        'already exists',
        'already in use',
        'already taken',
        'has already been taken',
        'duplicate entry',
        'ya existe',
    ];

    /**
     * Claves del payload cuyo valor se reemplaza por *** antes de loguearlo.
     *
     * El plan (§10) pide loguear el payload entero de cada llamada, porque el contrato de la API no
     * se pudo verificar y esa es la única forma de saber qué se mandó cuando la primera corrida
     * real falle. Pero el payload de create_database lleva la contraseña de la base del cliente:
     * loguearla en claro sería crear el mismo problema que el plan denuncia en §0.5 para el panel
     * de operaciones, esta vez en laravel.log. Se loguea la FORMA del payload, no los secretos.
     *
     * @var array<int, string>
     */
    const CLAVES_A_REDACTAR = ['password', 'user_password', 'database_user_password', 'token', 'secret'];

    /**
     * ¿Hay token configurado? Es lo primero que mira provision_check, para fallar temprano y sin
     * haber escrito nada (decisión 4 del plan).
     *
     * @return bool
     */
    public function token_configurado(): bool
    {
        return $this->token() !== '';
    }

    /**
     * Clasifica un error de la API en las tres únicas categorías que el llamador puede manejar.
     *
     * 🔴 La tercera categoría es la que importa. Ante un mensaje que no reconoce devuelve
     * DESCONOCIDA, y el llamador tiene que verificar contra el proveedor (un GET) o fallar. NUNCA
     * asumir "ya existía": esa suposición hace que el pipeline siga de largo creyendo que el
     * subdominio o la base están, cuando en realidad no se crearon nunca, y el error recién aparece
     * quince minutos después en un paso que no tiene nada que ver.
     *
     * @param  \Throwable  $excepcion
     * @return string  Una de las constantes CLASIFICACION_*.
     */
    public function clasificar_error(\Throwable $excepcion): string
    {
        $mensaje = mb_strtolower($excepcion->getMessage());

        foreach (self::PATRONES_YA_EXISTE as $patron) {
            if (strpos($mensaje, $patron) !== false) {
                return self::CLASIFICACION_YA_EXISTE;
            }
        }

        $codigo = (int) $excepcion->getCode();

        /*
         * Códigos donde "ya existe" está descartado con certeza: el token no sirve, no hay permiso,
         * el endpoint no existe, o el problema es del otro lado. Un 0 es error de transporte (no
         * hubo respuesta del proveedor), así que tampoco puede ser un "ya existe".
         */
        if ($codigo === 0 || $codigo === 401 || $codigo === 403 || $codigo === 404 || $codigo >= 500) {
            return self::CLASIFICACION_OTRO_ERROR;
        }

        return self::CLASIFICACION_DESCONOCIDA;
    }

    /**
     * ¿El error es, con certeza, "el recurso ya existía"?
     *
     * Atajo de clasificar_error() para el caso feliz de la idempotencia. Devuelve false tanto para
     * un error reconocidamente distinto como para uno desconocido: los dos tienen que hacer fallar
     * al llamador. Si necesitás distinguirlos —por ejemplo para consultar la zona antes de
     * rendirte—, usá clasificar_error() y mirá las tres categorías.
     *
     * ⚠️ Hoy no lo llama nadie del código: los tres lugares que clasifican un error del proveedor
     * necesitan distinguir "desconocido" de "otro error", así que usan clasificar_error(). Se queda
     * igual, y el criterio es este: es el par legible de una constante pública que SÍ se usa
     * (CLASIFICACION_YA_EXISTE), cuesta dos líneas y lo cubre HostingerApiClientTest en los tres
     * casos —incluido el que importa, el desconocido que NO puede dar true—. Si algún día hace
     * falta borrar algo de este archivo, este método es el primero de la lista.
     *
     * @param  \Throwable  $excepcion
     * @return bool
     */
    public function es_error_de_ya_existe(\Throwable $excepcion): bool
    {
        return $this->clasificar_error($excepcion) === self::CLASIFICACION_YA_EXISTE;
    }

    /**
     * ÚNICO punto por el que pasan todas las llamadas a la API.
     *
     * 🔴 Es `protected` a propósito: es el único método que sobreescribe HostingerApiClientFake, y
     * ese es todo el diseño de los tests (§7 del plan). Con el transporte falseado acá abajo, todo
     * lo de arriba —el armado de las rutas, el payload exacto de cada POST, el overwrite en false
     * del PUT de DNS y la clasificación de errores— corre de verdad en los tests. Un fake que
     * falseara los métodos públicos daría verde sin probar nada de lo que importa.
     *
     * Por eso también: acá NO va lógica de negocio. Todo lo que se meta en este método deja de
     * estar cubierto por los tests en el mismo momento en que se escribe.
     *
     * @param  string  $method  Verbo HTTP.
     * @param  string  $path    Path absoluto desde la base (empieza con /), ya armado.
     * @param  array<string, mixed>  $body
     * @return array<int|string, mixed>
     * @throws \RuntimeException
     */
    protected function request(string $method, string $path, array $body = []): array
    {
        if ($this->token() === '') {
            throw new \RuntimeException(self::MENSAJE_SIN_TOKEN);
        }

        /*
         * El payload se loguea antes de cada llamada porque el contrato de la API no se pudo
         * verificar (§10.1): cuando la primera corrida real falle, esto es lo único que dice qué se
         * mandó. Los valores sensibles van redactados y el header de autenticación no se loguea
         * nunca.
         */
        Log::info('HostingerApiClient: llamada saliente.', [
            'metodo' => $method,
            'ruta'   => $path,
            'body'   => $this->redactar_payload($body),
        ]);

        $url      = rtrim($this->base_url(), '/') . $path;
        $opciones = $body === [] ? [] : ['json' => $body];

        try {
            $respuesta = $this->build_http_client()->send($method, $url, $opciones);
        } catch (\Throwable $excepcion) {
            /*
             * Código 0: no hubo respuesta del proveedor. clasificar_error() lo usa para descartar
             * de plano que esto pueda ser un "ya existe".
             */
            throw new \RuntimeException(
                'Error de conexión con la API de Hostinger: ' . $excepcion->getMessage(),
                0
            );
        }

        if ($respuesta->failed()) {
            /* El cuerpo se recorta: un HTML de error de 200 KB no aporta nada al panel de logs. */
            throw new \RuntimeException(
                'La API de Hostinger respondió ' . $respuesta->status() . ': '
                    . substr((string) $respuesta->body(), 0, 300),
                (int) $respuesta->status()
            );
        }

        $cuerpo = trim((string) $respuesta->body());

        /* Un DELETE exitoso devuelve 204 sin cuerpo, y eso no es un error. */
        if ($cuerpo === '') {
            return [];
        }

        $decodificado = json_decode($cuerpo, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(
                'La API de Hostinger devolvió un JSON inválido: ' . substr($cuerpo, 0, 300),
                (int) $respuesta->status()
            );
        }

        /* Un escalar suelto igual se devuelve como array, para que el llamador no mire el tipo. */
        return is_array($decodificado) ? $decodificado : ['data' => $decodificado];
    }

    /**
     * Reemplaza por *** los valores de las claves sensibles del payload, para poder loguearlo.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    protected function redactar_payload(array $body): array
    {
        $redactado = [];

        foreach ($body as $clave => $valor) {
            if (is_array($valor)) {
                $redactado[$clave] = $this->redactar_payload($valor);
                continue;
            }

            $redactado[$clave] = in_array(strtolower((string) $clave), self::CLAVES_A_REDACTAR, true)
                ? '***'
                : $valor;
        }

        return $redactado;
    }

    /**
     * Cliente HTTP con el header de autenticación y la config TLS del proyecto.
     *
     * Mismo patrón que SubdomainSuggestionService::build_http_client(). El token va en el header
     * Authorization y nunca en la URL.
     *
     * @return PendingRequest
     */
    protected function build_http_client(): PendingRequest
    {
        $http = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->token(),
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
        ])->timeout((int) config('services.hostinger.timeout', 45));

        $verify_ssl = (bool) config('services.hostinger.verify_ssl', true);
        $ca_bundle  = config('services.hostinger.ca_bundle');

        if (! $verify_ssl) {
            $http = $http->withoutVerifying();
        } elseif (is_string($ca_bundle) && $ca_bundle !== '' && is_file($ca_bundle)) {
            $http = $http->withOptions(['verify' => $ca_bundle]);
        }

        return $http;
    }

    /**
     * Path de un recurso colgado de la cuenta de hosting.
     *
     * @param  string  $sufijo  Empieza con /.
     * @return string
     */
    protected function ruta_cuenta(string $sufijo): string
    {
        return '/api/hosting/v1/accounts/' . rawurlencode($this->usuario()) . $sufijo;
    }

    /**
     * Token Bearer.
     *
     * Se lee de config en cada uso y no se guarda en una propiedad del constructor: así un cambio
     * de config (un test, un .env recargado) aplica sin tener que reinstanciar el servicio, que en
     * el container se resuelve una sola vez por corrida.
     *
     * @return string
     */
    protected function token(): string
    {
        return trim((string) config('services.hostinger.api_token', ''));
    }

    /**
     * Usuario de la cuenta de hosting (ej: u767360347).
     *
     * @return string
     */
    protected function usuario(): string
    {
        return trim((string) config('services.hostinger.account_username', ''));
    }

    /**
     * Dominio dueño de la zona y de los subdominios.
     *
     * 🔴 Guarda G5 del plan: el dominio sale SIEMPRE de config y jamás de un request o de la base.
     * No hay un solo camino por el que un valor de afuera llegue a la URL de la zona DNS.
     *
     * @return string
     */
    protected function dominio(): string
    {
        return trim((string) config('services.hostinger.domain', ''));
    }

    /**
     * Base de la API, sin barra final.
     *
     * @return string
     */
    protected function base_url(): string
    {
        return trim((string) config('services.hostinger.base_url', 'https://developers.hostinger.com'));
    }
}

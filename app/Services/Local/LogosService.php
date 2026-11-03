<?php

namespace App\Services\Local;

use App\Helpers\AppTime;
use App\Models\Client;
use App\Services\ClientEmpresaApiUrlResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Trae el logo de cada negocio desde su propio sistema para dibujarlo como cabeza en «El local» (misión
 * el-local, 3/11/2026). Lo usa `local:sincronizar-logos`, una vez por día.
 *
 * De dónde sale, en orden:
 *   1. `GET {empresa-api}/api/admin-sync/branding` del cliente, con `X-Admin-Api-Key = clients.api_key` y la
 *      URL que resuelve {@see ClientEmpresaApiUrlResolver} — la MISMA llamada que hace
 *      `EcommerceInstallationService::fetch_branding_from_empresa_api()`. Del bloque `branding` se toma
 *      `image_url` (el logo del sistema) y, si no hay, `logo_url` (el de la tienda).
 *   2. `clients.setup_data.logo_url`, lo que se cargó en el formulario de la implementación.
 *   3. Nada: la SPA cae al webp curado por slug y, si no, a las iniciales.
 *
 * Se guarda en `clients.logo_url`, `logo_synced_at` (última vez que el sistema del cliente CONTESTÓ) y
 * `logo_sync_status` (cómo terminó el último intento: ok, sin_logo, sin_url, sin_api_key, error_red,
 * http_<código>, respuesta_invalida).
 *
 * 🔴 Si el sistema del cliente no contesta, el logo que ya estaba NO se borra: un corte de red de una noche
 * no puede dejar a un negocio sin cabeza. Solo se reemplaza cuando el cliente contesta.
 *
 * 🔴 Se escribe con `DB::table()->update()` y no con `$client->save()`: no tiene que mover el `updated_at`
 * del cliente (que leen otras pantallas como "última modificación") ni disparar las guardas de guardado
 * del modelo por tres columnas cosméticas.
 */
class LogosService
{
    /** Timeout de la llamada al sistema del cliente, en segundos. */
    const TIMEOUT_SEGUNDOS = 6;

    /** Largo máximo de `clients.logo_url`. */
    const LARGO_MAXIMO = 500;

    const ESTADO_OK                 = 'ok';
    const ESTADO_SIN_LOGO           = 'sin_logo';
    const ESTADO_SIN_URL            = 'sin_url';
    const ESTADO_SIN_API_KEY        = 'sin_api_key';
    const ESTADO_ERROR_RED          = 'error_red';
    const ESTADO_RESPUESTA_INVALIDA = 'respuesta_invalida';

    /**
     * Columnas de `clients` que hacen falta para resolver la URL y el logo. 🔴 Lista blanca: `Client` tiene
     * `$guarded = []` y ningún `$hidden`, así que nunca se trae ni se serializa el modelo entero.
     */
    const COLUMNAS = [
        'id',
        'name',
        'company_name',
        'slug',
        'uuid',
        'is_active',
        'api_key',
        'api_url',
        'active_client_api_id',
        'setup_data',
        'logo_url',
    ];

    /** @var ClientEmpresaApiUrlResolver */
    private $urls;

    /**
     * @param ClientEmpresaApiUrlResolver $urls Resuelve la URL del empresa-api de cada cliente.
     */
    public function __construct(ClientEmpresaApiUrlResolver $urls)
    {
        $this->urls = $urls;
    }

    /**
     * Clientes a sincronizar: todos los activos, o uno solo si se pasa el filtro (id, slug o uuid).
     *
     * Las dos relaciones que el resolver de URL necesita se cargan juntas (dos consultas para todos), así
     * no hay una consulta por cliente.
     *
     * @param string|null $filtro Id, slug o uuid de un cliente.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Client>
     */
    public function clientes($filtro = null)
    {
        $query = Client::query()->select(self::COLUMNAS)->orderBy('id');

        $filtro = $filtro === null ? '' : trim((string) $filtro);
        if ($filtro !== '') {
            $query->where(function ($q) use ($filtro) {
                if (ctype_digit($filtro)) {
                    $q->where('id', (int) $filtro);
                }
                $q->orWhere('slug', $filtro)->orWhere('uuid', $filtro);
            });
        } else {
            $query->where('is_active', true);
        }

        $clientes = $query->get();
        $clientes->load('active_client_api', 'client_apis');

        return $clientes;
    }

    /**
     * Sincroniza el logo de un cliente y lo persiste.
     *
     * @param Client $client Cliente con las columnas de {@see self::COLUMNAS}.
     *
     * @return array{estado: string, logo_url: string|null}
     */
    public function sincronizar(Client $client)
    {
        $del_formulario = $this->logo_del_formulario($client);
        $actual         = $this->url_valida($client->logo_url);

        $url     = $this->urls->admin_sync_url($client, ClientEmpresaApiUrlResolver::BRANDING_PATH);
        $api_key = (string) ($client->api_key ?? '');

        if ($url === '' || $api_key === '') {
            $estado = $url === '' ? self::ESTADO_SIN_URL : self::ESTADO_SIN_API_KEY;

            return $this->persistir($client, $actual !== null ? $actual : $del_formulario, $estado, false);
        }

        try {
            $respuesta = Http::withHeaders([
                    'X-Admin-Api-Key' => $api_key,
                    'Accept'          => 'application/json',
                ])
                ->timeout(self::TIMEOUT_SEGUNDOS)
                ->get($url);
        } catch (\Throwable $e) {
            Log::warning('LogosService: el sistema del cliente no contestó el branding.', [
                'client_id' => (int) $client->id,
                'error'     => get_class($e),
            ]);

            return $this->persistir($client, $actual !== null ? $actual : $del_formulario, self::ESTADO_ERROR_RED, false);
        }

        if (! $respuesta->successful()) {
            return $this->persistir(
                $client,
                $actual !== null ? $actual : $del_formulario,
                'http_' . (int) $respuesta->status(),
                false
            );
        }

        $branding = $respuesta->json('branding');
        if (! is_array($branding)) {
            return $this->persistir($client, $actual !== null ? $actual : $del_formulario, self::ESTADO_RESPUESTA_INVALIDA, false);
        }

        $logo = $this->url_valida(isset($branding['image_url']) ? $branding['image_url'] : null);
        if ($logo === null) {
            $logo = $this->url_valida(isset($branding['logo_url']) ? $branding['logo_url'] : null);
        }
        if ($logo === null) {
            $logo = $del_formulario;
        }

        return $this->persistir($client, $logo, $logo === null ? self::ESTADO_SIN_LOGO : self::ESTADO_OK, true);
    }

    /**
     * Escribe las tres columnas del logo.
     *
     * @param Client      $client     Cliente.
     * @param string|null $logo       URL a guardar (o null).
     * @param string      $estado     Cómo terminó el intento.
     * @param bool        $contesto   Si el sistema del cliente contestó (mueve `logo_synced_at`).
     *
     * @return array{estado: string, logo_url: string|null}
     */
    private function persistir(Client $client, $logo, $estado, $contesto)
    {
        $cambios = [
            'logo_url'         => $logo,
            'logo_sync_status' => mb_substr((string) $estado, 0, 40),
        ];

        if ($contesto) {
            $cambios['logo_synced_at'] = AppTime::now()->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
        }

        DB::table('clients')->where('id', (int) $client->id)->update($cambios);

        return ['estado' => (string) $estado, 'logo_url' => $logo];
    }

    /**
     * El logo cargado en el formulario de implementación (`setup_data.logo_url`), si es una URL válida.
     *
     * @param Client $client
     *
     * @return string|null
     */
    private function logo_del_formulario(Client $client)
    {
        $setup = $client->setup_data;

        if (! is_array($setup) || ! isset($setup['logo_url'])) {
            return null;
        }

        return $this->url_valida($setup['logo_url']);
    }

    /**
     * Devuelve la URL si es absoluta, http/https y entra en la columna; si no, null.
     *
     * @param mixed $valor
     *
     * @return string|null
     */
    private function url_valida($valor)
    {
        if ($valor === null || is_array($valor)) {
            return null;
        }

        $valor = trim((string) $valor);
        if ($valor === '' || strlen($valor) > self::LARGO_MAXIMO) {
            return null;
        }

        if (filter_var($valor, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $esquema = strtolower((string) parse_url($valor, PHP_URL_SCHEME));

        return in_array($esquema, ['http', 'https'], true) ? $valor : null;
    }
}

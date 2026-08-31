<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\CommonLaravel\BaseController;
use App\Jobs\RunEcommerceInstallationJob;
use App\Models\Client;
use App\Models\ClientEcommerce;
use App\Models\ClientEcommerceInstallation;
use App\Models\ClientSshCredential;
use App\Models\Demo;
use App\Models\EcommerceDeploymentLog;
use App\Models\EnvTemplate;
use App\Services\DemoPathResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoints del pipeline de instalación/actualización del ecommerce (tienda-spa + tienda-api).
 *
 * Espeja a `ClientInstallationController`/`DeploymentController` (empresa) pero para
 * `ClientEcommerce`: dispara instalaciones desde cero y actualizaciones (siempre última de master)
 * en un job de cola (`RunEcommerceInstallationJob`), y expone estado/logs de cada corrida para el
 * polling del panel de admin-spa.
 *
 * Desde el 31/8/2026 la tienda puede ser la de un cliente o la de una DEMO: los endpoints de
 * arranque aceptan `{ demo_id }` como alternativa a `{ client_id }` y el listado se puede filtrar
 * con `?owner=cliente|demo`. Es el mismo pipeline para los dos casos — lo único que cambia es de
 * dónde salen el nombre del comercio, el id de comercio y la api key.
 *
 * Sin lógica de negocio acá (regla del proyecto): toda la orquestación del pipeline vive en
 * `EcommerceInstallationService`/`EcommerceDeploymentService` y en el job de cola.
 */
class EcommerceInstallationController extends BaseController
{
    /**
     * Lista todas las corridas de instalación/actualización de ecommerce,
     * equivalente a `ClientInstallationController::index_all()`.
     *
     * Acepta un query param opcional `owner` para filtrar por tipo de dueño de la tienda:
     * `cliente` (las de siempre) o `demo` (las nuevas). SIN el parámetro se comporta exactamente
     * como antes —devuelve todo—, que es lo que sigue pidiendo la pantalla actual del panel.
     *
     * @param  Request  $request  Query: { owner?: 'cliente'|'demo' }
     * @return JsonResponse  { models: ClientEcommerceInstallation[] }
     */
    public function index_json(Request $request): JsonResponse
    {
        $query = ClientEcommerceInstallation::withAll()->orderByDesc('id');

        // Un valor desconocido ('clientes', 'x') se ignora y devuelve todo: el listado es de solo
        // lectura, así que degradar a "sin filtro" es preferible a un 422 que rompa la pantalla.
        $owner = strtolower(trim((string) $request->query('owner', '')));
        if ($owner === 'cliente' || $owner === 'demo') {
            $columna = $owner === 'demo' ? 'demo_id' : 'client_id';
            $query->whereHas('client_ecommerce', function ($subquery) use ($columna) {
                $subquery->whereNotNull($columna);
            });
        }

        return response()->json(['models' => $query->get()]);
    }

    /**
     * Estado del ecommerce de un cliente junto con sus corridas (instalación/actualización),
     * para el modal de gestión del panel.
     *
     * @param  ClientEcommerce  $client_ecommerce  Resuelta por route model binding.
     * @return JsonResponse  { model: ClientEcommerce }
     */
    public function show_json(ClientEcommerce $client_ecommerce): JsonResponse
    {
        // Se cargan las dos relaciones de dueño: una de las dos viene null siempre (ver el hook
        // `saving` de ClientEcommerce), y así el panel puede mostrar el dueño sin preguntar cuál.
        $client_ecommerce->load(['client', 'demo', 'installations' => function ($query) {
            $query->orderByDesc('id');
        }]);

        return response()->json(['model' => $client_ecommerce]);
    }

    /**
     * Dispara una instalación desde cero (`mode = 'install'`) para una tienda ya creada.
     *
     * @param  ClientEcommerce  $client_ecommerce  Resuelta por route model binding.
     * @return JsonResponse  { model: ClientEcommerceInstallation } o { error: string } (422)
     */
    public function start_install_json(ClientEcommerce $client_ecommerce): JsonResponse
    {
        return $this->arrancar_corrida($client_ecommerce, 'install');
    }

    /**
     * Dispara una actualización (`mode = 'update'`) del ecommerce ya configurado de un cliente.
     *
     * Trivial por diseño (pedido de Lucas): recibe solo el dueño, resuelve su `ClientEcommerce`
     * ya configurado y siempre usa la última versión de la rama `master` — no recibe versión/tag.
     *
     * Desde el 31/8/2026 acepta `demo_id` como alternativa a `client_id`: es el mismo pipeline,
     * cambia solo de dónde salen el nombre, el id de comercio y la api key (ver la sección
     * "DUEÑO DE LA TIENDA" de EcommerceInstallationService).
     *
     * @param  Request  $request  { client_id } o { demo_id }
     * @return JsonResponse  { model: ClientEcommerceInstallation } o { error: string } (404/422)
     */
    public function start_update_json(Request $request): JsonResponse
    {
        $client_ecommerce = $this->resolve_ecommerce_from_request($request);
        if ($client_ecommerce instanceof JsonResponse) {
            return $client_ecommerce;
        }

        return $this->arrancar_corrida($client_ecommerce, 'update');
    }

    /**
     * Dispara una instalación desde cero (`mode = 'install'`) resolviendo el `ClientEcommerce`
     * a partir del cliente, para el submódulo global "Instalaciones > Ecommerce" (a diferencia de
     * `start_install_json`, que requiere conocer de antemano el id del `ClientEcommerce` y se usa
     * desde el detalle embebido de un cliente puntual).
     *
     * Desde el 31/8/2026 acepta `demo_id` como alternativa a `client_id`. A diferencia del camino
     * del cliente —donde la tienda ya existe porque se carga desde el perfil—, con una demo la
     * fila de `client_ecommerces` se crea acá si todavía no está, copiando las URLs del catálogo
     * de demos (ver resolve_ecommerce_de_demo()).
     *
     * @param  Request  $request  { client_id } o { demo_id }
     * @return JsonResponse  { model: ClientEcommerceInstallation } o { error: string } (404/422)
     */
    public function start_install_for_client_json(Request $request): JsonResponse
    {
        $client_ecommerce = $this->resolve_ecommerce_from_request($request);
        if ($client_ecommerce instanceof JsonResponse) {
            return $client_ecommerce;
        }

        return $this->arrancar_corrida($client_ecommerce, 'install');
    }

    /**
     * Resuelve la tienda sobre la que hay que correr el pipeline a partir del cuerpo del pedido.
     *
     * `demo_id` gana sobre `client_id` si vinieran los dos, pero no es un caso a soportar: el
     * panel manda uno u otro según la pestaña. Sin ninguno de los dos se contesta el mismo
     * "Falta client_id." de siempre, para no romper al panel viejo.
     *
     * @param  Request  $request  { client_id } o { demo_id }
     * @return ClientEcommerce|JsonResponse  La tienda, o la respuesta de error a devolver tal cual.
     */
    protected function resolve_ecommerce_from_request(Request $request)
    {
        $demo_id = $request->input('demo_id');
        if (! empty($demo_id)) {
            return $this->resolve_ecommerce_de_demo($demo_id);
        }

        $client_id = $request->input('client_id');
        if (empty($client_id)) {
            return response()->json(['error' => 'Falta client_id.'], 422);
        }

        $client = Client::find($client_id);
        if ($client === null) {
            return response()->json(['error' => 'Cliente no encontrado.'], 404);
        }

        $client_ecommerce = $client->client_ecommerce;
        if ($client_ecommerce === null) {
            return response()->json([
                'error' => 'El cliente no tiene una tienda (ecommerce) configurada.',
            ], 422);
        }

        return $client_ecommerce;
    }

    /**
     * Resuelve —creándola si hace falta— la tienda de una demo.
     *
     * 🔴 EL CATÁLOGO DE DEMOS ES LA FUENTE DE VERDAD DE LAS URLs, y por eso se copian en CADA
     * arranque y no solo al crear la fila. Una demo se recicla: hoy es la demo de un lead y en dos
     * semanas apunta a otro subdominio. Si la fila de `client_ecommerces` se quedara con las URLs
     * del primer arranque, la corrida siguiente compilaría el SPA apuntando a una API que ya no
     * es la de esa demo, sin que nada lo denuncie. (Para un cliente esto no pasa: ahí las URLs se
     * cargan a mano en el perfil, que ES la fuente de verdad.)
     *
     * @param  mixed  $demo_id
     * @return ClientEcommerce|JsonResponse
     */
    protected function resolve_ecommerce_de_demo($demo_id)
    {
        $demo = Demo::find($demo_id);
        if ($demo === null) {
            return response()->json(['error' => 'Demo no encontrada.'], 404);
        }

        $spa_url = ClientEcommerce::normalize_url($demo->ecommerce_spa_url);
        $api_url = ClientEcommerce::normalize_url($demo->ecommerce_api_url);

        // Se validan acá y no en assert_ecommerce_is_configured() porque el mensaje de ese método
        // manda a la sección "Tienda online (ecommerce)" del perfil de un cliente, que para una
        // demo no existe: hay que mandar al módulo de Demos.
        $faltantes = [];
        if ($spa_url === '') {
            $faltantes[] = '«Ecommerce SPA URL»';
        }
        if ($api_url === '') {
            $faltantes[] = '«Ecommerce API URL»';
        }
        if (! empty($faltantes)) {
            return response()->json([
                'error' => 'La demo no tiene cargada la ' . implode(' ni la ', $faltantes)
                    . '. Completala en el módulo de Demos antes de instalar o actualizar su tienda.',
            ], 422);
        }

        $client_ecommerce = ClientEcommerce::where('demo_id', $demo->id)->first();

        if ($client_ecommerce === null) {
            $client_ecommerce = ClientEcommerce::create([
                // client_id explícito en null: es una tienda de demo, no de cliente. La guarda de
                // dueño único de ClientEcommerce rechaza cualquier otra combinación.
                'client_id' => null,
                'demo_id'   => $demo->id,
                'spa_url'   => $spa_url,
                'api_url'   => $api_url,
                'status'    => 'pending',
            ]);
        } else {
            $client_ecommerce->update([
                'spa_url' => $spa_url,
                'api_url' => $api_url,
            ]);
        }

        // La demo ya está en memoria: se la deja enganchada para que las guardas de más abajo no
        // vuelvan a buscarla en la base.
        $client_ecommerce->setRelation('demo', $demo);

        return $client_ecommerce;
    }

    /**
     * Tronco común de los tres endpoints de arranque: valida, crea la corrida y encola el job.
     *
     * Estaba copiado y pegado tres veces con el `mode` cambiado. Se unifica ahora porque la
     * polimorfización del dueño le agrega una validación más a cada uno, y mantener tres copias de
     * una lista de guardas es exactamente la forma de que una se quede sin la guarda nueva.
     * El orden de las verificaciones y los códigos de respuesta son los que ya tenían.
     *
     * @param  ClientEcommerce  $client_ecommerce
     * @param  string  $mode  'install' | 'update'
     * @return JsonResponse
     */
    protected function arrancar_corrida(ClientEcommerce $client_ecommerce, string $mode): JsonResponse
    {
        // Configuración mínima de la tienda (URL de SPA/API y dominio resoluble) antes de arrancar.
        $config_response = $this->assert_ecommerce_is_configured($client_ecommerce);
        if ($config_response !== null) {
            return $config_response;
        }

        // Requisitos del entorno de deploy (credenciales SSH, plantilla de .env, de dónde salen la
        // base y la APP_KEY) antes de encolar el job: si falta algo, se corta acá con un 422
        // legible en vez de a mitad de la corrida (ver assert_deploy_prerequisites()).
        $prerequisites_response = $this->assert_deploy_prerequisites($client_ecommerce, $mode);
        if ($prerequisites_response !== null) {
            return $prerequisites_response;
        }

        // No permitir solapar con una corrida ya en curso de esta misma tienda.
        $conflict_response = $this->assert_no_running_installation($client_ecommerce->id);
        if ($conflict_response !== null) {
            return $conflict_response;
        }

        $installation = ClientEcommerceInstallation::create([
            'client_ecommerce_id' => $client_ecommerce->id,
            'mode'                => $mode,
            'status'              => 'pendiente',
        ]);

        // Despacha el job en background (cola por defecto del sistema).
        RunEcommerceInstallationJob::dispatch($installation->uuid);

        return response()->json([
            'model' => $this->fullModel('client_ecommerce_installation', $installation->id),
        ], 201);
    }

    /**
     * Líneas de log de una corrida ordenadas por `created_at`, para el polling del panel.
     *
     * @param  ClientEcommerceInstallation  $installation  Resuelta por route model binding.
     * @return JsonResponse  { status: string, models: EcommerceDeploymentLog[] }
     */
    public function logs_json(ClientEcommerceInstallation $installation): JsonResponse
    {
        $logs = $installation->logs()->orderBy('created_at')->get();

        return response()->json([
            'status' => $installation->status,
            'models' => $logs,
        ]);
    }

    /**
     * Elimina una corrida de instalación/actualización y sus deployment_logs asociados.
     *
     * No permite eliminar una corrida en estado 'instalando': hay un
     * RunEcommerceInstallationJob corriendo en background sobre ese registro y
     * borrarlo a mitad de camino lo dejaría escribiendo sobre un modelo inexistente.
     *
     * @param  ClientEcommerceInstallation  $installation  Corrida a eliminar.
     * @return JsonResponse  { deleted: true } o { error: string } (422 si está en curso)
     */
    public function destroy_json(ClientEcommerceInstallation $installation): JsonResponse
    {
        // Bloquea el borrado mientras el job de instalación/actualización está corriendo en background.
        if ($installation->status === 'instalando') {
            return response()->json([
                'error' => 'No se puede eliminar una corrida en curso. Esperá a que termine o falle, o revisá el proceso en el VPS antes de forzar el borrado.',
            ], 422);
        }

        // ecommerce_deployment_logs no tiene FK en BD (convención del proyecto: sin FK, integridad en Eloquent), hay que limpiarlo a mano.
        EcommerceDeploymentLog::where('client_ecommerce_installation_id', $installation->id)->delete();

        $installation->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * Valida que no haya otra corrida en curso ('instalando') para la misma tienda: instalación y
     * actualización comparten el mismo pipeline SSH/SFTP y no deben solaparse.
     *
     * @param  int  $client_ecommerce_id
     * @return JsonResponse|null  Respuesta 422 si hay conflicto; null si se puede continuar.
     */
    protected function assert_no_running_installation(int $client_ecommerce_id): ?JsonResponse
    {
        $already_running = ClientEcommerceInstallation::where('client_ecommerce_id', $client_ecommerce_id)
            ->where('status', 'instalando')
            ->exists();

        if (! $already_running) {
            return null;
        }

        return response()->json([
            'error' => 'Ya hay una corrida de instalación/actualización en curso para esta tienda.',
        ], 422);
    }

    /**
     * Valida que la tienda tenga la configuración mínima cargada para poder arrancar cualquier
     * corrida (instalación o actualización): URL del SPA, URL de la API y un dominio resoluble.
     *
     * Se usa `resolve_domain()` (no la columna cruda `domain`) porque un cliente puede tener
     * cargada la URL del SPA y todavía no el campo `domain` a mano: en ese caso el dominio se
     * deriva solo de la URL y no hace falta pedirlo aparte.
     *
     * Unifica la validación que antes solo tenía `start_update_json()`: los tres endpoints de
     * arranque (`start_install_json`, `start_update_json`, `start_install_for_client_json`) la
     * llaman siempre antes de `assert_no_running_installation()`.
     *
     * @param  ClientEcommerce  $client_ecommerce
     * @return JsonResponse|null  Respuesta 422 si falta configuración; null si está todo cargado.
     */
    protected function assert_ecommerce_is_configured(ClientEcommerce $client_ecommerce): ?JsonResponse
    {
        // Campos mínimos: URL del SPA, URL de la API, y un dominio que se pueda resolver
        // (a mano en `domain` o derivado del host de `spa_url`).
        $missing_fields = [];
        if (empty($client_ecommerce->spa_url)) {
            $missing_fields[] = 'URL del SPA';
        }
        if (empty($client_ecommerce->api_url)) {
            $missing_fields[] = 'URL de la API';
        }
        if ($client_ecommerce->resolve_domain() === '') {
            $missing_fields[] = 'dominio';
        }

        if (empty($missing_fields)) {
            return null;
        }

        // El dónde-cargarlo cambia según el dueño: el perfil del cliente o el módulo de Demos.
        // Un mensaje que manda al lugar equivocado es peor que uno genérico.
        $donde_cargarlo = $client_ecommerce->is_demo()
            ? 'Cargalo en el módulo de Demos, en las URLs de ecommerce de esta demo.'
            : 'Cargalo en la sección "Tienda online (ecommerce)" del perfil del cliente en el admin.';

        return response()->json([
            'error' => 'La tienda del cliente no está configurada todavía (falta: '
                . implode(', ', $missing_fields)
                . '). ' . $donde_cargarlo,
        ], 422);
    }

    /**
     * Valida, antes de encolar el job, que el entorno de deploy tenga lo mínimo para completar la
     * corrida sin morir a mitad de camino: credenciales SSH del VPS de builds y del hosting
     * compartido (siempre), y en instalaciones desde cero además la plantilla de `.env` de tienda
     * y una API de empresa activa en el cliente (de ahí sale la DB y la APP_KEY que se copian al
     * `.env` de tienda-api).
     *
     * Se llama siempre después de `assert_ecommerce_is_configured()` y antes de
     * `assert_no_running_installation()` en los tres endpoints de arranque
     * (`start_install_json`, `start_update_json`, `start_install_for_client_json`).
     *
     * Si falla más de una verificación, se reporta la primera (mismo criterio que
     * `assert_ecommerce_is_configured()`).
     *
     * @param  ClientEcommerce  $client_ecommerce
     * @param  string  $mode  'install' o 'update'. Las verificaciones de plantilla de .env y API
     *                        de empresa activa solo aplican a 'install' (el pipeline de 'update'
     *                        no reescribe el .env de tienda-api).
     * @return JsonResponse|null  Respuesta 422 si falta algo del entorno; null si se puede continuar.
     */
    protected function assert_deploy_prerequisites(ClientEcommerce $client_ecommerce, string $mode): ?JsonResponse
    {
        // Credenciales SSH del VPS donde se compila tienda-spa/tienda-api. Sin esta fila el job
        // muere adentro de connect_build_vps() con un ModelNotFoundException de Eloquent.
        $has_vps_credential = ClientSshCredential::where('type', 'vps')->exists();
        if (! $has_vps_credential) {
            return response()->json([
                'error' => 'Faltan las credenciales SSH del VPS de builds. Cargalas en el admin antes de arrancar la instalación.',
            ], 422);
        }

        // Credenciales SSH del hosting compartido donde se sube tienda-spa/tienda-api ya compilado.
        // Sin esta fila el job muere adentro de connect_hosting_ssh() con el mismo tipo de error.
        $has_hosting_credential = ClientSshCredential::where('type', 'shared_hosting')->exists();
        if (! $has_hosting_credential) {
            return response()->json([
                'error' => 'Faltan las credenciales SSH del hosting compartido. Cargalas en el admin antes de arrancar la instalación.',
            ], 422);
        }

        // 🔴 Demo con el ecommerce marcado como VPS: el pipeline no lo soporta todavía y falla en
        // el constructor del servicio. Se repite la guarda acá para que el panel reciba un 422
        // legible en vez de una corrida que nace y muere en la cola. Aplica a los dos modes.
        if ($client_ecommerce->is_demo()) {
            $demo = $client_ecommerce->demo;
            if ($demo !== null && (new DemoPathResolver())->ecommerce_is_vps($demo)) {
                return response()->json([
                    'error' => DemoPathResolver::ECOMMERCE_VPS_NO_SOPORTADO,
                ], 422);
            }
        }

        // Las dos verificaciones siguientes solo aplican a instalaciones desde cero: el pipeline
        // de 'update' no vuelve a escribir el .env de tienda-api, así que no las necesita.
        if ($mode === 'install') {
            // Plantilla base del .env de tienda ('scope' = 'tienda'). Si no hay filas, el .env sale
            // con lo mínimo indispensable y tienda-api queda instalada pero sin bootear.
            $has_tienda_env_template = EnvTemplate::where('scope', 'tienda')->exists();
            if (! $has_tienda_env_template) {
                return response()->json([
                    'error' => 'No hay una plantilla de .env de tienda cargada. Cargala o corré el seeder de plantillas de tienda en admin-api antes de arrancar la instalación.',
                ], 422);
            }

            // De dónde salen DB_DATABASE, DB_USERNAME, DB_PASSWORD y APP_KEY para el .env de
            // tienda-api (misma base de datos física que la empresa del mismo dueño).
            if ($client_ecommerce->is_demo()) {
                // Demo: se lee el .env del ERP de la demo, en la ruta que resuelve
                // DemoPathResolver::api_path() a partir del subdominio de la «ERP SPA URL».
                $demo = $client_ecommerce->demo;
                if ($demo === null || trim((string) $demo->erp_spa_url) === '') {
                    return response()->json([
                        'error' => 'La tienda toma la base de datos y la clave de la aplicación del .env del ERP de la demo, así que la demo necesita su «ERP SPA URL» cargada en el módulo de Demos.',
                    ], 422);
                }
            } elseif ($client_ecommerce->client === null || $client_ecommerce->client->active_client_api === null) {
                // Cliente: la API de empresa activa de su perfil (comportamiento de siempre).
                return response()->json([
                    'error' => 'La tienda toma la base de datos y la clave de la aplicación del .env de la API de empresa del cliente, así que el cliente necesita una API activa seleccionada en su perfil.',
                ], 422);
            }
        }

        return null;
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\CommonLaravel\BaseController;
use App\Jobs\RunEcommerceInstallationJob;
use App\Models\Client;
use App\Models\ClientEcommerce;
use App\Models\ClientEcommerceInstallation;
use App\Models\ClientSshCredential;
use App\Models\EcommerceDeploymentLog;
use App\Models\EnvTemplate;
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
 * Sin lógica de negocio acá (regla del proyecto): toda la orquestación del pipeline vive en
 * `EcommerceInstallationService`/`EcommerceDeploymentService` y en el job de cola.
 */
class EcommerceInstallationController extends BaseController
{
    /**
     * Lista todas las corridas de instalación/actualización de ecommerce (todos los clientes),
     * equivalente a `ClientInstallationController::index_all()`.
     *
     * @return JsonResponse  { models: ClientEcommerceInstallation[] }
     */
    public function index_json(): JsonResponse
    {
        $installations = ClientEcommerceInstallation::withAll()
            ->orderByDesc('id')
            ->get();

        return response()->json(['models' => $installations]);
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
        $client_ecommerce->load(['client', 'installations' => function ($query) {
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
        // Configuración mínima de la tienda (URL de SPA/API y dominio resoluble) antes de arrancar.
        $config_response = $this->assert_ecommerce_is_configured($client_ecommerce);
        if ($config_response !== null) {
            return $config_response;
        }

        // Requisitos del entorno de deploy (credenciales SSH, plantilla de .env, API activa) antes
        // de encolar el job: si falta algo, se corta acá con un 422 legible en vez de a mitad de la
        // corrida (ver assert_deploy_prerequisites()).
        $prerequisites_response = $this->assert_deploy_prerequisites($client_ecommerce, 'install');
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
            'mode'                => 'install',
            'status'              => 'pendiente',
        ]);

        // Despacha el job en background (cola por defecto del sistema).
        RunEcommerceInstallationJob::dispatch($installation->uuid);

        return response()->json([
            'model' => $this->fullModel('client_ecommerce_installation', $installation->id),
        ], 201);
    }

    /**
     * Dispara una actualización (`mode = 'update'`) del ecommerce ya configurado de un cliente.
     *
     * Trivial por diseño (pedido de Lucas): recibe solo el cliente, resuelve su `ClientEcommerce`
     * ya configurado y siempre usa la última versión de la rama `master` — no recibe versión/tag.
     *
     * @param  Request  $request  { client_id }
     * @return JsonResponse  { model: ClientEcommerceInstallation } o { error: string } (404/422)
     */
    public function start_update_json(Request $request): JsonResponse
    {
        // client_id es el único dato que necesita el panel para disparar una actualización.
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

        // Configuración mínima de la tienda (URL de SPA/API y dominio resoluble) antes de arrancar.
        $config_response = $this->assert_ecommerce_is_configured($client_ecommerce);
        if ($config_response !== null) {
            return $config_response;
        }

        // Requisitos del entorno de deploy antes de encolar (ver assert_deploy_prerequisites()).
        $prerequisites_response = $this->assert_deploy_prerequisites($client_ecommerce, 'update');
        if ($prerequisites_response !== null) {
            return $prerequisites_response;
        }

        $conflict_response = $this->assert_no_running_installation($client_ecommerce->id);
        if ($conflict_response !== null) {
            return $conflict_response;
        }

        $installation = ClientEcommerceInstallation::create([
            'client_ecommerce_id' => $client_ecommerce->id,
            'mode'                => 'update',
            'status'              => 'pendiente',
        ]);

        RunEcommerceInstallationJob::dispatch($installation->uuid);

        return response()->json([
            'model' => $this->fullModel('client_ecommerce_installation', $installation->id),
        ], 201);
    }

    /**
     * Dispara una instalación desde cero (`mode = 'install'`) resolviendo el `ClientEcommerce`
     * a partir del cliente, para el submódulo global "Instalaciones > Ecommerce" (a diferencia de
     * `start_install_json`, que requiere conocer de antemano el id del `ClientEcommerce` y se usa
     * desde el detalle embebido de un cliente puntual).
     *
     * @param  Request  $request  { client_id }
     * @return JsonResponse  { model: ClientEcommerceInstallation } o { error: string } (404/422)
     */
    public function start_install_for_client_json(Request $request): JsonResponse
    {
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

        // Configuración mínima de la tienda (URL de SPA/API y dominio resoluble) antes de arrancar.
        $config_response = $this->assert_ecommerce_is_configured($client_ecommerce);
        if ($config_response !== null) {
            return $config_response;
        }

        // Requisitos del entorno de deploy antes de encolar (ver assert_deploy_prerequisites()).
        $prerequisites_response = $this->assert_deploy_prerequisites($client_ecommerce, 'install');
        if ($prerequisites_response !== null) {
            return $prerequisites_response;
        }

        $conflict_response = $this->assert_no_running_installation($client_ecommerce->id);
        if ($conflict_response !== null) {
            return $conflict_response;
        }

        $installation = ClientEcommerceInstallation::create([
            'client_ecommerce_id' => $client_ecommerce->id,
            'mode'                => 'install',
            'status'              => 'pendiente',
        ]);

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

        return response()->json([
            'error' => 'La tienda del cliente no está configurada todavía (falta: '
                . implode(', ', $missing_fields)
                . '). Cargalo en la sección "Tienda online (ecommerce)" del perfil del cliente en el admin.',
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

            // API de empresa activa del cliente: de su .env se copian DB_DATABASE, DB_USERNAME,
            // DB_PASSWORD y APP_KEY para el .env de tienda-api (misma base de datos física).
            if ($client_ecommerce->client === null || $client_ecommerce->client->active_client_api === null) {
                return response()->json([
                    'error' => 'La tienda toma la base de datos y la clave de la aplicación del .env de la API de empresa del cliente, así que el cliente necesita una API activa seleccionada en su perfil.',
                ], 422);
            }
        }

        return null;
    }
}

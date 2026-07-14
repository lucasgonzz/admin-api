<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RunClientInstallationJob;
use App\Models\Client;
use App\Models\ClientApi;
use App\Models\ClientInstallation;
use App\Models\DeploymentLog;
use App\Models\EnvTemplate;
use App\Models\Version;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gestión de instalaciones iniciales de sistema para clientes.
 *
 * Maneja el ciclo de vida completo de una ClientInstallation:
 * crear, cargar valores manuales de .env e iniciar el pipeline en background.
 */
class ClientInstallationController extends Controller
{
    /**
     * Lista todas las instalaciones del sistema (todos los clientes).
     *
     * Usado por el ítem del menú lateral en admin-spa.
     *
     * @return JsonResponse  { models: ClientInstallation[] }
     */
    public function index_all(): JsonResponse
    {
        // Carga instalaciones de todos los clientes con relaciones para el listado global.
        $installations = ClientInstallation::withAll()
            ->orderByDesc('id')
            ->get();

        return response()->json(['models' => $installations]);
    }

    /**
     * Lista todas las instalaciones de un cliente con sus relaciones completas.
     *
     * @param  Client  $client  Cliente resuelto por route model binding.
     * @return JsonResponse  { models: ClientInstallation[] }
     */
    public function index(Client $client): JsonResponse
    {
        // Carga todas las instalaciones del cliente con sus relaciones para la UI.
        $installations = ClientInstallation::where('client_id', $client->id)
            ->withAll()
            ->orderByDesc('id')
            ->get();

        return response()->json(['models' => $installations]);
    }

    /**
     * Crea una nueva instalación en estado 'pendiente'.
     *
     * Usa el active_client_api_id del cliente como API destino y la versión
     * publicada más reciente como versión a instalar.
     *
     * @param  Client  $client  Cliente al que pertenece la instalación.
     * @return JsonResponse  { model: ClientInstallation }
     */
    public function store(Client $client): JsonResponse
    {
        // Versión publicada más reciente disponible para instalar.
        $latest_version = Version::where('status', 'published')
            ->orderByDesc('id')
            ->first();

        // Crea la instalación con estado inicial 'pendiente'.
        $installation = ClientInstallation::create([
            'client_id'     => $client->id,
            'client_api_id' => $client->active_client_api_id,
            'version_id'    => $latest_version ? $latest_version->id : null,
            'status'        => 'pendiente',
        ]);

        // Recarga con relaciones para devolver al frontend.
        $installation->load(['client', 'client_api', 'version', 'deployment_logs']);

        return response()->json(['model' => $installation], 201);
    }

    /**
     * Crea una nueva instalación en estado 'pendiente' desde la raíz del módulo de
     * instalaciones (Installations.vue), sin pasar por la pestaña del cliente.
     *
     * A diferencia de store(), acá el cliente, la API destino y la versión se reciben
     * explícitamente en el request (con fallback a los valores activos del cliente si
     * no se informan), en vez de asumir siempre la API activa y la última versión.
     *
     * @param  Request  $request  { client_id, client_api_id?, version_id? }
     * @return JsonResponse  { model: ClientInstallation } o { error: string } (422)
     */
    public function store_global(Request $request): JsonResponse
    {
        // Validación de entrada: client_id obligatorio y debe existir; client_api_id y
        // version_id son opcionales (se resuelven con fallback más abajo).
        $request->validate([
            'client_id'     => 'required|integer|exists:clients,id',
            'client_api_id' => 'nullable|integer',
            'version_id'    => 'nullable|integer',
        ]);

        // Cliente destino de la nueva instalación.
        $client = Client::findOrFail($request->input('client_id'));

        // Resuelve client_api_id: el del request si viene y pertenece al cliente; si no,
        // el que tenga activo el cliente en su perfil.
        $client_api_id = $request->input('client_api_id');
        if ($client_api_id !== null) {
            // No confiar en el client_id que venga en el request: se valida contra la
            // ClientApi real que corresponde al client_api_id recibido.
            $client_api = ClientApi::find($client_api_id);
            if ($client_api === null || (int) $client_api->client_id !== (int) $client->id) {
                return response()->json([
                    'error' => 'La API indicada no pertenece al cliente seleccionado.',
                ], 422);
            }
        } else {
            $client_api_id = $client->active_client_api_id;
        }

        if (empty($client_api_id)) {
            return response()->json([
                'error' => 'El cliente no tiene API destino: informala en el request o cargá una API activa en su perfil.',
            ], 422);
        }

        // Resuelve version_id: la del request si viene; si no, la última versión publicada
        // (misma lógica que store()).
        $version_id = $request->input('version_id');
        if ($version_id === null) {
            $latest_version = Version::where('status', 'published')
                ->orderByDesc('id')
                ->first();
            $version_id = $latest_version ? $latest_version->id : null;
        }

        if (empty($version_id)) {
            return response()->json([
                'error' => 'No hay versión para instalar: informala en el request o publicá una versión primero.',
            ], 422);
        }

        // Crea la instalación con estado inicial 'pendiente'.
        $installation = ClientInstallation::create([
            'client_id'     => $client->id,
            'client_api_id' => $client_api_id,
            'version_id'    => $version_id,
            'status'        => 'pendiente',
        ]);

        // Recarga con relaciones para devolver al frontend (mismo shape que store()).
        $installation->load(['client', 'client_api', 'version', 'deployment_logs']);

        return response()->json(['model' => $installation], 201);
    }

    /**
     * Devuelve una instalación puntual con todas sus relaciones.
     *
     * Usado por el modal de gestión (admin-spa) para refrescar estado y logs
     * sin depender del listado completo del cliente.
     *
     * @param  ClientInstallation  $installation  Instalación resuelta por route model binding.
     * @return JsonResponse  { model: ClientInstallation }
     */
    public function show(ClientInstallation $installation): JsonResponse
    {
        // Carga las relaciones necesarias para el modal de gestión (mismo shape que store/start).
        $installation->load(['client', 'client_api', 'version', 'deployment_logs']);

        return response()->json(['model' => $installation]);
    }

    /**
     * Elimina una instalación y sus deployment_logs asociados.
     *
     * No permite eliminar una instalación en estado 'instalando': hay un
     * RunClientInstallationJob corriendo en background sobre ese registro y
     * borrarlo a mitad de camino lo dejaría escribiendo sobre un modelo inexistente.
     *
     * @param  ClientInstallation  $installation  Instalación a eliminar.
     * @return JsonResponse  { deleted: true } o { error: string } (422 si está en curso)
     */
    public function destroy(ClientInstallation $installation): JsonResponse
    {
        // Bloquea el borrado mientras el job de instalación está corriendo en background.
        if ($installation->status === 'instalando') {
            return response()->json([
                'error' => 'No se puede eliminar una instalación en curso. Esperá a que termine o falle, o revisá el proceso en el VPS antes de forzar el borrado.',
            ], 422);
        }

        // deployment_logs no tiene FK en BD (convención del proyecto: sin FK, integridad en Eloquent), hay que limpiarlo a mano.
        DeploymentLog::where('client_installation_id', $installation->id)->delete();

        $installation->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * Actualiza los valores de variables is_manual_on_create en la instalación.
     *
     * Solo permite guardar valores para las claves que tienen is_manual_on_create = true
     * en la tabla env_templates. Valores extra en el request son ignorados.
     *
     * @param  ClientInstallation  $installation  Instalación a actualizar.
     * @param  Request             $request        { values: { KEY: value, ... } }
     * @return JsonResponse  { model: ClientInstallation }
     */
    public function update_env_values(ClientInstallation $installation, Request $request): JsonResponse
    {
        $request->validate([
            'values'   => 'required|array',
            'values.*' => 'nullable|string',
        ]);

        // Solo se permiten las claves marcadas como manuales en el template.
        $allowed_keys = EnvTemplate::where('is_manual_on_create', true)
            ->pluck('key')
            ->all();

        // Filtra el input para aceptar únicamente claves permitidas.
        $raw_values     = $request->input('values', []);
        $filtered_values = [];
        foreach ($raw_values as $key => $value) {
            if (in_array($key, $allowed_keys, true)) {
                $filtered_values[$key] = $value;
            }
        }

        // Combina con los valores ya almacenados para no perder claves no enviadas.
        $existing_values = $installation->env_manual_values ?? [];
        $merged_values   = array_merge($existing_values, $filtered_values);

        $installation->update(['env_manual_values' => $merged_values]);

        $installation->load(['client', 'client_api', 'version', 'deployment_logs']);

        return response()->json(['model' => $installation]);
    }

    /**
     * Inicia el pipeline de instalación en un Job de background.
     *
     * Valida que todos los campos is_manual_on_create tengan valor en env_manual_values
     * antes de despachar el job. Cambia el status a 'instalando'.
     *
     * @param  ClientInstallation  $installation  Instalación a iniciar.
     * @return JsonResponse  { model: ClientInstallation } o { error: string }
     */
    public function start(ClientInstallation $installation): JsonResponse
    {
        // Solo se puede iniciar una instalación en estado 'pendiente'.
        if ($installation->status !== 'pendiente') {
            return response()->json([
                'error' => "No se puede iniciar una instalación en estado '{$installation->status}'.",
            ], 422);
        }

        // Obtiene todas las variables que requieren valor manual.
        $manual_templates = EnvTemplate::where('is_manual_on_create', true)->get();

        // Array de valores actuales guardados en la instalación.
        $env_manual_values = $installation->env_manual_values ?? [];

        // Valida que cada variable manual tenga un valor cargado (no vacío).
        $missing_keys = [];
        foreach ($manual_templates as $template) {
            $value = $env_manual_values[$template->key] ?? '';
            if (trim((string) $value) === '') {
                $missing_keys[] = $template->key;
            }
        }

        if (! empty($missing_keys)) {
            return response()->json([
                'error'        => 'Faltan valores para variables requeridas antes de iniciar.',
                'missing_keys' => $missing_keys,
            ], 422);
        }

        // Cambia el status a 'instalando' antes de despachar el job.
        $installation->update(['status' => 'instalando']);

        // Despacha el job en background (cola por defecto del sistema).
        RunClientInstallationJob::dispatch($installation->uuid);

        $installation->load(['client', 'client_api', 'version', 'deployment_logs']);

        return response()->json(['model' => $installation]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\BaseController;
use App\Jobs\RunDeploymentJob;
use App\Models\Client;
use App\Models\ClientApi;
use App\Models\ClientVersionUpgrade;
use Illuminate\Http\Request;

/**
 * Endpoints JSON de deployment automatizado y APIs por cliente.
 */
class DeploymentController extends BaseController
{
    /**
     * Estados que indican un deployment aún activo (no se puede iniciar otro).
     *
     * @var array<int, string>
     */
    protected $active_deployment_statuses = ['running', 'paused', 'paused_post_tasks'];

    /**
     * Inicia el deployment de un upgrade.
     *
     * @param  string  $id  UUID del ClientVersionUpgrade
     * @return \Illuminate\Http\JsonResponse
     */
    public function start_json($id)
    {
        $upgrade = $this->find_upgrade_by_route_id($id);

        // No permitir solapar con un deployment en curso.
        if (in_array($upgrade->deployment_status, $this->active_deployment_statuses, true)) {
            return response()->json([
                'message' => 'Ya hay un deployment en curso para este upgrade.',
            ], 422);
        }

        // API destino obligatoria antes de arrancar.
        if (empty($upgrade->target_client_api_id)) {
            return response()->json([
                'message' => 'Debe configurar la API destino (target_client_api) antes de iniciar el deployment.',
            ], 422);
        }

        // Reinicio limpio: borrar logs del intento anterior (fallido o incompleto).
        $upgrade->deployment_logs()->delete();

        $upgrade->update([
            'deployment_status'     => 'running',
            'deployment_started_at' => now(),
        ]);

        RunDeploymentJob::dispatch($upgrade);

        return response()->json([
            'model' => $upgrade->fresh()->loadMissing('target_client_api', 'deployment_logs'),
        ], 200);
    }

    /**
     * Inicia seeders y comandos post-cierre (requiere crons marcados y deployment en pausa pre-cierre).
     *
     * @param  string  $id  UUID del ClientVersionUpgrade
     * @return \Illuminate\Http\JsonResponse
     */
    public function start_post_closure_json($id)
    {
        $upgrade = $this->find_upgrade_by_route_id($id);

        if ($upgrade->deployment_status !== 'paused') {
            return response()->json([
                'message' => 'El deployment no está pausado esperando tareas post-cierre.',
            ], 422);
        }

        if (empty($upgrade->crons_supervisor_at)) {
            return response()->json([
                'message' => 'Debe marcar Crons / Supervisor como hecho antes de iniciar las tareas post-cierre.',
            ], 422);
        }

        $upgrade->update([
            'deployment_status' => 'running',
        ]);

        RunDeploymentJob::dispatch($upgrade, 'run_seeders');

        return response()->json([
            'model' => $upgrade->fresh()->loadMissing('target_client_api', 'deployment_logs'),
        ], 200);
    }

    /**
     * Ejecuta cambio de URL / versión por defecto (última etapa post-cierre).
     *
     * @param  string  $id  UUID del ClientVersionUpgrade
     * @return \Illuminate\Http\JsonResponse
     */
    public function configure_system_json($id)
    {
        $upgrade = $this->find_upgrade_by_route_id($id);

        if ($upgrade->deployment_status !== 'paused_post_tasks') {
            return response()->json([
                'message' => 'El deployment no está listo para configurar el sistema (URL/versión por defecto).',
            ], 422);
        }

        $upgrade->update([
            'deployment_status' => 'running',
        ]);

        RunDeploymentJob::dispatch($upgrade, 'update_default_version');

        return response()->json([
            'model' => $upgrade->fresh()->loadMissing('target_client_api', 'deployment_logs'),
        ], 200);
    }

    /**
     * Reintenta comandos automatizados desde el primero fallido o pendiente (no manual).
     * Omite los ya exitosos y los marcados como ejecución manual.
     *
     * @param  string  $id  UUID del ClientVersionUpgrade
     * @return \Illuminate\Http\JsonResponse
     */
    public function retry_commands_json($id)
    {
        $upgrade = $this->find_upgrade_by_route_id($id);

        if ($upgrade->deployment_status === 'running') {
            return response()->json([
                'message' => 'Hay un deployment en curso para este upgrade.',
            ], 422);
        }

        $upgrade->loadMissing('update_commands.version_command', 'update_seeders');

        // Un seeder saltado (skipped) se considera completado a efectos del reintento.
        $seeders_incomplete = $upgrade->update_seeders->contains(function ($update_seeder) {
            if ((bool) $update_seeder->skipped) {
                return false;
            }

            return $update_seeder->status !== 'exitoso';
        });

        if ($seeders_incomplete) {
            return response()->json([
                'message' => 'Completá o reintentá los seeders antes de reintentar los comandos.',
            ], 422);
        }

        // Un comando saltado (skipped) no es retriable.
        $has_retryable_command = $upgrade->update_commands->contains(function ($update_command) {
            $version_command = $update_command->version_command;
            if ($version_command === null) {
                return false;
            }
            if ((bool) $version_command->run_manually) {
                return false;
            }
            if ((bool) $update_command->skipped) {
                return false;
            }

            return in_array($update_command->status, ['fallido', 'pendiente'], true);
        });

        if (! $has_retryable_command) {
            return response()->json([
                'message' => 'No hay comandos automatizados pendientes o fallidos para reintentar.',
            ], 422);
        }

        $upgrade->update([
            'deployment_status' => 'running',
        ]);

        RunDeploymentJob::dispatch($upgrade, 'run_commands');

        return response()->json([
            'model' => $upgrade->fresh()->loadMissing('target_client_api', 'deployment_logs'),
        ], 200);
    }

    /**
     * @deprecated Usar start_post_closure_json. Mantenido por compatibilidad.
     *
     * @param  string  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function confirm_crons_json($id)
    {
        return $this->start_post_closure_json($id);
    }

    /**
     * Lista líneas de log del deployment ordenadas por created_at.
     *
     * @param  string  $id  UUID del ClientVersionUpgrade
     * @return \Illuminate\Http\JsonResponse
     */
    public function logs_json($id)
    {
        $upgrade = $this->find_upgrade_by_route_id($id);

        $logs = $upgrade->deployment_logs()->orderBy('created_at')->get();

        return response()->json(['models' => $logs], 200);
    }

    /**
     * Crea un endpoint de API para un cliente.
     *
     * @param  Request  $request
     * @param  string   $clientId  UUID del Client
     * @return \Illuminate\Http\JsonResponse
     */
    public function store_client_api_json(Request $request, $clientId)
    {
        $client = $this->find_client_by_route_id($clientId);

        $validated = $request->validate([
            'url'  => 'required|url',
            'path' => 'required|string|max:255',
        ]);

        // URL sin barra final, coherente con ClientController.
        $url = rtrim($validated['url'], '/');

        $client_api = $client->client_apis()->create([
            'url'  => $url,
            'path' => $validated['path'],
        ]);

        return response()->json(['model' => $client_api], 201);
    }

    /**
     * Actualiza un ClientApi del cliente.
     *
     * @param  Request  $request
     * @param  string   $clientId  UUID del Client
     * @param  string   $apiId     UUID del ClientApi
     * @return \Illuminate\Http\JsonResponse
     */
    public function update_client_api_json(Request $request, $clientId, $apiId)
    {
        $client = $this->find_client_by_route_id($clientId);
        $client_api = $this->find_client_api_for_client($client, $apiId);

        $validated = $request->validate([
            'url'           => 'sometimes|required|url',
            'path'          => 'sometimes|required|string|max:255',
            'spa_url'       => 'nullable|url',
            'hosting_type'  => 'sometimes|required|in:shared_hosting,vps',
            /* Identificador del cliente en el VPS; solo requerido cuando hosting_type=vps */
            'vps_path'      => 'nullable|string|max:255',
        ]);

        if (array_key_exists('url', $validated) && is_string($validated['url'])) {
            $validated['url'] = rtrim($validated['url'], '/');
        }
        if (array_key_exists('spa_url', $validated) && is_string($validated['spa_url'])) {
            $validated['spa_url'] = rtrim($validated['spa_url'], '/');
        }

        $client_api->update($validated);

        return response()->json(['model' => $client_api->fresh()], 200);
    }

    /**
     * Elimina un ClientApi del cliente si no es el activo.
     *
     * @param  string  $clientId  UUID del Client
     * @param  string  $apiId     UUID del ClientApi
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy_client_api_json($clientId, $apiId)
    {
        $client = $this->find_client_by_route_id($clientId);
        $client_api = $this->find_client_api_for_client($client, $apiId);

        if ((int) $client->active_client_api_id === (int) $client_api->id) {
            return response()->json([
                'message' => 'No se puede eliminar la API activa',
            ], 422);
        }

        $client_api->delete();

        return response()->json(['message' => 'API eliminada'], 200);
    }

    /**
     * Marca un ClientApi como API activa del cliente.
     *
     * @param  string  $clientId  UUID del Client
     * @param  string  $apiId     UUID del ClientApi
     * @return \Illuminate\Http\JsonResponse
     */
    public function set_active_api_json($clientId, $apiId)
    {
        $client = $this->find_client_by_route_id($clientId);
        $client_api = $this->find_client_api_for_client($client, $apiId);

        $client->active_client_api_id = $client_api->id;
        $client->save();

        return response()->json([
            'model' => $client->fresh()->loadMissing('active_client_api', 'client_apis'),
        ], 200);
    }

    /**
     * Busca ClientVersionUpgrade por id numérico o uuid (coherente con UpdateController).
     *
     * @param  int|string  $id
     * @return ClientVersionUpgrade
     */
    protected function find_upgrade_by_route_id($id)
    {
        if (is_numeric($id)) {
            return ClientVersionUpgrade::where('id', (int) $id)->firstOrFail();
        }

        return ClientVersionUpgrade::where('uuid', (string) $id)->firstOrFail();
    }

    /**
     * Busca Client por id numérico o uuid (coherente con ClientController / ClientEmployeeController).
     *
     * @param  int|string  $route_id
     * @return Client
     */
    protected function find_client_by_route_id($route_id)
    {
        if (is_numeric($route_id)) {
            return Client::findOrFail((int) $route_id);
        }

        return Client::where('uuid', (string) $route_id)->firstOrFail();
    }

    /**
     * Busca ClientApi por uuid validando que pertenezca al cliente.
     *
     * @param  Client  $client
     * @param  string  $api_uuid
     * @return ClientApi
     */
    protected function find_client_api_for_client(Client $client, $api_uuid)
    {
        return ClientApi::where('uuid', $api_uuid)
            ->where('client_id', $client->id)
            ->firstOrFail();
    }
}

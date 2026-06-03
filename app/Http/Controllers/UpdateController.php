<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\BaseController;
use App\Http\Controllers\CommonLaravel\Helpers\ModelPropertiesHelper;
use App\Models\Client;
use App\Models\ClientNotificationRead;
use App\Models\ClientVersionUpgrade;
use App\Models\UpdateCommand;
use App\Models\UpdateSeeder;
use App\Models\Version;
use App\Services\PublishVersionService;
use App\Services\VersionPathService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UpdateController extends BaseController
{
    /** Transiciones de estado permitidas (en orden). */
    const STATUS_FLOW = [
        'pendiente'             => 'listo_para_actualizar',
        'listo_para_actualizar' => 'actualizandose',
        'actualizandose'        => null,
        'terminada'             => null,
        'fallida'               => null,
    ];

    const STATUS_LABELS = [
        'pendiente'             => 'Pendiente',
        'listo_para_actualizar' => 'Listo para actualizar',
        'actualizandose'        => 'Actualizándose',
        'terminada'             => 'Terminada',
        'fallida'               => 'Fallida',
    ];

    function index(Request $request) {
        $query = ClientVersionUpgrade::with('client', 'from_version', 'to_version', 'created_by_admin')
            ->withCount([
                'update_seeders as seeders_failed_count' => function ($q) {
                    $q->where('status', 'fallido');
                },
                'update_commands as commands_failed_count' => function ($q) {
                    $q->where('status', 'fallido');
                },
                'update_seeders as seeders_total_count',
                'update_commands as commands_total_count',
                'update_seeders as seeders_done_count' => function ($q) {
                    $q->where('status', 'exitoso');
                },
                'update_commands as commands_done_count' => function ($q) {
                    $q->where('status', 'exitoso');
                },
            ])
            ->orderBy('id', 'desc');

        if ($request->filled('client_id')) {
            $query->where('client_id', $request->input('client_id'));
        }
        if ($request->filled('to_version_id')) {
            $query->where('to_version_id', $request->input('to_version_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $updates  = $query->paginate(30)->withQueryString();
        $clients  = Client::orderBy('name')->get();
        $versions = Version::orderBy('id', 'desc')->get();
        $statuses = self::STATUS_LABELS;

        return view('updates.index', compact('updates', 'clients', 'versions', 'statuses'));
    }

    function create(Request $request) {
        $clients         = Client::where('is_active', true)->orderBy('name')->get();
        $versions        = Version::where('status', 'published')->orderBy('id', 'desc')->get();
        $selected_client = $request->input('client_id');

        return view('updates.create', compact('clients', 'versions', 'selected_client'));
    }

    function store(Request $request) {
        $client  = Client::findOrFail($request->input('client_id'));
        $toId    = (int) $request->input('to_version_id');
        $version = Version::findOrFail($toId);

        $fromId = $client->current_version_id;
        // Incluye solo versiones estrictamente posteriores al estado actual del cliente, hasta el destino (id asc).
        $path   = VersionPathService::versionsInRangeWithSeedersAndCommands($fromId, $version->id, (int) $client->id);

        $upgrade = ClientVersionUpgrade::create(
            $this->build_upgrade_create_attributes($client, $fromId, $version->id, $request)
        );

        foreach ($path as $pathVersion) {
            foreach ($pathVersion->seeders as $seeder) {
                UpdateSeeder::create([
                    'client_version_upgrade_id' => $upgrade->id,
                    'version_seeder_id'         => $seeder->id,
                    'status'                    => 'pendiente',
                ]);
            }
        }

        foreach ($path as $pathVersion) {
            foreach ($pathVersion->commands as $command) {
                UpdateCommand::create([
                    'client_version_upgrade_id' => $upgrade->id,
                    'version_command_id'        => $command->id,
                    'status'                    => 'pendiente',
                ]);
            }
        }

        return redirect()->route('updates.show', $upgrade->id)
                         ->with('success', 'Actualización creada.');
    }

    function show($id) {
        $upgrade = ClientVersionUpgrade::withAll()->findOrFail($id);

        $to_version = $upgrade->to_version->load('manual_tasks', 'notifications');

        $fromId = $upgrade->from_version_id;
        $toId   = $upgrade->to_version_id;

        $clientId = (int) $upgrade->client_id;
        // (from, to] y solo ítems que aplican a este cliente (restricción por cliente en la versión).
        $aggregatedNotifications = VersionPathService::aggregatedNotifications($fromId, $toId, $clientId);
        $aggregatedManualTasks   = VersionPathService::aggregatedManualTasks($fromId, $toId, $clientId);

        $notificationIds = $aggregatedNotifications->pluck('id')->all();
        $readsByNotificationId = collect();
        if ($upgrade->client_id && !empty($notificationIds)) {
            $readsByNotificationId = ClientNotificationRead::query()
                ->where('client_id', $upgrade->client_id)
                ->whereIn('version_notification_id', $notificationIds)
                ->orderBy('read_at')
                ->get()
                ->groupBy(function (ClientNotificationRead $r) {
                    return (int) $r->version_notification_id;
                });
        }

        $next_status   = self::STATUS_FLOW[$upgrade->status] ?? null;
        $status_labels = self::STATUS_LABELS;

        return view('updates.show', compact(
            'upgrade',
            'to_version',
            'next_status',
            'status_labels',
            'aggregatedNotifications',
            'aggregatedManualTasks',
            'readsByNotificationId'
        ));
    }

    function advance_status(Request $request, $id) {
        $upgrade     = ClientVersionUpgrade::findOrFail($id);
        $next_status = self::STATUS_FLOW[$upgrade->status] ?? null;

        if (!$next_status) {
            return redirect()->route('updates.show', $id)
                             ->with('error', 'No se puede avanzar desde el estado actual.');
        }

        $data = ['status' => $next_status];

        if ($next_status === 'actualizandose' && !$upgrade->started_at) {
            $data['started_at'] = now();
        }

        $upgrade->update($data);

        return redirect()->route('updates.show', $id)
                         ->with('success', 'Estado actualizado a "' . self::STATUS_LABELS[$next_status] . '".');
    }

    function mark_step(Request $request, $id) {
        $upgrade = ClientVersionUpgrade::findOrFail($id);
        $step    = $request->input('step');

        $allowed_steps = [
            'sistema_actualizado_at',
            'migraciones_corridas_at',
            'crons_supervisor_at',
            'seeders_ejecutados_at',
            'comandos_ejecutados_at',
            'sistema_configurado_at',
        ];

        if (!in_array($step, $allowed_steps)) {
            return redirect()->route('updates.show', $id)
                             ->with('error', 'Step no reconocido.');
        }

        $unmark = $request->boolean('unmark');

        $upgrade->update([$step => $unmark ? null : now()]);

        return redirect()->route('updates.show', $id)
                         ->with('success', 'Step actualizado.');
    }

    function sync_to_client(Request $request, $id, PublishVersionService $service) {
        $upgrade = ClientVersionUpgrade::withAll()->findOrFail($id);

        $upgrade = $service->syncExisting($upgrade);

        if ($upgrade->status === 'terminada') {
            return redirect()->route('updates.show', $id)
                             ->with('success', 'Versión sincronizada correctamente al cliente.');
        }

        return redirect()->route('updates.show', $id)
                         ->with('error', 'No se pudo sincronizar: ' . $upgrade->notes);
    }

    // --- API JSON (admin-spa) ---

    public function index_json(Request $request)
    {
        $per = (int) $request->input('per_page', 50);
        if ($per < 1) {
            $per = 20;
        }
        if ($per > 200) {
            $per = 200;
        }
        $q = ClientVersionUpgrade::query()
            ->with('client', 'from_version', 'to_version', 'created_by_admin')
            ->orderBy('id', 'desc');
        if ($request->filled('client_id')) {
            $q->where('client_id', (int) $request->input('client_id'));
        }
        if ($request->filled('to_version_id')) {
            $q->where('to_version_id', (int) $request->input('to_version_id'));
        }
        if ($request->filled('status')) {
            $q->where('status', $request->input('status'));
        }
        if ($request->has('page')) {
            $models = $q->paginate($per);
        } else {
            $models = $q->get();
        }

        return response()->json(['models' => $models], 200);
    }

    public function show_json($id)
    {
        $m = $this->fullModel('update', $id);
        if (! $m) {
            return response()->json(['message' => 'No encontrado.'], 404);
        }

        return response()->json(['model' => $m], 200);
    }

    public function store_json(Request $request)
    {
        $client = Client::findOrFail($request->input('client_id'));
        $toId = (int) $request->input('to_version_id');
        $version = Version::findOrFail($toId);
        $fromId = $client->current_version_id;
        $path = VersionPathService::versionsInRangeWithSeedersAndCommands($fromId, $version->id, (int) $client->id);

        $upgrade = ClientVersionUpgrade::create(
            $this->build_upgrade_create_attributes($client, $fromId, $version->id, $request)
        );

        foreach ($path as $pathVersion) {
            foreach ($pathVersion->seeders as $seeder) {
                UpdateSeeder::create([
                    'client_version_upgrade_id' => $upgrade->id,
                    'version_seeder_id' => $seeder->id,
                    'status' => 'pendiente',
                ]);
            }
        }
        foreach ($path as $pathVersion) {
            foreach ($pathVersion->commands as $command) {
                UpdateCommand::create([
                    'client_version_upgrade_id' => $upgrade->id,
                    'version_command_id' => $command->id,
                    'status' => 'pendiente',
                ]);
            }
        }

        return response()->json(['model' => $this->fullModel('update', $upgrade->id)], 201);
    }

    public function update_json(Request $request, $id)
    {
        $upgrade = ClientVersionUpgrade::findOrFail($id);
        ModelPropertiesHelper::set_from_request($upgrade, $request, 'update');

        return response()->json(['model' => $this->fullModel('update', $id)], 200);
    }

    public function destroy_json($id)
    {
        $upgrade = ClientVersionUpgrade::findOrFail($id);
        $upgrade->delete();

        return response()->json(null, 204);
    }

    public function advance_status_json($id)
    {
        $upgrade = ClientVersionUpgrade::findOrFail($id);
        $next_status = self::STATUS_FLOW[$upgrade->status] ?? null;

        if (! $next_status) {
            return response()->json(['message' => 'No se puede avanzar desde el estado actual.'], 422);
        }

        $data = ['status' => $next_status];
        if ($next_status === 'actualizandose' && ! $upgrade->started_at) {
            $data['started_at'] = now();
        }

        $upgrade->update($data);

        return response()->json(['model' => $this->fullModel('update', $id)], 200);
    }

    public function mark_step_json(Request $request, $id)
    {
        $upgrade = ClientVersionUpgrade::findOrFail($id);
        $step = $request->input('step');

        $allowed_steps = [
            'sistema_actualizado_at',
            'migraciones_corridas_at',
            'crons_supervisor_at',
            'seeders_ejecutados_at',
            'comandos_ejecutados_at',
            'sistema_configurado_at',
        ];

        if (! in_array($step, $allowed_steps)) {
            return response()->json(['message' => 'Step no reconocido.'], 422);
        }

        $upgrade->update([$step => $request->boolean('unmark') ? null : now()]);

        return response()->json(['model' => $this->fullModel('update', $id)], 200);
    }

    public function sync_to_client_json($id, PublishVersionService $service)
    {
        $upgrade = ClientVersionUpgrade::withAll()->findOrFail($id);
        $upgrade = $service->syncExisting($upgrade);

        if ($upgrade->status === 'terminada') {
            return response()->json(['model' => $this->fullModel('update', $id)], 200);
        }

        return response()->json([
            'message' => 'No se pudo sincronizar: ' . $upgrade->notes,
            'model'   => $this->fullModel('update', $id),
        ], 422);
    }

    public function extra_data_json($id)
    {
        $upgrade  = ClientVersionUpgrade::findOrFail($id);
        $fromId   = $upgrade->from_version_id;
        $toId     = $upgrade->to_version_id;
        $clientId = $upgrade->client_id ? (int) $upgrade->client_id : null;

        $aggregated_notifications = VersionPathService::aggregatedNotifications($fromId, $toId, $clientId);
        $aggregated_manual_tasks  = VersionPathService::aggregatedManualTasks($fromId, $toId, $clientId);

        $reads_by_id = [];
        if ($clientId && $aggregated_notifications->isNotEmpty()) {
            $notif_ids = $aggregated_notifications->pluck('id')->all();
            $raw = ClientNotificationRead::query()
                ->where('client_id', $clientId)
                ->whereIn('version_notification_id', $notif_ids)
                ->orderBy('read_at')
                ->get();
            foreach ($raw as $r) {
                $nid = (int) $r->version_notification_id;
                $reads_by_id[$nid][] = $r;
            }
        }

        $notifications = $aggregated_notifications->map(function ($n) use ($reads_by_id) {
            $arr          = $n->toArray();
            $arr['reads'] = $reads_by_id[$n->id] ?? [];

            return $arr;
        });

        return response()->json([
            'notifications' => $notifications->values(),
            'manual_tasks'  => $aggregated_manual_tasks->values(),
        ], 200);
    }

    /**
     * Atributos comunes al crear un ClientVersionUpgrade (web y JSON).
     * target_client_api_id: del request si es válido; si no, primera API distinta de active_client_api_id.
     *
     * @param  Client  $client
     * @param  int|null  $from_version_id
     * @param  int  $to_version_id
     * @param  Request|null  $request
     * @return array<string, mixed>
     */
    protected function build_upgrade_create_attributes(Client $client, $from_version_id, $to_version_id, $request = null)
    {
        $notes = $request ? $request->input('notes') : null;

        $attributes = [
            'client_id'           => $client->id,
            'from_version_id'     => $from_version_id,
            'to_version_id'       => $to_version_id,
            'status'              => 'pendiente',
            'notes'               => $notes,
            'created_by_admin_id' => Auth::id(),
        ];

        $target_id = $request ? $request->input('target_client_api_id') : null;
        if ($target_id !== null && $target_id !== '') {
            $this->assert_target_client_api_belongs_to_client($client, (int) $target_id);
            $attributes['target_client_api_id'] = (int) $target_id;
        } else {
            $default_target_id = $this->resolve_default_target_client_api_id($client);
            if ($default_target_id !== null) {
                $attributes['target_client_api_id'] = $default_target_id;
            }
        }

        return $attributes;
    }

    /**
     * API destino por defecto: la primera ClientApi del cliente que no sea la activa en producción.
     *
     * @param  Client  $client
     * @return int|null
     */
    protected function resolve_default_target_client_api_id(Client $client)
    {
        $client->loadMissing('client_apis');

        $active_id = $client->active_client_api_id ? (int) $client->active_client_api_id : null;
        $fallback_id = null;

        foreach ($client->client_apis as $client_api) {
            $api_id = (int) $client_api->id;
            if ($fallback_id === null) {
                $fallback_id = $api_id;
            }
            if ($active_id !== null && $api_id !== $active_id) {
                return $api_id;
            }
        }

        return $fallback_id;
    }

    /**
     * Verifica que la ClientApi pertenezca al cliente del upgrade.
     *
     * @param  Client  $client
     * @param  int  $target_client_api_id
     * @return void
     */
    protected function assert_target_client_api_belongs_to_client(Client $client, $target_client_api_id)
    {
        $belongs = $client->client_apis()
            ->where('id', $target_client_api_id)
            ->exists();

        if (! $belongs) {
            abort(422, 'La API destino no pertenece al cliente seleccionado.');
        }
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\BaseController;
use App\Http\Controllers\CommonLaravel\Helpers\ModelPropertiesHelper;
use App\Models\Client;
use App\Models\ClientNotificationRead;
use App\Models\ClientVersionUpgrade;
use App\Models\Version;
use App\Services\ClientVersionUpgradeCreationService;
use App\Services\PublishVersionService;
use App\Services\VersionPathService;
use Illuminate\Http\Request;

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

    /**
     * Paso 1 → 2: arma las candidatas del rango (from, to] para que el admin confirme
     * el subconjunto antes de crear el upgrade. No persiste nada.
     */
    function preview(Request $request) {
        $request->validate([
            'client_id'     => 'required|exists:clients,id',
            'to_version_id' => 'required|exists:versions,id',
        ]);

        $client = Client::findOrFail($request->input('client_id'));
        $to     = Version::findOrFail($request->input('to_version_id'));

        if ($to->status !== 'published') {
            return redirect()->back()->withInput()
                             ->with('error', 'La versión destino debe estar publicada.');
        }

        $from       = $client->current_version;
        $candidates = VersionPathService::candidatesBetween($from, $to);

        $notes                = $request->input('notes');
        $scheduled_date       = $request->input('scheduled_date');
        $target_client_api_id = $request->input('target_client_api_id');

        return view('updates.preview', compact(
            'client', 'from', 'to', 'candidates', 'notes', 'scheduled_date', 'target_client_api_id'
        ));
    }

    function store(Request $request, ClientVersionUpgradeCreationService $creation_service) {
        $request->validate([
            'client_id'     => 'required|exists:clients,id',
            'to_version_id' => 'required|exists:versions,id',
            'version_ids'   => 'required|array|min:1',
            'version_ids.*' => 'integer',
        ]);

        $client = Client::findOrFail($request->input('client_id'));
        $to     = Version::findOrFail($request->input('to_version_id'));

        // Mismo criterio que `preview()`: un POST directo que no pasó por el preview no
        // puede crear una actualización hacia una versión todavía en borrador.
        if ($to->status !== 'published') {
            abort(422, 'La versión destino debe estar publicada.');
        }

        $confirmed_ids = $creation_service->resolve_confirmed_version_ids(
            $client,
            $to,
            array_map('intval', $request->input('version_ids'))
        );

        $upgrade = $creation_service->create(
            $client,
            $to,
            $confirmed_ids,
            $creation_service->options_from_request($request)
        );

        return redirect()->route('updates.show', $upgrade->id)
                         ->with('success', 'Actualización creada.');
    }

    function show($id) {
        $upgrade = ClientVersionUpgrade::withAll()->findOrFail($id);

        $to_version = $upgrade->to_version->load('manual_tasks', 'notifications');

        $clientId = (int) $upgrade->client_id;

        // Conjunto ya confirmado por el admin (pivot), no un recálculo del rango por id.
        $upgrade->loadMissing('confirmed_versions');
        $confirmed = VersionPathService::sortSemantically($upgrade->confirmed_versions);

        $aggregatedNotifications = VersionPathService::aggregatedNotifications($confirmed, $clientId);
        $aggregatedManualTasks   = VersionPathService::aggregatedManualTasks($confirmed, $clientId);

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
            ->orderBy('scheduled_date', 'desc')
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

    public function store_json(Request $request, ClientVersionUpgradeCreationService $creation_service)
    {
        // 🔴 `confirmed_version_ids` es obligatorio, igual que `version_ids` en el flujo web.
        // Antes había un fallback que armaba el conjunto solo (troncal + destino) cuando la
        // clave no venía: eso es exactamente el cálculo automático sin confirmación humana
        // que esta misión vino a eliminar. El único consumidor real (`Updates.vue`) siempre
        // manda el campo desde el modal de confirmación.
        $request->validate([
            'client_id'               => 'required|exists:clients,id',
            'to_version_id'           => 'required|exists:versions,id',
            'confirmed_version_ids'   => 'required|array|min:1',
            'confirmed_version_ids.*' => 'integer',
        ]);

        $client = Client::findOrFail($request->input('client_id'));
        $to     = Version::findOrFail($request->input('to_version_id'));

        // Mismo criterio que `preview_json()`: no se arma una actualización hacia una
        // versión en borrador, aunque el POST no haya pasado por el preview.
        if ($to->status !== 'published') {
            return response()->json(['message' => 'La versión destino debe estar publicada.'], 422);
        }

        $confirmed_ids = $creation_service->resolve_confirmed_version_ids(
            $client,
            $to,
            array_map('intval', $request->input('confirmed_version_ids'))
        );

        $upgrade = $creation_service->create(
            $client,
            $to,
            $confirmed_ids,
            $creation_service->options_from_request($request)
        );

        return response()->json(['model' => $this->fullModel('update', $upgrade->id)], 201);
    }

    /**
     * Contrato JSON del paso de confirmación para el SPA: candidatas del rango con
     * `default_checked` (troncal sí, hotfix no, destino siempre) e `is_target`.
     */
    public function preview_json(Request $request)
    {
        $request->validate([
            'client_id'     => 'required|exists:clients,id',
            'to_version_id' => 'required|exists:versions,id',
        ]);

        $client = Client::findOrFail($request->input('client_id'));
        $to     = Version::findOrFail($request->input('to_version_id'));

        if ($to->status !== 'published') {
            return response()->json(['message' => 'La versión destino debe estar publicada.'], 422);
        }

        $from       = $client->current_version;
        $candidates = VersionPathService::candidatesBetween($from, $to);

        $candidates_payload = $candidates->map(function (Version $v) use ($to) {
            $is_target = ((int) $v->id === (int) $to->id);
            $is_hotfix = (bool) $v->is_hotfix;

            return [
                'id'              => $v->id,
                'version'         => $v->version,
                'title'           => $v->title,
                'description'     => $v->description,
                'is_hotfix'       => $is_hotfix,
                'default_checked' => (! $is_hotfix) || $is_target,
                'is_target'       => $is_target,
            ];
        })->values();

        return response()->json([
            'from_version' => $from ? ['id' => $from->id, 'version' => $from->version] : null,
            'to_version'   => ['id' => $to->id, 'version' => $to->version, 'title' => $to->title],
            'candidates'   => $candidates_payload,
        ], 200);
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
        $clientId = $upgrade->client_id ? (int) $upgrade->client_id : null;

        $upgrade->loadMissing('confirmed_versions');
        $confirmed = VersionPathService::sortSemantically($upgrade->confirmed_versions);

        $aggregated_notifications = VersionPathService::aggregatedNotifications($confirmed, $clientId);
        $aggregated_manual_tasks  = VersionPathService::aggregatedManualTasks($confirmed, $clientId);

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
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RunClientInstallationGroupJob;
use App\Jobs\RunClientInstallationJob;
use App\Models\Client;
use App\Models\ClientApi;
use App\Models\ClientInstallation;
use App\Models\DeploymentLog;
use App\Models\EnvTemplate;
use App\Models\Version;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Gestión de instalaciones iniciales de sistema para clientes.
 *
 * Maneja el ciclo de vida completo de una ClientInstallation:
 * crear, cargar valores manuales de .env e iniciar el pipeline en background.
 *
 * Desde el 24/8/2026 un mismo pedido puede crear DOS filas: la instalación real sobre una ClientApi
 * del cliente y el esqueleto sobre la otra (ver ClientInstallation::KIND_*). Las dos comparten
 * group_uuid, se cargan y se inician juntas, pero cada una lleva su propio estado.
 *
 * 🔴 Toda respuesta que antes devolvía 'model' lo SIGUE devolviendo con el mismo contenido: 'models'
 * se agrega al lado. Los dos lados de un contrato nunca llegan a producción a la vez.
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
     * Crea siempre una instalación completa y suelta (sin grupo): es el flujo de la pestaña del
     * cliente, que no ofrece elegir destinos. No se toca.
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
     * Crea una o dos instalaciones en estado 'pendiente' desde la raíz del módulo de instalaciones
     * (Installations.vue), sin pasar por la pestaña del cliente.
     *
     * Acepta dos formas del payload:
     *
     *   Nueva:  { client_id, version_id?, targets: [ {client_api_id, kind}, ... ] }
     *   Vieja:  { client_id, client_api_id?, version_id? }
     *
     * 🔴 Si no viene 'targets' el comportamiento es idéntico al de antes del 24/8/2026: una sola
     * fila, kind='completa', group_uuid=null. Es la compatibilidad hacia atrás del endpoint, y hay
     * un test que la fija. Si vienen los dos, 'targets' gana y client_api_id se ignora.
     *
     * @param  Request  $request
     * @return JsonResponse  { model, models } (201) o { error: string } (422)
     */
    public function store_global(Request $request): JsonResponse
    {
        $request->validate([
            'client_id'               => 'required|integer|exists:clients,id',
            'client_api_id'           => 'nullable|integer',
            'version_id'              => 'nullable|integer',
            'targets'                 => 'nullable|array|max:2',
            'targets.*.client_api_id' => 'required_with:targets|integer',
            'targets.*.kind'          => 'required_with:targets|in:completa,esqueleto',
        ]);

        // Cliente destino de la nueva instalación.
        $client = Client::findOrFail($request->input('client_id'));

        $targets_input = $request->input('targets');

        // 'targets' se considera presente solo si es un array de verdad. Un null explícito cae al
        // camino viejo, que es lo que hace cualquier cliente que todavía no conoce este campo.
        if (is_array($targets_input)) {
            $targets_or_error = $this->normalize_targets($client, $targets_input);
            if ($targets_or_error instanceof JsonResponse) {
                return $targets_or_error;
            }
            $targets = $targets_or_error;
        } else {
            $target_or_error = $this->legacy_single_target($client, $request->input('client_api_id'));
            if ($target_or_error instanceof JsonResponse) {
                return $target_or_error;
            }
            $targets = $target_or_error;
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

        // group_uuid SOLO cuando hay dos o más filas. Una instalación suelta queda con group_uuid
        // null, que es lo que hace que start(), show() y update_env_values() la traten de a una
        // exactamente como antes.
        $group_uuid = count($targets) >= 2 ? (string) Str::uuid() : null;

        $created = [];
        foreach ($targets as $target) {
            $created[] = ClientInstallation::create([
                'client_id'     => $client->id,
                'client_api_id' => $target['client_api_id'],
                'version_id'    => $version_id,
                'kind'          => $target['kind'],
                'group_uuid'    => $group_uuid,
                'status'        => 'pendiente',
            ]);
        }

        $models = ClientInstallation::sort_real_first(new Collection($created));
        foreach ($models as $model) {
            $model->load(['client', 'client_api', 'version', 'deployment_logs']);
        }

        // 'model' es la instalación real (o la primera si el operador pidió solo esqueleto). Se
        // conserva porque el shape actual lo tiene y el SPA viejo lo lee.
        return response()->json([
            'model'  => $models->first(),
            'models' => $models->values(),
        ], 201);
    }

    /**
     * Devuelve una instalación puntual con todas sus relaciones.
     *
     * Usado por el modal de gestión (admin-spa) para refrescar estado y logs
     * sin depender del listado completo del cliente.
     *
     * @param  ClientInstallation  $installation  Instalación resuelta por route model binding.
     * @return JsonResponse  { model, models? }
     */
    public function show(ClientInstallation $installation): JsonResponse
    {
        // Carga las relaciones necesarias para el modal de gestión (mismo shape que store/start).
        $installation->load(['client', 'client_api', 'version', 'deployment_logs']);

        $response = ['model' => $installation];

        // Con grupo se devuelve también la hermana: el polling del SPA corre cada 3 segundos y sin
        // esto necesitaría un segundo request por fila para ver que la otra ya terminó.
        $group = $this->load_group($installation);
        if ($group !== null) {
            $response['models'] = $group->values();
        }

        return response()->json($response);
    }

    /**
     * Elimina una instalación y sus deployment_logs asociados.
     *
     * No permite eliminar una instalación en estado 'instalando': hay un
     * RunClientInstallationJob corriendo en background sobre ese registro y
     * borrarlo a mitad de camino lo dejaría escribiendo sobre un modelo inexistente.
     *
     * Borra SOLO la fila pedida, aunque tenga hermana. Es lo correcto: es el flujo de reintento
     * —borrar el esqueleto fallido y crear uno nuevo solo-esqueleto— y la instalación real que
     * quedó bien no se toca.
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
     * @return JsonResponse  { model, models? }
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

        // Las variables manuales se cargan una vez y valen para las dos filas del grupo: los dos
        // subdominios sirven la MISMA base del cliente —eso es lo que hace que la alternancia
        // blue/green funcione—, así que DB_DATABASE, DB_USERNAME y compañía son idénticas por
        // definición. Se copian a las dos filas en vez de que el esqueleto lea las de su hermana:
        // así cada fila se basta sola y se puede borrar o reintentar una sin la otra.
        if ($installation->group_uuid !== null) {
            ClientInstallation::ofGroup($installation->group_uuid)
                ->where('id', '!=', $installation->id)
                ->get()
                ->each(function ($sibling) use ($merged_values) {
                    $sibling->update(['env_manual_values' => $merged_values]);
                });
        }

        $installation->load(['client', 'client_api', 'version', 'deployment_logs']);

        $response = ['model' => $installation];

        $group = $this->load_group($installation);
        if ($group !== null) {
            $response['models'] = $group->values();
        }

        return response()->json($response);
    }

    /**
     * Inicia el pipeline de instalación en un Job de background.
     *
     * Sobre una fila suelta arranca esa fila, igual que siempre. Sobre una fila de un grupo arranca
     * TODAS las filas del grupo que estén en 'pendiente', con la instalación real primero.
     *
     * @param  ClientInstallation  $installation  Instalación (o fila del grupo) a iniciar.
     * @return JsonResponse  { model, models? } o { error: string }
     */
    public function start(ClientInstallation $installation): JsonResponse
    {
        $rows = $this->rows_to_start($installation);

        if ($rows->isEmpty()) {
            // Mismo mensaje exacto que antes del 24/8/2026: para una fila suelta esta respuesta
            // tiene que ser indistinguible de la de siempre.
            return response()->json([
                'error' => "No se puede iniciar una instalación en estado '{$installation->status}'.",
            ], 422);
        }

        // Obtiene todas las variables que requieren valor manual.
        $manual_templates = EnvTemplate::where('is_manual_on_create', true)->get();

        // Valida que cada variable manual tenga un valor cargado (no vacío) en CADA fila que se va
        // a iniciar: cada fila corre su propio step_write_env con sus propios valores.
        $missing_keys = [];
        foreach ($rows as $row) {
            $env_manual_values = $row->env_manual_values ?? [];
            foreach ($manual_templates as $template) {
                $value = $env_manual_values[$template->key] ?? '';
                if (trim((string) $value) === '' && ! in_array($template->key, $missing_keys, true)) {
                    $missing_keys[] = $template->key;
                }
            }
        }

        if (! empty($missing_keys)) {
            return response()->json([
                'error'        => 'Faltan valores para variables requeridas antes de iniciar.',
                'missing_keys' => $missing_keys,
            ], 422);
        }

        // Cambia el status a 'instalando' antes de despachar el job.
        $uuids = [];
        foreach ($rows as $row) {
            $row->update(['status' => 'instalando']);
            $uuids[] = $row->uuid;
        }

        if (count($uuids) === 1) {
            // Camino de siempre para una instalación suelta: mismo job, mismo dispatch.
            RunClientInstallationJob::dispatch($uuids[0]);
        } else {
            // 🔴 Un solo job para las dos y no dos dispatch: dos jobs saldrían a la cola sin orden
            // garantizado y abrirían dos sesiones SSH en paralelo contra el mismo hosting. El orden
            // —la instalación real primero— es un pedido explícito, no una casualidad del scheduler.
            RunClientInstallationGroupJob::dispatch($uuids);
        }

        $installation->refresh();
        $installation->load(['client', 'client_api', 'version', 'deployment_logs']);

        $response = ['model' => $installation];

        $group = $this->load_group($installation);
        if ($group !== null) {
            $response['models'] = $group->values();
        }

        return response()->json($response);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Valida y ordena los destinos del payload nuevo.
     *
     * Devuelve un array de ['client_api_id' => int, 'kind' => string] con la instalación real
     * primero, o directamente la respuesta 422 que corresponda.
     *
     * 🔴 Valida TODO antes de crear nada: un pedido inválido no puede dejar media instalación
     * creada en la base, porque la fila huérfana después arranca sola desde el listado.
     *
     * @param  Client  $client
     * @param  array<int, array>  $targets_input
     * @return array<int, array>|JsonResponse
     */
    private function normalize_targets(Client $client, array $targets_input)
    {
        if (empty($targets_input)) {
            return response()->json([
                'error' => 'Elegí al menos una API destino.',
            ], 422);
        }

        $targets     = [];
        $vistos      = [];
        $cant_reales = 0;

        foreach ($targets_input as $target_input) {
            $client_api_id = (int) ($target_input['client_api_id'] ?? 0);
            $kind          = (string) ($target_input['kind'] ?? ClientInstallation::KIND_COMPLETA);

            // No confiar en el client_id que venga en el request: se valida contra la ClientApi
            // real que corresponde al client_api_id recibido.
            $client_api = ClientApi::find($client_api_id);
            if ($client_api === null || (int) $client_api->client_id !== (int) $client->id) {
                return response()->json([
                    'error' => 'La API indicada no pertenece al cliente seleccionado.',
                ], 422);
            }

            if (in_array($client_api_id, $vistos, true)) {
                return response()->json([
                    'error' => 'No se puede instalar dos veces sobre la misma API del cliente.',
                ], 422);
            }
            $vistos[] = $client_api_id;

            if ($kind === ClientInstallation::KIND_COMPLETA) {
                $cant_reales++;
                if ($cant_reales > 1) {
                    return response()->json([
                        'error' => 'Solo puede haber una instalación real. Elegí una sola API para la instalación completa.',
                    ], 422);
                }
            }

            // Se rechaza acá y no recién en el job: InstallationService::get_api_path() hardcodea el
            // prefijo del hosting compartido, así que un esqueleto sobre una API en VPS crearía los
            // directorios y el .env en el servidor equivocado y devolvería éxito. El pipeline
            // completo sí sabe manejarlo, por eso la restricción es solo para el esqueleto.
            if ($kind === ClientInstallation::KIND_ESQUELETO && (string) $client_api->hosting_type === 'vps') {
                return response()->json([
                    'error' => 'El esqueleto todavía no soporta APIs en VPS: el pipeline de instalación resuelve las rutas asumiendo hosting compartido. Instalá esa API con el pipeline completo.',
                ], 422);
            }

            $targets[] = ['client_api_id' => $client_api_id, 'kind' => $kind];
        }

        // La instalación real va primero: es la larga, es la que el operador mira en el log en vivo,
        // y el esqueleto del subdominio hermano se corre después.
        usort($targets, function ($a, $b) {
            $a_es_real = $a['kind'] === ClientInstallation::KIND_COMPLETA ? 0 : 1;
            $b_es_real = $b['kind'] === ClientInstallation::KIND_COMPLETA ? 0 : 1;

            return $a_es_real - $b_es_real;
        });

        return $targets;
    }

    /**
     * Resuelve el destino único del payload viejo ({client_id, client_api_id?, version_id?}).
     *
     * Es el código que estaba antes del 24/8/2026, movido acá tal cual y sin cambiar ni un mensaje:
     * lo que devuelve es siempre una sola instalación completa y suelta.
     *
     * @param  Client  $client
     * @param  int|null  $client_api_id
     * @return array<int, array>|JsonResponse
     */
    private function legacy_single_target(Client $client, $client_api_id)
    {
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

        return [
            ['client_api_id' => (int) $client_api_id, 'kind' => ClientInstallation::KIND_COMPLETA],
        ];
    }

    /**
     * Filas que hay que arrancar a partir de la que pidió el operador.
     *
     * Sin grupo: la fila pedida, si está pendiente. Con grupo: todas las del grupo que estén
     * pendientes, con la real primero — así un start repetido sobre un grupo a medias corre solo lo
     * que falta, en vez de rechazar todo porque una de las dos ya está completada.
     *
     * @param  ClientInstallation  $installation
     * @return Collection
     */
    private function rows_to_start(ClientInstallation $installation): Collection
    {
        if ($installation->group_uuid === null) {
            return $installation->status === 'pendiente'
                ? new Collection([$installation])
                : new Collection();
        }

        $rows = ClientInstallation::ofGroup($installation->group_uuid)
            ->where('status', 'pendiente')
            ->get();

        return ClientInstallation::sort_real_first($rows);
    }

    /**
     * Filas del grupo de esta instalación, cargadas y ordenadas con la real primero.
     *
     * Devuelve null cuando la instalación no forma parte de un grupo: así el llamador sabe que no
     * tiene que agregar la clave 'models' a la respuesta, y un consumidor viejo del endpoint recibe
     * exactamente el mismo JSON que recibía.
     *
     * @param  ClientInstallation  $installation
     * @return Collection|null
     */
    private function load_group(ClientInstallation $installation)
    {
        if ($installation->group_uuid === null) {
            return null;
        }

        $rows = ClientInstallation::ofGroup($installation->group_uuid)
            ->withAll()
            ->get();

        return ClientInstallation::sort_real_first($rows);
    }
}

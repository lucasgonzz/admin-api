<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreClientInstallationRequest;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
     *   Nueva:  { client_id, version_id?, targets: [ {client_api_id, kind}, ... ], provision_hosting_type? }
     *   Vieja:  { client_id, client_api_id?, version_id? }
     *
     * 🔴 Si no viene 'targets' el comportamiento es idéntico al de antes del 24/8/2026: una sola
     * fila, kind='completa', group_uuid=null. Es la compatibilidad hacia atrás del endpoint, y hay
     * un test que la fija. Si vienen los dos, 'targets' gana y client_api_id se ignora.
     *
     * 🔴 Lo mismo vale para 'provision_hosting_type', que llegó el 31/8/2026: si no viene, la fila
     * queda con null y el pipeline es el de siempre, byte por byte. La validación y los mensajes
     * viven en StoreClientInstallationRequest (la regla R3 del plan le pone un techo de 700 líneas
     * a este archivo y la acción prescrita para cruzarlo es exactamente esa extracción).
     *
     * @param  StoreClientInstallationRequest  $request
     * @return JsonResponse  { model, models } (201) o { error: string } (422)
     */
    public function store_global(StoreClientInstallationRequest $request): JsonResponse
    {
        // 🔴 Hasta U9 acá había una guarda que rechazaba con 422 todo alta con
        // provision_hosting_type='vps': entre U8 y U9 el aprovisionamiento del VPS ya funcionaba y
        // el pipeline de instalación todavía no, y esa combinación era la que armaba el docroot del
        // SPA como la raíz de la cuenta compartida y la vaciaba con un `find . -mindepth 1 -delete`.
        // U9 hizo hosting-aware al pipeline y puso la guarda donde de verdad corresponde: pegada al
        // borrado, en ClientApiPathResolver::assert_directorio_de_spa_borrable().
        $provision_hosting_type = $request->input('provision_hosting_type');

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

        // 🔴 El hosting elegido tiene que ser el de las ClientApi destino. Se chequea acá para que
        // el operador se entere apretando "Crear" y no quince minutos después con recursos ya
        // creados de los dos lados; la guarda que de verdad frena está en el preflight del proveedor.
        $incoherencia = $this->hosting_incoherente($targets, $provision_hosting_type);
        if ($incoherencia !== null) {
            return $incoherencia;
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
                'client_id'              => $client->id,
                'client_api_id'          => $target['client_api_id'],
                'version_id'             => $version_id,
                'kind'                   => $target['kind'],
                'group_uuid'             => $group_uuid,
                'status'                 => 'pendiente',
                // Las DOS filas del grupo llevan el mismo tipo de aprovisionamiento: los 4
                // subdominios y la base son del cliente, no de una instancia.
                'provision_hosting_type' => $provision_hosting_type,
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
     * 🔴 La selección de las filas y el paso a 'instalando' van en UNA transacción con
     * lockForUpdate(). No es prolijidad: en la pestaña del cliente las dos filas del par están en
     * pantalla, cada una con su botón "Iniciar" y su propio flag `starting` —deshabilitar uno no
     * deshabilita el otro—, así que dos clics casi simultáneos son alcanzables con el mouse. Sin el
     * lock, los dos requests leían las mismas filas en 'pendiente' y despachaban DOS jobs sobre los
     * mismos uuids: dos pipelines en paralelo contra el mismo hosting, con el
     * `find . -mindepth 1 -delete` del public_html del SPA de uno corriendo contra el `unzip` del
     * otro. Con el lock, el segundo request encuentra cero filas pendientes y se va con un 422.
     *
     * 🔴 El dispatch va DESPUÉS del commit, fuera del closure: un worker real puede levantar el job
     * antes de que la transacción cierre y encontrarse las filas todavía en 'pendiente'.
     *
     * @param  ClientInstallation  $installation  Instalación (o fila del grupo) a iniciar.
     * @return JsonResponse  { model, models? } o { error: string }
     */
    public function start(ClientInstallation $installation): JsonResponse
    {
        // Obtiene todas las variables que requieren valor manual. Va afuera de la transacción: es
        // una lectura de configuración global, no compite con nadie y no tiene por qué alargar el
        // tiempo con las filas lockeadas.
        $manual_templates = EnvTemplate::where('is_manual_on_create', true)->get();

        $uuids_o_error = DB::transaction(function () use ($installation, $manual_templates) {
            $rows = $this->rows_to_start($installation);

            if ($rows->isEmpty()) {
                // refresh() dentro del lock: para el segundo de dos clics simultáneos, el estado que
                // el operador tiene que leer es 'instalando' (lo que la fila es AHORA), no
                // 'pendiente' (lo que era cuando el route model binding la cargó).
                $installation->refresh();

                // Mismo mensaje exacto que antes del 24/8/2026: para una fila suelta esta respuesta
                // tiene que ser indistinguible de la de siempre.
                return response()->json([
                    'error' => "No se puede iniciar una instalación en estado '{$installation->status}'.",
                ], 422);
            }

            // Valida que cada variable manual tenga un valor cargado (no vacío) en CADA fila que se
            // va a iniciar: cada fila corre su propio step_write_env con sus propios valores.
            $missing_keys = [];
            foreach ($rows as $row) {
                $env_manual_values = $row->env_manual_values ?? [];

                // 🔴 Con aprovisionamiento tildado, DB_DATABASE, DB_USERNAME y DB_PASSWORD no las
                // tipea nadie: las genera el paso provision_db y las escribe step_write_env. Si se
                // siguieran exigiendo acá, el operador no tendría forma de completarlas y el botón
                // "Iniciar" del modal quedaría gris para siempre (§0.4 del plan). Sin
                // aprovisionamiento la lista queda vacía y la validación es la de siempre.
                $exceptuadas = trim((string) $row->provision_hosting_type) === ''
                    ? []
                    : ClientInstallation::CLAVES_ENV_APROVISIONADAS;

                foreach ($manual_templates as $template) {
                    if (in_array($template->key, $exceptuadas, true)) {
                        continue;
                    }

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

            // Cambia el status a 'instalando' con las filas todavía lockeadas: recién cuando esto
            // commitea, otro request las ve fuera de 'pendiente'.
            $uuids = [];
            foreach ($rows as $row) {
                $row->update(['status' => 'instalando']);
                $uuids[] = $row->uuid;
            }

            return $uuids;
        });

        // Los 422 salen tal cual del closure, con el mismo shape de siempre. Devolverlos desde
        // adentro commitea una transacción que no escribió nada, que es exactamente lo que se
        // quiere: no hay nada que revertir.
        if ($uuids_o_error instanceof JsonResponse) {
            return $uuids_o_error;
        }

        $uuids = $uuids_o_error;

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

    /**
     * Devuelve, descifradas, las credenciales que el aprovisionamiento generó para una ClientApi.
     *
     * 🔴 Es un endpoint aparte y no un campo del show: ClientApi::$hidden oculta
     * provisioning_secrets y tiene que seguir ocultándolo, porque esa relación viaja en el index y
     * en el show de instalaciones, upgrades y clientes. Acá salen bajo demanda, de a una API por
     * vez, Y QUEDA RASTRO: lo único que protege al único endpoint que las devuelve descifradas es
     * `auth:sanctum`, así que el "bajo demanda" solo vale si se puede contestar quién las pidió. El
     * Log no lleva ninguna credencial: solo los NOMBRES de las claves.
     *
     * @param  ClientApi  $client_api
     * @return JsonResponse  { provisioning_secrets, hosting_provisioned_at, hosting_type }
     */
    public function hosting_credentials(ClientApi $client_api): JsonResponse
    {
        $secretos = $client_api->provisioning_secrets;
        $admin    = auth()->user();

        Log::info('Credenciales de hosting consultadas', [
            'admin'         => $admin === null ? null : $admin->id . ' ' . $admin->email,
            'client_api_id' => $client_api->id,
            'ip'            => request()->ip(),
            'claves'        => is_array($secretos) ? array_keys($secretos) : [],
        ]);

        return response()->json([
            'provisioning_secrets'   => is_array($secretos) ? $secretos : [],
            'hosting_provisioned_at' => $client_api->hosting_provisioned_at,
            'hosting_type'           => $client_api->hosting_type,
        ]);
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

            // 🔴 Acá se rechazaba un esqueleto sobre una API en VPS, porque
            // InstallationService::get_api_path() hardcodeaba el prefijo del hosting compartido y el
            // esqueleto habría escrito los directorios y el .env en el servidor equivocado
            // devolviendo éxito. U9 (31/8/2026) hizo hosting-aware a los dos pipelines —las rutas,
            // la credencial SSH, el SFTP y el chown salen del hosting_type de la API destino— así
            // que el motivo del rechazo dejó de existir y la restricción se levantó.
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
     * 422 si el hosting que se pidió aprovisionar no es el de alguna ClientApi destino.
     *
     * 🔴 Esto es el aviso temprano; la guarda de verdad está en el preflight del proveedor. El
     * escenario cruzado que las dos cierran está escrito entero en
     * HostingProvisioningStructure::assert_hosting_type_coherente(). ⚠️ Pedir 'vps' sobre APIs en
     * 'shared_hosting' NO es incoherencia: es el alta normal de la primera vez.
     *
     * @param  array<int, array>  $targets
     * @param  string|null        $provision_hosting_type
     * @return JsonResponse|null  null si está todo bien.
     */
    private function hosting_incoherente(array $targets, $provision_hosting_type)
    {
        $pedido = trim((string) $provision_hosting_type);

        if ($pedido === '' || $pedido === ClientInstallation::PROVISION_VPS) {
            return null;
        }

        foreach ($targets as $target) {
            $client_api = ClientApi::find($target['client_api_id']);
            $actual     = $client_api === null ? '' : trim((string) $client_api->hosting_type);

            if ($actual !== 'vps') {
                continue;
            }

            return response()->json([
                'error' => 'Elegiste aprovisionar "' . $pedido . '" pero la API ' . $client_api->url
                    . ' del cliente está marcada como "vps". Aprovisionar en un servidor mientras la '
                    . 'instalación va al otro deja los subdominios y la base de un lado y el código '
                    . 'del otro. Elegí VPS, o corregí el hosting_type de las dos APIs del cliente si '
                    . 'de verdad volvió al hosting compartido.',
            ], 422);
        }

        return null;
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
     * Filas que hay que arrancar a partir de la que pidió el operador, YA LOCKEADAS.
     *
     * Sin grupo: la fila pedida, si está pendiente. Con grupo: todas las del grupo que estén
     * pendientes, con la real primero — así un start repetido sobre un grupo a medias corre solo lo
     * que falta, en vez de rechazar todo porque una de las dos ya está completada.
     *
     * 🔴 Las dos ramas releen de la base con lockForUpdate() y no reusan la instancia que trajo el
     * route model binding, ni siquiera en el caso de una fila suelta. Ese `$installation->status`
     * es una foto de antes del request: dos clics simultáneos veían los dos 'pendiente' y
     * despachaban dos jobs sobre la misma instalación. Con el SELECT ... FOR UPDATE el segundo se
     * queda esperando el commit del primero y después lee 'instalando', así que se va con las manos
     * vacías.
     *
     * ⚠️ Sólo tiene sentido llamado adentro de una transacción: fuera de una, el lock se libera al
     * terminar la consulta y no protege nada. El único llamador es start(), que abre la suya.
     *
     * @param  ClientInstallation  $installation
     * @return Collection
     */
    private function rows_to_start(ClientInstallation $installation): Collection
    {
        if ($installation->group_uuid === null) {
            return ClientInstallation::where('id', $installation->id)
                ->where('status', 'pendiente')
                ->lockForUpdate()
                ->get();
        }

        $rows = ClientInstallation::ofGroup($installation->group_uuid)
            ->where('status', 'pendiente')
            ->lockForUpdate()
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

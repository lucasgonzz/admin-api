<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Version;
use App\Models\VersionCommand;
use App\Models\VersionManualTask;
use App\Models\VersionNotification;
use App\Models\VersionSeeder;
use App\Services\VersionItemSanitizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Endpoint de ingesta de ítems de versión (notificaciones, seeders, comandos y
 * tareas manuales) generados por el loop de Claude Code al cerrar un grupo de
 * prompts (grupo 255, "publicacion-novedades-al-admin"). Protegido por el mismo
 * middleware `claude.task.key` que ClaudeTaskIngestController (clave fija en el
 * header X-Claude-Task-Key, ver ClaudeTaskKey).
 *
 * A diferencia de `PUT /api/version/{id}` (que usa VersionNestedJsonSync y borra
 * todo hijo ausente del payload — correcto para el editor de admin-spa, que
 * siempre manda el set completo), este endpoint es estrictamente ADITIVO: nunca
 * borra una fila, bajo ninguna circunstancia.
 *
 * La idempotencia se logra con una clave de deduplicación por
 * version_id + source_group_id + clave natural del ítem (ver upsert_items()).
 * Una fila cargada a mano desde admin-spa (source_group_id = null) nunca se
 * pisa ni se duplica: la carga manual gana siempre sobre la automática.
 */
class ClaudeVersionItemsIngestController extends Controller
{
    /**
     * Devuelve la versión en estado draft de id más alto (si hay alguna), junto
     * con la cantidad de ítems que ya tiene cargados por tipo. No crea ni
     * modifica nada: sirve para que el loop diagnostique el estado antes de
     * escribir con store_json().
     *
     * @param  Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function draft_version_json(Request $request)
    {
        $version = $this->find_draft_version();

        if ($version === null) {
            return response()->json(['version' => null, 'exists' => false], 200);
        }

        return response()->json([
            'version' => [
                'id'      => $version->id,
                'version' => $version->version,
                'title'   => $version->title,
                'status'  => $version->status,
            ],
            'exists' => true,
            'counts' => [
                'notifications' => VersionNotification::where('version_id', $version->id)->count(),
                'seeders'       => VersionSeeder::where('version_id', $version->id)->count(),
                'commands'      => VersionCommand::where('version_id', $version->id)->count(),
                'manual_tasks'  => VersionManualTask::where('version_id', $version->id)->count(),
            ],
        ], 200);
    }

    /**
     * Ingesta aditiva e idempotente de ítems de versión (notificaciones,
     * seeders, comandos, tareas manuales) desde el loop de Claude Code. Nunca
     * borra filas existentes. Reejecutar el mismo payload (mismo
     * source_group_id + mismas claves naturales) actualiza en vez de duplicar.
     *
     * Alcance de clientes: si `client_ids` viene vacío o ausente, no se toca el
     * pivote `version_item_clients` (el ítem aplica a todos los clientes, que
     * es el default pedido). Solo se sincroniza el pivote si vienen ids.
     *
     * @param  Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store_json(Request $request)
    {
        // Validación de contrato externo: este endpoint lo llama un proceso
        // desatendido (el loop), no el SPA, así que sí conviene validar acá
        // (a diferencia del resto del backend, que valida en frontend).
        $request->validate([
            'source_group_id'            => 'required|string|max:120',
            'notifications'              => 'nullable|array',
            'notifications.*.title'      => 'required|string|max:200',
            'notifications.*.body'       => 'required|string',
            'notifications.*.sort_order' => 'nullable|integer',
            'seeders'                    => 'nullable|array',
            'seeders.*.seeder_class'     => 'required|string|max:200',
            'seeders.*.description'      => 'nullable|string',
            'seeders.*.run_scope'        => 'nullable|string|in:per_database,per_user',
            'seeders.*.is_required'      => 'nullable|boolean',
            'commands'                   => 'nullable|array',
            'commands.*.command'         => 'required|string|max:255',
            'commands.*.description'     => 'nullable|string',
            'commands.*.run_scope'       => 'nullable|string|in:per_database,per_user',
            'commands.*.run_manually'    => 'nullable|boolean',
            'manual_tasks'               => 'nullable|array',
            'manual_tasks.*.title'       => 'required|string|max:200',
            'manual_tasks.*.description' => 'nullable|string',
            'client_ids'                 => 'nullable|array',
            'client_ids.*'               => 'integer',
        ]);

        /*
         * 🔴 Validación de FORMATO, que las reglas de arriba no cubren: son string y largo, y un
         * `seeder_class` con namespace o un `migrate` sin `--force` pasan las dos sin problema.
         *
         * Los dos entraron por acá y quedaron guardados en la versión 4.0.0, y el 3/9/2026
         * voltearon la actualización de masquito: el seeder con namespace mató al upgrade 75 y el
         * migrate sin --force colgó al 76 durante 31 minutos. Se rechaza antes de escribir nada.
         */
        $this->assert_items_ejecutables($request);

        // Id del grupo de claude-comerciocity que origina esta ingesta: es la
        // clave de trazabilidad/idempotencia que se guarda en cada fila.
        $source_group_id = (string) $request->input('source_group_id');

        // Los cuatro arrays de ítems a cargar, ya validados arriba. Se filtran
        // con is_array por robustez, aunque la validación ya debería garantizarlo.
        $notifications = array_values(array_filter((array) $request->input('notifications', []), 'is_array'));
        $seeders       = array_values(array_filter((array) $request->input('seeders', []), 'is_array'));
        $commands      = array_values(array_filter((array) $request->input('commands', []), 'is_array'));
        $manual_tasks  = array_values(array_filter((array) $request->input('manual_tasks', []), 'is_array'));

        // Si no hay ningún ítem en ninguno de los cuatro arrays, no tiene
        // sentido crear (ni tocar) una versión borrador: se corta acá con 422.
        if (empty($notifications) && empty($seeders) && empty($commands) && empty($manual_tasks)) {
            return response()->json([
                'error' => 'no se recibió ningún ítem para cargar (los cuatro arrays vinieron vacíos o ausentes)',
            ], 422);
        }

        // Ids de clientes a los que restringir los ítems que se creen en esta
        // request. Vacío/ausente = aplica a todos los clientes (default pedido):
        // en ese caso no se escribe nada en el pivote.
        $client_ids = array_values(array_filter(array_map('intval', (array) $request->input('client_ids', []))));

        // Se arma dentro de la transacción y se usa después para loguear y
        // responder; por eso se captura por referencia.
        $result = null;

        // Toda la escritura (posible creación de versión + upserts de los
        // cuatro tipos de ítem) va en una única transacción: un fallo a mitad
        // no debe dejar la versión borrador con la mitad de los ítems del grupo.
        DB::transaction(function () use (
            $source_group_id,
            $notifications,
            $seeders,
            $commands,
            $manual_tasks,
            $client_ids,
            &$result
        ) {
            $version = $this->find_draft_version();
            $version_created = false;

            if ($version === null) {
                $version = $this->create_draft_version();
                $version_created = true;
            }

            $resultados = [
                'notifications' => $this->upsert_notifications($version, $notifications, $source_group_id, $client_ids),
                'seeders'       => $this->upsert_seeders($version, $seeders, $source_group_id, $client_ids),
                'commands'      => $this->upsert_commands($version, $commands, $source_group_id, $client_ids),
                'manual_tasks'  => $this->upsert_manual_tasks($version, $manual_tasks, $source_group_id, $client_ids),
            ];

            $result = [
                'version' => [
                    'id'      => $version->id,
                    'version' => $version->version,
                    'status'  => $version->status,
                ],
                'version_created' => $version_created,
                'source_group_id' => $source_group_id,
                'resultados'      => $resultados,
            ];
        });

        // Log de auditoría de la ingesta. Nunca se loguea la clave del header.
        Log::channel('daily')->info('ClaudeVersionItemsIngestController: ingesta de ítems de versión.', [
            'source_group_id' => $result['source_group_id'],
            'version_id'      => $result['version']['id'],
            'version_created' => $result['version_created'],
            'resultados'      => $result['resultados'],
        ]);

        return response()->json($result, 200);
    }

    /**
     * Busca la versión en estado draft de id más alto. Devuelve null si no hay
     * ninguna en curso. Nunca devuelve una versión published o archived: esas
     * no se tocan jamás desde este endpoint.
     *
     * @return Version|null
     */
    protected function find_draft_version(): ?Version
    {
        return Version::where('status', 'draft')->orderByDesc('id')->first();
    }

    /**
     * Crea una nueva versión borrador cuando no hay ninguna en curso. El número
     * de versión es el siguiente patch sobre la última publicada (o "0.0.1" si
     * no hay ninguna publicada, o si su formato no matchea major.minor.patch).
     *
     * @return Version
     */
    protected function create_draft_version(): Version
    {
        // Próximo número de versión libre (resuelve colisiones contra la
        // columna unique `version`, ver resolve_next_version_string()).
        $next_version_string = $this->resolve_next_version_string();

        $version = Version::create([
            'status'       => 'draft',
            'published_at' => null,
            'title'        => null,
            'version'      => $next_version_string,
        ]);

        Log::channel('daily')->info('ClaudeVersionItemsIngestController: versión borrador creada automáticamente.', [
            'version_id' => $version->id,
            'version'    => $version->version,
        ]);

        return $version;
    }

    /**
     * Calcula el siguiente número de versión (patch + 1 sobre la última
     * publicada), evitando colisiones con cualquier `version` ya existente en
     * la tabla (la columna es unique, en cualquier estado: draft/published/archived).
     *
     * @return string
     */
    protected function resolve_next_version_string(): string
    {
        // Última versión publicada: la de published_at más reciente, y ante
        // empate la de id más alto.
        $last_published = Version::where('status', 'published')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->first();

        $major = 0;
        $minor = 0;
        $patch = 1;

        if ($last_published !== null && preg_match('/^(\d+)\.(\d+)\.(\d+)$/', (string) $last_published->version, $matches)) {
            $major = (int) $matches[1];
            $minor = (int) $matches[2];
            $patch = (int) $matches[3] + 1;
        }
        // Si no hay ninguna publicada, o el formato no parsea, se usan los
        // defaults de arriba (0.0.1).

        // Tope de 200 intentos para no colgarse ante una tabla con muchísimas
        // versiones consecutivas ya tomadas.
        for ($attempt = 0; $attempt < 200; $attempt++) {
            $candidate = $major . '.' . $minor . '.' . $patch;
            $exists = Version::where('version', $candidate)->exists();
            if (!$exists) {
                return $candidate;
            }
            $patch++;
        }

        // No debería llegar acá en la práctica; devuelve el último candidato
        // probado como última opción razonable.
        return $major . '.' . $minor . '.' . $patch;
    }

    /**
     * Upsert idempotente de notificaciones. Clave natural: `title`.
     *
     * @param  Version $version
     * @param  array   $items
     * @param  string  $source_group_id
     * @param  array   $client_ids
     * @return array{creadas: int, actualizadas: int, omitidas_por_carga_manual: int}
     */
    protected function upsert_notifications(Version $version, array $items, string $source_group_id, array $client_ids): array
    {
        return $this->upsert_items(
            VersionNotification::class,
            $version,
            $items,
            $source_group_id,
            $client_ids,
            'title',
            'sort_order',
            function ($payload) {
                return [
                    'title'     => (string) ($payload['title'] ?? ''),
                    'body'      => (string) ($payload['body'] ?? ''),
                    // Default true salvo que el payload diga otra cosa (09).
                    'is_active' => $this->bool_from_payload($payload['is_active'] ?? true),
                ];
            }
        );
    }

    /**
     * Upsert idempotente de seeders. Clave natural: `seeder_class`.
     *
     * @param  Version $version
     * @param  array   $items
     * @param  string  $source_group_id
     * @param  array   $client_ids
     * @return array{creadas: int, actualizadas: int, omitidas_por_carga_manual: int}
     */
    protected function upsert_seeders(Version $version, array $items, string $source_group_id, array $client_ids): array
    {
        /*
         * 🔴 El saneamiento va ACÁ y no adentro del `$data_row_builder`, y el motivo es la
         * idempotencia: `upsert_items()` calcula la clave natural del PAYLOAD CRUDO
         * (`$payload[$natural_key_field]`), antes de llamar al builder. Saneando solo en el builder,
         * el upsert buscaría por el valor sucio —que no existe— y crearía una fila con el valor
         * limpio en cada publicación, duplicando el ítem una vez por corrida.
         */
        $items = $this->sanear_items($items, 'seeder_class', function ($valor) {
            return VersionItemSanitizer::sanear_seeder_class($valor);
        });

        return $this->upsert_items(
            VersionSeeder::class,
            $version,
            $items,
            $source_group_id,
            $client_ids,
            'seeder_class',
            'execution_order',
            function ($payload) {
                return [
                    'seeder_class' => (string) ($payload['seeder_class'] ?? ''),
                    'description'  => (string) ($payload['description'] ?? ''),
                    // Default per_database para seeders (una vez por base de datos).
                    'run_scope'    => $this->normalize_run_scope($payload['run_scope'] ?? null, 'per_database'),
                    // Default true salvo que el payload diga otra cosa (09).
                    'is_required'  => $this->bool_from_payload($payload['is_required'] ?? true),
                ];
            }
        );
    }

    /**
     * Upsert idempotente de comandos. Clave natural: `command`.
     *
     * @param  Version $version
     * @param  array   $items
     * @param  string  $source_group_id
     * @param  array   $client_ids
     * @return array{creadas: int, actualizadas: int, omitidas_por_carga_manual: int}
     */
    protected function upsert_commands(Version $version, array $items, string $source_group_id, array $client_ids): array
    {
        /* Mismo motivo que en upsert_seeders(): la clave natural sale del payload crudo. */
        $items = $this->sanear_items($items, 'command', function ($valor) {
            return VersionItemSanitizer::sanear_comando($valor);
        });

        return $this->upsert_items(
            VersionCommand::class,
            $version,
            $items,
            $source_group_id,
            $client_ids,
            'command',
            'execution_order',
            function ($payload) {
                return [
                    'command'      => (string) ($payload['command'] ?? ''),
                    'description'  => (string) ($payload['description'] ?? ''),
                    // Default per_user para comandos (una vez por tenant).
                    'run_scope'    => $this->normalize_run_scope($payload['run_scope'] ?? null, 'per_user'),
                    'run_manually' => $this->bool_from_payload($payload['run_manually'] ?? false),
                    // No forma parte del contrato validado para comandos: siempre
                    // true, salvo que el payload lo traiga igual (defensivo).
                    'is_required'  => $this->bool_from_payload($payload['is_required'] ?? true),
                ];
            }
        );
    }

    /**
     * Upsert idempotente de tareas manuales. Clave natural: `title`.
     *
     * @param  Version $version
     * @param  array   $items
     * @param  string  $source_group_id
     * @param  array   $client_ids
     * @return array{creadas: int, actualizadas: int, omitidas_por_carga_manual: int}
     */
    protected function upsert_manual_tasks(Version $version, array $items, string $source_group_id, array $client_ids): array
    {
        return $this->upsert_items(
            VersionManualTask::class,
            $version,
            $items,
            $source_group_id,
            $client_ids,
            'title',
            'execution_order',
            function ($payload) {
                return [
                    'title'       => (string) ($payload['title'] ?? ''),
                    'description' => (string) ($payload['description'] ?? ''),
                    // No forma parte del contrato validado para tareas manuales:
                    // siempre true, salvo que el payload lo traiga igual (defensivo).
                    'is_required' => $this->bool_from_payload($payload['is_required'] ?? true),
                ];
            }
        );
    }

    /**
     * Upsert genérico y ADITIVO (nunca borra) para los cuatro tipos de ítem de
     * versión. La clave de deduplicación/idempotencia es la terna
     * version_id + source_group_id + clave natural del ítem:
     *
     * - Si no existe una fila con esa terna → se crea.
     * - Si existe → se actualiza con los datos que llegan (nunca se recalcula
     *   su orden).
     * - Si existe una fila con la misma clave natural pero source_group_id
     *   null (cargada a mano) → no se toca ni se duplica: la carga manual gana
     *   siempre sobre la automática, se cuenta aparte.
     *
     * @param  string   $model_class        FQCN del modelo Eloquent (VersionNotification::class, etc).
     * @param  Version  $version            Versión borrador destino.
     * @param  array    $items              Ítems del payload ya validados.
     * @param  string   $source_group_id    Id del grupo que origina la ingesta.
     * @param  array    $client_ids         Ids de cliente a restringir (vacío = todos).
     * @param  string   $natural_key_field  Nombre de la columna que actúa de clave natural.
     * @param  string   $order_field        Nombre de la columna de orden (sort_order|execution_order).
     * @param  callable $data_row_builder   function(array $payload): array — campos de datos a guardar.
     * @return array{creadas: int, actualizadas: int, omitidas_por_carga_manual: int}
     */
    /**
     * Corta con 422 si algún seeder o comando del payload no se puede ejecutar sin terminal.
     *
     * Se hace ANTES de escribir nada: el endpoint es idempotente pero no borra, así que un ítem
     * malo guardado hay que sacarlo a mano del admin.
     *
     * @param  Request  $request
     * @return void
     */
    protected function assert_items_ejecutables(Request $request): void
    {
        foreach ((array) $request->input('seeders', []) as $i => $payload) {
            $motivo = VersionItemSanitizer::motivo_de_rechazo_de_seeder($payload['seeder_class'] ?? null);
            if ($motivo !== null) {
                abort(422, 'seeders.' . $i . '.seeder_class: ' . $motivo);
            }
        }

        foreach ((array) $request->input('commands', []) as $i => $payload) {
            $motivo = VersionItemSanitizer::motivo_de_rechazo_de_comando($payload['command'] ?? null);
            if ($motivo !== null) {
                abort(422, 'commands.' . $i . '.command: ' . $motivo);
            }
        }
    }

    /**
     * Aplica el saneamiento a un campo de cada ítem, dejando el resto del payload intacto.
     *
     * @param  array     $items
     * @param  string    $campo
     * @param  callable  $sanear
     * @return array
     */
    protected function sanear_items(array $items, string $campo, callable $sanear): array
    {
        foreach ($items as $i => $payload) {
            if (! is_array($payload) || ! array_key_exists($campo, $payload)) {
                continue;
            }

            $items[$i][$campo] = $sanear($payload[$campo]);
        }

        return $items;
    }

    protected function upsert_items(
        string $model_class,
        Version $version,
        array $items,
        string $source_group_id,
        array $client_ids,
        string $natural_key_field,
        string $order_field,
        callable $data_row_builder
    ): array {
        $counts = ['creadas' => 0, 'actualizadas' => 0, 'omitidas_por_carga_manual' => 0];

        if (empty($items)) {
            return $counts;
        }

        // Próximo valor de orden para los ítems nuevos: max existente de esta
        // versión + 1. Se va incrementando a medida que se crean ítems dentro
        // del mismo request, respetando el orden en que vienen en el array.
        $next_order = (int) ($model_class::where('version_id', $version->id)->max($order_field) ?? -1) + 1;

        foreach ($items as $payload) {
            $natural_key_value = (string) ($payload[$natural_key_field] ?? '');

            // 1) Precedencia de la carga manual: una fila con la misma clave
            // natural y source_group_id null la cargó Lucas a mano desde
            // admin-spa. Nunca se pisa ni se duplica.
            $manual = $model_class::where('version_id', $version->id)
                ->where($natural_key_field, $natural_key_value)
                ->whereNull('source_group_id')
                ->first();

            if ($manual !== null) {
                $counts['omitidas_por_carga_manual']++;
                continue;
            }

            // 2) Fila ya cargada antes por este mismo grupo: se actualiza
            // (esto es lo que hace la ingesta idempotente).
            $existing = $model_class::where('version_id', $version->id)
                ->where($natural_key_field, $natural_key_value)
                ->where('source_group_id', $source_group_id)
                ->first();

            $row = $data_row_builder($payload);

            if ($existing !== null) {
                // Actualizar: nunca se recalcula el orden de un ítem ya existente.
                $existing->update($row);

                // El pivote de clientes solo se toca si vienen ids explícitos;
                // vacío no revierte una restricción cargada a mano.
                if (!empty($client_ids)) {
                    $existing->syncRestrictedClientIdsFromRequest($client_ids);
                }

                $counts['actualizadas']++;
                continue;
            }

            // Crear: orden explícito del payload si vino (solo notificaciones
            // lo exponen en el contrato validado), si no el siguiente
            // correlativo de esta versión.
            $order_value = isset($payload[$order_field]) ? (int) $payload[$order_field] : $next_order;

            $model = $model_class::create(array_merge($row, [
                'version_id'      => $version->id,
                $order_field      => $order_value,
                'source_group_id' => $source_group_id,
            ]));

            // Alcance de clientes: solo se escribe el pivote si vinieron ids;
            // vacío/ausente = aplica a todos (no se toca el pivote).
            if (!empty($client_ids)) {
                $model->syncRestrictedClientIdsFromRequest($client_ids);
            }

            $next_order++;
            $counts['creadas']++;
        }

        return $counts;
    }

    /**
     * Normaliza run_scope del payload (per_database | per_user). La validación
     * de store_json() ya rechaza cualquier otro valor con 422, así que acá solo
     * resta aplicar el default cuando el campo vino vacío/ausente.
     *
     * @param  mixed  $value
     * @param  string $default
     * @return string
     */
    protected function normalize_run_scope($value, string $default): string
    {
        $allowed = ['per_database', 'per_user'];
        $normalized = is_string($value) ? trim($value) : '';

        if (in_array($normalized, $allowed, true)) {
            return $normalized;
        }

        return $default;
    }

    /**
     * Interpreta un valor de payload (bool, "true"/"false", 1/0, etc.) como
     * booleano estricto, igual criterio que VersionNestedJsonSync.
     *
     * @param  mixed $value
     * @return bool
     */
    protected function bool_from_payload($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Console\Commands\VencerDeploymentsColgados;
use App\Http\Controllers\Api\Concerns\RespuestasParaClaude;
use App\Http\Controllers\Controller;
use App\Http\Controllers\UpdateController;
use App\Jobs\SyncClientScheduleJob;
use App\Models\Client;
use App\Models\ClientScheduleDay;
use App\Services\ClientScheduleReplacementService;
use App\Services\ClientScheduleResolver;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Endpoints de clientes, horarios, versiones y actualizaciones para Claude
 * (misión "actualizaciones manejadas por Claude + horarios del cliente", 24/8/2026).
 *
 * Es de lectura salvo por los DOS endpoints de horarios que escriben:
 * `POST clients/{id}/schedule/sync` (idempotente, sin frenos: reenvía lo que el admin ya tiene) y
 * `PUT clients/{id}/schedule` (reemplazo del conjunto entero, con `dry_run` y `confirm_client_name`).
 * Los dos viven acá y no en un controlador aparte porque el corte de este bloque es por ENTIDAD
 * —clientes, upgrades, ecommerce— y no por verbo: separar el PUT del GET de la misma ruta
 * obligaría a copiar `dias_cargados_de()`, `resolver_client_id()` y el resolvedor a otro archivo.
 *
 * Protegidos por el middleware `claude.task.key` (clave fija en X-Claude-Task-Key), el mismo
 * bloque que ya usan ClaudeTaskIngestController y ClaudeLeadsAnalyticsController. No hay Sanctum:
 * quien llama es un proceso externo sin sesión de admin.
 *
 * 🔴 TRES REGLAS QUE GOBIERNAN TODO ESTE CONTROLADOR:
 *
 *  1. **Query builder con `select` explícito. Nunca `Client::withAll()` ni el modelo Eloquent
 *     serializado tal cual.** `Client::$appends` tiene dos accessors (`ecommerce_spa_url`,
 *     `ecommerce_api_url`) que tocan `$this->client_ecommerce`: serializar N clientes es N+1
 *     garantizado. Cada `include` se resuelve con UNA consulta agregada para toda la página,
 *     nunca una por cliente.
 *  2. **Nunca `ClientVersionUpgrade::scopeWithAll()` en un listado.** Ese scope arrastra
 *     `deployment_logs`, y un solo upgrade puede tener miles de líneas con la salida cruda de
 *     `npm run build`. Cargarlas revienta la memoria y la ventana de contexto.
 *  3. **PII opt-in.** `phone` del cliente solo viaja con `include=contacto`.
 *
 * 🔴 El problema de volumen no es la API, es la ventana de contexto: por eso los logs se truncan
 * por defecto (`max_line_chars`) y los seeders/comandos de un upgrade viajan como CONTEOS, no
 * como filas.
 *
 * Los estados de horario son TRES (`abierto` / `cerrado` / `sin_configurar`) y ningún endpoint
 * los colapsa a un booleano: confundir `sin_configurar` con `cerrado` arrancaría el post-cierre
 * de una actualización sobre un negocio abierto y con gente adentro del sistema.
 */
class ClaudeClientOpsController extends Controller
{
    /**
     * Paginación por cursor, normalización de parámetros y las respuestas 422/404 con la forma
     * única del bloque `claude/*`. Estos quince métodos vivían acá adentro como privados: se
     * movieron al trait tal cual estaban, sin tocarles una línea, cuando aparecieron los
     * controladores nuevos de `claude/query` y el lote — con ellos iban a ser seis copias de
     * `validar_o_422` conviviendo. Ver `ayuda_del_schema()` más abajo: es lo único que este
     * controlador sobrescribe, y es para que su texto de error NO cambie.
     */
    use RespuestasParaClaude;

    /** Página por defecto y tope duro del listado de clientes. */
    const LIMIT_CLIENTS_DEFAULT = 200;
    const LIMIT_CLIENTS_MAX     = 500;

    /** Página por defecto y tope duro del listado de upgrades. */
    const LIMIT_UPGRADES_DEFAULT = 50;
    const LIMIT_UPGRADES_MAX     = 200;

    /** Página por defecto y tope duro del listado de logs de deployment. */
    const LIMIT_LOGS_DEFAULT = 200;
    const LIMIT_LOGS_MAX     = 500;

    /**
     * Caracteres por línea de log antes de truncar.
     *
     * 🔴 Tiene default y no `null` a propósito: los logs de `compile_spa` traen la salida cruda de
     * `npm ci` y `npm run build`, que son decenas de miles de caracteres en una sola fila. Sin
     * truncar, una página de 200 líneas no entra en la ventana de contexto.
     */
    const MAX_LINE_CHARS_DEFAULT = 2000;

    /** Ventana de días resueltos del horario: default y tope. */
    const DIAS_SCHEDULE_DEFAULT = 7;
    const DIAS_SCHEDULE_MAX     = 31;

    /** Cantidad de upgrades recientes que viajan con `include=upgrades_recientes`. */
    const UPGRADES_RECIENTES = 3;

    /**
     * Minutos sin una sola línea de log nueva a partir de los cuales un deployment `running` se
     * reporta como colgado. Esto lo REPORTA; quien lo DESTRABA es
     * `deployments:vencer-colgados` —el gemelo de `leads:vencer-demo-setups-colgados`, que existe
     * desde la misión 61 y corre cada cinco minutos—.
     *
     * 🔴 Los dos números son distintos a propósito: 15 acá porque reportar de más no cuesta nada, y
     * 45 allá porque vencer DESTRUYE ESTADO y tiene que quedar por encima del `$timeout` del job
     * para no matar un deployment vivo. Si alguna vez se igualan, el que está mal es este.
     */
    const STALE_MINUTOS = 15;

    /** Caracteres de la última línea de error que viajan en el resumen de un upgrade. */
    const ULTIMO_ERROR_CHARS = 500;

    /** `include` válidos del listado y la ficha de clientes. */
    const CLIENTS_INCLUDES = ['apis', 'schedule', 'upgrades_recientes', 'contacto'];

    /**
     * Etapas del pipeline, replicadas de `DeploymentService::$steps`.
     *
     * ⚠️ Se replican a propósito: `$steps` es `private` y ese archivo corre el deployment real.
     * Hacerlo público para que lo lea un endpoint de lectura sería abrir el pipeline por un
     * motivo que no lo justifica.
     */
    const PIPELINE_PRE_CIERRE  = ['compile_spa', 'upload_spa', 'upload_api', 'run_migrations', 'pause_for_crons'];
    const PIPELINE_POST_CIERRE = ['run_seeders', 'run_commands', 'update_default_version', 'complete'];

    /** Valores válidos de `client_version_upgrades.deployment_status` (null = nunca arrancó). */
    const DEPLOYMENT_STATUSES = ['running', 'paused', 'paused_post_tasks', 'completed', 'failed'];

    /**
     * Estados que indican un deployment todavía activo. Misma lista que
     * `DeploymentController::$active_deployment_statuses`.
     */
    const ACTIVE_DEPLOYMENT_STATUSES = ['running', 'paused', 'paused_post_tasks'];

    /** Valores válidos de `deployment_logs.level`. */
    const LOG_LEVELS = ['info', 'success', 'error'];

    /** Timestamps de paso del upgrade que se exponen en la ficha. */
    const STEP_TIMESTAMPS = [
        'sistema_actualizado_at',
        'migraciones_corridas_at',
        'crons_supervisor_at',
        'seeders_ejecutados_at',
        'comandos_ejecutados_at',
        'sistema_configurado_at',
    ];

    /**
     * Columnas de `clients` de la proyección flaca (sin `phone`: PII opt-in).
     *
     * @var array<int, string>
     */
    const CLIENT_COLUMNS_BASE = [
        'clients.id',
        'clients.uuid',
        'clients.name',
        'clients.company_name',
        'clients.is_active',
        'clients.current_version_id',
        'clients.active_client_api_id',
        'clients.user_id',
        'clients.shared_database_group_id',
    ];

    /**
     * Auto-descripción de TODO este bloque: filtros, enumeraciones, la máquina de estados del
     * deployment, los frenos de escritura y las limitaciones conocidas.
     *
     * Existe para que Claude no tenga que adivinar ningún valor ni probar endpoints al azar
     * contra clientes de producción hasta que uno devuelva 200.
     *
     * @param Request $request Request entrante (sin parámetros).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function ops_schema_json(Request $request)
    {
        return response()->json([
            'day_keys' => ClientScheduleDay::day_keys_payload(),

            /* 🔴 Los estados de horario tienen DOS niveles y no son intercambiables. */
            'estados_de_horario' => [
                ClientScheduleResolver::ESTADO_ABIERTO,
                ClientScheduleResolver::ESTADO_CERRADO,
                ClientScheduleResolver::ESTADO_SIN_CONFIGURAR,
            ],
            'estados_de_horario_por_dia' => [
                ClientScheduleResolver::ESTADO_CON_HORARIO,
                ClientScheduleResolver::ESTADO_CERRADO,
                ClientScheduleResolver::ESTADO_SIN_CONFIGURAR,
            ],
            'estados_de_horario_nota' => 'Son dos niveles distintos. `estado_ahora` es de INSTANTE y vale '
                . '`abierto` / `cerrado` / `sin_configurar`. El `estado` de cada día resuelto es de DÍA y vale '
                . '`con_horario` / `cerrado` / `sin_configurar`: `con_horario` significa "ese día tiene rangos '
                . 'cargados", no que esté abierto en este momento. 🔴 `sin_configurar` NO es `cerrado`: quiere '
                . 'decir que no hay ni fila del día ni fila `todos`, y no se puede asumir nada.',

            'origenes_de_horario' => [
                ClientScheduleResolver::ORIGEN_DIA_PROPIO,
                ClientScheduleResolver::ORIGEN_TODOS_LOS_DIAS,
                ClientScheduleResolver::ORIGEN_SIN_CONFIGURAR,
            ],
            'origenes_de_horario_nota' => 'La fila `todos` actúa en nombre de todos los días, SALVO los días que '
                . 'tengan su propia fila, que la pisan. Una fila de día con cero rangos es la forma de decir '
                . '"ese día cerramos".',

            'motivos_sin_proximo_cierre' => [
                ClientScheduleResolver::MOTIVO_SIN_CONFIGURAR         => 'Apareció un día sin configurar en la ventana: '
                    . 'la búsqueda se corta ahí y no se adivina.',
                ClientScheduleResolver::MOTIVO_SIN_HORARIOS_EN_LA_VENTANA => 'Se agotó la ventana sin encontrar ningún '
                    . 'día con rangos (cliente cerrado toda la ventana).',
            ],

            'upgrade_statuses'    => UpdateController::STATUS_LABELS,
            'deployment_statuses' => array_merge([null], self::DEPLOYMENT_STATUSES),
            'deployment_statuses_activos' => self::ACTIVE_DEPLOYMENT_STATUSES,
            'log_levels'          => self::LOG_LEVELS,

            'pipeline' => [
                'pre_cierre'  => self::PIPELINE_PRE_CIERRE,
                'post_cierre' => self::PIPELINE_POST_CIERRE,
            ],

            'maquina_de_estados' => [
                [
                    'estado' => 'null | failed | completed',
                    'accion' => 'POST claude/upgrades/{id}/deploy/start',
                    'nota'   => 'Arranca el pre-cierre. 🔴 Borra los logs del intento anterior: si querés el log de '
                        . 'un fallo, leelo ANTES de reintentar.',
                ],
                [
                    'estado' => 'paused (sin crons_supervisor_at)',
                    'accion' => 'POST claude/upgrades/{id}/mark-crons',
                    'nota'   => '⚠️ Marcar los crons NO los mueve. Moverlos entre los subdominios del cliente en el '
                        . 'panel de Hostinger es trabajo MANUAL; este endpoint solo registra que alguien lo hizo.',
                ],
                [
                    'estado' => 'paused (con crons_supervisor_at)',
                    'accion' => 'POST claude/upgrades/{id}/deploy/start-post-closure',
                    'nota'   => 'Tiene gate de horario: con el negocio abierto o sin horarios cargados devuelve 422.',
                ],
                [
                    'estado' => 'paused_post_tasks | failed',
                    'accion' => 'POST claude/upgrades/{id}/deploy/configure-system',
                    'nota'   => 'Etapa final. Acepta `failed` para reintentar ese mismo paso.',
                ],
                [
                    'estado' => 'running',
                    'accion' => 'esperar; consultar GET claude/upgrades/{id}',
                    'nota'   => 'Mirá `salud.deployment_stale` y `salud.jobs_en_cola` antes de asumir que avanza.',
                ],
            ],

            'lectura' => [
                'GET claude/clients' => [
                    'filtros'  => [
                        'current_version_id'       => 'Id de `versions`.',
                        'current_version'          => 'String de versión (ej. "1.4.2"). Se resuelve contra `versions.version`; '
                            . 'si no existe devuelve 422, no una lista vacía.',
                        'is_active'                => 'Booleano.',
                        'q'                        => 'Busca en name, company_name y slug.',
                        'client_ids'               => 'Lista de ids (client_ids[]=1&client_ids[]=2 o client_ids=1,2).',
                        'has_schedule'             => 'Booleano: tiene o no filas en client_schedule_days.',
                        'shared_database_group_id' => 'Id del grupo de base compartida.',
                        'after_id'                 => 'Cursor por id.',
                        'limit'                    => 'Default ' . self::LIMIT_CLIENTS_DEFAULT . ', máximo ' . self::LIMIT_CLIENTS_MAX . '.',
                        'order'                    => 'asc | desc. Default asc.',
                    ],
                    'includes' => [
                        'apis'               => 'client_apis del cliente (id, uuid, url, path, spa_url, hosting_type) más '
                            . '`es_la_activa` por cada una.',
                        'schedule'           => 'Horarios crudos + la resolución de HOY + `estado_ahora`.',
                        'upgrades_recientes' => 'Los últimos ' . self::UPGRADES_RECIENTES . ' upgrades, flacos.',
                        'contacto'           => 'Agrega `phone`. PII: opt-in explícito, por defecto NO viaja.',
                    ],
                ],
                'GET claude/clients/{id}' => 'Ficha completa de un cliente (id numérico o uuid): proyección flaca + apis '
                    . '+ horarios crudos y resueltos + estado_ahora + proximo_cierre + upgrades recientes.',
                'GET claude/clients/{id}/schedule' => [
                    'parametros' => [
                        'dias'     => 'Días a resolver. Default ' . self::DIAS_SCHEDULE_DEFAULT . ', máximo ' . self::DIAS_SCHEDULE_MAX . '.',
                        'desde'    => 'Fecha Y-m-d. Default hoy.',
                        'timezone' => 'Default ' . config('app.timezone') . '.',
                    ],
                ],
                'PUT claude/clients/{id}/schedule' => [
                    'parametros' => [
                        'dias'                => '🔴 El conjunto COMPLETO de días: lo que no viaja acá se BORRA. Cada ítem es '
                            . '{"dia": <una de ' . implode('|', ClientScheduleDay::DAY_KEYS) . '>, "rangos": [{"desde":"HH:MM","hasta":"HH:MM"}]}.',
                        'dry_run'             => 'Booleano. Default TRUE: valida todo, devuelve dias_antes y dias_despues, y no escribe una fila.',
                        'confirm_client_name' => 'Obligatorio cuando dry_run es false. Tiene que coincidir con clients.name (trim + minúsculas). El error no dice el nombre correcto: es un freno, no un formulario.',
                    ],
                    'modelo_de_dias' => [
                        'fila del día CON rangos' => 'Ese día rige ese horario.',
                        'fila del día SIN rangos' => 'Ese día el negocio está CERRADO.',
                        'día sin fila'            => 'Rige la fila `todos` si existe; si no existe, el día queda SIN CONFIGURAR.',
                    ],
                    'nota' => '🔴 `sin_configurar` NO es `cerrado`, y el gate de horario del post-cierre rechaza los dos. '
                        . 'Un rango no cruza la medianoche (`hasta` > `desde`; un negocio que cierra a las 00:00 se carga '
                        . 'con 23:59) y dos rangos del mismo día no se solapan. Guardar encola el push al empresa-api del '
                        . 'cliente: el resultado se mira después en `sincronizacion` del GET.',
                ],
                'GET claude/versions' => [
                    'filtros' => [
                        'status'    => 'draft | published | archived | all. Default published.',
                        'is_hotfix' => 'Booleano.',
                        'q'         => 'Busca en version, title y description.',
                    ],
                    'nota' => '🔴 El default es `published` porque la creación de un upgrade rechaza con 422 toda versión '
                        . 'que no lo esté: la lista que ves es la lista de lo que efectivamente podés pedir.',
                ],
                'GET claude/upgrades' => [
                    'filtros' => [
                        'client_id'           => 'Id del cliente.',
                        'status'              => implode(' | ', array_keys(UpdateController::STATUS_LABELS)) . '.',
                        'deployment_status'   => implode(' | ', self::DEPLOYMENT_STATUSES) . '.',
                        'to_version_id'       => 'Id de la versión destino.',
                        'scheduled_date_from' => 'Fecha Y-m-d.',
                        'scheduled_date_to'   => 'Fecha Y-m-d.',
                        'activos'             => 'Booleano: deployment_status en ' . implode(', ', self::ACTIVE_DEPLOYMENT_STATUSES) . '.',
                        'after_id'            => 'Cursor por id.',
                        'limit'               => 'Default ' . self::LIMIT_UPGRADES_DEFAULT . ', máximo ' . self::LIMIT_UPGRADES_MAX . '.',
                        'order'               => 'asc | desc. Default desc.',
                    ],
                ],
                'GET claude/upgrades/{id}' => 'El endpoint de poleo. Trae estado, pasos, conteos de seeders y comandos, '
                    . 'salud del worker, horario del cliente y `siguiente_accion`. Los seeders y comandos viajan como '
                    . 'CONTEOS, no como filas.',
                'GET claude/upgrades/{id}/logs' => [
                    'filtros' => [
                        'step'           => 'Etapa del pipeline.',
                        'level'          => implode(' | ', self::LOG_LEVELS) . '.',
                        'after_id'       => 'Cursor por id.',
                        'limit'          => 'Default ' . self::LIMIT_LOGS_DEFAULT . ', máximo ' . self::LIMIT_LOGS_MAX . '.',
                        'order'          => 'asc | desc. Default asc (cronológico).',
                        'max_line_chars' => 'Default ' . self::MAX_LINE_CHARS_DEFAULT . '. Una línea truncada viene marcada '
                            . 'con `truncada: true` y `largo_original`.',
                    ],
                ],
            ],

            'frenos' => [
                'confirm_client_name' => 'Obligatorio en TODA escritura (endpoints 10 a 14). Tiene que coincidir con '
                    . '`clients.name` del cliente involucrado. Si no coincide, 422 sin escribir nada, y la respuesta NO '
                    . 'revela el nombre correcto: si lo revelara dejaría de ser un freno y sería un formulario a completar.',
                'dry_run' => 'POST claude/upgrades viene con dry_run=true por defecto. Con dry_run=false hace falta además '
                    . '`confirm_version_count` igual a la cantidad exacta de versiones que se van a confirmar.',
                'allow_deploy_to_active_api' => 'POST .../deploy/start rechaza con 422 si la API destino es la API ACTIVA '
                    . 'en producción, salvo que venga este flag explícito. La API destino por defecto puede ser la activa '
                    . 'cuando el cliente tiene una sola ClientApi.',
                'gate_de_horario' => 'POST .../deploy/start-post-closure exige que el negocio esté CERRADO. Con `abierto` '
                    . 'devuelve 422; con `sin_configurar` TAMBIÉN devuelve 422 (no se asume que esté cerrado). Se saltea '
                    . 'con force=true + force_reason, y eso queda registrado en el log diario.',
            ],

            'limites' => [
                'limit_default'               => self::LIMIT_CLIENTS_DEFAULT,
                'limit_max'                   => self::LIMIT_CLIENTS_MAX,
                'upgrades_limit_default'      => self::LIMIT_UPGRADES_DEFAULT,
                'upgrades_limit_max'          => self::LIMIT_UPGRADES_MAX,
                'logs_limit_default'          => self::LIMIT_LOGS_DEFAULT,
                'logs_limit_max'              => self::LIMIT_LOGS_MAX,
                'logs_max_line_chars_default' => self::MAX_LINE_CHARS_DEFAULT,
                'dias_schedule_max'           => self::DIAS_SCHEDULE_MAX,
                /* Los dos juntos, para que se lea la relación: 15 REPORTA el cuelgue, 45 lo VENCE. */
                'stale_minutos'               => self::STALE_MINUTOS,
                'vencimiento_minutos'         => VencerDeploymentsColgados::timeout_minutos_efectivo(),
            ],

            'limitaciones' => [
                'Un rango horario no puede cruzar la medianoche: se exige hasta > desde. Un negocio que cierra a '
                    . 'medianoche o después se carga con 23:59. Si un cliente cierra a las 2 AM, el post-cierre podría '
                    . 'arrancar hasta dos horas antes de tiempo.',
                'Los endpoints de deploy encolan en la conexión `database` y devuelven 202 al toque. Si el scheduler no '
                    . 'corre en el servidor, no pasa NADA visible: el upgrade queda en `running` y el job dormido en la '
                    . 'tabla `jobs`. `GET claude/upgrades/{id}` mide eso en `salud.jobs_en_cola` y `salud.deployment_stale`.',
                'Un deployment colgado en `running` lo destraba `deployments:vencer-colgados`, que el scheduler corre '
                    . 'cada cinco minutos: lo pasa a `failed` con el motivo escrito como línea de log cuando no reporta '
                    . 'actividad por más de `salud.vencimiento_minutos`. Desde ahí se puede reintentar. Dos '
                    . 'excepciones que SÍ necesitan intervención manual: un upgrade que quedó en `running` antes de que '
                    . 'existiera `deployment_running_since`, y un `deployment_stale: true` sostenido con el scheduler '
                    . 'caído (si no corre el scheduler, tampoco corre el que vence).',
                'Marcar los crons (endpoint 12) NO los mueve. Moverlos en el panel de Hostinger es trabajo manual.',
                'Los horarios del cliente son de SOLO LECTURA desde claude/*. Se cargan desde el modal del cliente en el '
                    . 'admin.',
                'Rate limit de 60 req/min por IP en producción, y claude/* no tiene usuario Sanctum: el limitador agrupa '
                    . 'por IP. Poleá GET claude/upgrades/{id} cada 30 o 60 segundos, NO cada 2.',
            ],

            'notas' => [
                'paginacion' => 'Cursor por id (after_id), no offset: es estable ante inserciones concurrentes y no '
                    . 'degrada con el desplazamiento.',
                'pii'        => 'El `phone` del cliente solo viaja con include=contacto. Por defecto no viaja.',
                'logs'       => '🔴 POST .../deploy/start BORRA los logs del intento anterior. Si querés conservar el log '
                    . 'de un intento fallido, leelo antes de reintentar: después no existe.',
                'created_via' => "client_version_upgrades.created_via = 'claude' marca los upgrades creados por estos "
                    . 'endpoints. NULL = origen no marcado (panel del admin y todo lo anterior a esa columna); en ese '
                    . 'caso el origen es `created_by_admin_id`.',
                'carrera_con_el_panel' => 'Mientras haya un deployment de Claude en curso, no tocar el botón del panel: '
                    . 'el del panel corre INLINE dentro del request y el de Claude en el worker.',
            ],

            'timezone'                 => (string) config('app.timezone'),
            'queue_connection_default' => (string) config('queue.default'),
        ], 200);
    }

    /**
     * Listado de clientes filtrable y paginado por cursor, con proyección flaca e `include`
     * opcional.
     *
     * @param Request $request Request entrante.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function clients_json(Request $request)
    {
        $invalido = $this->validar_o_422($request, [
            'current_version_id'       => 'nullable|integer|min:1',
            'current_version'          => 'nullable|string|max:30',
            'is_active'                => 'nullable|boolean',
            'has_schedule'             => 'nullable|boolean',
            'shared_database_group_id' => 'nullable|integer|min:1',
            'q'                        => 'nullable|string|max:200',
            'after_id'                 => 'nullable|integer|min:1',
            'limit'                    => 'nullable|integer',
            'order'                    => 'nullable|string|in:asc,desc',
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        $includes = $this->resolver_includes($request, self::CLIENTS_INCLUDES);
        if ($includes instanceof \Illuminate\Http\JsonResponse) {
            return $includes;
        }

        $direction = $this->texto_con_default($request, 'order', 'asc');
        $limit     = $this->resolver_limite($request->input('limit'), self::LIMIT_CLIENTS_DEFAULT, self::LIMIT_CLIENTS_MAX);
        $after_id  = $this->entero_o_null($request->input('after_id'));

        $columnas = self::CLIENT_COLUMNS_BASE;
        $columnas[] = 'versions.version as current_version';
        if (in_array('contacto', $includes, true)) {
            $columnas[] = 'clients.phone';
        }

        /* leftJoin y no `with()`: el string de la versión actual entra en la misma consulta y no
           hay ni un modelo Eloquent de por medio. */
        $query = DB::table('clients')
            ->leftJoin('versions', 'versions.id', '=', 'clients.current_version_id');

        $current_version_id = $this->entero_o_null($request->input('current_version_id'));
        if ($current_version_id !== null) {
            $query->where('clients.current_version_id', $current_version_id);
        }

        /* Un string de versión que no existe devuelve 422 con las válidas, no una lista vacía que
           parece un dato. */
        $current_version = $this->texto_o_null($request->input('current_version'));
        if ($current_version !== null) {
            $version_id = DB::table('versions')->where('version', $current_version)->value('id');
            if ($version_id === null) {
                return $this->error_422('La versión "' . $current_version . '" no existe.', [
                    'ayuda' => 'Consultá GET claude/versions?status=all para ver el catálogo.',
                ]);
            }
            $query->where('clients.current_version_id', (int) $version_id);
        }

        $is_active = $this->booleano_o_null($request, 'is_active');
        if ($is_active !== null) {
            $query->where('clients.is_active', $is_active ? 1 : 0);
        }

        $shared_group = $this->entero_o_null($request->input('shared_database_group_id'));
        if ($shared_group !== null) {
            $query->where('clients.shared_database_group_id', $shared_group);
        }

        $client_ids = $this->normalizar_lista_enteros($request->input('client_ids'));
        if (count($client_ids) > 0) {
            $query->whereIn('clients.id', $client_ids);
        }

        $q = $this->texto_o_null($request->input('q'));
        if ($q !== null) {
            $patron = '%' . $q . '%';
            $query->where(function ($sub) use ($patron) {
                $sub->where('clients.name', 'like', $patron)
                    ->orWhere('clients.company_name', 'like', $patron)
                    ->orWhere('clients.slug', 'like', $patron);
            });
        }

        $has_schedule = $this->booleano_o_null($request, 'has_schedule');
        if ($has_schedule !== null) {
            $existe = function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('client_schedule_days')
                    ->whereColumn('client_schedule_days.client_id', 'clients.id');
            };

            if ($has_schedule) {
                $query->whereExists($existe);
            } else {
                $query->whereNotExists($existe);
            }
        }

        $this->aplicar_cursor($query, 'clients.id', $after_id, $direction);
        $query->orderBy('clients.id', $direction);
        $query->select($columnas);

        $pagina = $this->traer_pagina($query, $limit);

        $data = [];
        $ids  = [];
        foreach ($pagina['rows'] as $row) {
            $cliente = $this->proyectar_cliente($row);
            $ids[]   = (int) $cliente['id'];
            $data[]  = $cliente;
        }

        $data = $this->aplicar_includes_de_clientes($data, $ids, $includes, null);

        $count = count($data);

        return response()->json([
            'data'          => $data,
            'count'         => $count,
            'has_more'      => $pagina['has_more'],
            'next_after_id' => ($pagina['has_more'] && $count > 0) ? (int) $data[$count - 1]['id'] : null,
        ], 200);
    }

    /**
     * Ficha completa de un cliente. Como es UNO solo, no hay problema de volumen y viene todo:
     * APIs, horarios crudos y resueltos, estado del negocio ahora, próximo cierre y los últimos
     * upgrades.
     *
     * @param Request    $request Request entrante.
     * @param int|string $id      Id numérico o uuid del cliente.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function client_json(Request $request, $id)
    {
        $invalido = $this->validar_o_422($request, [
            'timezone' => 'nullable|string|max:60',
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        $tz = $this->resolver_timezone_pedido($request);
        if ($tz instanceof \Illuminate\Http\JsonResponse) {
            return $tz;
        }

        $client_id = $this->resolver_client_id($id);
        if ($client_id === null) {
            return $this->error_404('no existe el cliente ' . $id);
        }

        $columnas = self::CLIENT_COLUMNS_BASE;
        $columnas[] = 'versions.version as current_version';
        $columnas[] = 'clients.phone';

        $row = DB::table('clients')
            ->leftJoin('versions', 'versions.id', '=', 'clients.current_version_id')
            ->where('clients.id', $client_id)
            ->select($columnas)
            ->first();

        if ($row === null) {
            return $this->error_404('no existe el cliente ' . $id);
        }

        $data = $this->aplicar_includes_de_clientes(
            [$this->proyectar_cliente($row)],
            [$client_id],
            self::CLIENTS_INCLUDES,
            $tz
        );

        $cliente = $data[0];

        /* La ficha resuelve la semana entera y el próximo cierre; el listado, solo el día de hoy. */
        $modelo = $this->cargar_cliente_con_horarios($client_id);
        if ($modelo !== null) {
            $resolver = $this->resolvedor();
            $ahora    = Carbon::now($tz);

            $cliente['schedule']['resueltos_proximos_dias'] = $resolver->resolve_dias(
                $modelo,
                $ahora,
                self::DIAS_SCHEDULE_DEFAULT,
                $tz
            );

            $detalle = $resolver->proximo_cierre_detallado($modelo, $ahora, self::DIAS_SCHEDULE_DEFAULT, $tz);
            $cliente['schedule']['proximo_cierre']        = $this->instante_iso($detalle['instante']);
            $cliente['schedule']['proximo_cierre_motivo'] = $detalle['motivo'];
        }

        return response()->json([
            'client'         => $cliente,
            'timezone'       => $tz,
            'generado_a_las' => Carbon::now($tz)->toIso8601String(),
        ], 200);
    }

    /**
     * Horarios de un cliente: los días cargados tal cual, la ventana resuelta, el estado del
     * negocio en este instante y el próximo cierre.
     *
     * 🔴 Cuando `proximo_cierre` es null, `proximo_cierre_motivo` dice POR QUÉ. Un null sin motivo
     * es una respuesta que el consumidor no puede interpretar: no es lo mismo "hay un día sin
     * configurar en la ventana y me corto antes de adivinar" que "está cerrado toda la ventana".
     *
     * @param Request    $request Request entrante.
     * @param int|string $id      Id numérico o uuid del cliente.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function client_schedule_json(Request $request, $id)
    {
        $invalido = $this->validar_o_422($request, [
            'dias'     => 'nullable|integer',
            'desde'    => 'nullable|date_format:Y-m-d',
            'timezone' => 'nullable|string|max:60',
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        $tz = $this->resolver_timezone_pedido($request);
        if ($tz instanceof \Illuminate\Http\JsonResponse) {
            return $tz;
        }

        $client_id = $this->resolver_client_id($id);
        if ($client_id === null) {
            return $this->error_404('no existe el cliente ' . $id);
        }

        $cliente = $this->cargar_cliente_con_horarios($client_id);
        if ($cliente === null) {
            return $this->error_404('no existe el cliente ' . $id);
        }

        $dias = $this->resolver_limite($request->input('dias'), self::DIAS_SCHEDULE_DEFAULT, self::DIAS_SCHEDULE_MAX);

        $desde_texto = $this->texto_o_null($request->input('desde'));
        $ahora       = Carbon::now($tz);
        $desde       = $desde_texto === null
            ? $ahora->copy()
            : Carbon::createFromFormat('Y-m-d', $desde_texto, $tz)->startOfDay();

        $resolver = $this->resolvedor();
        $detalle  = $resolver->proximo_cierre_detallado($cliente, $desde, $dias, $tz);

        return response()->json([
            'client' => [
                'id'   => (int) $cliente->id,
                'uuid' => (string) $cliente->uuid,
                'name' => (string) $cliente->name,
            ],
            'timezone'              => $tz,
            'generado_a_las'        => $ahora->toIso8601String(),
            'desde'                 => $desde->toDateString(),
            'dias'                  => $dias,
            'day_keys'              => ClientScheduleDay::day_keys_payload(),
            'dias_cargados'         => $this->dias_cargados_de($cliente),
            'resueltos'             => $resolver->resolve_dias($cliente, $desde, $dias, $tz),
            'estado_ahora'          => $resolver->estado_en($cliente, $ahora, $tz),
            'proximo_cierre'        => $this->instante_iso($detalle['instante']),
            'proximo_cierre_motivo' => $detalle['motivo'],
            /* Estado del último push de estos horarios al empresa-api del cliente. Viaja acá porque
               es el GET al que apuntan tanto el PUT como el POST .../sync cuando dicen "consultá el
               resultado después": sin esto, el que sigue esa indicación no encontraba el dato y la
               única forma de verlo era `GET claude/query?model=client`. Las tres claves en null
               significan "nunca se intentó", que NO es un fallo. */
            'sincronizacion'        => $this->estado_de_sincronizacion_de($cliente),
        ], 200);
    }

    /**
     * Estado del último push de los horarios de un cliente a su empresa-api.
     *
     * 🔴 Los tres en null son "nunca se intentó", que no es lo mismo que un fallo. Ningún consumidor
     * puede colapsarlos a un booleano.
     *
     * @param Client $cliente Cliente.
     *
     * @return array<string, string|null>
     */
    private function estado_de_sincronizacion_de(Client $cliente)
    {
        return [
            'estado'          => $cliente->schedule_sync_status === null ? null : (string) $cliente->schedule_sync_status,
            'mensaje'         => $cliente->schedule_sync_message === null ? null : (string) $cliente->schedule_sync_message,
            'sincronizado_at' => $cliente->schedule_synced_at === null ? null : (string) $cliente->schedule_synced_at,
        ];
    }

    /**
     * Encola el push de los horarios de un cliente a su empresa-api.
     *
     * Es idempotente (reenvía el estado actual de los horarios, no acumula nada del lado del
     * cliente) y por eso no lleva `confirm_client_name` ni `dry_run`, a diferencia de
     * `PUT clients/{id}/schedule`, que SÍ los lleva porque modifica el dato. Lo único que hace es
     * empujarle a la propia API del cliente algo que el admin ya tiene: el daño posible es cero.
     *
     * 🔴 Encola con `->onConnection('database')` explícito y devuelve 202: nunca corre el HTTP
     * adentro del request. Con QUEUE_CONNECTION=sync un dispatch pelado lo correría inline y le
     * sumaría hasta ~45 segundos de espera a este endpoint (timeout 15 s por dos reintentos).
     *
     * El `sincronizacion` que viaja es el estado PERSISTIDO al momento de encolar, o sea el del
     * intento anterior. El resultado de ESTE push se consulta después con
     * `GET claude/clients/{id}/schedule`.
     *
     * @param int|string $id Id numérico o uuid del cliente.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function sync_schedule_json($id)
    {
        $client_id = $this->resolver_client_id($id);
        if ($client_id === null) {
            return $this->error_404('no existe el cliente ' . $id);
        }

        $cliente = Client::find($client_id);
        if ($cliente === null) {
            return $this->error_404('no existe el cliente ' . $id);
        }

        SyncClientScheduleJob::dispatch($client_id)->onConnection('database');

        return response()->json([
            'encolado' => true,
            'conexion' => 'database',
            'client'   => [
                'id'   => (int) $cliente->id,
                'uuid' => (string) $cliente->uuid,
                'name' => (string) $cliente->name,
            ],
            'sincronizacion' => $this->estado_de_sincronizacion_de($cliente),
            'latencia_maxima_segundos' => 60,
            'consultar_estado_en'      => 'GET claude/clients/' . $cliente->id . '/schedule',
            'nota' => 'El push corre en el worker `queue:work database` que el scheduler dispara '
                . 'cada minuto. El estado que viaja acá es el del intento ANTERIOR: este endpoint no '
                . 'espera a que el push termine. Si el empresa-api del cliente todavía no tiene la '
                . 'ruta admin-sync/business-hours, el resultado esperado es `manual_required`.',
        ], 202);
    }

    /**
     * Reemplaza el conjunto entero de horarios de un cliente. Es el ÚNICO endpoint de `claude/*`
     * que escribe horarios, y el que le permite a Claude cargarlos sin pasar por el modal del admin.
     *
     * 🔴 REEMPLAZO ATÓMICO, no un parche por día. Manda el conjunto COMPLETO: lo que no viaja en
     * `dias` se borra. Un PUT con `dias: []` deja al cliente sin ningún horario (que NO es lo mismo
     * que cerrado: lo deja `sin_configurar`). La regla y la transacción viven en
     * `ClientScheduleReplacementService`, el mismo servicio que usa la SPA — no hay dos criterios.
     *
     * 🔴 EL MODELO DE DÍAS, QUE ES DONDE SE EQUIVOCA EL QUE LLAMA:
     *   - fila del día CON rangos  → ese día rige ese horario;
     *   - fila del día SIN rangos  → ese día el negocio está CERRADO;
     *   - día sin fila             → rige la fila `todos` si existe; si no, queda SIN CONFIGURAR.
     *   `sin_configurar` no es `cerrado`, y el gate de horario del post-cierre rechaza los dos: no
     *   se asume que un cliente sin horarios cargados esté cerrado.
     *
     * Frenos, en el mismo orden y con el mismo criterio que `POST claude/upgrades`:
     *   1. `dry_run`, por defecto `true`. Sin `dry_run=false` explícito esto NO escribe una fila,
     *      pero valida el payload entero igual: un dry-run que pasa garantiza que el real no va a
     *      rebotar por forma.
     *   2. `confirm_client_name`, obligatorio cuando `dry_run` es false. El daño más grande posible
     *      acá es pisarle los horarios al cliente equivocado, y un id numérico no tiene ninguna
     *      redundancia; el nombre sí. El error NO revela el nombre correcto.
     *
     * El push al empresa-api del cliente se ENCOLA (`->onConnection('database')`), nunca corre
     * adentro del request: ver `SyncClientScheduleJob`.
     *
     * @param Request                          $request   Request entrante.
     * @param int|string                       $id        Id numérico o uuid del cliente.
     * @param ClientScheduleReplacementService $reemplazo Inyectado por el IoC de Laravel.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function update_schedule_json(Request $request, $id, ClientScheduleReplacementService $reemplazo)
    {
        /* `confirm_client_name` es nullable acá y obligatorio más abajo, cuando `dry_run` es false:
           un dry-run tiene que poder previsualizar sin saber el nombre todavía. Mismo criterio que
           `POST claude/upgrades`. */
        $invalido = $this->validar_o_422($request, [
            'confirm_client_name' => 'nullable|string|max:190',
            'dry_run'             => 'nullable|boolean',
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        $client_id = $this->resolver_client_id($id);
        if ($client_id === null) {
            return $this->error_404('no existe el cliente ' . $id);
        }

        $cliente = $this->cargar_cliente_con_horarios($client_id);
        if ($cliente === null) {
            return $this->error_404('no existe el cliente ' . $id);
        }

        /* La forma del payload la decide el servicio, que es el que la define para los dos caminos.
           Su ValidationException se traduce acá al 422 con la forma única del bloque `claude/*`:
           quien consume esta API no tiene por qué recibir dos cuerpos de error distintos según qué
           validación falló. */
        try {
            $dias = $reemplazo->validar($request->all());
        } catch (ValidationException $e) {
            return $this->error_422(
                'El payload de horarios no es válido. No se escribió nada.',
                [
                    'client_id' => (int) $cliente->id,
                    'detalles'  => $e->validator->errors()->all(),
                    'ayuda'     => 'Cada día es {"dia": <una de day_keys>, "rangos": [{"desde":"HH:MM","hasta":"HH:MM"}]}. '
                        . 'Un día con "rangos": [] significa CERRADO. `hasta` tiene que ser mayor que `desde` '
                        . '(un rango no cruza la medianoche: usá 23:59) y dos rangos del mismo día no se solapan.',
                ]
            );
        }

        $dias_antes = $this->dias_cargados_de($cliente);

        /* Freno 1: sin `dry_run=false` explícito esto no escribe nada. Se contesta con el conjunto
           que QUEDARÍA, ya normalizado y ordenado por el servicio, para que se pueda comparar
           contra `dias_antes` antes de decidir. */
        $dry_run = $request->filled('dry_run') ? $request->boolean('dry_run') : true;
        if ($dry_run) {
            return response()->json([
                'dry_run'    => true,
                'escribio'   => false,
                'client'     => [
                    'id'   => (int) $cliente->id,
                    'uuid' => (string) $cliente->uuid,
                    'name' => (string) $cliente->name,
                ],
                'dias_antes'   => $dias_antes,
                'dias_despues' => $this->dias_como_payload($dias),
                'nota' => 'No se escribió NADA. Para guardar de verdad, repetí la misma llamada con '
                    . 'dry_run=false y confirm_client_name con el nombre exacto del cliente. ⚠️ Es un '
                    . 'REEMPLAZO: `dias_despues` es lo que va a quedar, no lo que se agrega.',
            ], 200);
        }

        /* Freno 2: el nombre. Con `dry_run=false` ya no es opcional. */
        $rechazo = $this->rechazar_si_el_nombre_del_cliente_no_confirma(
            $request,
            $cliente,
            'No se escribió nada.'
        );
        if ($rechazo !== null) {
            return $rechazo;
        }

        $reemplazo->reemplazar($cliente, $dias);

        /* Después de la transacción, nunca adentro: el job lee el cliente de la base cuando corre,
           así que despacharlo adentro sería empujar un estado que todavía puede hacer rollback. */
        SyncClientScheduleJob::dispatch($cliente->id)->onConnection('database');

        $tz       = $this->normalizar_timezone(null);
        $ahora    = Carbon::now($tz);
        $resolver = $this->resolvedor();

        // load() y no loadMissing(): la relación en memoria quedó vieja después del reemplazo.
        $cliente->load('schedule_days.schedule_ranges');

        return response()->json([
            'dry_run'  => false,
            'escribio' => true,
            'client'   => [
                'id'   => (int) $cliente->id,
                'uuid' => (string) $cliente->uuid,
                'name' => (string) $cliente->name,
            ],
            'timezone'      => $tz,
            'dias_antes'    => $dias_antes,
            'dias_cargados' => $this->dias_cargados_de($cliente),
            'resueltos'     => $resolver->resolve_dias($cliente, $ahora, self::DIAS_SCHEDULE_DEFAULT, $tz),
            'estado_ahora'  => $resolver->estado_en($cliente, $ahora, $tz),
            'sync_encolado' => true,
            'nota' => 'Los horarios quedaron guardados. El push al empresa-api del cliente se encoló '
                . 'en la conexión `database` y corre en el worker que el scheduler dispara cada '
                . 'minuto: el resultado se consulta con GET claude/clients/' . (int) $cliente->id
                . '/schedule, en `sincronizacion`.',
        ], 200);
    }

    /**
     * Los días ya validados, con la etiqueta visible agregada, para que el `dry_run` se lea igual
     * que `dias_cargados`.
     *
     * @param array<int, array> $dias Días normalizados que devolvió el servicio.
     *
     * @return array<int, array<string, mixed>>
     */
    private function dias_como_payload(array $dias)
    {
        $salida = [];

        foreach ($dias as $dia) {
            $salida[] = [
                'dia'       => (string) $dia['dia'],
                'dia_label' => ClientScheduleDay::label_for($dia['dia']),
                'rangos'    => $dia['rangos'],
            ];
        }

        return $salida;
    }

    /**
     * Catálogo de versiones. Sin paginación: son pocas filas.
     *
     * 🔴 El default del filtro es `published` porque la creación de un upgrade rechaza con 422
     * toda versión que no lo esté: la lista que se ve tiene que ser la lista de lo que se puede
     * pedir, no un catálogo con trampas.
     *
     * @param Request $request Request entrante.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function versions_json(Request $request)
    {
        $invalido = $this->validar_o_422($request, [
            'status'    => 'nullable|string|in:draft,published,archived,all',
            'is_hotfix' => 'nullable|boolean',
            'q'         => 'nullable|string|max:200',
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        $status = $this->texto_con_default($request, 'status', 'published');

        $query = DB::table('versions');

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $is_hotfix = $this->booleano_o_null($request, 'is_hotfix');
        if ($is_hotfix !== null) {
            $query->where('is_hotfix', $is_hotfix ? 1 : 0);
        }

        $q = $this->texto_o_null($request->input('q'));
        if ($q !== null) {
            $patron = '%' . $q . '%';
            $query->where(function ($sub) use ($patron) {
                $sub->where('version', 'like', $patron)
                    ->orWhere('title', 'like', $patron)
                    ->orWhere('description', 'like', $patron);
            });
        }

        $rows = $query->orderBy('id', 'desc')
            ->select(['id', 'uuid', 'version', 'title', 'description', 'status', 'is_hotfix', 'created_at'])
            ->get();

        $data = [];
        foreach ($rows as $row) {
            $version               = (array) $row;
            $version['id']         = (int) $version['id'];
            $version['is_hotfix']  = (bool) $version['is_hotfix'];
            $data[]                = $version;
        }

        return response()->json([
            'data'         => $data,
            'count'        => count($data),
            'status_usado' => $status,
            'nota'         => 'Solo se puede crear un upgrade hacia una versión `published`.',
        ], 200);
    }

    /**
     * Listado de actualizaciones filtrable y paginado por cursor.
     *
     * 🔴 Proyección flaca a mano, NUNCA `ClientVersionUpgrade::scopeWithAll()`: ese scope arrastra
     * `deployment_logs`, y un solo upgrade puede tener miles de líneas con la salida de
     * `npm run build`.
     *
     * @param Request $request Request entrante.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function upgrades_json(Request $request)
    {
        $invalido = $this->validar_o_422($request, [
            'client_id'           => 'nullable|integer|min:1',
            'status'              => 'nullable|string|in:' . implode(',', array_keys(UpdateController::STATUS_LABELS)),
            'deployment_status'   => 'nullable|string|in:' . implode(',', self::DEPLOYMENT_STATUSES),
            'to_version_id'       => 'nullable|integer|min:1',
            /* 🔴 `ids` y `created_via` existen para poder preguntar por un LOTE de una sola vez.
               Sin ellos, después de `POST claude/upgrades/batch` con veinte clientes no había forma
               de saber cómo iban esos veinte salvo veinte llamadas a GET claude/upgrades/{id} —y el
               lote de ecommerce sí lo tenía resuelto (su 202 manda a
               GET claude/ecommerce/installations?created_via=claude). Empresa quedó sin el equivalente.
               `ids` no lleva regla de tipo porque acepta las dos formas de siempre del bloque
               (`ids[]=1&ids[]=2` o `ids=1,2`) y lo normaliza `normalizar_lista_enteros()`. */
            'created_via'         => 'nullable|string|max:30',
            'scheduled_date_from' => 'nullable|date_format:Y-m-d',
            'scheduled_date_to'   => 'nullable|date_format:Y-m-d',
            'activos'             => 'nullable|boolean',
            'after_id'            => 'nullable|integer|min:1',
            'limit'               => 'nullable|integer',
            'order'               => 'nullable|string|in:asc,desc',
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        $direction = $this->texto_con_default($request, 'order', 'desc');
        $limit     = $this->resolver_limite($request->input('limit'), self::LIMIT_UPGRADES_DEFAULT, self::LIMIT_UPGRADES_MAX);
        $after_id  = $this->entero_o_null($request->input('after_id'));

        $query = DB::table('client_version_upgrades')
            ->leftJoin('clients', 'clients.id', '=', 'client_version_upgrades.client_id')
            ->leftJoin('versions as from_versions', 'from_versions.id', '=', 'client_version_upgrades.from_version_id')
            ->leftJoin('versions as to_versions', 'to_versions.id', '=', 'client_version_upgrades.to_version_id');

        $client_id = $this->entero_o_null($request->input('client_id'));
        if ($client_id !== null) {
            $query->where('client_version_upgrades.client_id', $client_id);
        }

        /* El filtro que convierte "¿cómo van los 20 que creó el lote?" en UNA llamada. La respuesta
           201 de POST claude/upgrades/batch devuelve esta misma lista de ids armada. */
        $ids = $this->normalizar_lista_enteros($request->input('ids'));
        if (count($ids) > 0) {
            $query->whereIn('client_version_upgrades.id', $ids);
        }

        /* Contraparte de `created_via` del lote de ecommerce: sirve para pedir "todo lo que creó
           Claude" cuando ya no se tienen los ids a mano. */
        $created_via = $this->texto_o_null($request->input('created_via'));
        if ($created_via !== null) {
            $query->where('client_version_upgrades.created_via', $created_via);
        }

        $status = $this->texto_o_null($request->input('status'));
        if ($status !== null) {
            $query->where('client_version_upgrades.status', $status);
        }

        $deployment_status = $this->texto_o_null($request->input('deployment_status'));
        if ($deployment_status !== null) {
            $query->where('client_version_upgrades.deployment_status', $deployment_status);
        }

        $to_version_id = $this->entero_o_null($request->input('to_version_id'));
        if ($to_version_id !== null) {
            $query->where('client_version_upgrades.to_version_id', $to_version_id);
        }

        $desde = $this->texto_o_null($request->input('scheduled_date_from'));
        if ($desde !== null) {
            $query->where('client_version_upgrades.scheduled_date', '>=', $desde);
        }

        $hasta = $this->texto_o_null($request->input('scheduled_date_to'));
        if ($hasta !== null) {
            $query->where('client_version_upgrades.scheduled_date', '<=', $hasta);
        }

        $activos = $this->booleano_o_null($request, 'activos');
        if ($activos === true) {
            $query->whereIn('client_version_upgrades.deployment_status', self::ACTIVE_DEPLOYMENT_STATUSES);
        } elseif ($activos === false) {
            /* whereNotIn a secas dejaría afuera los NULL (NULL NOT IN (...) es NULL, no true), que
               son justamente los upgrades que nunca arrancaron: hay que sumarlos explícito. */
            $query->where(function ($sub) {
                $sub->whereNull('client_version_upgrades.deployment_status')
                    ->orWhereNotIn('client_version_upgrades.deployment_status', self::ACTIVE_DEPLOYMENT_STATUSES);
            });
        }

        $this->aplicar_cursor($query, 'client_version_upgrades.id', $after_id, $direction);
        $query->orderBy('client_version_upgrades.id', $direction);
        $query->select([
            'client_version_upgrades.id',
            'client_version_upgrades.uuid',
            'client_version_upgrades.client_id',
            'clients.name as client_name',
            'from_versions.version as from_version',
            'to_versions.version as to_version',
            'client_version_upgrades.status',
            'client_version_upgrades.deployment_status',
            'client_version_upgrades.scheduled_date',
            'client_version_upgrades.deployment_started_at',
            'client_version_upgrades.created_via',
        ]);

        $pagina = $this->traer_pagina($query, $limit);

        $data = [];
        foreach ($pagina['rows'] as $row) {
            $upgrade                = (array) $row;
            $upgrade['id']          = (int) $upgrade['id'];
            $upgrade['client_id']   = (int) $upgrade['client_id'];
            $data[]                 = $upgrade;
        }

        $count = count($data);

        return response()->json([
            'data'          => $data,
            'count'         => $count,
            'has_more'      => $pagina['has_more'],
            'next_after_id' => ($pagina['has_more'] && $count > 0) ? (int) $data[$count - 1]['id'] : null,
        ], 200);
    }

    /**
     * Estado detallado de un upgrade: el endpoint de poleo.
     *
     * Trae todo lo que hace falta para orquestar una actualización sin adivinar: los pasos ya
     * marcados, los conteos de seeders y comandos, la salud del worker, el horario del cliente y
     * `siguiente_accion`, que es la máquina de estados aplicada a este upgrade concreto.
     *
     * @param Request    $request Request entrante.
     * @param int|string $id      Id numérico o uuid del upgrade.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function upgrade_json(Request $request, $id)
    {
        $invalido = $this->validar_o_422($request, [
            'timezone' => 'nullable|string|max:60',
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        $tz = $this->resolver_timezone_pedido($request);
        if ($tz instanceof \Illuminate\Http\JsonResponse) {
            return $tz;
        }

        $upgrade_id = $this->resolver_upgrade_id($id);
        if ($upgrade_id === null) {
            return $this->error_404('no existe el upgrade ' . $id);
        }

        $columnas = [
            'client_version_upgrades.id',
            'client_version_upgrades.uuid',
            'client_version_upgrades.client_id',
            'client_version_upgrades.status',
            'client_version_upgrades.deployment_status',
            'client_version_upgrades.deployment_started_at',
            'client_version_upgrades.deployment_running_since',
            'client_version_upgrades.scheduled_date',
            'client_version_upgrades.target_client_api_id',
            'client_version_upgrades.created_by_admin_id',
            'client_version_upgrades.created_via',
            'client_version_upgrades.default_version_sync_status',
            'client_version_upgrades.default_version_sync_message',
            'clients.name as client_name',
            'clients.uuid as client_uuid',
            'clients.active_client_api_id',
            'from_versions.version as from_version',
            'to_versions.version as to_version',
        ];

        foreach (self::STEP_TIMESTAMPS as $timestamp) {
            $columnas[] = 'client_version_upgrades.' . $timestamp;
        }

        $row = DB::table('client_version_upgrades')
            ->leftJoin('clients', 'clients.id', '=', 'client_version_upgrades.client_id')
            ->leftJoin('versions as from_versions', 'from_versions.id', '=', 'client_version_upgrades.from_version_id')
            ->leftJoin('versions as to_versions', 'to_versions.id', '=', 'client_version_upgrades.to_version_id')
            ->where('client_version_upgrades.id', $upgrade_id)
            ->select($columnas)
            ->first();

        if ($row === null) {
            return $this->error_404('no existe el upgrade ' . $id);
        }

        $fila = (array) $row;

        $target_api = null;
        if ($fila['target_client_api_id'] !== null) {
            $api = DB::table('client_apis')
                ->where('id', (int) $fila['target_client_api_id'])
                ->select(['id', 'uuid', 'url', 'path', 'spa_url', 'hosting_type'])
                ->first();

            if ($api !== null) {
                $target_api                  = (array) $api;
                $target_api['id']            = (int) $target_api['id'];
                $target_api['es_la_activa']  = ((int) $target_api['id']) === (int) $fila['active_client_api_id'];
            }
        }

        $steps = [];
        foreach (self::STEP_TIMESTAMPS as $timestamp) {
            $steps[$timestamp] = $fila[$timestamp];
        }

        $logs = $this->resumen_de_logs($upgrade_id);

        $deployment_status = $this->texto_o_null($fila['deployment_status']);

        $horario = ['estado_ahora' => null, 'proximo_cierre' => null, 'proximo_cierre_motivo' => null, 'timezone' => $tz];
        $cliente = $this->cargar_cliente_con_horarios((int) $fila['client_id']);
        if ($cliente !== null) {
            $resolver = $this->resolvedor();
            $ahora    = Carbon::now($tz);
            $detalle  = $resolver->proximo_cierre_detallado($cliente, $ahora, self::DIAS_SCHEDULE_DEFAULT, $tz);

            $horario['estado_ahora']          = $resolver->estado_en($cliente, $ahora, $tz);
            $horario['proximo_cierre']        = $this->instante_iso($detalle['instante']);
            $horario['proximo_cierre_motivo'] = $detalle['motivo'];
        }

        $salud = $this->salud_del_deployment(
            $deployment_status,
            $fila['deployment_started_at'],
            $logs['ultimo_at'],
            $fila['deployment_running_since']
        );

        return response()->json([
            'upgrade' => [
                'id'                          => (int) $fila['id'],
                'uuid'                        => (string) $fila['uuid'],
                'status'                      => $fila['status'],
                'status_label'                => isset(UpdateController::STATUS_LABELS[$fila['status']])
                    ? UpdateController::STATUS_LABELS[$fila['status']]
                    : $fila['status'],
                'deployment_status'           => $deployment_status,
                'deployment_started_at'       => $fila['deployment_started_at'],
                'scheduled_date'              => $fila['scheduled_date'],
                'created_by_admin_id'         => $fila['created_by_admin_id'] === null ? null : (int) $fila['created_by_admin_id'],
                'created_via'                 => $fila['created_via'],
                'default_version_sync_status' => $fila['default_version_sync_status'],
                'default_version_sync_message' => $fila['default_version_sync_message'],
                'client' => [
                    'id'                   => (int) $fila['client_id'],
                    'uuid'                 => $fila['client_uuid'],
                    'name'                 => $fila['client_name'],
                    'active_client_api_id' => $fila['active_client_api_id'] === null ? null : (int) $fila['active_client_api_id'],
                ],
                'target_client_api' => $target_api,
                'from_version'      => $fila['from_version'],
                'to_version'        => $fila['to_version'],
            ],
            'steps'    => $steps,
            'seeders'  => $this->conteos_de_seeders($upgrade_id),
            'comandos' => $this->conteos_de_comandos($upgrade_id),
            'logs'     => [
                'total'         => $logs['total'],
                'ultimo_at'     => $logs['ultimo_at'],
                'ultimo_error'  => $logs['ultimo_error'],
                'consultar_en'  => 'GET claude/upgrades/' . (int) $fila['id'] . '/logs',
            ],
            'salud'            => $salud,
            'horario_cliente'  => $horario,
            /* 🔴 La salud se calcula UNA vez y se le pasa: `siguiente_accion` necesita
               `deployment_stale` para saber si ofrecer expire-stuck, y recalcularlo adentro serían
               dos mediciones del mismo instante que pueden no coincidir (`jobs_en_cola` y el último
               log se leen de la base). Lo que se publica y lo que se propone tienen que salir del
               mismo número. */
            'siguiente_accion' => $this->siguiente_accion(
                (int) $fila['id'],
                $deployment_status,
                $fila['crons_supervisor_at'],
                $fila['comandos_ejecutados_at'],
                (bool) $salud['deployment_stale'],
                (int) $salud['vencimiento_minutos']
            ),
            'generado_a_las' => Carbon::now($tz)->toIso8601String(),
        ], 200);
    }

    /**
     * Logs del deployment de un upgrade, paginados por cursor y con las líneas truncadas.
     *
     * 🔴 `max_line_chars` tiene default y no null: los logs de `compile_spa` traen la salida cruda
     * de `npm ci` y `npm run build`. Sin truncar, una página no entra en la ventana de contexto.
     * Cuando una línea se trunca la respuesta lo marca, para que no se lea una salida cortada como
     * si fuera completa.
     *
     * @param Request    $request Request entrante.
     * @param int|string $id      Id numérico o uuid del upgrade.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function upgrade_logs_json(Request $request, $id)
    {
        $invalido = $this->validar_o_422($request, [
            'step'           => 'nullable|string|max:60',
            'level'          => 'nullable|string|in:' . implode(',', self::LOG_LEVELS),
            'after_id'       => 'nullable|integer|min:1',
            'limit'          => 'nullable|integer',
            'order'          => 'nullable|string|in:asc,desc',
            'max_line_chars' => 'nullable|integer|min:1',
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        $upgrade_id = $this->resolver_upgrade_id($id);
        if ($upgrade_id === null) {
            return $this->error_404('no existe el upgrade ' . $id);
        }

        $direction = $this->texto_con_default($request, 'order', 'asc');
        $limit     = $this->resolver_limite($request->input('limit'), self::LIMIT_LOGS_DEFAULT, self::LIMIT_LOGS_MAX);

        $max_line_chars = $this->entero_o_null($request->input('max_line_chars'));
        if ($max_line_chars === null) {
            $max_line_chars = self::MAX_LINE_CHARS_DEFAULT;
        }

        $after_id = $this->entero_o_null($request->input('after_id'));

        $query = DB::table('deployment_logs')->where('client_version_upgrade_id', $upgrade_id);

        $step = $this->texto_o_null($request->input('step'));
        if ($step !== null) {
            $query->where('step', $step);
        }

        $level = $this->texto_o_null($request->input('level'));
        if ($level !== null) {
            $query->where('level', $level);
        }

        $this->aplicar_cursor($query, 'id', $after_id, $direction);
        $query->orderBy('id', $direction);
        $query->select(['id', 'step', 'level', 'line', 'created_at']);

        $pagina = $this->traer_pagina($query, $limit);

        $data = [];
        foreach ($pagina['rows'] as $row) {
            $log       = (array) $row;
            $log['id'] = (int) $log['id'];

            $linea = (string) $log['line'];
            $largo = mb_strlen($linea);

            if ($largo > $max_line_chars) {
                $log['line']           = mb_substr($linea, 0, $max_line_chars);
                $log['truncada']       = true;
                $log['largo_original'] = $largo;
            } else {
                $log['truncada'] = false;
            }

            $data[] = $log;
        }

        $count = count($data);

        return response()->json([
            'data'           => $data,
            'count'          => $count,
            'has_more'       => $pagina['has_more'],
            'next_after_id'  => ($pagina['has_more'] && $count > 0) ? (int) $data[$count - 1]['id'] : null,
            'max_line_chars' => $max_line_chars,
            'nota'           => '🔴 POST claude/upgrades/{id}/deploy/start borra los logs del intento anterior. Si querés '
                . 'conservar el log de un intento fallido, leelo antes de reintentar.',
        ], 200);
    }

    /* ------------------------------------------------------------------------------------------
     | Armado de la respuesta de clientes
     |----------------------------------------------------------------------------------------- */

    /**
     * Normaliza una fila cruda de `clients` a la proyección de la respuesta.
     *
     * @param object $row Fila del query builder.
     *
     * @return array<string, mixed>
     */
    private function proyectar_cliente($row)
    {
        $cliente = (array) $row;

        $cliente['id']        = (int) $cliente['id'];
        $cliente['is_active'] = (bool) $cliente['is_active'];

        foreach (['current_version_id', 'active_client_api_id', 'user_id', 'shared_database_group_id'] as $campo) {
            if (array_key_exists($campo, $cliente) && $cliente[$campo] !== null) {
                $cliente[$campo] = (int) $cliente[$campo];
            }
        }

        return $cliente;
    }

    /**
     * Resuelve los `include` de una página de clientes.
     *
     * 🔴 Cada include cuesta un número FIJO de consultas para toda la página, nunca una por
     * cliente. Es lo que impide reintroducir el N+1 que tienen los `$appends` del modelo Client.
     *
     * @param array<int, array<string, mixed>> $data     Clientes ya proyectados.
     * @param array<int, int>                  $ids      Ids de esa página.
     * @param array<int, string>               $includes Includes pedidos.
     * @param string|null                      $timezone Timezone a usar en los horarios.
     *
     * @return array<int, array<string, mixed>>
     */
    private function aplicar_includes_de_clientes(array $data, array $ids, array $includes, $timezone)
    {
        if (count($ids) === 0) {
            return $data;
        }

        if (in_array('apis', $includes, true)) {
            $apis = $this->apis_por_cliente($ids);
            foreach ($data as $indice => $cliente) {
                $client_id            = (int) $cliente['id'];
                $data[$indice]['apis'] = isset($apis[$client_id]) ? $apis[$client_id] : [];
            }
        }

        if (in_array('schedule', $includes, true)) {
            $horarios = $this->horarios_por_cliente($ids, $timezone);
            foreach ($data as $indice => $cliente) {
                $client_id = (int) $cliente['id'];
                $data[$indice]['schedule'] = isset($horarios[$client_id])
                    ? $horarios[$client_id]
                    : ['dias_cargados' => [], 'hoy' => null, 'estado_ahora' => null];
            }
        }

        if (in_array('upgrades_recientes', $includes, true)) {
            $upgrades = $this->upgrades_recientes_por_cliente($ids);
            foreach ($data as $indice => $cliente) {
                $client_id = (int) $cliente['id'];
                $data[$indice]['upgrades_recientes'] = isset($upgrades[$client_id]) ? $upgrades[$client_id] : [];
            }
        }

        return $data;
    }

    /**
     * APIs de una página de clientes, en UNA consulta, con el flag de cuál es la activa.
     *
     * @param array<int, int> $client_ids Ids de la página.
     *
     * @return array<int, array<int, array<string, mixed>>> Indexado por client_id.
     */
    private function apis_por_cliente(array $client_ids)
    {
        $activas = DB::table('clients')
            ->whereIn('id', $client_ids)
            ->pluck('active_client_api_id', 'id');

        $rows = DB::table('client_apis')
            ->whereIn('client_id', $client_ids)
            ->orderBy('id')
            ->select(['id', 'uuid', 'client_id', 'url', 'path', 'spa_url', 'hosting_type'])
            ->get();

        $por_cliente = [];
        foreach ($rows as $row) {
            $api              = (array) $row;
            $client_id        = (int) $api['client_id'];
            $api['id']        = (int) $api['id'];
            $api['client_id'] = $client_id;

            $activa               = isset($activas[$client_id]) ? $activas[$client_id] : null;
            $api['es_la_activa']  = $activa !== null && ((int) $activa) === $api['id'];

            if (! isset($por_cliente[$client_id])) {
                $por_cliente[$client_id] = [];
            }
            $por_cliente[$client_id][] = $api;
        }

        return $por_cliente;
    }

    /**
     * Horarios de una página de clientes: los días cargados tal cual, la resolución de HOY y el
     * estado del negocio en este instante.
     *
     * Los modelos Client se cargan con `with('schedule_days.schedule_ranges')` —tres consultas
     * para toda la página, no una por cliente— y **nunca se serializan**: solo se le pasan al
     * resolvedor. Serializarlos dispararía los accessors de `$appends`, que es el N+1 que este
     * controlador viene a evitar.
     *
     * @param array<int, int> $client_ids Ids de la página.
     * @param string|null     $timezone   Timezone a usar.
     *
     * @return array<int, array<string, mixed>> Indexado por client_id.
     */
    private function horarios_por_cliente(array $client_ids, $timezone)
    {
        $resolver = $this->resolvedor();
        $tz       = $this->normalizar_timezone($timezone);
        $ahora    = Carbon::now($tz);

        $clientes = Client::query()
            ->whereIn('id', $client_ids)
            ->with('schedule_days.schedule_ranges')
            ->get();

        $por_cliente = [];
        foreach ($clientes as $cliente) {
            $por_cliente[(int) $cliente->id] = [
                'timezone'      => $tz,
                'dias_cargados' => $this->dias_cargados_de($cliente),
                'hoy'           => $resolver->resolve_for_date($cliente, $ahora, $tz),
                'estado_ahora'  => $resolver->estado_en($cliente, $ahora, $tz),
            ];
        }

        return $por_cliente;
    }

    /**
     * Los últimos upgrades de cada cliente de la página, flacos y en UNA consulta.
     *
     * Se trae la lista ordenada por cliente e id descendente y se corta en memoria: una consulta
     * por cliente sería exactamente el N+1 que este controlador evita. Un cliente tiene un puñado
     * de upgrades, así que el corte en memoria no es un problema de volumen.
     *
     * 🔴 Sin `withAll()`: ese scope arrastra `deployment_logs`.
     *
     * @param array<int, int> $client_ids Ids de la página.
     *
     * @return array<int, array<int, array<string, mixed>>> Indexado por client_id.
     */
    private function upgrades_recientes_por_cliente(array $client_ids)
    {
        $rows = DB::table('client_version_upgrades')
            ->whereIn('client_id', $client_ids)
            ->orderBy('client_id', 'asc')
            ->orderBy('id', 'desc')
            ->select([
                'id',
                'uuid',
                'client_id',
                'status',
                'deployment_status',
                'to_version_id',
                'scheduled_date',
                'created_via',
            ])
            ->get();

        $por_cliente = [];
        foreach ($rows as $row) {
            $upgrade   = (array) $row;
            $client_id = (int) $upgrade['client_id'];

            if (! isset($por_cliente[$client_id])) {
                $por_cliente[$client_id] = [];
            }

            if (count($por_cliente[$client_id]) >= self::UPGRADES_RECIENTES) {
                continue;
            }

            $upgrade['id']            = (int) $upgrade['id'];
            $upgrade['client_id']     = $client_id;
            $upgrade['to_version_id'] = $upgrade['to_version_id'] === null ? null : (int) $upgrade['to_version_id'];

            $por_cliente[$client_id][] = $upgrade;
        }

        return $por_cliente;
    }

    /**
     * Los días cargados de un cliente, tal cual están en la base, en el orden de presentación.
     *
     * Es el dato CRUDO: no resuelve la precedencia de la fila 'todos'. Para eso está
     * `resolve_dias()` del resolvedor, que es la única fuente de la regla.
     *
     * @param Client $cliente Cliente con `schedule_days.schedule_ranges` ya cargado.
     *
     * @return array<int, array<string, mixed>>
     */
    private function dias_cargados_de(Client $cliente)
    {
        $cliente->loadMissing('schedule_days.schedule_ranges');

        $por_key = [];
        foreach ($cliente->schedule_days as $dia) {
            $rangos = [];
            foreach ($dia->schedule_ranges as $rango) {
                $rangos[] = [
                    'desde' => $this->hora_hhmm($rango->start_time),
                    'hasta' => $this->hora_hhmm($rango->end_time),
                ];
            }

            $por_key[(string) $dia->day_key] = [
                'dia'       => (string) $dia->day_key,
                'dia_label' => ClientScheduleDay::label_for($dia->day_key),
                'rangos'    => $rangos,
            ];
        }

        /* Se devuelve en el orden de DAY_KEYS (todos, lunes … domingo) y no en el de la base:
           el orden de lectura es parte del contrato con quien lo muestra. */
        $dias = [];
        foreach (ClientScheduleDay::DAY_KEYS as $day_key) {
            if (isset($por_key[$day_key])) {
                $dias[] = $por_key[$day_key];
            }
        }

        return $dias;
    }

    /* ------------------------------------------------------------------------------------------
     | Armado de la respuesta de upgrades
     |----------------------------------------------------------------------------------------- */

    /**
     * Conteos agregados de los seeders de un upgrade. Son CONTEOS y no filas: un upgrade puede
     * tener decenas de cada uno y no aportan nada al poleo.
     *
     * @param int $upgrade_id Id del upgrade.
     *
     * @return array<string, int>
     */
    private function conteos_de_seeders($upgrade_id)
    {
        $row = DB::table('update_seeders')
            ->where('client_version_upgrade_id', $upgrade_id)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status = 'exitoso' THEN 1 ELSE 0 END) as exitosos")
            ->selectRaw("SUM(CASE WHEN status = 'fallido' THEN 1 ELSE 0 END) as fallidos")
            ->selectRaw("SUM(CASE WHEN status = 'pendiente' THEN 1 ELSE 0 END) as pendientes")
            ->selectRaw('SUM(CASE WHEN skipped = 1 THEN 1 ELSE 0 END) as skipped')
            ->first();

        return $this->normalizar_conteos($row, ['total', 'exitosos', 'fallidos', 'pendientes', 'skipped']);
    }

    /**
     * Conteos agregados de los comandos de un upgrade, más cuántos son de ejecución manual.
     *
     * `manuales` sale de `version_commands.run_manually`: son los que el pipeline NO ejecuta solo
     * y que alguien tiene que correr a mano en el servidor del cliente.
     *
     * @param int $upgrade_id Id del upgrade.
     *
     * @return array<string, int>
     */
    private function conteos_de_comandos($upgrade_id)
    {
        $row = DB::table('update_commands')
            ->leftJoin('version_commands', 'version_commands.id', '=', 'update_commands.version_command_id')
            ->where('update_commands.client_version_upgrade_id', $upgrade_id)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN update_commands.status = 'exitoso' THEN 1 ELSE 0 END) as exitosos")
            ->selectRaw("SUM(CASE WHEN update_commands.status = 'fallido' THEN 1 ELSE 0 END) as fallidos")
            ->selectRaw("SUM(CASE WHEN update_commands.status = 'pendiente' THEN 1 ELSE 0 END) as pendientes")
            ->selectRaw('SUM(CASE WHEN update_commands.skipped = 1 THEN 1 ELSE 0 END) as skipped')
            ->selectRaw('SUM(CASE WHEN version_commands.run_manually = 1 THEN 1 ELSE 0 END) as manuales')
            ->first();

        return $this->normalizar_conteos($row, ['total', 'exitosos', 'fallidos', 'pendientes', 'skipped', 'manuales']);
    }

    /**
     * Castea a entero los agregados de un SUM/COUNT (que llegan como string o null).
     *
     * @param object|null        $row     Fila del agregado.
     * @param array<int, string> $campos  Claves esperadas.
     *
     * @return array<string, int>
     */
    private function normalizar_conteos($row, array $campos)
    {
        $fila     = $row === null ? [] : (array) $row;
        $conteos  = [];

        foreach ($campos as $campo) {
            $conteos[$campo] = isset($fila[$campo]) && $fila[$campo] !== null ? (int) $fila[$campo] : 0;
        }

        return $conteos;
    }

    /**
     * Total de logs, instante del último y la última línea de error truncada.
     *
     * `ultimo_error` es el 90% de lo que hace falta cuando algo falla, sin bajar los logs enteros.
     *
     * @param int $upgrade_id Id del upgrade.
     *
     * @return array<string, mixed>
     */
    private function resumen_de_logs($upgrade_id)
    {
        $agregado = DB::table('deployment_logs')
            ->where('client_version_upgrade_id', $upgrade_id)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('MAX(created_at) as ultimo_at')
            ->first();

        $fila  = $agregado === null ? [] : (array) $agregado;
        $total = isset($fila['total']) ? (int) $fila['total'] : 0;
        $ultimo_at = isset($fila['ultimo_at']) ? $fila['ultimo_at'] : null;

        $ultimo_error = null;
        if ($total > 0) {
            $linea = DB::table('deployment_logs')
                ->where('client_version_upgrade_id', $upgrade_id)
                ->where('level', 'error')
                ->orderBy('id', 'desc')
                ->value('line');

            if ($linea !== null) {
                $linea = (string) $linea;
                $ultimo_error = mb_strlen($linea) > self::ULTIMO_ERROR_CHARS
                    ? mb_substr($linea, 0, self::ULTIMO_ERROR_CHARS)
                    : $linea;
            }
        }

        return ['total' => $total, 'ultimo_at' => $ultimo_at, 'ultimo_error' => $ultimo_error];
    }

    /**
     * Señales de si el worker está vivo, calculadas y NO persistidas.
     *
     * 🔴 `deployment_status = 'running'` es un estado intermedio del que, desde la misión 61, sí se
     * sale solo: lo destraba `deployments:vencer-colgados`. Lo que sigue sin tener salida es el
     * caso en que **el scheduler entero no corre** en el servidor — ahí ni el worker avanza ni el
     * comando que vence llega a correr, y el upgrade se queda para siempre con el job durmiendo en
     * la tabla `jobs`. `jobs_en_cola` es la medición honesta de eso: `running` + `jobs_en_cola > 0`
     * sostenido varios minutos significa que el worker no está consumiendo. Es un dato, no una
     * conjetura.
     *
     * 🔴 El ancla es `deployment_running_since`, NO `deployment_started_at`. Este método usaba la
     * segunda y por eso mentía: `deployment_started_at` solo lo estampa el `start`, mientras que
     * post-cierre, configure-system y retry-commands entran a `running` sin tocarla. Un upgrade que
     * estuvo dos días en `paused` y al que recién le apretaron "post-cierre" tenía el arranque y el
     * último log con dos días de antigüedad, así que este método devolvía `deployment_stale: true`
     * para un deployment que acababa de encolarse. Se cae a `deployment_started_at` solo para los
     * upgrades anteriores a la migración que agregó la columna.
     *
     * @param string|null $deployment_status        Estado del deployment.
     * @param string|null $deployment_started_at    Cuándo arrancó el deployment entero.
     * @param string|null $ultimo_log_at            Instante del último log.
     * @param string|null $deployment_running_since Cuándo entró al tramo `running` en curso.
     *
     * @return array<string, mixed>
     */
    private function salud_del_deployment($deployment_status, $deployment_started_at, $ultimo_log_at, $deployment_running_since = null)
    {
        $limite  = Carbon::now()->subMinutes(self::STALE_MINUTOS);
        $ultimo  = $this->parsear_o_null($ultimo_log_at);
        $sello   = $this->parsear_o_null($deployment_running_since);
        $arranco = $sello === null ? $this->parsear_o_null($deployment_started_at) : $sello;

        $stale = false;
        if ($deployment_status === 'running') {
            $sin_logs_recientes = $ultimo === null || $ultimo->lessThan($limite);
            $arranco_hace_rato  = $arranco !== null && $arranco->lessThan($limite);
            $stale              = $sin_logs_recientes && $arranco_hace_rato;
        }

        return [
            'deployment_stale'          => $stale,
            'ultimo_log_at'             => $ultimo_log_at,
            'segundos_desde_ultimo_log' => $ultimo === null ? null : Carbon::now()->diffInSeconds($ultimo),
            'jobs_en_cola'              => (int) DB::table('jobs')->count(),
            'stale_minutos'             => self::STALE_MINUTOS,
            'deployment_running_since'  => $deployment_running_since,
            'vencimiento_minutos'       => VencerDeploymentsColgados::timeout_minutos_efectivo(),
            /* ⚠️ Los destrabadores son DOS desde el 28/8/2026 y esta nota los nombra a los dos. Decir sólo el
               del scheduler mandaba a esperar cinco minutos por algo que ya se puede pedir a mano, y ese es
               justamente el caso para el que se construyó `deploy/expire-stuck`. Los dos llaman al MISMO
               `VencerDeploymentsColgados::vencer_upgrade()`: no son dos definiciones de "vencer un deployment". */
            'nota'                      => '`deployment_stale` REPORTA que el worker no está avanzando; no lo arregla. '
                . 'Lo destraban DOS, y los dos corren el mismo vencimiento: `deployments:vencer-colgados`, que el '
                . 'scheduler dispara cada cinco minutos, y `POST claude/upgrades/{id}/deploy/expire-stuck`, que lo pide '
                . 'a mano. Los dos pasan el upgrade a `failed` con el motivo escrito como línea de log cuando no '
                . 'reporta actividad por más de `vencimiento_minutos` — que NO son los `stale_minutos` de esta misma '
                . 'respuesta: estar stale no alcanza para vencer, y expire-stuck contesta 422 si todavía no llegó al '
                . 'umbral destructivo. Un upgrade que quedó en `running` ANTES de que existiera '
                . '`deployment_running_since` no se vence solo ni por el comando ni por el endpoint: ese sigue '
                . 'saliendo a mano, o con force.',
        ];
    }

    /**
     * La máquina de estados del deployment aplicada a este upgrade: qué endpoint toca ahora.
     *
     * Es lo que convierte al endpoint de poleo en el único que hace falta para orquestar una
     * actualización, en vez de probar endpoints al azar hasta que uno devuelva 200.
     *
     * ⚠️ Decisión que el plan no cerraba: `failed` figura en DOS filas de la máquina de estados
     * (puede reintentarse entero con `start`, o solo la etapa final con `configure-system`). Se
     * desempata mirando hasta dónde llegó: si los comandos ya se ejecutaron, lo único que falta es
     * la etapa final. En los dos casos se devuelve también la `alternativa`, para no esconder el
     * otro camino.
     *
     * 🔴 CONOCE LOS DOS ENDPOINTS QUE SE AGREGARON EL 28/8/2026, PORQUE SI NO ESTE MÉTODO MIENTE.
     * Es el único lugar del que sale "qué hago ahora", así que un endpoint que no figura acá no
     * existe para el que orquesta:
     *   - `expire-stuck` en `running` + `salud.deployment_stale`. Antes se devolvía `endpoint: null`
     *     y "esperá", que es exactamente el consejo equivocado para un worker que ya no avanza: el
     *     endpoint se construyó para este caso. ⚠️ Se ofrece SÓLO con `deployment_stale` en true, y
     *     el texto aclara que vencer exige el umbral destructivo (`vencimiento_minutos`) y no el de
     *     aviso (15), para no proponer una llamada que va a contestar 422.
     *   - `retry-commands` en `failed` y en `paused_post_tasks`. Es el camino barato —reintenta sólo
     *     los comandos y NO borra los logs— y estaba invisible, así que la máquina de estados
     *     empujaba a `start`, que sí los borra: el motivo por el que falló se perdía justo antes de
     *     leerlo. Va como `alternativa` y no como `endpoint` a propósito: exige seeders completos y
     *     al menos un comando reintentable, y eso no se puede saber desde acá sin consultar.
     *
     * 🔴 La FORMA de la respuesta no cambió: siguen siendo `endpoint`, `motivo` y `alternativa`.
     * Cambió qué proponen, no las claves.
     *
     * @param int         $upgrade_id             Id del upgrade.
     * @param string|null $deployment_status      Estado del deployment.
     * @param string|null $crons_supervisor_at    Cuándo se marcaron los crons.
     * @param string|null $comandos_ejecutados_at Cuándo se ejecutaron los comandos.
     * @param bool        $deployment_stale       `salud.deployment_stale` ya calculado.
     * @param int|null    $vencimiento_minutos    Umbral destructivo, en minutos.
     *
     * @return array<string, mixed>
     */
    private function siguiente_accion(
        $upgrade_id,
        $deployment_status,
        $crons_supervisor_at,
        $comandos_ejecutados_at,
        $deployment_stale = false,
        $vencimiento_minutos = null
    ) {
        $start            = 'POST claude/upgrades/' . $upgrade_id . '/deploy/start';
        $mark_crons       = 'POST claude/upgrades/' . $upgrade_id . '/mark-crons';
        $post_closure     = 'POST claude/upgrades/' . $upgrade_id . '/deploy/start-post-closure';
        $configure_system = 'POST claude/upgrades/' . $upgrade_id . '/deploy/configure-system';
        $retry_commands   = 'POST claude/upgrades/' . $upgrade_id . '/deploy/retry-commands';
        $expire_stuck     = 'POST claude/upgrades/' . $upgrade_id . '/deploy/expire-stuck';

        $umbral = $vencimiento_minutos === null
            ? VencerDeploymentsColgados::timeout_minutos_efectivo()
            : (int) $vencimiento_minutos;

        if ($deployment_status === 'running') {
            /* Con el worker avanzando no hay nada que hacer más que esperar: proponer expire-stuck
               acá sería proponer matar un pipeline vivo, y quedarían dos DeploymentService por SSH
               sobre el hosting del mismo cliente. */
            if (! $deployment_stale) {
                return [
                    'endpoint' => null,
                    'motivo'   => 'El deployment está corriendo en el worker. Esperá y volvé a consultar '
                        . 'GET claude/upgrades/' . $upgrade_id . ' cada 30 o 60 segundos (rate limit por IP). '
                        . 'Si `salud.deployment_stale` se pone en true, el worker no está avanzando.',
                ];
            }

            return [
                'endpoint'    => $expire_stuck,
                'motivo'      => 'El deployment figura `running` pero `salud.deployment_stale` está en true: el worker '
                    . 'no reporta actividad hace más de ' . self::STALE_MINUTOS . ' minutos. expire-stuck lo pasa a '
                    . '`failed` con el motivo escrito como línea de log y recién ahí se puede arrancar de nuevo. '
                    . '⚠️ Vencer exige el umbral DESTRUCTIVO, que son ' . $umbral . ' minutos sin actividad, no los '
                    . self::STALE_MINUTOS . ' del aviso: si todavía no llegó, contesta 422 y no toca nada. Mirá '
                    . '`salud.vencimiento_minutos` y `salud.segundos_desde_ultimo_log` antes de llamar.',
                'alternativa' => 'Esperar. `deployments:vencer-colgados` corre solo cada cinco minutos y hace '
                    . 'exactamente lo mismo cuando se cumple el umbral: expire-stuck sólo evita la espera.',
            ];
        }

        if ($deployment_status === 'paused') {
            if ($crons_supervisor_at === null) {
                return [
                    'endpoint' => $mark_crons,
                    'motivo'   => 'El deployment está pausado esperando que se muevan los crons. Falta marcar '
                        . 'crons_supervisor_at. ⚠️ Marcarlo NO mueve los crons: moverlos en el panel de Hostinger es '
                        . 'trabajo manual, y este endpoint solo registra que alguien lo hizo.',
                ];
            }

            return [
                'endpoint' => $post_closure,
                'motivo'   => 'Los crons ya están marcados. Falta arrancar las tareas post-cierre, que corren seeders y '
                    . 'comandos sobre el sistema en uso: solo se ejecutan con el negocio CERRADO. Mirá '
                    . '`horario_cliente.estado_ahora` antes de llamar.',
            ];
        }

        if ($deployment_status === 'paused_post_tasks') {
            /* `retry-commands` también es legal acá y no aparecía: el panel lo rechaza sólo en
               `running`, así que `paused` y `paused_post_tasks` pasan. Es el camino para el caso en
               que los seeders terminaron y quedó un comando fallado: reintentarlo no obliga a pasar
               por configure-system con un comando pendiente adentro. */
            return [
                'endpoint'    => $configure_system,
                'motivo'      => 'Las tareas post-cierre terminaron. Falta la etapa final de configuración del sistema.',
                'alternativa' => $retry_commands . ' si quedó algún comando fallado o pendiente: reintenta SOLO los '
                    . 'comandos, no borra ningún log y lleva el mismo gate de horario que el post-cierre (corre sobre el '
                    . 'sistema en uso). Exige seeders completos y al menos un comando reintentable; si no los hay, 422 y '
                    . 'no toca nada.',
            ];
        }

        if ($deployment_status === 'failed') {
            /* 🔴 `retry-commands` entra en las DOS ramas de `failed`. Las dos proponían un endpoint
               caro: `start` reintenta el pipeline entero Y BORRA LOS LOGS del intento fallido —o sea
               el motivo por el que falló, justo antes de leerlo—, y `configure-system` se saltea los
               comandos. Si lo que falló fue un comando, el camino barato es reintentar ese comando.
               Va como alternativa y no como endpoint porque tiene precondiciones que desde acá no se
               pueden mirar sin consultar. */
            $alternativa_retry = $retry_commands . ' reintenta SOLO los comandos y 🔴 NO borra ningún log: si lo que '
                . 'falló fue un comando, es el camino barato. Exige seeders completos y al menos un comando fallado o '
                . 'pendiente; si no, 422 sin tocar nada.';

            if ($comandos_ejecutados_at !== null) {
                return [
                    'endpoint'    => $configure_system,
                    'motivo'      => 'El deployment falló DESPUÉS de ejecutar seeders y comandos: lo único que falta es la '
                        . 'etapa final, y configure-system reintenta ese mismo paso.',
                    'alternativa' => $alternativa_retry . ' | ' . $start . ' reintenta el pipeline COMPLETO desde '
                        . 'compile_spa. 🔴 Borra los logs de este intento: leelos antes.',
                ];
            }

            return [
                'endpoint'    => $start,
                'motivo'      => 'El deployment falló antes de terminar las tareas post-cierre: se reintenta el pipeline '
                    . 'desde el principio. 🔴 start BORRA los logs de este intento fallido: leelos antes de reintentar. '
                    . 'Antes de pagar eso, mirá la alternativa: si lo que falló fue un comando, retry-commands lo '
                    . 'reintenta solo y no borra nada.',
                'alternativa' => $alternativa_retry . ' | ' . $configure_system . ' solo si lo que falló fue la etapa '
                    . 'final de configuración.',
            ];
        }

        if ($deployment_status === 'completed') {
            return [
                'endpoint' => $start,
                'motivo'   => 'El deployment ya terminó. Volver a llamar a start correría el pipeline entero de nuevo: '
                    . 'no hace falta ninguna acción salvo que quieras reejecutarlo a propósito.',
            ];
        }

        return [
            'endpoint' => $start,
            'motivo'   => 'El deployment nunca arrancó. Arranca el pre-cierre (compile_spa → upload_spa → upload_api → '
                . 'run_migrations → pause_for_crons), que es lo que se hace con el negocio ABIERTO: sube el código a la '
                . 'API destino, que no es la que atiende.',
        ];
    }

    /* ------------------------------------------------------------------------------------------
     | Helpers
     |----------------------------------------------------------------------------------------- */

    /**
     * Instancia del resolvedor de horarios. La regla de precedencia vive ahí y solo ahí.
     *
     * @return ClientScheduleResolver
     */
    private function resolvedor()
    {
        return app(ClientScheduleResolver::class);
    }

    /**
     * Cliente con sus horarios ya cargados, listo para el resolvedor.
     *
     * ⚠️ El modelo se usa SOLO para resolver: nunca se serializa en la respuesta, porque sus
     * `$appends` disparan consultas.
     *
     * @param int $client_id Id del cliente.
     *
     * @return Client|null
     */
    private function cargar_cliente_con_horarios($client_id)
    {
        return Client::query()
            ->where('id', $client_id)
            ->with('schedule_days.schedule_ranges')
            ->first();
    }

    /**
     * Resuelve el id de un cliente a partir de un id numérico o un uuid de la ruta.
     *
     * Devuelve null en vez de tirar ModelNotFoundException: acá el 404 se arma a mano, con un
     * cuerpo JSON legible del otro lado.
     *
     * @param int|string $route_id Segmento de la ruta.
     *
     * @return int|null
     */
    private function resolver_client_id($route_id)
    {
        $id = is_numeric($route_id)
            ? DB::table('clients')->where('id', (int) $route_id)->value('id')
            : DB::table('clients')->where('uuid', (string) $route_id)->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Resuelve el id de un upgrade a partir de un id numérico o un uuid de la ruta.
     *
     * @param int|string $route_id Segmento de la ruta.
     *
     * @return int|null
     */
    private function resolver_upgrade_id($route_id)
    {
        $id = is_numeric($route_id)
            ? DB::table('client_version_upgrades')->where('id', (int) $route_id)->value('id')
            : DB::table('client_version_upgrades')->where('uuid', (string) $route_id)->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Timezone pedido por la request, validado contra la lista real de zonas.
     *
     * Se valida acá y no adentro del resolvedor porque una zona inventada tiene que devolver un
     * 422 legible, no una excepción de Carbon convertida en 500.
     *
     * @param Request $request Request entrante.
     *
     * @return string|\Illuminate\Http\JsonResponse
     */
    private function resolver_timezone_pedido(Request $request)
    {
        $pedido = $this->texto_o_null($request->input('timezone'));

        if ($pedido === null) {
            return $this->normalizar_timezone(null);
        }

        if (! in_array($pedido, timezone_identifiers_list(), true)) {
            return $this->error_422('El timezone "' . $pedido . '" no existe.', [
                'ayuda' => 'Usá un identificador de la base de zonas horarias, por ejemplo ' . config('app.timezone') . '.',
            ]);
        }

        return $pedido;
    }

    /**
     * Timezone efectivo, cayendo al de la app.
     *
     * @param string|null $timezone Timezone pedido.
     *
     * @return string
     */
    private function normalizar_timezone($timezone)
    {
        $timezone = trim((string) $timezone);

        if ($timezone === '') {
            $timezone = trim((string) config('app.timezone'));
        }

        return $timezone === '' ? 'UTC' : $timezone;
    }

    /**
     * Hora de una columna `time` ('09:00:00') normalizada a 'H:i'.
     *
     * @param mixed $valor Valor crudo de la columna.
     *
     * @return string|null
     */
    private function hora_hhmm($valor)
    {
        if ($valor instanceof Carbon) {
            return $valor->format('H:i');
        }

        $valor = trim((string) $valor);
        if ($valor === '') {
            return null;
        }

        $partes = explode(':', $valor);
        $hora   = isset($partes[0]) ? (int) $partes[0] : 0;
        $minuto = isset($partes[1]) ? (int) $partes[1] : 0;

        return sprintf('%02d:%02d', $hora, $minuto);
    }

    /**
     * Instante en ISO 8601 con offset explícito, o null.
     *
     * 🔴 Toda hora que viaja lleva su zona: una hora sin zona declarada es discutible, y acá una
     * hora mal interpretada arranca un deployment sobre un negocio abierto.
     *
     * @param Carbon|null $instante Instante a formatear.
     *
     * @return string|null
     */
    private function instante_iso($instante)
    {
        return $instante === null ? null : $instante->toIso8601String();
    }

    /**
     * Endpoint que se le sugiere al que recibió un 422.
     *
     * 🔴 Sobrescribe el default del trait (`GET claude/catalog`) para devolver el mismo
     * `GET claude/ops-schema` que este controlador viene publicando desde el 24/8/2026. La
     * extracción al trait no puede cambiar ni un carácter de una respuesta que ya está en uso:
     * el que quiera el texto nuevo es un endpoint nuevo, no este.
     *
     * @return string
     */
    protected function ayuda_del_schema()
    {
        return 'GET claude/ops-schema';
    }
}

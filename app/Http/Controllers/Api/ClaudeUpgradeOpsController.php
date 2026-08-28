<?php

namespace App\Http\Controllers\Api;

use App\Console\Commands\VencerDeploymentsColgados;
use App\Http\Controllers\Api\Concerns\RespuestasParaClaude;
use App\Http\Controllers\Controller;
use App\Jobs\RunDeploymentJob;
use App\Models\Client;
use App\Models\ClientVersionUpgrade;
use App\Models\Version;
use App\Services\ClientScheduleResolver;
use App\Services\ClientVersionUpgradeCreationService;
use App\Services\VersionPathService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Endpoints de ESCRITURA de actualizaciones y deployment para Claude (endpoints 9 a 14 del plan
 * "actualizaciones manejadas por Claude + horarios del cliente", 24/8/2026).
 *
 * 🔴 ESTO ES LO MÁS PELIGROSO QUE HAY EN `claude/*`. Un envío de WhatsApp mal dirigido es un
 * mensaje incómodo; un deployment mal dirigido baja el sistema de un negocio en funcionamiento.
 * Estos endpoints crean actualizaciones sobre clientes REALES y arrancan procesos por SSH contra
 * sus servidores. No hay entorno intermedio entre esto y la producción de un cliente.
 *
 * De ahí los cuatro frenos, que no son decorativos:
 *
 *  1. `confirm_client_name` — obligatorio en TODA escritura. Tiene que coincidir con
 *     `clients.name` del cliente involucrado (comparado con trim + mb_strtolower en las dos
 *     puntas). 🔴 El error NO revela el nombre correcto: si lo revelara dejaría de ser un freno y
 *     sería un formulario a completar. El daño más grande posible acá es actuar sobre el cliente
 *     equivocado, y un id numérico no tiene ninguna redundancia; el nombre sí.
 *  2. `dry_run` — por defecto `true` en la creación. Para crear de verdad hace falta además
 *     `confirm_version_count` igual a la cantidad exacta de versiones que se van a confirmar.
 *  3. `allow_deploy_to_active_api` — el `start` rechaza desplegar sobre la API ACTIVA en
 *     producción salvo que venga el flag explícito. La API destino por defecto PUEDE ser la
 *     activa cuando el cliente tiene una sola `ClientApi`.
 *  4. Gate de horario — el post-cierre solo corre cuando la JORNADA DE HOY YA TERMINÓ, que no es
 *     lo mismo que "el negocio está cerrado en este instante": a las 14:00 de un 8–13 / 16–21 está
 *     cerrado y reabre a las 16. `abierto` rechaza, `sin_configurar` TAMBIÉN rechaza (no se asume
 *     que un cliente sin horarios cargados esté cerrado), y también rechaza cuando el próximo
 *     cierre del día cae hoy.
 *
 * 🔴 Todo freno que rechaza devuelve 422 y no escribe absolutamente nada: ni estado, ni log, ni
 * job encolado.
 *
 * 🔴 LOS CUATRO `dispatch()` DE ESTE CONTROLADOR (endpoints 11, 13, 14 y el reintento de comandos)
 * VAN CON `->onConnection('database')` EXPLÍCITO, SIN EXCEPCIÓN.
 * `QUEUE_CONNECTION` es `sync` en este proyecto: un `dispatch()` pelado corre el pipeline SSH
 * ENTERO (compile_spa con `npm ci` y `npm run build` en el VPS de builds, uploads, migraciones)
 * adentro del request HTTP, y lo mata `max_execution_time` a los 120 segundos con un fatal que no
 * captura ni el `catch` del job. Es exactamente la clase de error que ya costó tres demos mudas
 * (`RunDemoSetupJob`) y que este repo ya tiene documentada y arreglada en ese otro camino. Por eso
 * estos endpoints devuelven **202** de inmediato y nunca esperan a que el pipeline termine.
 *
 * ⚠️ Precondición de infraestructura que se declara en la propia respuesta: el pipeline lo corre
 * el worker `queue:work database --stop-when-empty` que el scheduler dispara cada minuto. Si ese
 * cron no corre en el servidor, estos endpoints no hacen NADA visible: el upgrade queda en
 * `running` y el job dormido en la tabla `jobs`. `GET claude/upgrades/{id}` mide eso en
 * `salud.jobs_en_cola` y `salud.deployment_stale`.
 *
 * ⚠️ Este controlador NO toca `DeploymentController`, `DeploymentService` ni `RunDeploymentJob`:
 * reusa el pipeline tal cual está. Espeja las reglas de estado del panel (mismas listas, mismas
 * precondiciones) y les suma los frenos, que son del lado de Claude solamente.
 *
 * 🔴 Y hay un endpoint más, `deploy/expire-stuck`, que NO arranca nada sino que DESTRABA: pasa a
 * `failed` un deployment colgado en `running`. No reimplementa el vencimiento: llama al mismo
 * `VencerDeploymentsColgados::vencer_upgrade()` que corre el scheduler cada cinco minutos. El
 * detalle de por qué exige el umbral destructivo (45 min) y no el de reporte (15) está en su
 * docblock.
 */
class ClaudeUpgradeOpsController extends Controller
{
    /**
     * Los helpers genéricos del bloque `claude/*`: normalización de parámetros y las respuestas
     * 422 y 404 con la forma única del bloque. Este controlador tenía su propia copia privada de
     * `texto_o_null()`, `error_422()` y `error_404()`, con el mismo cuerpo línea por línea que las
     * de `ClaudeClientOpsController`; se borraron y ahora salen del trait.
     *
     * ⚠️ `validar_o_422()` y `mensajes_de_validacion()` NO se borraron: las de acá divergieron de
     * las de ClientOps y siguen abajo, sobrescribiendo al trait. El motivo está escrito en cada
     * una. Se unifican el día que se decida cambiar el texto de esas respuestas, que es un cambio
     * de contrato y no un refactor.
     */
    use RespuestasParaClaude;

    /**
     * Estados que indican un deployment todavía activo: con cualquiera de ellos no se arranca
     * otro. Misma lista que `DeploymentController::$active_deployment_statuses`.
     */
    const ACTIVE_DEPLOYMENT_STATUSES = ['running', 'paused', 'paused_post_tasks'];

    /** Conexión de cola en la que se encolan los tres dispatch. 🔴 Explícita, siempre. */
    const CONEXION_DE_COLA = 'database';

    /**
     * Latencia máxima esperable entre el encolado y el arranque real del pipeline, en segundos.
     * El scheduler corre cada minuto: ese es el peor caso de espera antes de ver movimiento.
     */
    const LATENCIA_MAXIMA_SEGUNDOS = 60;

    /** Etapa desde la que arranca cada uno de los cuatro reanudes del pipeline. */
    const ETAPA_PRE_CIERRE       = 'compile_spa';
    const ETAPA_POST_CIERRE      = 'run_seeders';
    const ETAPA_CONFIGURACION    = 'update_default_version';

    /**
     * Etapa del reintento de comandos. Mismo string que despacha el botón del panel en
     * `DeploymentController::retry_commands_json()`: es la segunda mitad del post-cierre, y por eso
     * `deploy_retry_commands_json()` lleva el mismo gate de horario que aquél.
     */
    const ETAPA_REINTENTO_COMANDOS = 'run_commands';

    /** Mínimo de caracteres del motivo con el que se saltea el gate de horario. */
    const FORCE_REASON_MINIMO = 10;

    /** Días de ventana con los que se busca el próximo cierre en los mensajes de error. */
    const DIAS_VENTANA = 7;

    /* ==============================================================================================
     | 9) POST claude/upgrades/preview — candidatas del rango. No persiste nada.
     |============================================================================================= */

    /**
     * Candidatas de versión entre la versión actual del cliente y la versión destino.
     *
     * Espeja el contrato de `UpdateController::preview_json()` (el mismo que consume `Updates.vue`)
     * y usa la misma fuente de la regla, `VersionPathService::candidatesBetween()`. No persiste
     * nada, así que no lleva ningún freno.
     *
     * @param Request $request Request entrante (client_id, to_version_id).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function preview_json(Request $request)
    {
        $invalido = $this->validar_o_422($request, [
            'client_id'     => 'required|exists:clients,id',
            'to_version_id' => 'required|exists:versions,id',
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        $client = Client::find((int) $request->input('client_id'));
        $to     = Version::find((int) $request->input('to_version_id'));

        $rechazo = $this->rechazar_si_no_esta_publicada($to);
        if ($rechazo !== null) {
            return $rechazo;
        }

        $from       = $client->current_version;
        $candidatas = VersionPathService::candidatesBetween($from, $to);

        $payload = [];
        foreach ($candidatas as $candidata) {
            $es_destino = ((int) $candidata->id === (int) $to->id);
            $es_hotfix  = (bool) $candidata->is_hotfix;

            $payload[] = [
                'id'          => (int) $candidata->id,
                'version'     => $candidata->version,
                'title'       => $candidata->title,
                'description' => $candidata->description,
                'is_hotfix'   => $es_hotfix,
                /* 🔴 La regla (troncal sí, hotfix no, destino siempre) NO se escribe acá: sale de
                   `ClientVersionUpgradeCreationService::es_sugerida_por_defecto()`, que es la misma
                   que usa `POST claude/upgrades/batch` para armar el conjunto de cada cliente con la
                   política `sugeridas_del_panel`. Con dos copias, el lote crearía upgrades con un
                   conjunto distinto del que este preview muestra tildado. */
                'default_checked' => ClientVersionUpgradeCreationService::es_sugerida_por_defecto($candidata, $to),
                'is_target'       => $es_destino,
            ];
        }

        return response()->json([
            'client'       => ['id' => (int) $client->id, 'name' => $client->name],
            'from_version' => $from === null ? null : ['id' => (int) $from->id, 'version' => $from->version],
            'to_version'   => ['id' => (int) $to->id, 'version' => $to->version, 'title' => $to->title],
            'candidates'   => $payload,
            'cantidad'     => count($payload),
            'nota'         => '`default_checked` es la SUGERENCIA del panel (troncal sí, hotfix no, destino siempre), '
                . 'no una decisión tomada. 🔴 `confirmed_version_ids` no tiene fallback: para crear la actualización hay '
                . 'que nombrar una por una las versiones que van, mirando esta lista.',
        ], 200);
    }

    /* ==============================================================================================
     | 10) POST claude/upgrades — crear la actualización. dry_run por defecto.
     |============================================================================================= */

    /**
     * Crea un `ClientVersionUpgrade` con las versiones confirmadas, sus `UpdateSeeder` y sus
     * `UpdateCommand`.
     *
     * 🔴 `confirmed_version_ids` es OBLIGATORIO y no tiene fallback. El cálculo automático del
     * conjunto sin confirmación humana es exactamente lo que una misión anterior vino a sacar de
     * `UpdateController::store_json()`, y no se reintroduce por la puerta de atrás de `claude/*`.
     * El flujo correcto es: preview → mirar las candidatas → nombrarlas.
     *
     * Frenos, en orden: `dry_run` (default true) → `confirm_version_count` → `confirm_client_name`.
     *
     * @param Request $request Request entrante.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function store_json(Request $request)
    {
        $invalido = $this->validar_o_422($request, [
            'client_id'               => 'required|exists:clients,id',
            'to_version_id'           => 'required|exists:versions,id',
            'confirmed_version_ids'   => 'required|array|min:1',
            'confirmed_version_ids.*' => 'integer',
            'confirm_client_name'     => 'nullable|string|max:190',
            'confirm_version_count'   => 'nullable|integer|min:1',
            'dry_run'                 => 'nullable|boolean',
            'notes'                   => 'nullable|string',
            'scheduled_date'          => 'nullable|date_format:Y-m-d',
            'target_client_api_id'    => 'nullable|integer|min:1',
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        $client = Client::find((int) $request->input('client_id'));
        $to     = Version::find((int) $request->input('to_version_id'));

        $rechazo = $this->rechazar_si_no_esta_publicada($to);
        if ($rechazo !== null) {
            return $rechazo;
        }

        $service = app(ClientVersionUpgradeCreationService::class);

        /* 🔴 La pertenencia de la API destino se chequea ACÁ y sale por error_422(), como todo el
           resto del bloque. El servicio la valida con `abort(422)`, que en un dry-run saldría por el
           handler de Laravel: sin `error`, sin `ayuda`, con otra forma. El contrato del bloque tiene
           que ser uno solo aunque el caso no escriba nada. */
        $rechazo_api = $this->rechazar_si_la_api_destino_no_es_del_cliente($request, $client);
        if ($rechazo_api !== null) {
            return $rechazo_api;
        }

        /* Resuelve y valida pertenencia al rango SIN crear nada: si viene una versión de afuera,
           esto aborta con 422 antes de tocar la base. */
        $confirmed_ids = $service->resolve_confirmed_version_ids(
            $client,
            $to,
            array_map('intval', $request->input('confirmed_version_ids'))
        );

        $cantidad = count($confirmed_ids);

        /* Freno 2, primera mitad: sin `dry_run=false` explícito esto NO escribe nada. */
        $dry_run = $request->filled('dry_run') ? $request->boolean('dry_run') : true;
        if ($dry_run) {
            return response()->json([
                'dry_run' => true,
                'crearia' => $this->previsualizar_creacion($service, $client, $to, $confirmed_ids, $request),
                'nota'    => 'No se creó NADA. Para crear de verdad, repetí la misma llamada con dry_run=false, '
                    . 'confirm_version_count=' . $cantidad . ' y confirm_client_name con el nombre exacto del cliente.',
            ], 200);
        }

        /* Freno 2, segunda mitad: la cantidad confirmada tiene que coincidir exacto. */
        if (! $request->filled('confirm_version_count')) {
            return $this->error_422('confirm_version_count es obligatorio cuando dry_run es false. No se creó nada.', [
                'versiones_que_se_confirmarian' => $cantidad,
            ]);
        }

        $confirm_version_count = (int) $request->input('confirm_version_count');
        if ($confirm_version_count !== $cantidad) {
            return $this->error_422(
                'confirm_version_count no coincide con la cantidad real de versiones a confirmar (' . $cantidad . '). '
                    . 'No se creó nada.',
                [
                    'confirm_version_count_recibido' => $confirm_version_count,
                    'versiones_que_se_confirmarian'  => $cantidad,
                ]
            );
        }

        /* Freno 1: el nombre del cliente. */
        $rechazo_nombre = $this->rechazar_si_el_nombre_no_confirma($request, $client, null);
        if ($rechazo_nombre !== null) {
            return $rechazo_nombre;
        }

        $opciones = $service->options_from_request($request);
        /* 🔴 `created_by_admin_id` queda en null (Auth::id() sin sesión), que es honesto: no lo creó
           ningún admin. `created_via` dice quién sí. */
        $opciones['created_via'] = ClientVersionUpgrade::CREATED_VIA_CLAUDE;

        /* 🔴 La transacción va acá afuera a propósito: el servicio no la abre porque el camino de la
           SPA nunca la tuvo, y agregársela sería cambiarle el comportamiento a un flujo que Lucas
           usa todos los días. El que la quiere, la envuelve. */
        $upgrade = DB::transaction(function () use ($service, $client, $to, $confirmed_ids, $opciones) {
            return $service->create($client, $to, $confirmed_ids, $opciones);
        });

        /* La respuesta es el MISMO payload que `GET claude/upgrades/{id}`: una sola forma de leer un
           upgrade, no dos que se pueden desincronizar. */
        return $this->payload_de_upgrade((int) $upgrade->id, 201);
    }

    /* ==============================================================================================
     | 11) POST claude/upgrades/{id}/deploy/start — pre-cierre. Encola.
     |============================================================================================= */

    /**
     * Arranca el pipeline PRE-CIERRE: compile_spa → upload_spa → upload_api → run_migrations →
     * pause_for_crons.
     *
     * 🔴 Acá NO hay gate de horario, y es a propósito: el pre-cierre es exactamente lo que se hace
     * con el negocio abierto, porque sube el código a la API DESTINO, que no es la que atiende.
     * Poner un gate sería impedir el uso normal. Sí se informa el horario del cliente, como
     * contexto.
     *
     * ⚠️ Borra los logs del intento anterior, igual que el botón del panel: si querés el log de un
     * intento fallido, leelo ANTES de reintentar.
     *
     * @param Request    $request Request entrante.
     * @param int|string $id      Id numérico o uuid del upgrade.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function deploy_start_json(Request $request, $id)
    {
        $invalido = $this->validar_o_422($request, [
            'confirm_client_name'        => 'required|string|max:190',
            'allow_deploy_to_active_api' => 'nullable|boolean',
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        $upgrade = $this->buscar_upgrade($id);
        if ($upgrade === null) {
            return $this->error_404('no existe el upgrade ' . $id);
        }

        $client = $this->cliente_del_upgrade($upgrade);
        if ($client === null) {
            return $this->error_422('El upgrade no tiene cliente asociado.');
        }

        $rechazo = $this->rechazar_si_el_nombre_no_confirma($request, $client, $upgrade);
        if ($rechazo !== null) {
            return $rechazo;
        }

        if (in_array($upgrade->deployment_status, self::ACTIVE_DEPLOYMENT_STATUSES, true)) {
            return $this->error_422('Ya hay un deployment en curso para este upgrade. No se encoló nada.', [
                'deployment_status' => $upgrade->deployment_status,
                'ayuda'             => 'Consultá GET claude/upgrades/' . (int) $upgrade->id . ' para ver en qué estado quedó.',
            ]);
        }

        if (empty($upgrade->target_client_api_id)) {
            return $this->error_422('El upgrade no tiene API destino configurada (target_client_api_id). No se encoló nada.');
        }

        /* Freno 3: la API destino por defecto PUEDE ser la activa en producción cuando el cliente
           tiene una sola ClientApi. Desplegar ahí es desplegar encima de la instancia viva. */
        $es_la_activa = $client->active_client_api_id !== null
            && ((int) $upgrade->target_client_api_id) === ((int) $client->active_client_api_id);

        if ($es_la_activa && ! $this->pidio_en_true($request, 'allow_deploy_to_active_api')) {
            return $this->error_422(
                'La API destino de este upgrade ES la API ACTIVA en producción del cliente: el deployment iría encima '
                    . 'de la instancia que está atendiendo. No se encoló nada.',
                [
                    'target_client_api_id' => (int) $upgrade->target_client_api_id,
                    'active_client_api_id' => (int) $client->active_client_api_id,
                    'ayuda'                => 'Si es a propósito, repetí con allow_deploy_to_active_api=true. Si no, '
                        . 'corregí la API destino del upgrade antes de arrancar.',
                ]
            );
        }

        /* Reinicio limpio, igual que el panel. */
        $upgrade->deployment_logs()->delete();

        /* `deployment_running_since` acompaña SIEMPRE a `deployment_status => 'running'`: es el
         * ancla con la que `deployments:vencer-colgados` decide si esto está colgado. */
        $upgrade->update([
            'deployment_status'        => 'running',
            'deployment_started_at'    => now(),
            'deployment_running_since' => now(),
        ]);

        /* 🔴 onConnection('database') explícito: sin esto el pipeline SSH entero correría adentro
           de este request y lo mataría max_execution_time. */
        RunDeploymentJob::dispatch($upgrade)->onConnection(self::CONEXION_DE_COLA);

        $respuesta = $this->respuesta_de_encolado($upgrade, self::ETAPA_PRE_CIERRE);
        $respuesta['horario_cliente'] = $this->horario_del_cliente($client);
        $respuesta['nota_logs']       = '🔴 Se borraron los logs del intento anterior de este upgrade.';

        if ($es_la_activa) {
            $respuesta['advertencia'] = 'Se desplegó sobre la API ACTIVA en producción porque vino '
                . 'allow_deploy_to_active_api=true.';
        }

        return response()->json($respuesta, 202);
    }

    /* ==============================================================================================
     | 12) POST claude/upgrades/{id}/mark-crons
     |============================================================================================= */

    /**
     * Marca (o desmarca) `crons_supervisor_at`.
     *
     * 🔴 No se expone la lista blanca de los seis pasos de `UpdateController::mark_step_json`: este
     * endpoint marca UNO y solo uno. Los otros cinco los marca `DeploymentService` cuando el paso
     * realmente corrió, y marcarlos a mano desde afuera sería mentirle al panel — diría
     * "migraciones corridas" sin que haya corrido ninguna.
     *
     * ⚠️ Marcar los crons NO los mueve. Moverlos entre los subdominios del cliente en el panel de
     * Hostinger es trabajo MANUAL; este endpoint solo registra que alguien lo hizo.
     *
     * @param Request    $request Request entrante.
     * @param int|string $id      Id numérico o uuid del upgrade.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function mark_crons_json(Request $request, $id)
    {
        $invalido = $this->validar_o_422($request, [
            'confirm_client_name' => 'required|string|max:190',
            'unmark'              => 'nullable|boolean',
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        $upgrade = $this->buscar_upgrade($id);
        if ($upgrade === null) {
            return $this->error_404('no existe el upgrade ' . $id);
        }

        $client = $this->cliente_del_upgrade($upgrade);
        if ($client === null) {
            return $this->error_422('El upgrade no tiene cliente asociado.');
        }

        $rechazo = $this->rechazar_si_el_nombre_no_confirma($request, $client, $upgrade);
        if ($rechazo !== null) {
            return $rechazo;
        }

        $desmarcar = $this->pidio_en_true($request, 'unmark');

        $upgrade->update([
            'crons_supervisor_at' => $desmarcar ? null : now(),
        ]);

        return $this->payload_de_upgrade((int) $upgrade->id, 200);
    }

    /* ==============================================================================================
     | 13) POST claude/upgrades/{id}/deploy/start-post-closure — con GATE DE HORARIO.
     |============================================================================================= */

    /**
     * Arranca las tareas POST-CIERRE: run_seeders → run_commands.
     *
     * 🔴 Este es el endpoint que amarra las dos mitades de la misión. El post-cierre corre seeders
     * y comandos sobre el sistema EN USO del cliente: si el negocio está abierto, no se ejecuta.
     *
     * 🔴 La condición que se exige es que **la jornada de hoy haya terminado**, no que el negocio
     * esté cerrado en este instante. La regla completa y por qué está en
     * `rechazo_del_gate_de_horario()`; en corto, rechaza con 422:
     *   - `sin_configurar` → NO se asume que esté cerrado. "No sé" no es "está cerrado".
     *   - `abierto`        → obvio.
     *   - un día sin configurar en la ventana del próximo cierre → no se adivina.
     *   - el próximo cierre cae HOY → o todavía no abrió, o está en el hueco entre turnos.
     * Y deja pasar cuando el próximo cierre cae otro día (la jornada terminó), cuando el día está
     * cerrado entero y cuando el cliente está cerrado toda la ventana.
     *
     * Se saltea con `force=true` + `force_reason` (mínimo 10 caracteres), y eso deja constancia en
     * el log diario SIEMPRE que el gate hubiera rechazado: un freno que se puede saltear sin dejar
     * rastro no es un freno.
     *
     * @param Request    $request Request entrante.
     * @param int|string $id      Id numérico o uuid del upgrade.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function deploy_start_post_closure_json(Request $request, $id)
    {
        $invalido = $this->validar_o_422($request, [
            'confirm_client_name' => 'required|string|max:190',
            'force'               => 'nullable|boolean',
            'force_reason'        => 'nullable|string|max:500',
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        $upgrade = $this->buscar_upgrade($id);
        if ($upgrade === null) {
            return $this->error_404('no existe el upgrade ' . $id);
        }

        $client = $this->cliente_del_upgrade($upgrade);
        if ($client === null) {
            return $this->error_422('El upgrade no tiene cliente asociado.');
        }

        $rechazo = $this->rechazar_si_el_nombre_no_confirma($request, $client, $upgrade);
        if ($rechazo !== null) {
            return $rechazo;
        }

        if ($upgrade->deployment_status !== 'paused') {
            return $this->error_422(
                'El deployment no está pausado esperando las tareas post-cierre. No se encoló nada.',
                [
                    'deployment_status'  => $upgrade->deployment_status,
                    'deployment_status_esperado' => 'paused',
                    'ayuda'              => 'Consultá GET claude/upgrades/' . (int) $upgrade->id . ' y mirá `siguiente_accion`.',
                ]
            );
        }

        if (empty($upgrade->crons_supervisor_at)) {
            return $this->error_422(
                'Falta marcar los crons antes de arrancar las tareas post-cierre. No se encoló nada.',
                [
                    'ayuda' => 'POST claude/upgrades/' . (int) $upgrade->id . '/mark-crons. ⚠️ Marcarlos NO los mueve: '
                        . 'moverlos en el panel de Hostinger es trabajo manual.',
                ]
            );
        }

        /* Gate de horario. La regla y el `force` viven en aplicar_gate_de_horario(), que comparten
           este endpoint y el reintento de comandos: son la misma pregunta sobre el mismo sistema en
           uso, y dos copias de un freno se desincronizan. */
        $gate = $this->aplicar_gate_de_horario($request, $client, $upgrade, 'el post-cierre');
        if ($gate['rechazo'] !== null) {
            return $gate['rechazo'];
        }

        /* Sello del tramo: sin esto, un upgrade que estuvo días en `paused` entraría a `running`
         * con el ancla vieja y el vencimiento lo mataría en el primer tick. */
        $upgrade->update([
            'deployment_status'        => 'running',
            'deployment_running_since' => now(),
        ]);

        /* 🔴 onConnection('database') explícito. */
        RunDeploymentJob::dispatch($upgrade, self::ETAPA_POST_CIERRE)->onConnection(self::CONEXION_DE_COLA);

        $respuesta = $this->respuesta_de_encolado($upgrade, self::ETAPA_POST_CIERRE);
        $respuesta['horario_cliente'] = $this->horario_del_cliente($client);

        if ($gate['salteado'] !== null) {
            $respuesta['gate_de_horario_salteado'] = $gate['salteado'];
        }

        return response()->json($respuesta, 202);
    }

    /* ==============================================================================================
     | 15) POST claude/upgrades/{id}/deploy/retry-commands — reintento de comandos, CON gate.
     |============================================================================================= */

    /**
     * Reintenta los comandos automatizados desde el primero fallido o pendiente.
     *
     * 🔴 ESPEJA `DeploymentController::retry_commands_json()` (el botón del panel) en todo lo que es
     * estado, y le SUMA un freno que el panel no tiene. Lo que se espeja, exacto:
     *
     *  - Se rechaza SOLO `running`. `paused` y `paused_post_tasks` **sí** pasan: espejar significa
     *    espejar, y desde `paused_post_tasks` es justamente donde se reintenta un comando que falló.
     *  - Seeders completos: ningún `UpdateSeeder` con `skipped = false` y `status !== 'exitoso'`. Un
     *    seeder `skipped` cuenta como completo (lo marca `SharedDatabaseAutoSkipService` cuando ya
     *    corrió en un cliente hermano de la misma base).
     *  - Al menos un `UpdateCommand` retriable: con `version_command` cargado, `run_manually = false`,
     *    `skipped = false` y `status` en `fallido` o `pendiente`.
     *  - Se despacha `RunDeploymentJob` desde la etapa `run_commands`.
     *
     * 🔴 LA ÚNICA DIVERGENCIA DELIBERADA CON EL PANEL ES EL GATE DE HORARIO, y va escrita acá para
     * que nadie la lea como un olvido ni la "corrija". `run_commands` corre comandos de artisan sobre
     * el sistema EN USO del cliente: es la segunda mitad exacta del post-cierre. Si el post-cierre no
     * arranca con el negocio abierto, un reintento de esos mismos comandos tampoco puede. El panel se
     * lo puede permitir porque lo aprieta un humano que sabe si el local está lleno de gente;
     * `claude/*` no tiene esa información salvo que la mire, y por eso la mira. Se saltea igual que
     * en el post-cierre: `force=true` + `force_reason` de al menos FORCE_REASON_MINIMO caracteres, y
     * queda registrado en el log diario.
     *
     * ⚠️ A diferencia de `start`, este endpoint **NO borra los logs** del intento anterior: el
     * motivo por el que un comando falló es justo lo que hace falta para decidir si reintentar. Se
     * declara en la respuesta.
     *
     * @param Request    $request Request entrante.
     * @param int|string $id      Id numérico o uuid del upgrade.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function deploy_retry_commands_json(Request $request, $id)
    {
        $invalido = $this->validar_o_422($request, [
            'confirm_client_name' => 'required|string|max:190',
            'force'               => 'nullable|boolean',
            'force_reason'        => 'nullable|string|max:500',
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        $upgrade = $this->buscar_upgrade($id);
        if ($upgrade === null) {
            return $this->error_404('no existe el upgrade ' . $id);
        }

        $client = $this->cliente_del_upgrade($upgrade);
        if ($client === null) {
            return $this->error_422('El upgrade no tiene cliente asociado.');
        }

        $rechazo = $this->rechazar_si_el_nombre_no_confirma($request, $client, $upgrade);
        if ($rechazo !== null) {
            return $rechazo;
        }

        /* Espejo exacto del panel: SOLO `running` rechaza. */
        if ($upgrade->deployment_status === 'running') {
            return $this->error_422(
                'Ya hay un deployment en curso para este upgrade. No se encoló nada.',
                [
                    'deployment_status' => $upgrade->deployment_status,
                    'ayuda'             => 'Consultá GET claude/upgrades/' . (int) $upgrade->id . ' y mirá '
                        . '`salud.deployment_stale`. Si el worker se murió, POST claude/upgrades/'
                        . (int) $upgrade->id . '/deploy/expire-stuck lo destraba pasado el umbral de vencimiento.',
                ]
            );
        }

        $upgrade->loadMissing('update_commands.version_command', 'update_seeders');

        /* Espejo: un seeder `skipped` cuenta como completo. */
        $seeders_incompletos = $upgrade->update_seeders->filter(function ($update_seeder) {
            if ((bool) $update_seeder->skipped) {
                return false;
            }

            return $update_seeder->status !== 'exitoso';
        });

        if ($seeders_incompletos->isNotEmpty()) {
            return $this->error_422(
                'Faltan seeders por completar: los comandos no se reintentan con seeders a medias. No se encoló nada.',
                [
                    'seeders_incompletos' => $seeders_incompletos->count(),
                    'ayuda'               => 'Un seeder saltado (skipped) cuenta como completo; los que faltan están en '
                        . 'estado distinto de `exitoso`. Mirá GET claude/upgrades/' . (int) $upgrade->id
                        . ' (bloque `seeders`) y arrancá las tareas post-cierre antes de reintentar los comandos.',
                ]
            );
        }

        /* Espejo: `run_manually` y `skipped` no son retriables. */
        $retriables = $upgrade->update_commands->filter(function ($update_command) {
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

        if ($retriables->isEmpty()) {
            return $this->error_422(
                'No hay ningún comando automatizado pendiente ni fallido para reintentar. No se encoló nada.',
                [
                    'ayuda' => 'Los comandos marcados para ejecución manual (`run_manually`) y los saltados (`skipped`) '
                        . 'no se reintentan. Mirá GET claude/upgrades/' . (int) $upgrade->id . ' (bloque `comandos`).',
                ]
            );
        }

        /* 🔴 El freno que el panel NO tiene. Ver el docblock de este método. */
        $gate = $this->aplicar_gate_de_horario($request, $client, $upgrade, 'el reintento de comandos');
        if ($gate['rechazo'] !== null) {
            return $gate['rechazo'];
        }

        /* Sello del tramo, igual que en las otras entradas a `running`. */
        $upgrade->update([
            'deployment_status'        => 'running',
            'deployment_running_since' => now(),
        ]);

        /* 🔴 onConnection('database') explícito. */
        RunDeploymentJob::dispatch($upgrade, self::ETAPA_REINTENTO_COMANDOS)->onConnection(self::CONEXION_DE_COLA);

        $respuesta = $this->respuesta_de_encolado($upgrade, self::ETAPA_REINTENTO_COMANDOS);
        $respuesta['horario_cliente']       = $this->horario_del_cliente($client);
        $respuesta['comandos_a_reintentar'] = $this->detalle_de_comandos($retriables);
        $respuesta['nota_logs']             = 'A diferencia de `deploy/start`, este endpoint NO borra los logs del '
            . 'intento anterior: el motivo por el que el comando falló sigue disponible en GET claude/upgrades/'
            . (int) $upgrade->id . '/logs.';

        if ($gate['salteado'] !== null) {
            $respuesta['gate_de_horario_salteado'] = $gate['salteado'];
        }

        return response()->json($respuesta, 202);
    }

    /* ==============================================================================================
     | 16) POST claude/upgrades/{id}/deploy/expire-stuck — destrabar un deployment colgado.
     |============================================================================================= */

    /**
     * Vence un deployment que quedó colgado en `running` y lo deja en `failed`, con el motivo escrito
     * como línea de log.
     *
     * 🔴 NO REIMPLEMENTA EL VENCIMIENTO: llama a `VencerDeploymentsColgados::vencer_upgrade()`, que es
     * exactamente el cuerpo que corre el comando `deployments:vencer-colgados` cada cinco minutos —la
     * medición de la última actividad, el claim atómico condicionado por `id` + `deployment_status` +
     * `deployment_running_since`, la línea `step = 'vencimiento'` y el `Log::warning`—. Dos
     * definiciones de "qué significa vencer un deployment" se desincronizan, y la que se quedaría
     * vieja es justo la que un humano invoca a mano cuando algo ya salió mal.
     *
     * 🔴 EL UMBRAL QUE HABILITA ESTA ESCRITURA ES EL DESTRUCTIVO (45 min por defecto), NO EL DE
     * REPORTE (15). `salud.deployment_stale` de `GET claude/upgrades/{id}` avisa a los 15 minutos
     * porque reportar de más no cuesta nada; vencer marca `failed`, y `failed` es un estado del que
     * las dos puertas dejan arrancar de nuevo. Si el pipeline seguía vivo —un `compile_spa` puede
     * pasar varios minutos sin escribir una línea— quedarían dos `DeploymentService` por SSH sobre el
     * hosting del mismo cliente, uno descomprimiendo la API mientras el otro corre migraciones. Por
     * eso el 422 devuelve LOS DOS números y explica la diferencia en vez de mostrar uno solo.
     *
     * 🔴 Y si el claim afecta 0 filas —el worker terminó entre la medición y el UPDATE— se devuelve
     * 409 sin tocar nada: sin ese caso, este endpoint mataría un tramo recién nacido.
     *
     * @param Request    $request Request entrante.
     * @param int|string $id      Id numérico o uuid del upgrade.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function deploy_expire_stuck_json(Request $request, $id)
    {
        $invalido = $this->validar_o_422($request, [
            'confirm_client_name' => 'required|string|max:190',
            'force'               => 'nullable|boolean',
            'force_reason'        => 'nullable|string|max:500',
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        $upgrade = $this->buscar_upgrade($id);
        if ($upgrade === null) {
            return $this->error_404('no existe el upgrade ' . $id);
        }

        $client = $this->cliente_del_upgrade($upgrade);
        if ($client === null) {
            return $this->error_422('El upgrade no tiene cliente asociado.');
        }

        $rechazo = $this->rechazar_si_el_nombre_no_confirma($request, $client, $upgrade);
        if ($rechazo !== null) {
            return $rechazo;
        }

        if ($upgrade->deployment_status !== 'running') {
            return $this->error_422(
                'Este deployment no está en `running`: no hay nada que destrabar. No se tocó nada.',
                [
                    'deployment_status'          => $upgrade->deployment_status,
                    'deployment_status_esperado' => 'running',
                    'ayuda'                      => 'Consultá GET claude/upgrades/' . (int) $upgrade->id
                        . ' y mirá `siguiente_accion`. `paused` y `paused_post_tasks` son esperas legítimas, no cuelgues.',
                ]
            );
        }

        /* El motivo del force se valida ANTES de medir nada: si viene mal, se rechaza sin haber
           calculado ni escrito una sola cosa. */
        $forzado = $this->pidio_en_true($request, 'force');
        $motivo_del_force = $this->texto_o_null($request->input('force_reason'));

        if ($forzado && ($motivo_del_force === null || mb_strlen($motivo_del_force) < self::FORCE_REASON_MINIMO)) {
            return $this->error_422(
                'force_reason es obligatorio cuando force es true, y tiene que tener al menos '
                    . self::FORCE_REASON_MINIMO . ' caracteres. No se tocó nada.',
                [
                    'ayuda' => 'El motivo queda registrado en el log del sistema junto con la medición que se salteó. '
                        . 'Un freno que se saltea sin dejar rastro no es un freno.',
                ]
            );
        }

        $vencimiento_minutos = VencerDeploymentsColgados::timeout_minutos_efectivo();
        $salud               = $this->salud_del_upgrade((int) $upgrade->id);

        /* 🔴 Sin `deployment_running_since` no hay ancla, así que NO HAY MEDICIÓN: es el mismo caso
           que `deployments:vencer-colgados` saltea con su `whereNotNull`. Vencerlo sería inventar
           la medición, y por eso sólo se sale con force. */
        if ($upgrade->deployment_running_since === null && ! $forzado) {
            return $this->error_422(
                'Este deployment no tiene `deployment_running_since`: sin ancla no se puede medir hace cuánto está '
                    . 'colgado, y vencerlo sería inventar la medición. No se tocó nada.',
                [
                    'deployment_running_since' => null,
                    'vencimiento_minutos'      => $vencimiento_minutos,
                    'ayuda'                    => 'Son los upgrades que entraron a `running` ANTES de la migración que '
                        . 'agregó la columna: `deployments:vencer-colgados` también los saltea. Si sabés a mano que el '
                        . 'proceso murió, repetí con force=true y force_reason (mínimo ' . self::FORCE_REASON_MINIMO
                        . ' caracteres).',
                ]
            );
        }

        /* 🔴 La escritura entera —medición, claim atómico, línea de log— vive en el comando. Acá no
           se reimplementa ninguna de las tres. */
        $resultado = app(VencerDeploymentsColgados::class)->vencer_upgrade($upgrade, $vencimiento_minutos, $forzado);

        if (! $resultado['vencido'] && $resultado['motivo'] === 'claim_perdido') {
            return response()->json([
                'error'             => 'El deployment cambió de estado mientras se lo evaluaba. No se tocó nada.',
                'upgrade_id'        => (int) $upgrade->id,
                'deployment_status' => (string) $upgrade->refresh()->deployment_status,
                'ayuda'             => 'El worker terminó el tramo entre la medición y la escritura, así que el claim '
                    . 'atómico no afectó ninguna fila: marcar `failed` ahora mataría un tramo distinto del que se midió. '
                    . 'Consultá GET claude/upgrades/' . (int) $upgrade->id . ' y decidí con el estado nuevo a la vista.',
            ], 409);
        }

        if (! $resultado['vencido']) {
            return $this->error_422('Este deployment no está vencido todavía. No se tocó nada.', [
                'deployment_stale'      => $salud === null ? null : $salud['deployment_stale'],
                'minutos_sin_actividad' => $resultado['minutos_sin_actividad'],
                'stale_minutos_reporte' => $salud === null ? null : $salud['stale_minutos'],
                'vencimiento_minutos'   => $vencimiento_minutos,
                'ayuda'                 => '`deployment_stale` en true significa que el worker no reporta hace más de '
                    . ($salud === null ? '15' : $salud['stale_minutos']) . ' min, que es el umbral de AVISO. Vencer un '
                    . 'deployment lo marca `failed` y habilita arrancar otro: si el pipeline seguía vivo, quedarían dos '
                    . 'DeploymentService por SSH sobre el mismo hosting. Esperá a los ' . $vencimiento_minutos
                    . ' min, o volvé con force=true y force_reason.',
            ]);
        }

        /* Constancia del salteo, con el mismo criterio que el gate de horario: se escribe cuando el
           force hizo una diferencia real, o sea cuando la medición NO alcanzaba el umbral. */
        $salteo_real = $forzado
            && ($resultado['minutos_sin_actividad'] === null
                || $resultado['minutos_sin_actividad'] < $vencimiento_minutos);

        if ($salteo_real) {
            Log::channel('daily')->warning('[claude/upgrades] Vencimiento FORZADO de un deployment por debajo del umbral.', [
                'client_id'             => (int) $client->id,
                'upgrade_id'            => (int) $upgrade->id,
                'minutos_sin_actividad' => $resultado['minutos_sin_actividad'],
                'vencimiento_minutos'   => $vencimiento_minutos,
                'force_reason'          => $motivo_del_force,
            ]);
        }

        $respuesta = [
            'vencido'                  => true,
            'upgrade_id'               => (int) $upgrade->id,
            'upgrade_uuid'             => (string) $upgrade->uuid,
            'deployment_status'        => 'failed',
            'minutos_sin_actividad'    => $resultado['minutos_sin_actividad'],
            'timeout_minutos'          => $vencimiento_minutos,
            'motivo_escrito_en_el_log' => $resultado['motivo_escrito_en_el_log'],
            'siguiente_accion'         => 'POST claude/upgrades/' . (int) $upgrade->id . '/deploy/start',
            'advertencia'              => '⚠️ El servidor del cliente quedó en un estado DESCONOCIDO: se marcó `failed` '
                . 'para poder reintentar, no porque se sepa que falló. Verificá en qué estado quedó antes de volver a '
                . 'arrancar.',
            'nota'                     => 'Es la misma escritura que hace `deployments:vencer-colgados` cada cinco '
                . 'minutos: se llama al mismo método, no a una copia. Los logs del deployment NO se borraron; los borra '
                . '`deploy/start` cuando se reintenta.',
        ];

        if ($salteo_real) {
            $respuesta['vencimiento_forzado'] = [
                'minutos_sin_actividad' => $resultado['minutos_sin_actividad'],
                'vencimiento_minutos'   => $vencimiento_minutos,
                'motivo'                => $motivo_del_force,
                'registrado'            => true,
            ];
        }

        return response()->json($respuesta, 200);
    }

    /* ==============================================================================================
     | 14) POST claude/upgrades/{id}/deploy/configure-system — etapa final.
     |============================================================================================= */

    /**
     * Arranca la etapa final: update_default_version → complete.
     *
     * Acepta `paused_post_tasks` (flujo normal, recién terminados los comandos) y `failed` (el
     * reintento manual de ese mismo paso), misma regla que `DeploymentController::configure_system_json`.
     *
     * ⚠️ Esta etapa marca `sistema_configurado_at` solo si `default_version_sync_status` quedó en
     * `success`: si degradó a `manual_required`, el timestamp queda sin marcar a propósito, para que
     * se vea pendiente hasta que un humano lo resuelva en el servidor del cliente.
     * `GET claude/upgrades/{id}` expone `default_version_sync_status` para distinguir "terminó" de
     * "terminó pero quedó algo a mano".
     *
     * @param Request    $request Request entrante.
     * @param int|string $id      Id numérico o uuid del upgrade.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function deploy_configure_system_json(Request $request, $id)
    {
        $invalido = $this->validar_o_422($request, [
            'confirm_client_name' => 'required|string|max:190',
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        $upgrade = $this->buscar_upgrade($id);
        if ($upgrade === null) {
            return $this->error_404('no existe el upgrade ' . $id);
        }

        $client = $this->cliente_del_upgrade($upgrade);
        if ($client === null) {
            return $this->error_422('El upgrade no tiene cliente asociado.');
        }

        $rechazo = $this->rechazar_si_el_nombre_no_confirma($request, $client, $upgrade);
        if ($rechazo !== null) {
            return $rechazo;
        }

        if ($upgrade->deployment_status !== 'paused_post_tasks' && $upgrade->deployment_status !== 'failed') {
            return $this->error_422(
                'El deployment no está listo para la etapa final de configuración. No se encoló nada.',
                [
                    'deployment_status'          => $upgrade->deployment_status,
                    'deployment_status_esperado' => 'paused_post_tasks | failed',
                    'ayuda'                      => 'Consultá GET claude/upgrades/' . (int) $upgrade->id
                        . ' y mirá `siguiente_accion`.',
                ]
            );
        }

        /* Sello del tramo: sin esto, un upgrade que estuvo días en `paused` entraría a `running`
         * con el ancla vieja y el vencimiento lo mataría en el primer tick. */
        $upgrade->update([
            'deployment_status'        => 'running',
            'deployment_running_since' => now(),
        ]);

        /* 🔴 onConnection('database') explícito. */
        RunDeploymentJob::dispatch($upgrade, self::ETAPA_CONFIGURACION)->onConnection(self::CONEXION_DE_COLA);

        $respuesta = $this->respuesta_de_encolado($upgrade, self::ETAPA_CONFIGURACION);
        $respuesta['horario_cliente'] = $this->horario_del_cliente($client);

        return response()->json($respuesta, 202);
    }

    /* ==============================================================================================
     | Frenos
     |============================================================================================= */

    /**
     * Freno 1: `confirm_client_name` tiene que coincidir con `clients.name`, comparado con trim +
     * mb_strtolower en las dos puntas.
     *
     * 🔴 El error NO revela el nombre correcto. Si lo revelara, dejaría de ser un freno y sería un
     * formulario a completar: quien se equivocó de cliente leería el nombre real y lo copiaría sin
     * darse cuenta de que está por tocar otro negocio. Se dice qué cliente resolvió (por id), no
     * cómo se llama.
     *
     * @param Request                   $request Request entrante.
     * @param Client                    $client  Cliente involucrado.
     * @param ClientVersionUpgrade|null $upgrade Upgrade involucrado, si hay.
     *
     * @return \Illuminate\Http\JsonResponse|null Null si confirma bien.
     */
    private function rechazar_si_el_nombre_no_confirma(Request $request, Client $client, $upgrade)
    {
        $recibido = $this->normalizar_nombre($request->input('confirm_client_name'));
        $real     = $this->normalizar_nombre($client->name);

        /*
         * 🔴 Cliente sin nombre cargado: `$real` queda vacío y NINGÚN `confirm_client_name` puede
         * coincidir, así que los seis endpoints de escritura le devolverían 422 para siempre con un
         * mensaje que habla de que el nombre "no coincide" — o sea, mintiendo sobre la causa. El
         * freno se mantiene cerrado (no se afloja), pero se dice qué pasa de verdad y cómo se
         * arregla.
         */
        if ($real === '') {
            $sin_nombre = [
                'client_id'   => (int) $client->id,
                'client_uuid' => (string) $client->uuid,
                'ayuda'       => 'Abrí el cliente en el admin y cargale el campo Nombre. Sin nombre no hay con qué '
                    . 'confirmar contra qué negocio se está operando, y este freno no se saltea.',
            ];

            if ($upgrade !== null) {
                $sin_nombre['upgrade_id'] = (int) $upgrade->id;
            }

            return $this->error_422(
                'El cliente NO tiene nombre cargado en el admin: por eso no se puede confirmar con '
                    . 'confirm_client_name y esta operación no se puede hacer. No se escribió nada.',
                $sin_nombre
            );
        }

        if ($recibido !== '' && $recibido === $real) {
            return null;
        }

        $extra = [
            'client_id'   => (int) $client->id,
            'client_uuid' => (string) $client->uuid,
            'ayuda'       => 'Verificá contra qué cliente estás operando con GET claude/clients/' . (int) $client->id
                . '. La respuesta de este error no dice el nombre a propósito: es un freno, no un formulario a completar.',
        ];

        if ($upgrade !== null) {
            $extra['upgrade_id'] = (int) $upgrade->id;
        }

        return $this->error_422(
            'confirm_client_name no coincide con el nombre del cliente de esta operación. No se escribió nada.',
            $extra
        );
    }

    /**
     * Rechaza un `target_client_api_id` que no pertenezca al cliente, con el 422 del bloque.
     *
     * 🔴 Existe para que este caso NO salga por el `abort(422)` del servicio: un abort sale por el
     * handler de Laravel, sin `error` ni `ayuda`, con una forma distinta a la de todos los demás
     * rechazos de `claude/*`. No escribe nada en ninguno de los dos caminos, pero el contrato del
     * bloque tiene que ser uno solo.
     *
     * @param Request $request Request entrante.
     * @param Client  $client  Cliente destino.
     *
     * @return \Illuminate\Http\JsonResponse|null Null si no vino la API o si pertenece al cliente.
     */
    private function rechazar_si_la_api_destino_no_es_del_cliente(Request $request, Client $client)
    {
        $pedido = $request->input('target_client_api_id');

        if ($pedido === null || $pedido === '') {
            return null;
        }

        $pertenece = $client->client_apis()->where('id', (int) $pedido)->exists();

        if ($pertenece) {
            return null;
        }

        return $this->error_422(
            'La API destino no pertenece al cliente seleccionado. No se creó nada.',
            [
                'target_client_api_id' => (int) $pedido,
                'client_id'            => (int) $client->id,
                'ayuda'                => 'Mirá las APIs del cliente con GET claude/clients/' . (int) $client->id
                    . '?include=apis, y usá el id de una de ellas (o no mandes target_client_api_id y dejá el default).',
            ]
        );
    }

    /**
     * Aplica el GATE DE HORARIO a un arranque de tareas sobre el sistema EN USO del cliente, con su
     * salteo por `force` + `force_reason` y su constancia en el log diario.
     *
     * 🔴 Existe porque tiene DOS llamadores —`deploy_start_post_closure_json()` y
     * `deploy_retry_commands_json()`— y un freno con dos copias es un freno que se va a
     * desincronizar: alcanza con que alguien ajuste el mínimo del motivo, o lo que se registra en el
     * log, en una sola de las dos. Las dos operaciones hacen exactamente la misma pregunta (¿ya
     * terminó la jornada de hoy?) sobre exactamente el mismo riesgo (correr seeders o comandos sobre
     * el sistema que el negocio está usando), así que tienen que tener una sola respuesta.
     *
     * ⚠️ El texto del log incluye `$donde` para poder distinguir después cuál de los dos se salteó.
     * Para el post-cierre queda idéntico al que este controlador viene escribiendo desde el 24/8.
     *
     * @param Request              $request Request entrante (force, force_reason).
     * @param Client               $client  Cliente del upgrade, con horarios cargados.
     * @param ClientVersionUpgrade $upgrade Upgrade involucrado.
     * @param string               $donde   Nombre de la operación, para el log ('el post-cierre'…).
     *
     * @return array{rechazo: \Illuminate\Http\JsonResponse|null, salteado: array<string, mixed>|null}
     */
    private function aplicar_gate_de_horario(Request $request, Client $client, ClientVersionUpgrade $upgrade, $donde)
    {
        $tz       = $this->timezone();
        $ahora    = Carbon::now($tz);
        $resolver = app(ClientScheduleResolver::class);
        $estado   = $resolver->estado_en($client, $ahora, $tz);

        /*
         * 🔴 La decisión NO es "¿está cerrado en este instante?" sino "¿ya terminó la jornada de
         * hoy?". Un `estado_en()` de instante deja pasar el hueco del mediodía y el rato de antes
         * de abrir, y en los dos casos el negocio reabre con las tareas a medio correr.
         */
        $rechazo_del_gate = $this->rechazo_del_gate_de_horario($resolver, $client, $ahora, $tz, $estado);

        $forzado = $this->pidio_en_true($request, 'force');

        if (! $forzado) {
            if ($rechazo_del_gate !== null) {
                return [
                    'rechazo'  => $this->error_422($rechazo_del_gate['mensaje'], $rechazo_del_gate['detalle']),
                    'salteado' => null,
                ];
            }

            return ['rechazo' => null, 'salteado' => null];
        }

        $motivo = $this->texto_o_null($request->input('force_reason'));

        if ($motivo === null || mb_strlen($motivo) < self::FORCE_REASON_MINIMO) {
            return [
                'rechazo' => $this->error_422(
                    'force_reason es obligatorio cuando force es true, y tiene que tener al menos '
                        . self::FORCE_REASON_MINIMO . ' caracteres. No se encoló nada.',
                    [
                        'ayuda' => 'El motivo queda registrado en el log del sistema junto con el estado de horario que '
                            . 'se salteó. Un freno que se saltea sin dejar rastro no es un freno.',
                    ]
                ),
                'salteado' => null,
            ];
        }

        /*
         * 🔴 La constancia del salteo se escribe SIEMPRE que el gate hubiera rechazado, no solo
         * cuando el estado del instante no era `cerrado`. Un negocio "cerrado a las 14:00 pero
         * que reabre a las 16" forzado tiene que dejar rastro igual que uno abierto.
         */
        if ($rechazo_del_gate === null) {
            return ['rechazo' => null, 'salteado' => null];
        }

        Log::channel('daily')->warning('[claude/upgrades] Gate de horario SALTEADO con force en ' . $donde . '.', [
            'client_id'          => (int) $client->id,
            'upgrade_id'         => (int) $upgrade->id,
            'estado_ahora'       => $estado,
            'motivo_del_gate'    => $rechazo_del_gate['motivo_del_gate'],
            'force_reason'       => $motivo,
            'timezone'           => $tz,
            'momento_evaluado'   => $ahora->toIso8601String(),
        ]);

        return [
            'rechazo'  => null,
            'salteado' => [
                'estado_ahora'    => $estado,
                'motivo_del_gate' => $rechazo_del_gate['motivo_del_gate'],
                'motivo'          => $motivo,
                'registrado'      => true,
            ],
        ];
    }

    /**
     * Lista legible de los comandos que el reintento va a volver a correr.
     *
     * Es contexto de la respuesta 202: quien pide un reintento tiene que poder ver QUÉ se va a
     * ejecutar sobre el sistema del cliente sin ir a buscarlo a otro endpoint.
     *
     * @param \Illuminate\Support\Collection $retriables UpdateCommand ya filtrados.
     *
     * @return array<int, array<string, mixed>>
     */
    private function detalle_de_comandos($retriables)
    {
        $detalle = [];

        foreach ($retriables as $update_command) {
            $version_command = $update_command->version_command;

            $detalle[] = [
                'update_command_id' => (int) $update_command->id,
                'comando'           => $version_command === null ? null : $version_command->command,
                'status'            => $update_command->status,
            ];
        }

        return $detalle;
    }

    /**
     * Bloque `salud` de `GET claude/upgrades/{id}` para este upgrade.
     *
     * 🔴 Se pide al controlador de LECTURA en vez de recalcularlo: `deployment_stale` y
     * `stale_minutos` tienen UNA definición (`ClaudeClientOpsController::salud_del_deployment()`), y
     * el 422 de `expire-stuck` publica esos dos números al lado del umbral de vencimiento justamente
     * para explicar por qué son distintos. Con una copia acá, el endpoint podría terminar diciendo
     * que el deployment está `stale` con un criterio que el endpoint de poleo ya no usa.
     *
     * @param int $upgrade_id Id del upgrade.
     *
     * @return array<string, mixed>|null Null si la lectura no devolvió 200.
     */
    private function salud_del_upgrade($upgrade_id)
    {
        $lectura   = app(ClaudeClientOpsController::class);
        $respuesta = $lectura->upgrade_json(Request::create('/', 'GET'), $upgrade_id);

        if ($respuesta->getStatusCode() !== 200) {
            return null;
        }

        $datos = $respuesta->getData(true);

        return isset($datos['salud']) ? $datos['salud'] : null;
    }

    /**
     * Normaliza un nombre para la comparación del freno 1: recorte y minúsculas multibyte.
     *
     * @param mixed $valor Valor crudo.
     *
     * @return string
     */
    private function normalizar_nombre($valor)
    {
        if ($valor === null || is_array($valor)) {
            return '';
        }

        return mb_strtolower(trim((string) $valor));
    }

    /**
     * Rechaza una versión destino que no esté publicada, misma regla que `UpdateController`.
     *
     * @param Version|null $to Versión destino.
     *
     * @return \Illuminate\Http\JsonResponse|null
     */
    private function rechazar_si_no_esta_publicada($to)
    {
        if ($to !== null && $to->status === 'published') {
            return null;
        }

        return $this->error_422('La versión destino tiene que estar publicada.', [
            'status_actual' => $to === null ? null : $to->status,
            'ayuda'         => 'Consultá GET claude/versions para ver el catálogo de lo que se puede pedir.',
        ]);
    }

    /* ==============================================================================================
     | Armado de respuestas
     |============================================================================================= */

    /**
     * Cuerpo común de los tres endpoints que encolan un deployment.
     *
     * 🔴 Declara la conexión usada y la latencia esperable, y dice explícitamente que el endpoint no
     * espera a que el pipeline termine. Un consumidor que crea que el 202 significa "ya está" se
     * queda esperando un resultado que llega minutos después, o nunca si el scheduler no corre.
     *
     * @param ClientVersionUpgrade $upgrade Upgrade encolado.
     * @param string               $etapa   Etapa desde la que arranca el pipeline.
     *
     * @return array<string, mixed>
     */
    private function respuesta_de_encolado(ClientVersionUpgrade $upgrade, $etapa)
    {
        $id = (int) $upgrade->id;

        return [
            'encolado'                 => true,
            'conexion'                 => self::CONEXION_DE_COLA,
            'upgrade_id'               => $id,
            'upgrade_uuid'             => (string) $upgrade->uuid,
            'deployment_status'        => 'running',
            'desde_etapa'              => $etapa,
            'latencia_maxima_segundos' => self::LATENCIA_MAXIMA_SEGUNDOS,
            'consultar_estado_en'      => 'GET claude/upgrades/' . $id,
            'consultar_logs_en'        => 'GET claude/upgrades/' . $id . '/logs',
            'nota'                     => 'El pipeline corre en el worker `queue:work database` que el scheduler dispara '
                . 'cada minuto. Este endpoint NO espera a que termine. Si el scheduler no corre en el servidor, el '
                . 'upgrade queda en `running` y el job dormido en la tabla `jobs`: mirá `salud.jobs_en_cola` y '
                . '`salud.deployment_stale` en GET claude/upgrades/' . $id . '. Poleá cada 30 o 60 segundos, no cada 2 '
                . '(rate limit por IP).',
        ];
    }

    /**
     * Lo que la creación haría, calculado en memoria y SIN escribir una sola fila.
     *
     * Los conteos de seeders y comandos salen de `VersionPathService::withSeedersAndCommands()`, que
     * es la misma fuente que usa la creación real: si el día de mañana cambia la regla de qué
     * seeders se generan, la simulación cambia con ella y no queda mintiendo.
     *
     * @param ClientVersionUpgradeCreationService $service       Servicio de creación.
     * @param Client                              $client        Cliente destino.
     * @param Version                             $to            Versión destino.
     * @param array<int, int>                     $confirmed_ids Conjunto ya resuelto.
     * @param Request                             $request       Request entrante.
     *
     * @return array<string, mixed>
     */
    private function previsualizar_creacion(
        ClientVersionUpgradeCreationService $service,
        Client $client,
        Version $to,
        array $confirmed_ids,
        Request $request
    ) {
        $versiones = Version::whereIn('id', $confirmed_ids)->get();
        $camino    = VersionPathService::withSeedersAndCommands($versiones, (int) $client->id);

        $seeders  = 0;
        $comandos = 0;
        $detalle  = [];

        foreach ($camino as $version) {
            $seeders  += count($version->seeders);
            $comandos += count($version->commands);

            $detalle[] = [
                'id'        => (int) $version->id,
                'version'   => $version->version,
                'is_hotfix' => (bool) $version->is_hotfix,
                'seeders'   => count($version->seeders),
                'comandos'  => count($version->commands),
            ];
        }

        $from = $client->current_version;

        return [
            'client'                => ['id' => (int) $client->id, 'name' => $client->name],
            'from_version'          => $from === null ? null : ['id' => (int) $from->id, 'version' => $from->version],
            'to_version'            => ['id' => (int) $to->id, 'version' => $to->version],
            'versiones_confirmadas' => $detalle,
            'cantidad'              => count($confirmed_ids),
            'seeders_que_se_crearian'  => $seeders,
            'comandos_que_se_crearian' => $comandos,
            'target_client_api'     => $this->previsualizar_api_destino($service, $client, $request),
            'created_by_admin_id'   => null,
            'created_via'           => ClientVersionUpgrade::CREATED_VIA_CLAUDE,
            'scheduled_date'        => $this->texto_o_null($request->input('scheduled_date')) !== null
                ? $this->texto_o_null($request->input('scheduled_date'))
                : now()->toDateString(),
        ];
    }

    /**
     * API destino que quedaría en el upgrade, con el aviso de si es la ACTIVA en producción.
     *
     * 🔴 `resolve_default_target_client_api_id()` devuelve la única ClientApi del cliente cuando hay
     * una sola, y esa única es la activa: un upgrade creado sin `target_client_api_id` explícito
     * puede apuntar a producción. Acá se ve antes de crear nada; el freno que lo detiene está en el
     * `start`.
     *
     * @param ClientVersionUpgradeCreationService $service Servicio de creación.
     * @param Client                              $client  Cliente destino.
     * @param Request                             $request Request entrante.
     *
     * @return array<string, mixed>|null
     */
    private function previsualizar_api_destino(ClientVersionUpgradeCreationService $service, Client $client, Request $request)
    {
        $pedido = $request->input('target_client_api_id');

        if ($pedido !== null && $pedido !== '') {
            /* La pertenencia ya la chequeó `rechazar_si_la_api_destino_no_es_del_cliente()` arriba,
               con error_422(): acá no se vuelve a validar con abort(). */
            $target_id = (int) $pedido;
            $origen    = 'pedido_explicito';
        } else {
            $target_id = $service->resolve_default_target_client_api_id($client);
            $origen    = 'default_del_servicio';
        }

        if ($target_id === null) {
            return null;
        }

        $api = DB::table('client_apis')
            ->where('id', $target_id)
            ->select(['id', 'uuid', 'url', 'path', 'spa_url', 'hosting_type'])
            ->first();

        if ($api === null) {
            return null;
        }

        $payload               = (array) $api;
        $payload['id']         = (int) $payload['id'];
        $payload['origen']     = $origen;
        $payload['es_la_activa'] = $client->active_client_api_id !== null
            && $payload['id'] === (int) $client->active_client_api_id;

        if ($payload['es_la_activa']) {
            $payload['advertencia'] = '🔴 Esta es la API ACTIVA en producción del cliente. El `start` la va a rechazar '
                . 'salvo que venga allow_deploy_to_active_api=true.';
        }

        return $payload;
    }

    /**
     * Decide si el gate de horario RECHAZA el arranque de las tareas post-cierre.
     *
     * 🔴 La regla NO es "¿el negocio está cerrado en este instante?", es "¿la jornada de hoy ya
     * terminó?". `estado_en()` mira un instante y devuelve `cerrado` tanto a las 14:00 de un
     * negocio 8–13 / 16–21 (que reabre a las 16) como a las 08:00 de uno 9–18 (que abre en una
     * hora). En los dos casos el post-cierre correría seeders y comandos y el negocio abriría con
     * eso a medio camino — que es exactamente el hazard que documenta
     * `ClientScheduleResolver::proximo_cierre()`.
     *
     * Orden de evaluación:
     *
     *  1. `sin_configurar` → rechaza (no se asume que esté cerrado).
     *  2. `abierto` → rechaza.
     *  3. aparece un día sin configurar en la ventana del próximo cierre → rechaza (no se adivina).
     *  4. el próximo cierre cae HOY → rechaza: la jornada no terminó. Se distingue "todavía no
     *     abrió" de "está en el hueco entre turnos" mirando la primera apertura del día.
     *  5. si no, deja pasar. Un día cerrado entero (fila propia, cero rangos) pasa: su próximo
     *     cierre cae otro día. Un cliente cerrado toda la ventana también pasa: no hay jornada.
     *
     * @param ClientScheduleResolver $resolver Resolvedor.
     * @param Client                 $client   Cliente del upgrade.
     * @param Carbon                 $ahora    Instante evaluado, ya en $tz.
     * @param string                 $tz       Timezone del gate.
     * @param string                 $estado   Estado del negocio en ese instante.
     *
     * @return array<string, mixed>|null Null si el gate deja pasar; si no,
     *                                   ['motivo_del_gate', 'mensaje', 'detalle'].
     */
    private function rechazo_del_gate_de_horario(ClientScheduleResolver $resolver, Client $client, Carbon $ahora, $tz, $estado)
    {
        if ($estado === ClientScheduleResolver::ESTADO_SIN_CONFIGURAR) {
            return [
                'motivo_del_gate' => 'sin_configurar',
                'mensaje'         => 'El cliente NO tiene horarios cargados: no se puede saber si está cerrado, y no se '
                    . 'asume que lo esté. No se encoló nada.',
                'detalle'         => array_merge(
                    $this->detalle_del_gate($resolver, $client, $ahora, $tz, $estado),
                    [
                        'ayuda' => 'Cargá los horarios del cliente desde el modal del cliente en el admin (pestaña '
                            . 'Horarios), o repetí con force=true y force_reason si sabés a mano que está cerrado.',
                    ]
                ),
            ];
        }

        if ($estado === ClientScheduleResolver::ESTADO_ABIERTO) {
            return [
                'motivo_del_gate' => 'abierto',
                'mensaje'         => 'El negocio del cliente está ABIERTO en este momento: las tareas post-cierre corren '
                    . 'seeders y comandos sobre el sistema en uso. No se encoló nada.',
                'detalle'         => $this->detalle_del_gate($resolver, $client, $ahora, $tz, $estado),
            ];
        }

        $cierre = $resolver->proximo_cierre_detallado($client, $ahora, self::DIAS_VENTANA, $tz);

        if ($cierre['motivo'] === ClientScheduleResolver::MOTIVO_SIN_CONFIGURAR) {
            return [
                'motivo_del_gate' => 'dia_sin_configurar_en_la_ventana',
                'mensaje'         => 'Hay un día SIN CONFIGURAR en la ventana del próximo cierre: no se puede afirmar que '
                    . 'la jornada de hoy haya terminado, y no se adivina. No se encoló nada.',
                'detalle'         => array_merge(
                    $this->detalle_del_gate($resolver, $client, $ahora, $tz, $estado),
                    [
                        'ayuda' => 'Completá los horarios del cliente (pestaña Horarios del modal del cliente) para que la '
                            . 'ventana quede sin huecos, o repetí con force=true y force_reason.',
                    ]
                ),
            ];
        }

        /* Cerrado toda la ventana: no hay ninguna jornada pendiente que esperar. Pasa. */
        if ($cierre['instante'] === null) {
            return null;
        }

        $instante_local = $cierre['instante']->copy()->setTimezone($tz);

        /* El próximo cierre cae otro día: la jornada de hoy terminó. Pasa. */
        if ($instante_local->toDateString() !== $ahora->copy()->setTimezone($tz)->toDateString()) {
            return null;
        }

        $hoy             = $resolver->resolve_for_date($client, $ahora, $tz);
        $hora            = $ahora->copy()->setTimezone($tz)->format('H:i');
        $primera         = $this->primera_apertura($hoy['rangos']);
        $reapertura      = $this->proxima_apertura($hoy['rangos'], $hora);
        $todavia_no_abrio = $primera !== null && $hora < $primera;

        $mensaje = $todavia_no_abrio
            ? 'El negocio del cliente TODAVÍA NO ABRIÓ hoy: abre a las ' . $primera . ' y su jornada recién termina a las '
                . $hoy['cierre_del_dia'] . '. Las tareas post-cierre lo agarrarían con el negocio por abrir. No se encoló nada.'
            : 'El negocio del cliente está en el HUECO ENTRE TURNOS: reabre a las '
                . ($reapertura === null ? '—' : $reapertura) . ' y su jornada recién termina a las '
                . $hoy['cierre_del_dia'] . '. Estar cerrado ahora no significa que la jornada haya terminado. No se encoló nada.';

        return [
            'motivo_del_gate' => $todavia_no_abrio ? 'todavia_no_abrio_hoy' : 'hueco_entre_turnos',
            'mensaje'         => $mensaje,
            'detalle'         => array_merge(
                $this->detalle_del_gate($resolver, $client, $ahora, $tz, $estado),
                [
                    'jornada_de_hoy_termino' => false,
                    'primera_apertura_de_hoy' => $primera,
                    'reabre_a_las'           => $reapertura,
                    'ayuda'                  => 'Esperá al cierre del día (' . $hoy['cierre_del_dia'] . ') y repetí, o '
                        . 'repetí con force=true y force_reason si sabés a mano que el negocio no vuelve a abrir hoy.',
                ]
            ),
        ];
    }

    /**
     * Hora de la PRIMERA apertura de un día, mirando los rangos que rigen.
     *
     * @param array<int, array> $rangos Rangos del día, con claves 'desde' y 'hasta'.
     *
     * @return string|null 'HH:MM', o null si el día no tiene rangos.
     */
    private function primera_apertura(array $rangos)
    {
        $primera = null;

        foreach ($rangos as $rango) {
            if ($primera === null || $rango['desde'] < $primera) {
                $primera = $rango['desde'];
            }
        }

        return $primera;
    }

    /**
     * Hora de la próxima apertura POSTERIOR a un momento del día.
     *
     * @param array<int, array> $rangos Rangos del día, con claves 'desde' y 'hasta'.
     * @param string            $hora   Hora del día en formato 'HH:MM'.
     *
     * @return string|null 'HH:MM', o null si ya no vuelve a abrir hoy.
     */
    private function proxima_apertura(array $rangos, $hora)
    {
        $proxima = null;

        foreach ($rangos as $rango) {
            if ($rango['desde'] > $hora && ($proxima === null || $rango['desde'] < $proxima)) {
                $proxima = $rango['desde'];
            }
        }

        return $proxima;
    }

    /**
     * Detalle del horario que acompaña un rechazo del gate: qué rige hoy y cuándo cierra.
     *
     * @param ClientScheduleResolver $resolver Resolvedor.
     * @param Client                 $client   Cliente.
     * @param Carbon                 $ahora    Instante evaluado.
     * @param string                 $tz       Timezone usado.
     * @param string                 $estado   Estado del negocio en ese instante.
     *
     * @return array<string, mixed>
     */
    private function detalle_del_gate(ClientScheduleResolver $resolver, Client $client, Carbon $ahora, $tz, $estado)
    {
        $hoy     = $resolver->resolve_for_date($client, $ahora, $tz);
        $detalle = $resolver->proximo_cierre_detallado($client, $ahora, self::DIAS_VENTANA, $tz);

        return [
            'estado_ahora'          => $estado,
            'evaluado_a_las'        => $ahora->toIso8601String(),
            'timezone'              => $tz,
            'dia'                   => $hoy['dia'],
            'origen_del_horario'    => $hoy['origen'],
            'rangos_de_hoy'         => $hoy['rangos'],
            'cierre_del_dia'        => $hoy['cierre_del_dia'],
            'proximo_cierre'        => $detalle['instante'] === null ? null : $detalle['instante']->toIso8601String(),
            'proximo_cierre_motivo' => $detalle['motivo'],
            'como_saltearlo'        => 'force=true + force_reason (mínimo ' . self::FORCE_REASON_MINIMO . ' caracteres). '
                . 'Queda registrado en el log del sistema con el estado que se salteó.',
        ];
    }

    /**
     * Horario del cliente que viaja como CONTEXTO en las respuestas 202.
     *
     * @param Client $client Cliente del upgrade.
     *
     * @return array<string, mixed>
     */
    private function horario_del_cliente(Client $client)
    {
        $tz       = $this->timezone();
        $ahora    = Carbon::now($tz);
        $resolver = app(ClientScheduleResolver::class);
        $detalle  = $resolver->proximo_cierre_detallado($client, $ahora, self::DIAS_VENTANA, $tz);

        return [
            'estado_ahora'          => $resolver->estado_en($client, $ahora, $tz),
            'proximo_cierre'        => $detalle['instante'] === null ? null : $detalle['instante']->toIso8601String(),
            'proximo_cierre_motivo' => $detalle['motivo'],
            'timezone'              => $tz,
        ];
    }

    /**
     * Devuelve el payload de `GET claude/upgrades/{id}` con el status pedido.
     *
     * 🔴 Se delega en el controlador de LECTURA a propósito: un upgrade se lee de UNA sola forma. Si
     * acá se armara un payload propio, el día que cambie uno el otro queda viejo y nadie se entera
     * hasta que algo del otro lado lee un campo que ya no existe.
     *
     * 🔴 Se le pasa un Request LIMPIO, no el del POST de escritura. El endpoint de lectura valida
     * su propio body (`timezone`), y con el body de escritura adentro un `timezone` inválido haría
     * que este método devuelva 422 DESPUÉS de haber creado el upgrade: quien lee un 422 asume que
     * no se escribió nada y reintenta, y queda un upgrade duplicado.
     *
     * @param int $upgrade_id Id del upgrade.
     * @param int $status     Código HTTP de la respuesta.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    private function payload_de_upgrade($upgrade_id, $status)
    {
        $lectura   = app(ClaudeClientOpsController::class);
        $respuesta = $lectura->upgrade_json(Request::create('/', 'GET'), $upgrade_id);

        if ($respuesta->getStatusCode() !== 200) {
            return $respuesta;
        }

        return response()->json($respuesta->getData(true), $status);
    }

    /* ==============================================================================================
     | Helpers
     |============================================================================================= */

    /**
     * Busca el upgrade por id numérico o uuid, sin lanzar: acá el 404 se arma a mano con un cuerpo
     * JSON legible del otro lado.
     *
     * @param int|string $route_id Segmento de la ruta.
     *
     * @return ClientVersionUpgrade|null
     */
    private function buscar_upgrade($route_id)
    {
        if (is_numeric($route_id)) {
            return ClientVersionUpgrade::where('id', (int) $route_id)->first();
        }

        return ClientVersionUpgrade::where('uuid', (string) $route_id)->first();
    }

    /**
     * Cliente del upgrade, con los horarios ya cargados para el resolvedor.
     *
     * @param ClientVersionUpgrade $upgrade Upgrade involucrado.
     *
     * @return Client|null
     */
    private function cliente_del_upgrade(ClientVersionUpgrade $upgrade)
    {
        if ($upgrade->client_id === null) {
            return null;
        }

        return Client::query()
            ->where('id', (int) $upgrade->client_id)
            ->with('schedule_days.schedule_ranges')
            ->first();
    }

    /**
     * Timezone efectivo de las decisiones de horario.
     *
     * Toda respuesta que involucre horarios lo devuelve explícito: una hora sin zona declarada es
     * discutible, y acá una hora mal interpretada arranca un deployment sobre un negocio abierto.
     *
     * @return string
     */
    private function timezone()
    {
        $timezone = trim((string) config('app.timezone'));

        return $timezone === '' ? 'UTC' : $timezone;
    }

    /**
     * Lee un flag booleano que solo cuenta si vino explícito en true.
     *
     * @param Request $request Request entrante.
     * @param string  $clave   Nombre del parámetro.
     *
     * @return bool
     */
    private function pidio_en_true(Request $request, $clave)
    {
        if (! $request->filled($clave)) {
            return false;
        }

        return $request->boolean($clave);
    }

    /**
     * Corre `$request->validate()` garantizando una respuesta JSON 422 aunque la request no traiga
     * `Accept: application/json`. Sin esto, un POST pelado de un script recibiría un redirect 302 en
     * vez del error, que es imposible de diagnosticar del otro lado.
     *
     * 🔴 SOBRESCRIBE la del trait `RespuestasParaClaude` en vez de usarla, y no es un descuido:
     * las dos copias YA divergieron. Esta dice "para ver los frenos y los valores válidos" y la de
     * `ClaudeClientOpsController` dice "para ver los filtros y valores válidos" — que para un
     * controlador de escritura es la diferencia entre nombrar lo que te protege y nombrar lo que
     * no tiene. Unificarlas cambiaría el texto de una respuesta que ya está en producción, así que
     * la divergencia queda declarada acá y se resuelve cuando se decida cambiar el contrato.
     *
     * @param Request               $request Request entrante.
     * @param array<string, string> $reglas  Reglas de validación.
     *
     * @return \Illuminate\Http\JsonResponse|null Null si validó bien.
     */
    private function validar_o_422(Request $request, array $reglas)
    {
        try {
            $request->validate($reglas, $this->mensajes_de_validacion());
        } catch (ValidationException $e) {
            return response()->json([
                'error'   => 'parámetros inválidos',
                'errores' => $e->errors(),
                'ayuda'   => 'Consultá GET claude/ops-schema para ver los frenos y los valores válidos.',
            ], 422);
        }

        return null;
    }

    /**
     * Mensajes de validación en español. El proyecto solo tiene traducciones en inglés
     * (resources/lang/en), así que se pasan inline.
     *
     * 🔴 SOBRESCRIBE la del trait `RespuestasParaClaude`. ⚠️ El motivo original ya no vale: el trait
     * no tenía `exists` ni `array` y por eso los endpoints de lote contestaban en inglés, y desde el
     * 28/8/2026 los tiene, con el MISMO texto que estas dos líneas. Lo único que queda distinto es
     * que esta lista no trae `date` ni `in`, que este controlador no usa. Se borra el día que
     * alguien verifique endpoint por endpoint que devolver la del trait no cambia ningún mensaje;
     * mientras tanto queda, porque unificarla es un cambio de contrato y no un refactor.
     *
     * @return array<string, string>
     */
    private function mensajes_de_validacion()
    {
        return [
            'required'    => 'El parámetro :attribute es obligatorio.',
            'exists'      => 'El :attribute que mandaste no existe.',
            'array'       => 'El parámetro :attribute tiene que ser una lista.',
            'date_format' => 'El parámetro :attribute tiene que venir en formato :format.',
            'integer'     => 'El parámetro :attribute tiene que ser un número entero.',
            'boolean'     => 'El parámetro :attribute tiene que ser booleano (1, 0, true o false).',
            'string'      => 'El parámetro :attribute tiene que ser texto.',
            'min'         => 'El parámetro :attribute está por debajo del mínimo permitido.',
            'max'         => 'El parámetro :attribute supera el máximo permitido.',
        ];
    }
}

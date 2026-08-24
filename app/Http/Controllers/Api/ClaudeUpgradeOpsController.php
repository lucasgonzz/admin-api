<?php

namespace App\Http\Controllers\Api;

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
 *  4. Gate de horario — el post-cierre solo corre con el negocio CERRADO. `abierto` rechaza y
 *     `sin_configurar` TAMBIÉN rechaza: no se asume que un cliente sin horarios cargados esté
 *     cerrado.
 *
 * 🔴 Todo freno que rechaza devuelve 422 y no escribe absolutamente nada: ni estado, ni log, ni
 * job encolado.
 *
 * 🔴 LOS TRES `dispatch()` DE ESTE CONTROLADOR (endpoints 11, 13 y 14) VAN CON
 * `->onConnection('database')` EXPLÍCITO, SIN EXCEPCIÓN.
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
 */
class ClaudeUpgradeOpsController extends Controller
{
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

    /** Etapa desde la que arranca cada uno de los tres reanudes del pipeline. */
    const ETAPA_PRE_CIERRE       = 'compile_spa';
    const ETAPA_POST_CIERRE      = 'run_seeders';
    const ETAPA_CONFIGURACION    = 'update_default_version';

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
                'id'              => (int) $candidata->id,
                'version'         => $candidata->version,
                'title'           => $candidata->title,
                'description'     => $candidata->description,
                'is_hotfix'       => $es_hotfix,
                'default_checked' => (! $es_hotfix) || $es_destino,
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
        return $this->payload_de_upgrade($request, (int) $upgrade->id, 201);
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

        $upgrade->update([
            'deployment_status'     => 'running',
            'deployment_started_at' => now(),
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

        return $this->payload_de_upgrade($request, (int) $upgrade->id, 200);
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
     * El gate tiene tres salidas, no dos:
     *   - `cerrado`        → pasa.
     *   - `abierto`        → 422 con el detalle (rangos del día y próximo cierre).
     *   - `sin_configurar` → 422. 🔴 NO se asume que esté cerrado. "No sé" no es "está cerrado":
     *     ese es exactamente el caso donde adivinar deja a un negocio sin sistema en pleno horario.
     *
     * Se saltea con `force=true` + `force_reason` (mínimo 10 caracteres), y eso deja constancia en
     * el log diario: un freno que se puede saltear sin dejar rastro no es un freno.
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

        /* Gate de horario. */
        $tz       = $this->timezone();
        $ahora    = Carbon::now($tz);
        $resolver = app(ClientScheduleResolver::class);
        $estado   = $resolver->estado_en($client, $ahora, $tz);

        $forzado = $this->pidio_en_true($request, 'force');

        if ($forzado) {
            $motivo = $this->texto_o_null($request->input('force_reason'));

            if ($motivo === null || mb_strlen($motivo) < self::FORCE_REASON_MINIMO) {
                return $this->error_422(
                    'force_reason es obligatorio cuando force es true, y tiene que tener al menos '
                        . self::FORCE_REASON_MINIMO . ' caracteres. No se encoló nada.',
                    [
                        'ayuda' => 'El motivo queda registrado en el log del sistema junto con el estado de horario que '
                            . 'se salteó. Un freno que se saltea sin dejar rastro no es un freno.',
                    ]
                );
            }

            if ($estado !== ClientScheduleResolver::ESTADO_CERRADO) {
                /* 🔴 La constancia del salteo. Sin esto, forzar sería gratis y silencioso. */
                Log::channel('daily')->warning('[claude/upgrades] Gate de horario SALTEADO con force en el post-cierre.', [
                    'client_id'          => (int) $client->id,
                    'upgrade_id'         => (int) $upgrade->id,
                    'estado_ahora'       => $estado,
                    'force_reason'       => $motivo,
                    'timezone'           => $tz,
                    'momento_evaluado'   => $ahora->toIso8601String(),
                ]);
            }
        } elseif ($estado === ClientScheduleResolver::ESTADO_ABIERTO) {
            return $this->error_422(
                'El negocio del cliente está ABIERTO en este momento: las tareas post-cierre corren seeders y comandos '
                    . 'sobre el sistema en uso. No se encoló nada.',
                $this->detalle_del_gate($resolver, $client, $ahora, $tz, $estado)
            );
        } elseif ($estado === ClientScheduleResolver::ESTADO_SIN_CONFIGURAR) {
            return $this->error_422(
                'El cliente NO tiene horarios cargados: no se puede saber si está cerrado, y no se asume que lo esté. '
                    . 'No se encoló nada.',
                array_merge(
                    $this->detalle_del_gate($resolver, $client, $ahora, $tz, $estado),
                    [
                        'ayuda' => 'Cargá los horarios del cliente desde el modal del cliente en el admin (pestaña '
                            . 'Horarios), o repetí con force=true y force_reason si sabés a mano que está cerrado.',
                    ]
                )
            );
        }

        $upgrade->update(['deployment_status' => 'running']);

        /* 🔴 onConnection('database') explícito. */
        RunDeploymentJob::dispatch($upgrade, self::ETAPA_POST_CIERRE)->onConnection(self::CONEXION_DE_COLA);

        $respuesta = $this->respuesta_de_encolado($upgrade, self::ETAPA_POST_CIERRE);
        $respuesta['horario_cliente'] = $this->horario_del_cliente($client);

        if ($forzado && $estado !== ClientScheduleResolver::ESTADO_CERRADO) {
            $respuesta['gate_de_horario_salteado'] = [
                'estado_ahora' => $estado,
                'motivo'       => $this->texto_o_null($request->input('force_reason')),
                'registrado'   => true,
            ];
        }

        return response()->json($respuesta, 202);
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

        $upgrade->update(['deployment_status' => 'running']);

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
            $service->assert_target_client_api_belongs_to_client($client, (int) $pedido);
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
     * @param Request $request    Request entrante.
     * @param int     $upgrade_id Id del upgrade.
     * @param int     $status     Código HTTP de la respuesta.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    private function payload_de_upgrade(Request $request, $upgrade_id, $status)
    {
        $lectura   = app(ClaudeClientOpsController::class);
        $respuesta = $lectura->upgrade_json($request, $upgrade_id);

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
     * Texto recortado, o null si quedó vacío.
     *
     * @param mixed $valor Valor crudo.
     *
     * @return string|null
     */
    private function texto_o_null($valor)
    {
        if ($valor === null || is_array($valor)) {
            return null;
        }

        $valor = trim((string) $valor);

        return $valor === '' ? null : $valor;
    }

    /**
     * Corre `$request->validate()` garantizando una respuesta JSON 422 aunque la request no traiga
     * `Accept: application/json`. Sin esto, un POST pelado de un script recibiría un redirect 302 en
     * vez del error, que es imposible de diagnosticar del otro lado.
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

    /**
     * Respuesta 422 con mensaje legible en español.
     *
     * @param string               $mensaje Mensaje del error.
     * @param array<string, mixed> $extra   Datos adicionales.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    private function error_422($mensaje, array $extra = [])
    {
        return response()->json(array_merge(['error' => $mensaje], $extra), 422);
    }

    /**
     * Respuesta 404 con mensaje legible en español.
     *
     * @param string $mensaje Mensaje del error.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    private function error_404($mensaje)
    {
        return response()->json(['error' => $mensaje], 404);
    }
}

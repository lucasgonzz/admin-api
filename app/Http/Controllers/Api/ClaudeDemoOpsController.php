<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespuestasParaClaude;
use App\Http\Controllers\Controller;
use App\Jobs\RunDemoUpdateJob;
use App\Models\Demo;
use App\Models\DemoUpdate;
use App\Models\Version;
use App\Services\DemoCommandRunner;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Actualizar la versión de una DEMO desde la sesión de Claude.
 *
 * 🔴 POR QUÉ EXISTE. Es el mismo agujero que tenía `demo-media` el 27/8/2026 y por el mismo
 * motivo: un paso del pipeline de `/filmar` que la sesión no podía hacer sola. Medido el 29/8/2026
 * relevando las 42 rutas del bloque: **ninguna actualizaba una demo**. Cuando un clip queda trabado
 * por un arreglo que ya está en `develop` —el `2.6` lo estuvo— la sesión no tiene forma de bajarlo
 * a la instancia, y el clip espera a que alguien se acuerde de apretar el botón en el panel. Eso ya
 * pasó con los clips publicados en R2 que nadie apuntó.
 *
 * 🔴 UNA DEMO NO ES UN CLIENTE, Y ESTA ES LA CONFUSIÓN QUE HAY QUE NO REPETIR. Las demos tienen su
 * propio modelo (`Demo`) y su propio pipeline (`DemoUpdate` → `RunDemoUpdateJob` →
 * `DemoUpdateService`), en paralelo al de los clientes (`ClientVersionUpgrade` → `RunDeploymentJob`
 * → `DeploymentService`). La tabla `clients` no tiene ninguna columna de demo. Por eso este
 * controlador NO reusa nada de `ClaudeUpgradeOpsController` salvo la forma de las respuestas: son
 * dos máquinas distintas y mezclarlas sería inventar un parecido que no existe.
 *
 * ⚠️ Y hay una asimetría con el camino de clientes que conviene tener presente: el de clientes son
 * CINCO pasos con una intervención humana obligatoria en el medio (mover los crons a mano). El de
 * demos es **uno solo y corre entero**: `compile_spa`, `upload_spa`, `upload_api`, `run_migrations`,
 * `restart_queue_workers`, `verify_demo`. No hay pausa ni gate de horario, y está bien que no la
 * haya: una demo no es el sistema de un negocio en funcionamiento.
 *
 * Los frenos, que son del lado de Claude y no del panel:
 *
 *  1. `confirm_demo_name` — obligatorio para escribir. Tiene que coincidir con la URL de la SPA de
 *     la demo (`erp_spa_url`), comparada con trim + minúsculas. 🔴 El error NO revela cuál es la
 *     correcta: si la revelara dejaría de ser un freno y sería un formulario a completar. El daño
 *     posible acá es actualizar la demo equivocada mientras otra sesión está filmando sobre ella.
 *  2. `dry_run` — por defecto **true**. Sin `dry_run=false` explícito no se escribe ni se encola
 *     nada; se devuelve lo que se haría.
 *  3. Rechazo si esa demo ya tiene una actualización viva (`pendiente` o `ejecutandose`). Dos
 *     pipelines sobre la misma demo se pisan los archivos.
 *
 * 🔴 EL `dispatch()` VA CON `->onConnection('database')` EXPLÍCITO. `QUEUE_CONNECTION` cae a `sync`
 * si no está seteada, y en `sync` un dispatch pelado corre el pipeline SSH ENTERO —`npm ci`,
 * `npm run build`, los uploads, las migraciones— adentro del request HTTP, donde lo mata
 * `max_execution_time` con un fatal que no captura ni el `catch` del job. Es la misma clase de
 * error que ya dejó tres demos mudas con `RunDemoSetupJob`. Por eso este endpoint devuelve **202**
 * de inmediato y nunca espera a que el pipeline termine: el estado se sigue con
 * `GET claude/demo-updates/{id}`.
 *
 * ⚠️ Precondición de infraestructura, y se declara en la propia respuesta: el pipeline lo corre el
 * worker `queue:work database --stop-when-empty` que el scheduler dispara cada minuto. Si ese cron
 * no corre, este endpoint no hace NADA visible — el `DemoUpdate` queda en `pendiente` y el job
 * dormido en la tabla `jobs`. `GET claude/demo-updates/{id}` mide exactamente eso en `salud`.
 */
class ClaudeDemoOpsController extends Controller
{
    use RespuestasParaClaude;

    /**
     * Estados con los que una actualización se considera todavía viva: con cualquiera de los dos
     * no se arranca otra sobre la misma demo.
     */
    const ESTADOS_ACTIVOS = ['pendiente', 'ejecutandose'];

    /** Conexión de cola del dispatch. 🔴 Explícita, siempre. Ver el docblock de la clase. */
    const CONEXION_DE_COLA = 'database';

    /**
     * Latencia máxima esperable entre el encolado y el arranque real del pipeline, en segundos.
     * El scheduler corre cada minuto: ese es el peor caso de espera antes de ver movimiento.
     */
    const LATENCIA_MAXIMA_SEGUNDOS = 60;

    /** Cuántos caracteres del final del log devuelve el detalle. El log entero puede ser enorme. */
    const COLA_DEL_LOG = 4000;

    /**
     * Las seis etapas de `DemoUpdateService::run()`, en orden, para que el que sigue una corrida
     * sepa contra qué comparar lo que ve en el log.
     *
     * ⚠️ Es una copia declarativa con fines de documentación: la verdad de qué corre está en
     * `DemoUpdateService::run()`. Si allá se agrega una etapa, acá queda desactualizado y lo único
     * que se rompe es el texto informativo, no el pipeline.
     */
    const ETAPAS = [
        'compile_spa',
        'upload_spa',
        'upload_api',
        'run_migrations',
        'restart_queue_workers',
        'verify_demo',
    ];

    /**
     * Las demos que existen, con su versión actual y si tienen una actualización en curso.
     *
     * Es el punto de entrada: sin esto, el que quiere actualizar una demo no tiene de dónde sacar
     * el `demo_id` ni con qué confirmar el nombre.
     *
     * @param Request $request Request entrante.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function demos_json(Request $request)
    {
        $demos = Demo::orderBy('id')->get();

        $filas = [];

        foreach ($demos as $demo) {

            $ultima = DemoUpdate::where('demo_id', $demo->id)->orderBy('id', 'desc')->first();

            $filas[] = [
                'id'                => (int) $demo->id,
                'uuid'              => (string) $demo->uuid,
                'erp_spa_url'       => $demo->erp_spa_url,
                'erp_api_url'       => $demo->erp_api_url,
                'erp_hosting_type'  => $demo->erp_hosting_type,
                'ecommerce_spa_url' => $demo->ecommerce_spa_url,
                'ultima_actualizacion' => $ultima === null ? null : $this->fila_de_update($ultima),
                'tiene_una_viva'    => $ultima !== null && in_array($ultima->status, self::ESTADOS_ACTIVOS, true),
            ];
        }

        return response()->json([
            'demos' => $filas,
            'ayuda' => 'Para actualizar una: POST claude/demo-updates con demo_id, version_id, '
                . 'confirm_demo_name (la erp_spa_url de esta lista) y dry_run=false. '
                . 'Las versiones publicadas salen de GET claude/versions.',
        ], 200);
    }

    /**
     * Las últimas actualizaciones de demo, opcionalmente filtradas por demo.
     *
     * @param Request $request Request entrante.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function demo_updates_json(Request $request)
    {
        $error = $this->validar_o_422($request, [
            'demo_id' => 'nullable|integer|exists:demos,id',
            'limit'   => 'nullable|integer|min:1|max:100',
        ]);

        if ($error !== null) {
            return $error;
        }

        $query = DemoUpdate::with(['demo', 'version'])->orderBy('id', 'desc');

        $demo_id = $this->entero_o_null($request->input('demo_id'));

        if ($demo_id !== null) {
            $query->where('demo_id', $demo_id);
        }

        $limite = $this->resolver_limite($request->input('limit'), 20, 100);

        $filas = [];

        foreach ($query->limit($limite)->get() as $update) {
            $filas[] = $this->fila_de_update($update);
        }

        return response()->json(['demo_updates' => $filas], 200);
    }

    /**
     * El detalle de una actualización: estado, cola del log y las señales de salud que dicen si el
     * pipeline está avanzando o si el job quedó dormido porque no hay worker.
     *
     * @param Request    $request Request entrante.
     * @param int|string $id      Id del DemoUpdate.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function demo_update_json(Request $request, $id)
    {
        $update = DemoUpdate::with(['demo', 'version'])->find($id);

        if ($update === null) {
            return $this->error_404('No existe la actualización de demo ' . $id . '.');
        }

        $fila = $this->fila_de_update($update);

        $log = $update->log === null ? '' : (string) $update->log;

        $fila['log_tail']      = mb_substr($log, -self::COLA_DEL_LOG);
        $fila['log_truncado']  = mb_strlen($log) > self::COLA_DEL_LOG;
        $fila['etapas']        = self::ETAPAS;
        $fila['salud']         = $this->salud_de($update);

        return response()->json(['demo_update' => $fila], 200);
    }

    /**
     * Crea la actualización y encola el pipeline.
     *
     * @param Request $request Request entrante.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function store_json(Request $request)
    {
        $error = $this->validar_o_422($request, [
            'demo_id'           => 'required|integer|exists:demos,id',
            'version_id'        => 'required|integer|exists:versions,id',
            'confirm_demo_name' => 'nullable|string',
            'dry_run'           => 'nullable|boolean',
        ]);

        if ($error !== null) {
            return $error;
        }

        $demo    = Demo::find($request->input('demo_id'));
        $version = Version::find($request->input('version_id'));

        /*
         * `dry_run` es true salvo que venga explícitamente en false. Se lee con
         * `booleano_o_null()` y no con `$request->boolean()` porque hay que distinguir "no vino"
         * (que es dry run) de "vino en false" (que escribe).
         */
        $dry_run = $this->booleano_o_null($request, 'dry_run');
        $dry_run = $dry_run === null ? true : $dry_run;

        // Una sola actualización viva por demo: dos pipelines sobre la misma se pisan los archivos.
        $viva = DemoUpdate::where('demo_id', $demo->id)
            ->whereIn('status', self::ESTADOS_ACTIVOS)
            ->orderBy('id', 'desc')
            ->first();

        if ($viva !== null) {
            return $this->error_422(
                'Esa demo ya tiene una actualización en curso (estado "' . $viva->status . '"). No se encoló nada.',
                [
                    'demo_update_id' => (int) $viva->id,
                    'ayuda'          => 'Seguila con GET claude/demo-updates/' . (int) $viva->id
                        . '. Si quedó colgada, hay que resolverla desde el panel del admin: este bloque no la destraba.',
                ]
            );
        }

        if ($dry_run) {
            return response()->json([
                'dry_run'  => true,
                'se_haria' => [
                    'demo_id'       => (int) $demo->id,
                    'erp_spa_url'   => $demo->erp_spa_url,
                    'version_id'    => (int) $version->id,
                    'version'       => $this->nombre_de_version($version),
                    'etapas'        => self::ETAPAS,
                ],
                'ayuda' => 'Para hacerlo de verdad, repetí con dry_run=false y confirm_demo_name '
                    . 'igual a la erp_spa_url de la demo.',
            ], 200);
        }

        $rechazo = $this->rechazar_si_el_nombre_de_la_demo_no_confirma($request, $demo);

        if ($rechazo !== null) {
            return $rechazo;
        }

        /*
         * El registro y el encolado van juntos: si el dispatch falla, no queda una fila en
         * `pendiente` que nadie va a levantar nunca y que además bloquearía el próximo intento por
         * la guarda de "una viva por demo".
         */
        $update = DB::transaction(function () use ($demo, $version) {

            $creado = DemoUpdate::create([
                'demo_id'             => $demo->id,
                'version_id'          => $version->id,
                'created_by_admin_id' => null,
                'status'              => 'pendiente',
            ]);

            RunDemoUpdateJob::dispatch($creado)->onConnection(self::CONEXION_DE_COLA);

            return $creado;
        });

        return response()->json([
            'demo_update'   => $this->fila_de_update($update->fresh(['demo', 'version'])),
            'encolado_en'   => self::CONEXION_DE_COLA,
            'etapas'        => self::ETAPAS,
            'precondicion'  => 'El pipeline lo corre el worker `queue:work database --stop-when-empty` que el '
                . 'scheduler dispara cada minuto. Si ese cron no está corriendo, esto queda en "pendiente" y no '
                . 'pasa nada más: GET claude/demo-updates/' . (int) $update->id . ' lo mide en salud.jobs_en_cola.',
            'ayuda'         => 'Seguila con GET claude/demo-updates/' . (int) $update->id
                . '. Puede tardar hasta ' . self::LATENCIA_MAXIMA_SEGUNDOS . ' segundos en arrancar.',
        ], 202);
    }

    /**
     * Corre un comando de Artisan de la lista blanca sobre el servidor de una demo.
     *
     * 🔴 POR QUÉ HACE FALTA. El pipeline de actualización hace seis etapas fijas y **no corre
     * comandos sueltos**; `DeploymentService`, el equivalente de los clientes, sí tiene
     * `run_seeders` y `run_commands`. Esa asimetría trabó trabajo dos veces: el clip `4.4`, que
     * necesita `demo:sembrar-trazabilidad` —un comando que YA está en el servidor de las tres demos
     * desde la release 4.0.7 y que no había forma de ejecutar—, y los clips `1.7`, `1.8` y `2.10`,
     * que el 28/8 se trabaron esperando un `queue:restart`.
     *
     * La única alternativa era el demo-setup, que arranca con `migrate:fresh` y le vacía la base a
     * la instancia: inaceptable sobre una demo en uso.
     *
     * ⚠️ Es SÍNCRONO, a diferencia de `store_json()`: estos comandos tardan segundos, no minutos.
     * Por eso devuelve 200 con la salida, y no 202.
     *
     * Frenos, los mismos de la escritura de este bloque más el de la lista blanca:
     *  1. `dry_run` en `true` por defecto.
     *  2. `confirm_demo_name` contra la `erp_spa_url`.
     *  3. 🔴 Lista blanca de comandos y patrón cerrado de argumentos, en `DemoCommandRunner`. Un
     *     endpoint que acepte comando libre es una shell remota con otro nombre.
     *
     * @param Request $request Request entrante.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function run_command_json(Request $request)
    {
        $error = $this->validar_o_422($request, [
            'demo_id'           => 'required|integer|exists:demos,id',
            'comando'           => 'required|string',
            'argumentos'        => 'nullable|string',
            'confirm_demo_name' => 'nullable|string',
            'dry_run'           => 'nullable|boolean',
        ]);

        if ($error !== null) {
            return $error;
        }

        $demo       = Demo::find($request->input('demo_id'));
        $comando    = $this->texto_o_null($request->input('comando'));
        $argumentos = $this->texto_o_null($request->input('argumentos'));
        $argumentos = $argumentos === null ? '' : $argumentos;

        // La lista blanca se chequea ANTES del dry run, para que el simulacro también avise que el
        // comando no está permitido en vez de decir que se haría algo que nunca se haría.
        if (! array_key_exists($comando, DemoCommandRunner::COMANDOS_PERMITIDOS)) {
            return $this->error_422('El comando "' . $comando . '" no está permitido. No se corrió nada.', [
                'comandos_permitidos' => array_keys(DemoCommandRunner::COMANDOS_PERMITIDOS),
                'ayuda'               => 'La lista blanca vive en DemoCommandRunner::COMANDOS_PERMITIDOS. '
                    . 'Si hace falta uno nuevo, se agrega ahí con el patrón de sus argumentos, no se afloja el freno.',
            ]);
        }

        $dry_run = $this->booleano_o_null($request, 'dry_run');
        $dry_run = $dry_run === null ? true : $dry_run;

        if ($dry_run) {
            return response()->json([
                'dry_run'  => true,
                'se_haria' => [
                    'demo_id'          => (int) $demo->id,
                    'erp_spa_url'      => $demo->erp_spa_url,
                    'comando_completo' => 'php artisan ' . $comando . ($argumentos !== '' ? ' ' . $argumentos : ''),
                ],
                'ayuda' => 'Para correrlo de verdad, repetí con dry_run=false y confirm_demo_name '
                    . 'igual a la erp_spa_url de la demo.',
            ], 200);
        }

        $rechazo = $this->rechazar_si_el_nombre_de_la_demo_no_confirma($request, $demo);

        if ($rechazo !== null) {
            return $rechazo;
        }

        try {
            $runner    = new DemoCommandRunner();
            $resultado = $runner->run($demo, $comando, $argumentos);
        } catch (\Throwable $e) {
            /*
             * Se devuelve 422 y no 500: el fallo esperable acá es de configuración o de la propia
             * demo (credencial faltante, SSH rechazado, comando que salió con error), no un bug de
             * este endpoint. El mensaje del runner ya dice cuál de los tres fue.
             */
            return $this->error_422('No se pudo correr el comando: ' . $e->getMessage(), [
                'demo_id' => (int) $demo->id,
            ]);
        }

        return response()->json([
            'demo_id'          => (int) $demo->id,
            'erp_spa_url'      => $demo->erp_spa_url,
            'comando_completo' => $resultado['comando_completo'],
            'salida'           => $resultado['salida'],
            'ayuda'            => 'La salida es la del artisan tal cual, incluida la de error: este endpoint no '
                . 'interpreta si el comando "salió bien". Leela.',
        ], 200);
    }

    /**
     * Freno del nombre para demos.
     *
     * 🔴 POR QUÉ NO USA `rechazar_si_el_nombre_del_cliente_no_confirma()` DEL TRAIT. Ese método está
     * tipado contra `Client` y compara `clients.name`. Una demo no es un `Client` y no tiene `name`:
     * lo que la identifica sin ambigüedad es su URL. Aflojar el tipo del método del trait para que
     * acepte las dos cosas volvería genérico un freno cuya fuerza está justamente en ser específico.
     *
     * 🔴 El error NO dice cuál es la URL correcta, por la misma razón que el de clientes: quien se
     * equivocó de demo la copiaría sin darse cuenta de que está por actualizar la instancia sobre la
     * que otra sesión está filmando.
     *
     * @param Request $request Request entrante.
     * @param Demo    $demo    Demo involucrada.
     *
     * @return \Illuminate\Http\JsonResponse|null Null si confirma bien.
     */
    private function rechazar_si_el_nombre_de_la_demo_no_confirma(Request $request, Demo $demo)
    {
        $recibido = $this->normalizar_nombre($request->input('confirm_demo_name'));
        $real     = $this->normalizar_nombre($demo->erp_spa_url);

        /*
         * Demo sin URL cargada: `$real` queda vacío y ningún `confirm_demo_name` puede coincidir,
         * así que el endpoint devolvería 422 para siempre con un mensaje que habla de que "no
         * coincide" — o sea, mintiendo sobre la causa. El freno se mantiene cerrado, pero se dice
         * qué pasa de verdad.
         */
        if ($real === '') {
            return $this->error_422(
                'La demo NO tiene cargada la URL de su SPA (erp_spa_url) en el admin: por eso no se puede '
                    . 'confirmar con confirm_demo_name y esta operación no se puede hacer. No se encoló nada.',
                [
                    'demo_id' => (int) $demo->id,
                    'ayuda'   => 'Abrí la demo en el admin y cargale el campo de la URL de la SPA. Sin eso no hay '
                        . 'con qué confirmar sobre qué instancia se está operando, y este freno no se saltea.',
                ]
            );
        }

        if ($recibido !== '' && $recibido === $real) {
            return null;
        }

        return $this->error_422(
            'confirm_demo_name no coincide con la URL de la SPA de esta demo. No se encoló nada.',
            [
                'demo_id' => (int) $demo->id,
                'ayuda'   => 'Verificá sobre qué demo estás operando con GET claude/demos. La respuesta de este '
                    . 'error no dice la URL a propósito: es un freno, no un formulario a completar.',
            ]
        );
    }

    /**
     * La forma con la que este bloque devuelve una actualización, en un solo lugar para que el
     * listado y el detalle no se separen con el tiempo.
     *
     * @param DemoUpdate $update Actualización.
     *
     * @return array<string, mixed>
     */
    private function fila_de_update(DemoUpdate $update)
    {
        return [
            'id'          => (int) $update->id,
            'uuid'        => (string) $update->uuid,
            'demo_id'     => (int) $update->demo_id,
            'demo_url'    => $update->demo === null ? null : $update->demo->erp_spa_url,
            'version_id'  => $update->version_id === null ? null : (int) $update->version_id,
            'version'     => $update->version === null ? null : $this->nombre_de_version($update->version),
            'status'      => $update->status,
            'started_at'  => $update->started_at === null ? null : $update->started_at->toDateTimeString(),
            'finished_at' => $update->finished_at === null ? null : $update->finished_at->toDateTimeString(),
        ];
    }

    /**
     * Las señales que dicen si una corrida está avanzando de verdad o si el job quedó dormido.
     *
     * 🔴 Es lo que distingue "todavía no arrancó" de "no hay worker y no va a arrancar nunca", que
     * desde afuera se ven exactamente igual: las dos son un `pendiente` que no se mueve.
     *
     * @param DemoUpdate $update Actualización.
     *
     * @return array<string, mixed>
     */
    private function salud_de(DemoUpdate $update)
    {
        $jobs_en_cola = null;

        /*
         * La tabla `jobs` puede no existir cuando la conexión de cola no es `database` (por
         * ejemplo en un entorno de test con `sync`). Que falte no es un error de este endpoint: se
         * devuelve null y se dice por qué, en vez de tirar 500 al pedir el detalle.
         */
        try {
            $jobs_en_cola = (int) DB::table('jobs')->count();
        } catch (\Throwable $e) {
            $jobs_en_cola = null;
        }

        $activa = in_array($update->status, self::ESTADOS_ACTIVOS, true);

        /*
         * "Sin movimiento": sigue activa y ya pasó más de la latencia esperable desde que se creó
         * (si nunca arrancó) o desde que arrancó. No es prueba de que esté rota — un
         * `compile_spa` largo se ve igual —, pero es la primera señal a mirar.
         */
        $sin_movimiento = false;

        if ($activa) {
            $referencia = $update->started_at !== null ? $update->started_at : $update->created_at;

            if ($referencia !== null) {
                $sin_movimiento = Carbon::parse($referencia)->diffInSeconds(Carbon::now())
                    > self::LATENCIA_MAXIMA_SEGUNDOS;
            }
        }

        return [
            'activa'                 => $activa,
            'jobs_en_cola'           => $jobs_en_cola,
            'sin_movimiento'         => $sin_movimiento,
            'que_significa'          => 'Si está en "pendiente", sin movimiento y jobs_en_cola es mayor que cero, '
                . 'el job está encolado y NO hay worker levantándolo: revisá que el cron del scheduler corra en el '
                . 'servidor. Si jobs_en_cola es cero y sigue en "pendiente", el dispatch no llegó a la cola.',
        ];
    }

    /**
     * Cómo se nombra una versión en las respuestas de este bloque.
     *
     * La columna es `versions.version` (string de 30, unique), verificada contra la migración: no
     * se tantean varios nombres de campo, porque tantear devuelve null en silencio el día que
     * ninguno acierta.
     *
     * @param Version|null $version Versión.
     *
     * @return string|null
     */
    private function nombre_de_version($version)
    {
        if ($version === null || $version->version === null || $version->version === '') {
            return null;
        }

        return (string) $version->version;
    }
}

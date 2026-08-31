<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\BaseController;
use App\Jobs\RunDemoInstallationJob;
use App\Models\Demo;
use App\Models\DemoInstallation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * CRUD JSON del recurso DemoInstallation para admin-spa.
 *
 * Mismo contrato que DemoUpdateController: las corridas no se editan, sólo se crean (disparando el
 * job) o se borran. Lo único que cambia es que el alta acepta además `env_manual_values`, que son
 * las credenciales de la base que el operador creó a mano en hPanel y que el pipeline escribe en
 * el .env de la demo.
 */
class DemoInstallationController extends BaseController
{
    /**
     * Conexión de cola en la que se encola el pipeline.
     *
     * Es la misma constante y el mismo valor que DeploymentController::CONEXION_DE_COLA: `database`
     * es la única conexión con worker (el scheduler corre `queue:work database --stop-when-empty`
     * cada minuto). El default del proyecto es `sync`, que ejecutaría el pipeline adentro del
     * request — ver el comentario del dispatch en store_json().
     *
     * @var string
     */
    const CONEXION_DE_COLA = 'database';

    /**
     * Estados en los que una corrida todavía está viva y bloquea el arranque de otra.
     *
     * @var array<int, string>
     */
    const ESTADOS_EN_CURSO = [DemoInstallation::STATUS_PENDIENTE, DemoInstallation::STATUS_INSTALANDO];

    /**
     * Lista las instalaciones con sus relaciones, de la más reciente a la más vieja.
     *
     * Acepta `demo_id` para filtrar por demo (es lo que usa la solapa Sistema del módulo de
     * Instalaciones cuando se abre parado en una demo) y paginado opcional.
     *
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index_json(Request $request)
    {
        // Tamaño de página configurable por la grilla, con topes fijos por protección.
        $per_page = (int) $request->input('per_page', 100);
        if ($per_page < 1) {
            $per_page = 20;
        }
        if ($per_page > 200) {
            $per_page = 200;
        }

        $query = DemoInstallation::withAll()->orderBy('id', 'desc');

        $demo_id = $request->input('demo_id');
        if ($demo_id !== null && $demo_id !== '') {
            $query->where('demo_id', (int) $demo_id);
        }

        if ($request->has('page')) {
            $models = $query->paginate($per_page);
        } else {
            $models = $query->get();
        }

        return response()->json(['models' => $models], 200);
    }

    /**
     * Devuelve una corrida puntual con todas sus relaciones, incluido el log completo.
     *
     * Es el endpoint contra el que hace polling el panel de Operaciones mientras la instalación
     * corre: la relación `logs` de scopeWithAll() es la consola en vivo.
     *
     * @param  int|string  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show_json($id)
    {
        $model = $this->fullModel('demo_installation', $id);
        if (! $model) {
            return response()->json(['message' => 'No encontrado.'], 404);
        }

        return response()->json(['model' => $model], 200);
    }

    /**
     * Crea una instalación en estado `pendiente` y despacha el job que corre el pipeline.
     *
     * 🔴 El pipeline es DESTRUCTIVO: su etapa `run_demo_setup` le hace `migrate:fresh` a la base de
     * la demo. Por eso este endpoint es lo único que lo dispara y lo hace UNA vez por fila: no hay
     * un `start` aparte como en client-installations, así que no existe la secuencia "crear, mirar,
     * volver a arrancar la misma fila" que en una demo sería un segundo migrate:fresh.
     *
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store_json(Request $request)
    {
        $request->validate([
            'demo_id'           => 'required|integer|exists:demos,id',
            'version_id'        => 'required|integer|exists:versions,id',
            'env_manual_values' => 'nullable|array',
        ]);

        $demo = Demo::findOrFail((int) $request->input('demo_id'));

        /* 🔴 Las dos guardas de abajo van ANTES de crear la fila, y las dos existen por lo mismo:
         * la etapa 8 de este pipeline le hace `migrate:fresh` a la base de la demo. Cualquier
         * validación que llegue tarde, llega después de que la base ya se vació. */

        $rechazo = $this->rechazar_si_ya_hay_una_corriendo($demo);
        if ($rechazo !== null) {
            return $rechazo;
        }

        $rechazo = $this->rechazar_si_falta_el_id_de_comercio($demo);
        if ($rechazo !== null) {
            return $rechazo;
        }

        // Admin autenticado que inicia el proceso (null si no hay sesión).
        $admin    = Auth::guard('sanctum')->user();
        $admin_id = $admin ? $admin->id : null;

        $env_manual_values = $request->input('env_manual_values');
        if (! is_array($env_manual_values)) {
            $env_manual_values = null;
        }

        $installation = DemoInstallation::create([
            'demo_id'             => (int) $request->input('demo_id'),
            'version_id'          => (int) $request->input('version_id'),
            'created_by_admin_id' => $admin_id,
            'status'              => DemoInstallation::STATUS_PENDIENTE,
            'env_manual_values'   => $env_manual_values,
        ]);

        /* 🔴 `onConnection('database')` NO es decorativo. `QUEUE_CONNECTION` de admin-api es `sync`
         * (verificado en el .env el 31/8/2026), así que un `dispatch()` pelado corre el pipeline
         * ENTERO —npm ci, npm run build, los SFTP, composer install y el demo-setup— adentro de
         * este request HTTP. `max_execution_time` lo mata con un fatal que no pasa ni por el catch
         * del service ni por `failed()` del job, y la fila queda en `instalando` para siempre: sin
         * nadie que la venza y sin poder borrarla (destroy_json rechaza ese estado). Es el mismo
         * criterio que DeploymentController::CONEXION_DE_COLA, y la conexión `database` es la que
         * tiene worker (el scheduler corre `queue:work database --stop-when-empty` cada minuto). */
        RunDemoInstallationJob::dispatch($installation)->onConnection(self::CONEXION_DE_COLA);

        $created = DemoInstallation::withAll()->find($installation->id);

        return response()->json(['model' => $created], 201);
    }

    /**
     * Borra una corrida y sus líneas de log.
     *
     * 🔴 No se borra una corrida que está `instalando`: el job sigue vivo del otro lado y su
     * `failed()` (y el propio service) buscan la fila para cerrarla. Sin ella, el pipeline sigue
     * escribiendo en un servidor real sin que quede registro de nada.
     *
     * El log se borra explícitamente porque `demo_installation_logs` no tiene FK con
     * `ON DELETE CASCADE` — el proyecto no usa FK en la base — así que si no se hace acá, esas
     * filas quedan huérfanas para siempre.
     *
     * @param  int|string  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy_json($id)
    {
        $installation = DemoInstallation::findOrFail($id);

        if ($installation->status === DemoInstallation::STATUS_INSTALANDO) {
            return response()->json([
                'message' => 'No se puede borrar una instalación que está corriendo. Esperá a que '
                    . 'termine (o falle) y borrala después.',
            ], 422);
        }

        $installation->logs()->delete();
        $installation->delete();

        return response()->json(null, 204);
    }

    /**
     * Rechaza el alta si esa demo ya tiene una corrida en curso.
     *
     * 🔴 El docblock de store_json argumenta que el pipeline se dispara "una vez por fila", y es
     * cierto: no hay endpoint de arranque separado. Pero eso no impide crear una SEGUNDA fila para
     * la misma demo, que es un clic de distancia — y más probable de lo que parece, porque el
     * pipeline tarda media hora y es fácil creer que no arrancó. Serían dos jobs descomprimiendo
     * sobre el mismo directorio remoto, dos `find . -mindepth 1 -delete` sobre el mismo SPA y dos
     * `migrate:fresh` sobre la misma base. El `flock` de empresa-api tapa solamente el último.
     *
     * Es la misma guarda que el pipeline de ecommerce ya tiene (assert_no_running_installation) y
     * que el de deployment resuelve con su lista de estados activos.
     *
     * @param  Demo  $demo
     * @return \Illuminate\Http\JsonResponse|null  null si se puede arrancar.
     */
    protected function rechazar_si_ya_hay_una_corriendo(Demo $demo)
    {
        $en_curso = DemoInstallation::where('demo_id', $demo->id)
            ->whereIn('status', self::ESTADOS_EN_CURSO)
            ->orderBy('id', 'desc')
            ->first();

        if ($en_curso === null) {
            return null;
        }

        return response()->json([
            'message' => 'Esta demo ya tiene una instalación en curso (N° ' . $en_curso->id . ', '
                . $en_curso->status . '). Esperá a que termine antes de arrancar otra: dos '
                . 'instalaciones sobre la misma demo se pisan el directorio y la base.',
        ], 422);
    }

    /**
     * Rechaza el alta si la demo no tiene cargado el ID de comercio (USER_ID).
     *
     * 🔴 Sin este número, step_write_env() no escribe la clave y el .env de la demo queda con
     * `USER_ID=` vacío (la plantilla la trae sin valor). El demo-setup crea después el User con
     * `config('app.USER_ID')`, o sea que la demo nace colgando de un usuario que no es el que la
     * tienda va a pedir: el síntoma aparece recién cuando se le instala el ecommerce, y para
     * entonces arreglar el número ya no alcanza porque los datos quedaron sembrados con el otro.
     *
     * Va acá y no en el service porque el service corre DESPUÉS del migrate:fresh de la etapa 8.
     * Es la misma exigencia que el pipeline de ecommerce hace por adelantado en
     * EcommerceInstallationService::assert_owner_commerce_id(); la asimetría estaba justo del lado
     * destructivo.
     *
     * @param  Demo  $demo
     * @return \Illuminate\Http\JsonResponse|null  null si el dato está.
     */
    protected function rechazar_si_falta_el_id_de_comercio(Demo $demo)
    {
        if ($demo->user_id !== null && (int) $demo->user_id > 0) {
            return null;
        }

        return response()->json([
            'message' => 'La demo no tiene cargado el «ID de comercio (USER_ID)». Ese número es el '
                . 'que se escribe en el .env de la demo y con el que el demo-setup crea el usuario: '
                . 'sin él la demo nace vacía y la tienda no encuentra su comercio. Cargalo en Demos '
                . '> Catálogo antes de instalar. Si la demo es nueva, el número lo elegís vos: '
                . 'tiene que ser uno que no use ningún otro sistema.',
        ], 422);
    }
}

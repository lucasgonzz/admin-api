<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Services\Agenda\AgendaAdminResolver;
use App\Services\Agenda\AgendaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La Agenda para Claude (misión agenda-admin, 31/10/2026): las rutas `claude/agenda…` bajo el
 * middleware `claude.task.key`, para crear tareas, moverlas, marcarlas hechas y leer la agenda de
 * cualquier admin desde la conversación. Reemplaza al calendario de Structured como lugar donde
 * "agendar" algo.
 *
 * 🔴 DELGADO A PROPÓSITO: valida, expande y serializa con el MISMO {@see AgendaService} que el
 * panel, así que la forma de la tarea y las reglas son idénticas. Lo único propio de acá es de
 * quién es la agenda: Claude no tiene sesión, así que la elige con `admin_id` / `admin_name` o cae
 * en el admin por defecto de las tareas de Claude ({@see AgendaAdminResolver}).
 *
 * En las rutas con `{id}` la tarea se identifica solo por el id (de cualquier admin):
 * `admin_id` / `admin_name` se usan para listar y para crear, no para buscar una tarea puntual.
 */
class ClaudeAgendaController extends Controller
{
    /** @var AgendaService */
    private $agenda;

    /** @var AgendaAdminResolver */
    private $admins;

    /**
     * @param AgendaService       $agenda La lógica de la Agenda.
     * @param AgendaAdminResolver $admins Quién es el dueño de la agenda que se pide.
     */
    public function __construct(AgendaService $agenda, AgendaAdminResolver $admins)
    {
        $this->agenda = $agenda;
        $this->admins = $admins;
    }

    /**
     * `GET claude/agenda`: la agenda de un admin en un rango, y opcionalmente una búsqueda.
     *
     * Sin `desde`/`hasta` es hoy..hoy+7. Con `buscar` se suma `coincidencias` (tareas sin expandir
     * cuyo título o notas contienen el texto, sin importar el rango, tope 50).
     *
     * @param Request $request `desde?`, `hasta?`, `incluir_completadas?`, `admin_id?`, `admin_name?`, `buscar?`.
     *
     * @return JsonResponse `200 {desde, hasta, hoy, ocurrencias, bandeja, admin, coincidencias?}`.
     */
    public function index(Request $request): JsonResponse
    {
        $admin  = $this->admins->resolver($request->all());
        $agenda = $this->agenda->listar((int) $admin->id, $request->all(), false);

        $agenda['admin'] = $this->admin_resumido($admin);

        $buscar = $request->input('buscar');
        if ($buscar !== null && ! is_array($buscar) && trim((string) $buscar) !== '') {
            $agenda['coincidencias'] = $this->agenda->buscar((int) $admin->id, mb_substr(trim((string) $buscar), 0, 200));
        }

        return response()->json($agenda, 200);
    }

    /**
     * `GET claude/agenda/{id}`: una tarea sin expandir.
     *
     * @param string $id Id de la tarea.
     *
     * @return JsonResponse `200 {model}`.
     */
    public function show($id): JsonResponse
    {
        return response()->json(['model' => $this->agenda->serializar($this->agenda->encontrar((int) $id, null))], 200);
    }

    /**
     * `POST claude/agenda`: crea una tarea (con `created_via = 'claude'`).
     *
     * @param Request $request Campos de la tarea; `subtasks` puede ser un array de strings.
     *
     * @return JsonResponse `201 {model, admin}`.
     */
    public function store(Request $request): JsonResponse
    {
        $admin = $this->admins->resolver($request->all());
        $tarea = $this->agenda->crear((int) $admin->id, $request->all(), 'claude');

        return response()->json([
            'model' => $this->agenda->serializar($tarea),
            'admin' => $this->admin_resumido($admin),
        ], 201);
    }

    /**
     * `PATCH claude/agenda/{id}`: edita o mueve una tarea (mismas reglas que `PUT admin/agenda/{id}`).
     *
     * @param Request $request Campos a cambiar.
     * @param string  $id      Id de la tarea.
     *
     * @return JsonResponse `200 {model}`.
     */
    public function update(Request $request, $id): JsonResponse
    {
        $tarea = $this->agenda->encontrar((int) $id, null);
        $tarea = $this->agenda->actualizar($tarea, $request->all());

        return response()->json(['model' => $this->agenda->serializar($tarea)], 200);
    }

    /**
     * `DELETE claude/agenda/{id}`: borra la tarea con sus subpasos y sus ocurrencias hechas.
     *
     * @param string $id Id de la tarea.
     *
     * @return JsonResponse `200 {deleted: true, id}`.
     */
    public function destroy($id): JsonResponse
    {
        $tarea = $this->agenda->encontrar((int) $id, null);

        return response()->json(['deleted' => true, 'id' => $this->agenda->borrar($tarea)], 200);
    }

    /**
     * `POST claude/agenda/{id}/complete`: marca hecha la tarea (o la ocurrencia `date`).
     *
     * @param Request $request `date` (Y-m-d), obligatorio si la tarea se repite.
     * @param string  $id      Id de la tarea.
     *
     * @return JsonResponse `200 {model}` en forma de ocurrencia.
     */
    public function complete(Request $request, $id): JsonResponse
    {
        $tarea = $this->agenda->encontrar((int) $id, null);

        return response()->json(['model' => $this->agenda->completar($tarea, $request->all())], 200);
    }

    /**
     * `POST claude/agenda/{id}/uncomplete`: desmarca la tarea (o la ocurrencia `date`).
     *
     * @param Request $request `date` (Y-m-d), obligatorio si la tarea se repite.
     * @param string  $id      Id de la tarea.
     *
     * @return JsonResponse `200 {model}` en forma de ocurrencia.
     */
    public function uncomplete(Request $request, $id): JsonResponse
    {
        $tarea = $this->agenda->encontrar((int) $id, null);

        return response()->json(['model' => $this->agenda->descompletar($tarea, $request->all())], 200);
    }

    /**
     * `POST claude/agenda/{id}/subtasks`: agrega un subpaso al final de la tarea.
     *
     * @param Request $request `title`, `done?`.
     * @param string  $id      Id de la tarea.
     *
     * @return JsonResponse `201 {model}` con la tarea entera.
     */
    public function store_subtask(Request $request, $id): JsonResponse
    {
        $tarea = $this->agenda->encontrar((int) $id, null);
        $tarea = $this->agenda->agregar_subpaso($tarea, $request->all());

        return response()->json(['model' => $this->agenda->serializar($tarea)], 201);
    }

    /**
     * `PATCH claude/agenda/{id}/subtasks/{subtask_id}`: edita o tilda un subpaso.
     *
     * @param Request $request    `title?`, `done?`.
     * @param string  $id         Id de la tarea.
     * @param string  $subtask_id Id del subpaso.
     *
     * @return JsonResponse `200 {model}` con la tarea entera.
     */
    public function update_subtask(Request $request, $id, $subtask_id): JsonResponse
    {
        $tarea = $this->agenda->encontrar((int) $id, null);
        $tarea = $this->agenda->actualizar_subpaso($tarea, (int) $subtask_id, $request->all());

        return response()->json(['model' => $this->agenda->serializar($tarea)], 200);
    }

    /**
     * El dueño de la agenda, sin datos sensibles (sin email ni teléfono).
     *
     * @param Admin $admin
     *
     * @return array{id: int, name: string}
     */
    private function admin_resumido(Admin $admin): array
    {
        return ['id' => (int) $admin->id, 'name' => (string) $admin->name];
    }
}

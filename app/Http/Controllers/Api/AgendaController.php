<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Agenda\AgendaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La Agenda del panel (misión agenda-admin, 31/10/2026): las rutas `admin/agenda…` bajo
 * `auth:sanctum` que consume el SPA (la vista tipo Structured del Inicio y de `/agenda`).
 *
 * 🔴 DELGADO A PROPÓSITO: toda la lógica (validación, expansión de repetidas, serialización) vive
 * en {@see AgendaService}, el mismo que usa `ClaudeAgendaController`. Acá solo se decide de quién es
 * la agenda —SIEMPRE la del admin logueado— y con qué código se responde.
 *
 * Una tarea de otro admin es 404, no 403: el panel no confirma siquiera que existe.
 */
class AgendaController extends Controller
{
    /** @var AgendaService */
    private $agenda;

    /**
     * @param AgendaService $agenda La lógica de la Agenda.
     */
    public function __construct(AgendaService $agenda)
    {
        $this->agenda = $agenda;
    }

    /**
     * `GET admin/agenda?desde=&hasta=&incluir_completadas=`: las ocurrencias del rango y la bandeja.
     *
     * @param Request $request `desde` y `hasta` (Y-m-d, obligatorios, ≤ 100 días), `incluir_completadas`.
     *
     * @return JsonResponse `200 {desde, hasta, hoy, ocurrencias, bandeja}`.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->agenda->listar($this->admin_id($request), $request->all(), true), 200);
    }

    /**
     * `GET admin/agenda/{id}`: una tarea sin expandir.
     *
     * @param Request $request
     * @param string  $id      Id de la tarea (solo dígitos, por `whereNumber`).
     *
     * @return JsonResponse `200 {model}`.
     */
    public function show(Request $request, $id): JsonResponse
    {
        $tarea = $this->agenda->encontrar((int) $id, $this->admin_id($request));

        return response()->json(['model' => $this->agenda->serializar($tarea)], 200);
    }

    /**
     * `POST admin/agenda`: crea una tarea en la agenda del admin logueado.
     *
     * @param Request $request Campos de la tarea (ver AgendaValidator::validar_campos()).
     *
     * @return JsonResponse `201 {model}`.
     */
    public function store(Request $request): JsonResponse
    {
        $tarea = $this->agenda->crear($this->admin_id($request), $request->all(), 'panel');

        return response()->json(['model' => $this->agenda->serializar($tarea)], 201);
    }

    /**
     * `PUT admin/agenda/{id}`: edita o mueve una tarea (parcial; `subtasks` reemplaza el conjunto).
     *
     * @param Request $request Campos a cambiar.
     * @param string  $id      Id de la tarea.
     *
     * @return JsonResponse `200 {model}`.
     */
    public function update(Request $request, $id): JsonResponse
    {
        $tarea = $this->agenda->encontrar((int) $id, $this->admin_id($request));
        $tarea = $this->agenda->actualizar($tarea, $request->all());

        return response()->json(['model' => $this->agenda->serializar($tarea)], 200);
    }

    /**
     * `DELETE admin/agenda/{id}`: borra la tarea con sus subpasos y sus ocurrencias hechas.
     *
     * @param Request $request
     * @param string  $id      Id de la tarea.
     *
     * @return JsonResponse `200 {deleted: true, id}`.
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        $tarea = $this->agenda->encontrar((int) $id, $this->admin_id($request));

        return response()->json(['deleted' => true, 'id' => $this->agenda->borrar($tarea)], 200);
    }

    /**
     * `POST admin/agenda/{id}/complete`: marca hecha la tarea (o la ocurrencia `date` de una repetida).
     *
     * @param Request $request `date` (Y-m-d), obligatorio si la tarea se repite.
     * @param string  $id      Id de la tarea.
     *
     * @return JsonResponse `200 {model}` en forma de ocurrencia.
     */
    public function complete(Request $request, $id): JsonResponse
    {
        $tarea = $this->agenda->encontrar((int) $id, $this->admin_id($request));

        return response()->json(['model' => $this->agenda->completar($tarea, $request->all())], 200);
    }

    /**
     * `POST admin/agenda/{id}/uncomplete`: desmarca la tarea (o la ocurrencia `date`).
     *
     * @param Request $request `date` (Y-m-d), obligatorio si la tarea se repite.
     * @param string  $id      Id de la tarea.
     *
     * @return JsonResponse `200 {model}` en forma de ocurrencia.
     */
    public function uncomplete(Request $request, $id): JsonResponse
    {
        $tarea = $this->agenda->encontrar((int) $id, $this->admin_id($request));

        return response()->json(['model' => $this->agenda->descompletar($tarea, $request->all())], 200);
    }

    /**
     * `POST admin/agenda/{id}/duplicate`: copia la tarea (subpasos sin tildar, no hecha).
     *
     * @param Request $request
     * @param string  $id      Id de la tarea a copiar.
     *
     * @return JsonResponse `201 {model}` con la copia.
     */
    public function duplicate(Request $request, $id): JsonResponse
    {
        $tarea = $this->agenda->encontrar((int) $id, $this->admin_id($request));
        $copia = $this->agenda->duplicar($tarea, 'panel');

        return response()->json(['model' => $this->agenda->serializar($copia)], 201);
    }

    /**
     * `PUT admin/agenda/{id}/subtasks/{subtask_id}`: edita o tilda un subpaso.
     *
     * @param Request $request    `title?`, `done?`.
     * @param string  $id         Id de la tarea.
     * @param string  $subtask_id Id del subpaso.
     *
     * @return JsonResponse `200 {model}` con la tarea entera.
     */
    public function update_subtask(Request $request, $id, $subtask_id): JsonResponse
    {
        $tarea = $this->agenda->encontrar((int) $id, $this->admin_id($request));
        $tarea = $this->agenda->actualizar_subpaso($tarea, (int) $subtask_id, $request->all());

        return response()->json(['model' => $this->agenda->serializar($tarea)], 200);
    }

    /**
     * El admin logueado, dueño de la agenda que se opera.
     *
     * @param Request $request
     *
     * @return int
     */
    private function admin_id(Request $request): int
    {
        return (int) $request->user()->id;
    }
}

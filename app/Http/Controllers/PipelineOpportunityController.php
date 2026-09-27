<?php

namespace App\Http\Controllers;

use App\Helpers\AppTime;
use App\Models\PipelineActivity;
use App\Models\PipelineOpportunity;
use App\Services\Pipelines\PipelineAgenda;
use App\Services\Pipelines\PipelineConsultasService;
use App\Services\Pipelines\PipelineOpportunityService;
use App\Services\Pipelines\PipelinePresenter;
use App\Services\Pipelines\PipelineRuleException;
use App\Services\Pipelines\PipelineValidator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;

/**
 * Las oportunidades del CRM de pipelines (misión pipelines-crm, 27/9/2026), endpoints #13–19 del
 * contrato: las de un cliente / lead, la agenda, la ficha, próxima acción y responsable, mover,
 * notas y borrar.
 *
 * Las reglas viven en `PipelineOpportunityService` (escrituras) y `PipelineConsultasService`
 * (lecturas); el JSON lo arma `PipelinePresenter`.
 *
 * 🔴 Las respuestas de las acciones sobre UNA oportunidad (#16 actualizar, #17 mover, #18 nota)
 * devuelven la oportunidad con la misma forma que la ficha (#15): sujeto con teléfono y mail, y
 * `pipeline` completo. Es un superconjunto de la forma Opportunity del tablero, a propósito: la
 * ficha puede reemplazar su copia con la respuesta sin perder el contacto ni el pipeline, y el
 * tablero puede usarla igual (ignora lo que le sobra).
 *
 * 🔴 Nada de acá manda mensajes. El link de WhatsApp de la ficha lo arma la SPA y solo abre la app.
 */
class PipelineOpportunityController extends Controller
{
    /** Opciones del presenter para la forma de la ficha. */
    const FORMA_FICHA = ['contacto' => true, 'pipeline' => 'completo'];

    /**
     * #13 `GET pipeline-opportunities?client_id=` o `?lead_id=` — las oportunidades de un cliente o
     * de un lead en todos los pipelines (la pestaña "Pipelines" de su ficha). Abiertas primero,
     * después las cerradas de la más reciente a la más vieja.
     *
     * @param Request                  $request
     * @param PipelineConsultasService $consultas
     * @param PipelinePresenter        $presenter
     *
     * @return \Illuminate\Http\JsonResponse {opportunities: [Opportunity + {pipeline: {id, name, archived_at}}]}
     */
    public function index_json(Request $request, PipelineConsultasService $consultas, PipelinePresenter $presenter)
    {
        $datos = PipelineValidator::validar($request->query(), [
            'client_id' => ['nullable', 'integer', 'min:1'],
            'lead_id'   => ['nullable', 'integer', 'min:1'],
        ]);

        $cliente = isset($datos['client_id']) ? $datos['client_id'] : null;
        $lead    = isset($datos['lead_id']) ? $datos['lead_id'] : null;

        if (($cliente === null) === ($lead === null)) {
            throw PipelineRuleException::validacion([
                'client_id' => ['Indicá un cliente (client_id) o un lead (lead_id), uno de los dos.'],
            ]);
        }

        $oportunidades = $cliente !== null
            ? $consultas->del_sujeto(PipelineOpportunity::SUBJECT_CLIENT, (int) $cliente)
            : $consultas->del_sujeto(PipelineOpportunity::SUBJECT_LEAD, (int) $lead);

        return response()->json([
            'opportunities' => $presenter->opportunities($oportunidades, ['pipeline' => 'id_nombre_archivo']),
        ]);
    }

    /**
     * #14 `GET pipeline-opportunities/agenda` — las abiertas de pipelines no archivados, repartidas
     * en vencidas / hoy / próximos 7 días / más adelante / sin próxima acción.
     *
     * @param Request                  $request
     * @param PipelineConsultasService $consultas
     * @param PipelinePresenter        $presenter
     *
     * @return \Illuminate\Http\JsonResponse {overdue, today, week, later, none}
     */
    public function agenda_json(Request $request, PipelineConsultasService $consultas, PipelinePresenter $presenter)
    {
        $datos = PipelineValidator::validar($request->query(), [
            'owner_admin_id' => ['nullable', 'integer'],
            'pipeline_id'    => ['nullable', 'integer'],
        ]);

        $ahora  = AppTime::now();
        $baldes = $consultas->agenda($datos, $ahora);

        $respuesta = [];
        foreach (PipelineAgenda::BALDES_ABIERTOS as $balde) {
            $respuesta[$balde] = $presenter->opportunities($baldes[$balde], ['pipeline' => 'id_nombre', 'ahora' => $ahora]);
        }

        return response()->json($respuesta);
    }

    /**
     * #15 `GET pipeline-opportunities/{id}` — la ficha: la oportunidad (con teléfono y mail del
     * sujeto y el pipeline completo) y su historial, de la más nueva a la más vieja.
     *
     * @param int               $id
     * @param PipelinePresenter $presenter
     *
     * @return \Illuminate\Http\JsonResponse {opportunity, activities}
     */
    public function show_json($id, PipelinePresenter $presenter)
    {
        $oportunidad = $this->oportunidad_o_404($id);

        return response()->json([
            'opportunity' => $presenter->opportunity($oportunidad, self::FORMA_FICHA),
            'activities'  => $presenter->activities($oportunidad->activities()->get()),
        ]);
    }

    /**
     * #16 `PUT pipeline-opportunities/{id}` — cambia el responsable y/o la próxima acción. Deja
     * una actividad `owner` y/o `next_action` si algo cambió. 422 si se le quiere poner próxima
     * acción a una cerrada.
     *
     * @param Request                    $request   {owner_admin_id?, next_action_at?: "Y-m-d H:i"|"Y-m-d"|null, next_action_note?}
     * @param int                        $id
     * @param PipelineOpportunityService $servicio
     * @param PipelinePresenter          $presenter
     *
     * @return \Illuminate\Http\JsonResponse {opportunity, activities: [nuevas]}
     */
    public function update_json(Request $request, $id, PipelineOpportunityService $servicio, PipelinePresenter $presenter)
    {
        $oportunidad = $this->oportunidad_o_404($id);

        $datos = PipelineValidator::validar($request->all(), [
            'owner_admin_id'   => ['sometimes', 'nullable', 'integer', 'exists:admins,id'],
            'next_action_at'   => ['sometimes', 'nullable', 'string'],
            'next_action_note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        list($oportunidad, $nuevas) = $servicio->actualizar($oportunidad, $datos, $this->admin_id($request));

        return response()->json([
            'opportunity' => $presenter->opportunity($oportunidad, self::FORMA_FICHA),
            'activities'  => $presenter->activities(new EloquentCollection($nuevas)),
        ]);
    }

    /**
     * #17 `POST pipeline-opportunities/{id}/move` — mueve de etapa con los campos de la etapa
     * destino, la nota, el motivo (si es perdida) y la próxima acción, todo en una sola acción.
     * Las reglas están en `PipelineOpportunityService::mover()`.
     *
     * @param Request                    $request   {stage_id, fields?, note?, lost_reason?, next_action_at?, next_action_note?}
     * @param int                        $id
     * @param PipelineOpportunityService $servicio
     * @param PipelinePresenter          $presenter
     *
     * @return \Illuminate\Http\JsonResponse {opportunity, activity}
     */
    public function move_json(Request $request, $id, PipelineOpportunityService $servicio, PipelinePresenter $presenter)
    {
        $oportunidad = $this->oportunidad_o_404($id);

        /* `fields` sin regla de tipo a propósito: su forma y sus valores los valida
           PipelineFieldsService contra la definición de la etapa destino, con errores por campo. */
        $datos = PipelineValidator::validar($request->all(), [
            'stage_id'         => ['required', 'integer'],
            'fields'           => ['nullable'],
            'note'             => ['nullable', 'string', 'max:' . PipelineOpportunityService::MAX_NOTA],
            'lost_reason'      => ['nullable', 'string', 'max:' . PipelineOpportunityService::MAX_CORTO],
            'next_action_at'   => ['nullable', 'string'],
            'next_action_note' => ['nullable', 'string', 'max:' . PipelineOpportunityService::MAX_CORTO],
        ]);

        list($oportunidad, $actividad) = $servicio->mover($oportunidad, $datos, $this->admin_id($request));

        return response()->json([
            'opportunity' => $presenter->opportunity($oportunidad, self::FORMA_FICHA),
            'activity'    => $presenter->activity($actividad->loadMissing('admin')),
        ]);
    }

    /**
     * #18 `POST pipeline-opportunities/{id}/notes` — agrega una nota al historial, con canal y
     * fecha opcionales (`occurred_at` no puede ser futura).
     *
     * @param Request                    $request   {body, channel?, occurred_at?: "Y-m-d H:i"}
     * @param int                        $id
     * @param PipelineOpportunityService $servicio
     * @param PipelinePresenter          $presenter
     *
     * @return \Illuminate\Http\JsonResponse 201 {activity, opportunity}
     */
    public function store_note_json(Request $request, $id, PipelineOpportunityService $servicio, PipelinePresenter $presenter)
    {
        $oportunidad = $this->oportunidad_o_404($id);

        $datos = PipelineValidator::validar($request->all(), [
            'body'        => ['required', 'string', 'max:' . PipelineOpportunityService::MAX_NOTA],
            'channel'     => ['nullable', 'string', 'in:' . implode(',', array_keys(PipelineActivity::CHANNEL_LABELS))],
            'occurred_at' => ['nullable', 'string'],
        ]);

        $actividad = $servicio->agregar_nota(
            $oportunidad,
            $datos['body'],
            isset($datos['channel']) ? $datos['channel'] : null,
            isset($datos['occurred_at']) ? $datos['occurred_at'] : null,
            $this->admin_id($request)
        );

        return response()->json([
            'activity'    => $presenter->activity($actividad->loadMissing('admin')),
            'opportunity' => $presenter->opportunity($oportunidad->fresh(), self::FORMA_FICHA),
        ], 201);
    }

    /**
     * #19 `DELETE pipeline-opportunities/{id}` — borra la oportunidad y todo su historial.
     *
     * @param int                        $id
     * @param PipelineOpportunityService $servicio
     *
     * @return \Illuminate\Http\JsonResponse {ok: true}
     */
    public function destroy_json($id, PipelineOpportunityService $servicio)
    {
        $servicio->borrar($this->oportunidad_o_404($id));

        return response()->json(['ok' => true]);
    }

    /**
     * La oportunidad, o 404 con mensaje legible.
     *
     * @param int $id
     *
     * @return PipelineOpportunity
     *
     * @throws PipelineRuleException
     */
    private function oportunidad_o_404($id)
    {
        $oportunidad = PipelineOpportunity::query()->find((int) $id);

        if ($oportunidad === null) {
            throw PipelineRuleException::no_existe('La oportunidad');
        }

        return $oportunidad;
    }

    /**
     * Id del admin logueado.
     *
     * @param Request $request
     *
     * @return int|null
     */
    private function admin_id(Request $request)
    {
        $admin = $request->user();

        return $admin ? (int) $admin->id : null;
    }
}

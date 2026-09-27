<?php

namespace App\Http\Controllers;

use App\Helpers\AppTime;
use App\Models\Pipeline;
use App\Models\PipelineOpportunity;
use App\Services\Pipelines\PipelineConfigService;
use App\Services\Pipelines\PipelineConsultasService;
use App\Services\Pipelines\PipelineOpportunityService;
use App\Services\Pipelines\PipelinePresenter;
use App\Services\Pipelines\PipelineRuleException;
use App\Services\Pipelines\PipelineValidator;
use Illuminate\Http\Request;

/**
 * El CRM de pipelines del admin (misión pipelines-crm, 27/9/2026): enumeraciones, ABM de
 * pipelines, alta de etapas, tablero, candidatos y alta masiva de oportunidades.
 *
 * Endpoints #1–7 y #10–12 del contrato del plan de la misión. Acá solo se valida la FORMA del
 * request y se arma la respuesta: las reglas viven en `PipelineConfigService` (ABM),
 * `PipelineOpportunityService` (escrituras) y `PipelineConsultasService` (lecturas), y el JSON lo
 * produce `PipelinePresenter`, que es el único productor de esas formas.
 *
 * Lo usa cualquier admin (no hay permisos por módulo en el admin). 🔴 No manda ningún mensaje:
 * el CRM solo registra.
 */
class PipelineController extends Controller
{
    /** Formato de un color de etapa. */
    const REGLA_COLOR = 'regex:/^#[0-9a-fA-F]{6}$/';

    /**
     * #1 `GET pipelines/meta` — las enumeraciones del módulo con su etiqueta en español: tipos de
     * etapa, tipos de campo, canales y tipos de actividad. La SPA las lee de acá y no las escribe
     * de nuevo.
     *
     * @param PipelinePresenter $presenter
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function meta_json(PipelinePresenter $presenter)
    {
        return response()->json($presenter->meta());
    }

    /**
     * #2 `GET pipelines` — los pipelines con sus etapas y conteos, por `sort_order` e `id`. Los
     * archivados solo con `include_archived=1`.
     *
     * @param Request           $request
     * @param PipelinePresenter $presenter
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index_json(Request $request, PipelinePresenter $presenter)
    {
        $datos = PipelineValidator::validar($request->query(), [
            'include_archived' => ['nullable', 'boolean'],
        ]);

        $query = Pipeline::query()->orderBy('sort_order')->orderBy('id');

        if (empty($datos['include_archived'])) {
            $query->whereNull('archived_at');
        }

        return response()->json(['pipelines' => $presenter->pipelines($query->get())]);
    }

    /**
     * #3 `POST pipelines` — crea un pipeline. Sin etapas (o con la lista vacía) nace con "Por
     * contactar" / "Ganado" / "Perdido"; con etapas, exige al menos una abierta.
     *
     * @param Request               $request   {name, description?, lost_reasons?, stages?: [{name, color?, type?, fields?}]}
     * @param PipelineConfigService $config
     * @param PipelinePresenter     $presenter
     *
     * @return \Illuminate\Http\JsonResponse 201 {pipeline}
     */
    public function store_json(Request $request, PipelineConfigService $config, PipelinePresenter $presenter)
    {
        $datos = PipelineValidator::validar($request->all(), [
            'name'            => ['required', 'string', 'max:120'],
            'description'     => ['nullable', 'string', 'max:5000'],
            'lost_reasons'    => ['nullable', 'array', 'max:50'],
            'lost_reasons.*'  => ['nullable', 'string', 'max:255'],
            'stages'          => ['nullable', 'array', 'max:30'],
            'stages.*'        => ['array'],
            'stages.*.name'   => ['required', 'string', 'max:120'],
            'stages.*.color'  => ['nullable', 'string', self::REGLA_COLOR],
            'stages.*.type'   => ['nullable', 'string', 'in:open,won,lost'],
            'stages.*.fields' => ['nullable'],
        ]);

        /* Una lista de etapas vacía se trata como "sin etapas": nace con las tres por defecto, en
           vez de rebotar por no tener ninguna abierta. */
        if (array_key_exists('stages', $datos) && is_array($datos['stages']) && count($datos['stages']) === 0) {
            $datos['stages'] = null;
        }

        $pipeline = $config->crear_pipeline($datos, $this->admin_id($request));

        return response()->json(['pipeline' => $presenter->pipeline($pipeline)], 201);
    }

    /**
     * #4 `PUT pipelines/{id}` — edita nombre, descripción, motivos de pérdida y/o archiva
     * (`archived: true`) o desarchiva (`archived: false`).
     *
     * @param Request               $request
     * @param int                   $id
     * @param PipelineConfigService $config
     * @param PipelinePresenter     $presenter
     *
     * @return \Illuminate\Http\JsonResponse {pipeline}
     */
    public function update_json(Request $request, $id, PipelineConfigService $config, PipelinePresenter $presenter)
    {
        $pipeline = $this->pipeline_o_404($id);

        $datos = PipelineValidator::validar($request->all(), [
            'name'           => ['sometimes', 'required', 'string', 'max:120'],
            'description'    => ['sometimes', 'nullable', 'string', 'max:5000'],
            'lost_reasons'   => ['sometimes', 'nullable', 'array', 'max:50'],
            'lost_reasons.*' => ['nullable', 'string', 'max:255'],
            'archived'       => ['sometimes', 'nullable', 'boolean'],
        ]);

        $pipeline = $config->actualizar_pipeline($pipeline, $datos);

        return response()->json(['pipeline' => $presenter->pipeline($pipeline)]);
    }

    /**
     * #5 `DELETE pipelines/{id}` — borra un pipeline sin oportunidades. Con oportunidades, 422:
     * se archiva en vez de borrarse.
     *
     * @param int                   $id
     * @param PipelineConfigService $config
     *
     * @return \Illuminate\Http\JsonResponse {ok: true}
     */
    public function destroy_json($id, PipelineConfigService $config)
    {
        $config->borrar_pipeline($this->pipeline_o_404($id));

        return response()->json(['ok' => true]);
    }

    /**
     * #6 `PUT pipelines/{id}/stage-order` — reordena las etapas. `stage_ids` tiene que traer
     * exactamente todas las etapas del pipeline.
     *
     * @param Request               $request   {stage_ids: [int]}
     * @param int                   $id
     * @param PipelineConfigService $config
     * @param PipelinePresenter     $presenter
     *
     * @return \Illuminate\Http\JsonResponse {pipeline}
     */
    public function stage_order_json(Request $request, $id, PipelineConfigService $config, PipelinePresenter $presenter)
    {
        $pipeline = $this->pipeline_o_404($id);

        $datos = PipelineValidator::validar($request->all(), [
            'stage_ids'   => ['required', 'array', 'min:1'],
            'stage_ids.*' => ['integer'],
        ]);

        $pipeline = $config->ordenar_etapas($pipeline, $datos['stage_ids']);

        return response()->json(['pipeline' => $presenter->pipeline($pipeline)]);
    }

    /**
     * #7 `POST pipelines/{id}/stages` — agrega una etapa. Abierta → antes de la primera cerrada;
     * ganada o perdida → al final.
     *
     * @param Request               $request   {name, color?, type?, fields?}
     * @param int                   $id
     * @param PipelineConfigService $config
     * @param PipelinePresenter     $presenter
     *
     * @return \Illuminate\Http\JsonResponse 201 {stage}
     */
    public function store_stage_json(Request $request, $id, PipelineConfigService $config, PipelinePresenter $presenter)
    {
        $pipeline = $this->pipeline_o_404($id);

        $datos = PipelineValidator::validar($request->all(), [
            'name'   => ['required', 'string', 'max:120'],
            'color'  => ['nullable', 'string', self::REGLA_COLOR],
            'type'   => ['nullable', 'string', 'in:open,won,lost'],
            'fields' => ['nullable'],
        ]);

        $etapa = $config->crear_etapa($pipeline, $datos);

        return response()->json(['stage' => $presenter->stage($etapa, 0)], 201);
    }

    /**
     * #10 `GET pipelines/{id}/opportunities` — el tablero / listado: las oportunidades filtradas y
     * el resumen (embudo, abiertas / ganadas / perdidas, motivos y agenda).
     *
     * El resumen ignora los filtros de la vista salvo `owner_admin_id` y `subject_type` (ver
     * `PipelineConsultasService::resumen()`).
     *
     * @param Request                  $request
     * @param int                      $id
     * @param PipelineConsultasService $consultas
     * @param PipelinePresenter        $presenter
     *
     * @return \Illuminate\Http\JsonResponse {opportunities, resumen}
     */
    public function opportunities_json(Request $request, $id, PipelineConsultasService $consultas, PipelinePresenter $presenter)
    {
        $pipeline = $this->pipeline_o_404($id);

        $datos = PipelineValidator::validar($request->query(), [
            'estado'         => ['nullable', 'in:todas,abiertas,ganadas,perdidas'],
            'owner_admin_id' => ['nullable', 'integer'],
            'subject_type'   => ['nullable', 'in:client,lead'],
            'q'              => ['nullable', 'string', 'max:120'],
            'agenda'         => ['nullable', 'in:overdue,today,week,none'],
            'stage_id'       => ['nullable', 'integer'],
        ]);

        /* Un solo reloj para toda la respuesta: la tarjeta, el filtro y los contadores tienen que
           caer en el mismo balde aunque el request cruce la medianoche. */
        $ahora = AppTime::now();

        $oportunidades = $consultas->oportunidades_del_tablero($pipeline, $datos, $ahora);

        return response()->json([
            'opportunities' => $presenter->opportunities($oportunidades, ['ahora' => $ahora]),
            'resumen'       => $consultas->resumen($pipeline, $datos, $ahora),
        ]);
    }

    /**
     * #11 `GET pipelines/{id}/candidates` — clientes o leads para el alta masiva, con su
     * oportunidad abierta en este pipeline (si hay) y cuántas cerradas tienen. Paginado por
     * `limit` (default 100, máximo 300) y `offset`.
     *
     * @param Request                  $request
     * @param int                      $id
     * @param PipelineConsultasService $consultas
     * @param PipelinePresenter        $presenter
     *
     * @return \Illuminate\Http\JsonResponse {candidates, total, has_more, lead_statuses (solo leads)}
     */
    public function candidates_json(Request $request, $id, PipelineConsultasService $consultas, PipelinePresenter $presenter)
    {
        $pipeline = $this->pipeline_o_404($id);

        $datos = PipelineValidator::validar($request->query(), [
            'type'         => ['required', 'in:client,lead'],
            'q'            => ['nullable', 'string', 'max:120'],
            'solo_activos' => ['nullable', 'boolean'],
            'lead_status'  => ['nullable', 'string', 'max:64'],
            'limit'        => ['nullable', 'integer', 'min:1'],
            'offset'       => ['nullable', 'integer', 'min:0'],
        ]);

        $resultado = $consultas->candidatos($pipeline, $datos);
        $es_lead   = $datos['type'] === PipelineOpportunity::SUBJECT_LEAD;

        $candidatos = [];
        foreach ($resultado['filas'] as $fila) {
            $sujeto = $es_lead
                ? $presenter->subject_de_lead($fila, true)
                : $presenter->subject_de_cliente($fila, true);

            $sujeto['open_opportunity_id'] = isset($resultado['abiertas'][$fila->id]) ? $resultado['abiertas'][$fila->id] : null;
            $sujeto['closed_count']        = isset($resultado['cerradas'][$fila->id]) ? $resultado['cerradas'][$fila->id] : 0;

            $candidatos[] = $sujeto;
        }

        $respuesta = [
            'candidates' => $candidatos,
            'total'      => $resultado['total'],
            'has_more'   => $resultado['has_more'],
        ];

        if ($es_lead) {
            $respuesta['lead_statuses'] = $presenter->estados_de_lead();
        }

        return response()->json($respuesta);
    }

    /**
     * #12 `POST pipelines/{id}/opportunities` — alta masiva de clientes y/o leads en la etapa
     * inicial.
     *
     * 🔴 `owner_admin_id` distingue "no vino" de "vino null": si la clave no está, el responsable
     * es el admin logueado; si viene en null, queda sin responsable.
     *
     * @param Request                    $request   {subjects: [{type, id}], stage_id?, owner_admin_id?, note?}
     * @param int                        $id
     * @param PipelineOpportunityService $servicio
     * @param PipelinePresenter          $presenter
     *
     * @return \Illuminate\Http\JsonResponse 201 {created: [Opportunity], skipped: [{type, id, reason}]}
     */
    public function store_opportunities_json(Request $request, $id, PipelineOpportunityService $servicio, PipelinePresenter $presenter)
    {
        $pipeline = $this->pipeline_o_404($id);

        $datos = PipelineValidator::validar($request->all(), [
            'subjects'        => ['required', 'array', 'min:1', 'max:500'],
            'subjects.*'      => ['required', 'array'],
            'subjects.*.type' => ['required', 'in:client,lead'],
            'subjects.*.id'   => ['required', 'integer', 'min:1'],
            'stage_id'        => ['nullable', 'integer'],
            'owner_admin_id'  => ['nullable', 'integer', 'exists:admins,id'],
            'note'            => ['nullable', 'string', 'max:5000'],
        ]);

        $admin_id = $this->admin_id($request);
        $owner    = array_key_exists('owner_admin_id', $datos)
            ? ($datos['owner_admin_id'] === null ? null : (int) $datos['owner_admin_id'])
            : $admin_id;

        $sujetos = [];
        foreach ($datos['subjects'] as $sujeto) {
            $sujetos[] = ['type' => (string) $sujeto['type'], 'id' => (int) $sujeto['id']];
        }

        $resultado = $servicio->alta_masiva(
            $pipeline,
            $sujetos,
            isset($datos['stage_id']) ? (int) $datos['stage_id'] : null,
            $owner,
            isset($datos['note']) ? $datos['note'] : null,
            $admin_id
        );

        $creadas = PipelineOpportunity::query()
            ->whereIn('id', $resultado['creadas'])
            ->orderBy('id')
            ->get();

        return response()->json([
            'created' => $presenter->opportunities($creadas),
            'skipped' => $resultado['salteados'],
        ], 201);
    }

    /**
     * El pipeline, o 404 con mensaje legible.
     *
     * @param int $id
     *
     * @return Pipeline
     *
     * @throws PipelineRuleException
     */
    private function pipeline_o_404($id)
    {
        $pipeline = Pipeline::query()->find((int) $id);

        if ($pipeline === null) {
            throw PipelineRuleException::no_existe('El pipeline', false);
        }

        return $pipeline;
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

<?php

namespace App\Services\Pipelines;

use App\Models\Client;
use App\Models\Lead;
use App\Models\Pipeline;
use App\Models\PipelineActivity;
use App\Models\PipelineOpportunity;
use App\Models\PipelineStage;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

/**
 * Las lecturas del CRM de pipelines (misión pipelines-crm, 27/9/2026): el tablero con sus filtros
 * y su resumen, la agenda, las oportunidades de un cliente / lead y los candidatos del alta masiva.
 *
 * Devuelve modelos y números; el JSON lo arma `PipelinePresenter`. Las escrituras viven en
 * `PipelineOpportunityService`.
 *
 * 🔴 El balde de agenda (vencida / hoy / semana / sin próxima) lo decide SIEMPRE
 * `PipelineAgenda::balde()`, también para filtrar y para contar: el filtro `agenda=today` del
 * tablero, el chip "Hoy (n)" del resumen y la columna "Hoy" de la vista Agenda tienen que contar
 * exactamente lo mismo, y eso solo pasa si los tres preguntan al mismo helper.
 */
class PipelineConsultasService
{
    /** `estado` del tablero → tipos de etapa. `todas` no filtra. */
    const ESTADOS = [
        'abiertas' => PipelineStage::TYPE_OPEN,
        'ganadas'  => PipelineStage::TYPE_WON,
        'perdidas' => PipelineStage::TYPE_LOST,
    ];

    /** Límite de candidatos por página: default y máximo. */
    const CANDIDATOS_LIMITE_DEFAULT = 100;
    const CANDIDATOS_LIMITE_MAX     = 300;

    /** Motivo que se cuenta cuando una oportunidad perdida no tiene motivo guardado. */
    const SIN_MOTIVO = 'Sin motivo';

    /* ------------------------------------------------------------------------------------------
     | Tablero
     |----------------------------------------------------------------------------------------- */

    /**
     * Las oportunidades del tablero / listado de un pipeline, filtradas y ordenadas.
     *
     * Orden: próxima acción ascendente con las que no tienen al final, después las que llevan más
     * tiempo en su etapa.
     *
     * @param Pipeline             $pipeline
     * @param array<string, mixed> $filtros estado?, owner_admin_id?, subject_type?, q?, agenda?, stage_id?
     * @param Carbon               $ahora   El mismo reloj que usa el presenter.
     *
     * @return EloquentCollection de PipelineOpportunity (con stage, client, lead y owner cargados).
     */
    public function oportunidades_del_tablero(Pipeline $pipeline, array $filtros, Carbon $ahora)
    {
        $query = PipelineOpportunity::query()->where('pipeline_opportunities.pipeline_id', $pipeline->id);

        $this->aplicar_filtros_base($query, $filtros);

        $estado = isset($filtros['estado']) ? $filtros['estado'] : null;
        if ($estado !== null && isset(self::ESTADOS[$estado])) {
            $tipo = self::ESTADOS[$estado];
            $query->whereIn('pipeline_opportunities.stage_id', function ($sub) use ($pipeline, $tipo) {
                $sub->select('id')->from('pipeline_stages')
                    ->where('pipeline_id', $pipeline->id)
                    ->where('type', $tipo);
            });
        }

        if (isset($filtros['stage_id']) && $filtros['stage_id'] !== null) {
            $query->where('pipeline_opportunities.stage_id', (int) $filtros['stage_id']);
        }

        $texto = self::texto_o_null(isset($filtros['q']) ? $filtros['q'] : null);
        if ($texto !== null) {
            $this->aplicar_busqueda_por_sujeto($query, $texto);
        }

        $this->ordenar_por_proxima_accion($query);

        $oportunidades = $query->with(['stage', 'client', 'lead', 'owner'])->get();

        $agenda = isset($filtros['agenda']) ? $filtros['agenda'] : null;
        if ($agenda !== null) {
            $oportunidades = $oportunidades->filter(function (PipelineOpportunity $op) use ($agenda, $ahora) {
                $tipo = $op->stage ? $op->stage->type : null;

                return PipelineAgenda::balde($tipo, $op->next_action_at, $ahora) === $agenda;
            })->values();
        }

        return $oportunidades;
    }

    /**
     * El resumen del tablero: embudo por etapa, abiertas / ganadas / perdidas, motivos de pérdida
     * y contadores de agenda.
     *
     * 🔴 Se calcula SIN los filtros de la vista salvo `owner_admin_id` y `subject_type`: el embudo
     * describe el pipeline (de ese responsable, de ese tipo de sujeto), no el resultado de una
     * búsqueda. Si se achicara con `q` o con `estado`, buscar un nombre dejaría el embudo en 1.
     *
     * `pasaron` = oportunidades DISTINTAS que alguna vez entraron a esa etapa (actividades `created`
     * y `stage_change` con ese `to_stage_id`), en UNA consulta agrupada.
     *
     * @param Pipeline             $pipeline
     * @param array<string, mixed> $filtros Se usan solo owner_admin_id y subject_type.
     * @param Carbon               $ahora
     *
     * @return array<string, mixed>
     */
    public function resumen(Pipeline $pipeline, array $filtros, Carbon $ahora)
    {
        $pipeline->loadMissing('stages');

        $tipos_por_etapa = [];
        foreach ($pipeline->stages as $etapa) {
            $tipos_por_etapa[(int) $etapa->id] = $etapa->type;
        }

        $base = PipelineOpportunity::query()->where('pipeline_opportunities.pipeline_id', $pipeline->id);
        $this->aplicar_filtros_base($base, $filtros);

        $filas = $base->get([
            'pipeline_opportunities.id',
            'pipeline_opportunities.stage_id',
            'pipeline_opportunities.next_action_at',
            'pipeline_opportunities.lost_reason',
        ]);

        $ahora_por_etapa = [];
        $abiertas        = 0;
        $ganadas         = 0;
        $perdidas        = 0;
        $motivos         = [];
        $agenda          = [
            PipelineAgenda::OVERDUE => 0,
            PipelineAgenda::TODAY   => 0,
            PipelineAgenda::WEEK    => 0,
            PipelineAgenda::NONE    => 0,
        ];

        foreach ($filas as $fila) {
            $etapa_id = (int) $fila->stage_id;
            $tipo     = isset($tipos_por_etapa[$etapa_id]) ? $tipos_por_etapa[$etapa_id] : null;

            $ahora_por_etapa[$etapa_id] = (isset($ahora_por_etapa[$etapa_id]) ? $ahora_por_etapa[$etapa_id] : 0) + 1;

            if ($tipo === PipelineStage::TYPE_OPEN) {
                $abiertas++;
                $balde = PipelineAgenda::balde($tipo, $fila->next_action_at, $ahora);
                if (isset($agenda[$balde])) {
                    $agenda[$balde]++;
                }
            } elseif ($tipo === PipelineStage::TYPE_WON) {
                $ganadas++;
            } elseif ($tipo === PipelineStage::TYPE_LOST) {
                $perdidas++;
                $motivo = self::texto_o_null($fila->lost_reason);
                $motivo = $motivo !== null ? $motivo : self::SIN_MOTIVO;
                $motivos[$motivo] = (isset($motivos[$motivo]) ? $motivos[$motivo] : 0) + 1;
            }
        }

        $pasaron = $this->pasaron_por_etapa($pipeline, $filtros);

        $por_etapa = [];
        foreach ($pipeline->stages as $etapa) {
            $id = (int) $etapa->id;
            $por_etapa[] = [
                'stage_id' => $id,
                'ahora'    => isset($ahora_por_etapa[$id]) ? $ahora_por_etapa[$id] : 0,
                'pasaron'  => isset($pasaron[$id]) ? $pasaron[$id] : 0,
            ];
        }

        /* Motivos: el más frecuente primero; a igual cantidad, alfabético (orden estable). */
        $motivos_lista = [];
        foreach ($motivos as $motivo => $cantidad) {
            $motivos_lista[] = ['motivo' => (string) $motivo, 'cantidad' => (int) $cantidad];
        }
        usort($motivos_lista, function ($a, $b) {
            if ($a['cantidad'] !== $b['cantidad']) {
                return $b['cantidad'] - $a['cantidad'];
            }

            return strcmp($a['motivo'], $b['motivo']);
        });

        return [
            'por_etapa'       => $por_etapa,
            'abiertas'        => $abiertas,
            'ganadas'         => $ganadas,
            'perdidas'        => $perdidas,
            'motivos_perdida' => $motivos_lista,
            'agenda'          => $agenda,
        ];
    }

    /* ------------------------------------------------------------------------------------------
     | Agenda
     |----------------------------------------------------------------------------------------- */

    /**
     * La agenda: oportunidades ABIERTAS de pipelines NO archivados, repartidas por balde.
     *
     * @param array<string, mixed> $filtros owner_admin_id?, pipeline_id?
     * @param Carbon               $ahora
     *
     * @return array<string, EloquentCollection> `overdue | today | week | later | none` → oportunidades.
     */
    public function agenda(array $filtros, Carbon $ahora)
    {
        $query = PipelineOpportunity::query()
            ->select('pipeline_opportunities.*')
            ->join('pipeline_stages', 'pipeline_stages.id', '=', 'pipeline_opportunities.stage_id')
            ->join('pipelines', 'pipelines.id', '=', 'pipeline_opportunities.pipeline_id')
            ->where('pipeline_stages.type', PipelineStage::TYPE_OPEN)
            ->whereNull('pipelines.archived_at');

        if (isset($filtros['owner_admin_id']) && $filtros['owner_admin_id'] !== null) {
            $query->where('pipeline_opportunities.owner_admin_id', (int) $filtros['owner_admin_id']);
        }

        if (isset($filtros['pipeline_id']) && $filtros['pipeline_id'] !== null) {
            $query->where('pipeline_opportunities.pipeline_id', (int) $filtros['pipeline_id']);
        }

        $this->ordenar_por_proxima_accion($query);

        $oportunidades = $query->with(['stage', 'client', 'lead', 'owner', 'pipeline'])->get();

        $baldes = [];
        foreach (PipelineAgenda::BALDES_ABIERTOS as $balde) {
            $baldes[$balde] = new EloquentCollection();
        }

        foreach ($oportunidades as $op) {
            $balde = PipelineAgenda::balde($op->stage ? $op->stage->type : null, $op->next_action_at, $ahora);
            if (isset($baldes[$balde])) {
                $baldes[$balde]->push($op);
            }
        }

        return $baldes;
    }

    /* ------------------------------------------------------------------------------------------
     | Oportunidades de un sujeto
     |----------------------------------------------------------------------------------------- */

    /**
     * Las oportunidades de un cliente o de un lead, en todos los pipelines (archivados incluidos):
     * abiertas primero (por el orden de los pipelines), después las cerradas de la más reciente a la
     * más vieja.
     *
     * @param string $tipo client | lead
     * @param int    $id
     *
     * @return EloquentCollection de PipelineOpportunity (con pipeline cargado).
     */
    public function del_sujeto($tipo, $id)
    {
        $columna = $tipo === PipelineOpportunity::SUBJECT_CLIENT
            ? 'pipeline_opportunities.client_id'
            : 'pipeline_opportunities.lead_id';

        return PipelineOpportunity::query()
            ->select('pipeline_opportunities.*')
            ->leftJoin('pipeline_stages', 'pipeline_stages.id', '=', 'pipeline_opportunities.stage_id')
            ->leftJoin('pipelines', 'pipelines.id', '=', 'pipeline_opportunities.pipeline_id')
            ->where($columna, (int) $id)
            ->orderByRaw("CASE WHEN pipeline_stages.type = 'open' THEN 0 ELSE 1 END")
            ->orderByDesc('pipeline_opportunities.closed_at')
            ->orderBy('pipelines.sort_order')
            ->orderBy('pipelines.id')
            ->orderByDesc('pipeline_opportunities.id')
            ->with(['stage', 'client', 'lead', 'owner', 'pipeline'])
            ->get();
    }

    /* ------------------------------------------------------------------------------------------
     | Candidatos del alta masiva
     |----------------------------------------------------------------------------------------- */

    /**
     * Clientes o leads para el alta masiva, con lo que ya tienen en ESTE pipeline (la abierta, si
     * hay, y cuántas cerradas), para que la SPA muestre "ya está" deshabilitado.
     *
     * Clientes por nombre; leads del más nuevo al más viejo.
     *
     * @param Pipeline             $pipeline
     * @param array<string, mixed> $filtros type, q?, solo_activos?, lead_status?, limit?, offset?
     *
     * @return array{filas: EloquentCollection, abiertas: array<int, int>, cerradas: array<int, int>, total: int, has_more: bool}
     */
    public function candidatos(Pipeline $pipeline, array $filtros)
    {
        $tipo   = (string) $filtros['type'];
        $limite = isset($filtros['limit']) && $filtros['limit'] !== null ? (int) $filtros['limit'] : self::CANDIDATOS_LIMITE_DEFAULT;
        $limite = max(1, min(self::CANDIDATOS_LIMITE_MAX, $limite));
        $desde  = isset($filtros['offset']) && $filtros['offset'] !== null ? max(0, (int) $filtros['offset']) : 0;
        $texto  = self::texto_o_null(isset($filtros['q']) ? $filtros['q'] : null);

        if ($tipo === PipelineOpportunity::SUBJECT_CLIENT) {
            $query = Client::query();

            if (! empty($filtros['solo_activos'])) {
                $query->where('is_active', true);
            }

            if ($texto !== null) {
                $like = '%' . self::escapar_like($texto) . '%';
                $query->where(function ($sub) use ($like) {
                    $sub->where('name', 'like', $like)
                        ->orWhere('company_name', 'like', $like)
                        ->orWhere('phone', 'like', $like)
                        ->orWhere('email', 'like', $like);
                });
            }

            $total = (clone $query)->count();
            $filas = $query->orderBy('name')->orderBy('id')
                ->skip($desde)->take($limite)
                ->get(['id', 'name', 'company_name', 'is_active', 'phone', 'email']);
        } else {
            $query = Lead::query();

            $estado = self::texto_o_null(isset($filtros['lead_status']) ? $filtros['lead_status'] : null);
            if ($estado !== null) {
                $query->where('status', $estado);
            }

            if ($texto !== null) {
                $like = '%' . self::escapar_like($texto) . '%';
                $query->where(function ($sub) use ($like) {
                    $sub->where('company_name', 'like', $like)
                        ->orWhere('contact_name', 'like', $like)
                        ->orWhere('phone', 'like', $like)
                        ->orWhere('email', 'like', $like);
                });
            }

            $total = (clone $query)->count();
            $filas = $query->orderByDesc('id')
                ->skip($desde)->take($limite)
                ->get(['id', 'company_name', 'contact_name', 'status', 'promoted_client_id', 'phone', 'email']);
        }

        list($abiertas, $cerradas) = $this->situacion_en_el_pipeline($pipeline->id, $tipo, $filas->pluck('id')->all());

        return [
            'filas'    => $filas,
            'abiertas' => $abiertas,
            'cerradas' => $cerradas,
            'total'    => (int) $total,
            'has_more' => ($desde + $filas->count()) < $total,
        ];
    }

    /* ------------------------------------------------------------------------------------------
     | Interno
     |----------------------------------------------------------------------------------------- */

    /**
     * Los dos filtros que valen también para el resumen: responsable y tipo de sujeto.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param array<string, mixed>                  $filtros
     *
     * @return void
     */
    private function aplicar_filtros_base($query, array $filtros)
    {
        if (isset($filtros['owner_admin_id']) && $filtros['owner_admin_id'] !== null) {
            $query->where('pipeline_opportunities.owner_admin_id', (int) $filtros['owner_admin_id']);
        }

        $tipo = isset($filtros['subject_type']) ? $filtros['subject_type'] : null;
        if ($tipo === PipelineOpportunity::SUBJECT_CLIENT) {
            $query->whereNotNull('pipeline_opportunities.client_id');
        } elseif ($tipo === PipelineOpportunity::SUBJECT_LEAD) {
            $query->whereNotNull('pipeline_opportunities.lead_id');
        }
    }

    /**
     * Búsqueda por nombre del sujeto: nombre o razón social del cliente, empresa o contacto del
     * lead. Por subconsulta, sin join, para no duplicar filas.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string                                $texto
     *
     * @return void
     */
    private function aplicar_busqueda_por_sujeto($query, $texto)
    {
        $like = '%' . self::escapar_like($texto) . '%';

        $query->where(function ($sub) use ($like) {
            $sub->whereIn('pipeline_opportunities.client_id', function ($clientes) use ($like) {
                $clientes->select('id')->from('clients')
                    ->where(function ($w) use ($like) {
                        $w->where('name', 'like', $like)->orWhere('company_name', 'like', $like);
                    });
            })->orWhereIn('pipeline_opportunities.lead_id', function ($leads) use ($like) {
                $leads->select('id')->from('leads')
                    ->where(function ($w) use ($like) {
                        $w->where('company_name', 'like', $like)->orWhere('contact_name', 'like', $like);
                    });
            });
        });
    }

    /**
     * Próxima acción ascendente (sin próxima al final), después más tiempo en la etapa primero.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return void
     */
    private function ordenar_por_proxima_accion($query)
    {
        $query->orderByRaw('pipeline_opportunities.next_action_at IS NULL')
            ->orderBy('pipeline_opportunities.next_action_at')
            ->orderBy('pipeline_opportunities.stage_entered_at')
            ->orderBy('pipeline_opportunities.id');
    }

    /**
     * Cuántas oportunidades distintas entraron alguna vez a cada etapa, con los filtros base.
     *
     * @param Pipeline             $pipeline
     * @param array<string, mixed> $filtros
     *
     * @return array<int, int> `stage_id => cantidad`
     */
    private function pasaron_por_etapa(Pipeline $pipeline, array $filtros)
    {
        $query = PipelineActivity::query()
            ->select('to_stage_id', DB::raw('COUNT(DISTINCT opportunity_id) AS cantidad'))
            ->where('pipeline_id', $pipeline->id)
            ->whereIn('type', [PipelineActivity::TYPE_CREATED, PipelineActivity::TYPE_STAGE_CHANGE])
            ->whereNotNull('to_stage_id')
            ->groupBy('to_stage_id');

        $con_filtros = (isset($filtros['owner_admin_id']) && $filtros['owner_admin_id'] !== null)
            || (isset($filtros['subject_type']) && $filtros['subject_type'] !== null);

        if ($con_filtros) {
            $oportunidades = PipelineOpportunity::query()
                ->select('pipeline_opportunities.id')
                ->where('pipeline_opportunities.pipeline_id', $pipeline->id);
            $this->aplicar_filtros_base($oportunidades, $filtros);

            $query->whereIn('opportunity_id', $oportunidades->toBase());
        }

        $pasaron = [];
        foreach ($query->get() as $fila) {
            $pasaron[(int) $fila->to_stage_id] = (int) $fila->cantidad;
        }

        return $pasaron;
    }

    /**
     * Para una página de candidatos: su oportunidad abierta en el pipeline (si hay) y cuántas
     * cerradas tienen. Una consulta para toda la página.
     *
     * @param int             $pipeline_id
     * @param string          $tipo
     * @param array<int, int> $ids
     *
     * @return array{0: array<int, int>, 1: array<int, int>} [abiertas `sujeto => oportunidad`, cerradas `sujeto => cantidad`]
     */
    private function situacion_en_el_pipeline($pipeline_id, $tipo, array $ids)
    {
        if ($ids === []) {
            return [[], []];
        }

        $columna = $tipo === PipelineOpportunity::SUBJECT_CLIENT ? 'client_id' : 'lead_id';

        $filas = PipelineOpportunity::query()
            ->leftJoin('pipeline_stages', 'pipeline_stages.id', '=', 'pipeline_opportunities.stage_id')
            ->where('pipeline_opportunities.pipeline_id', $pipeline_id)
            ->whereIn('pipeline_opportunities.' . $columna, $ids)
            ->orderBy('pipeline_opportunities.id')
            ->get([
                'pipeline_opportunities.id AS oportunidad_id',
                'pipeline_opportunities.' . $columna . ' AS sujeto_id',
                'pipeline_stages.type AS tipo_de_etapa',
            ]);

        $abiertas = [];
        $cerradas = [];
        foreach ($filas as $fila) {
            $sujeto = (int) $fila->sujeto_id;

            if ($fila->tipo_de_etapa === PipelineStage::TYPE_OPEN) {
                $abiertas[$sujeto] = (int) $fila->oportunidad_id;
            } else {
                $cerradas[$sujeto] = (isset($cerradas[$sujeto]) ? $cerradas[$sujeto] : 0) + 1;
            }
        }

        return [$abiertas, $cerradas];
    }

    /**
     * Escapa los comodines de LIKE, para que un "_" o un "%" tipeado se busque literal.
     *
     * @param string $texto
     *
     * @return string
     */
    private static function escapar_like($texto)
    {
        return addcslashes($texto, '%_\\');
    }

    /**
     * Texto recortado, o null si está vacío.
     *
     * @param mixed $valor
     *
     * @return string|null
     */
    private static function texto_o_null($valor)
    {
        if ($valor === null || is_array($valor) || is_bool($valor)) {
            return null;
        }

        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }
}

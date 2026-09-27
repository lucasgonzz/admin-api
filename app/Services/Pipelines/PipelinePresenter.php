<?php

namespace App\Services\Pipelines;

use App\Helpers\AppTime;
use App\Models\Client;
use App\Models\Lead;
use App\Models\LeadPipelineStatus;
use App\Models\Pipeline;
use App\Models\PipelineActivity;
use App\Models\PipelineOpportunity;
use App\Models\PipelineStage;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

/**
 * 🔴 EL ÚNICO PRODUCTOR DEL JSON DEL CRM DE PIPELINES (misión pipelines-crm, 27/9/2026).
 *
 * Tablero, listado, agenda, ficha y la pestaña del cliente / lead usan la MISMA forma de
 * oportunidad y de actividad, y sale de acá. Si cada endpoint armara la suya, la tarjeta del
 * tablero y la ficha terminarían diciendo cosas distintas del mismo registro (clase "dos
 * productores de la misma estructura, cada uno con su consumidor", APRENDER_NO_PARCHEAR.md).
 *
 * Reglas que valen para todo lo que sale de acá:
 *  - Las fechas son hora local `Y-m-d H:i:s`, NUNCA ISO con `Z` (la SPA las reasigna a campos de
 *    fecha y un `Z` las correría tres horas).
 *  - Nada dispara una consulta por fila: la última actividad, los conteos por etapa y las etiquetas
 *    de estado de los leads salen de UNA consulta por lista.
 *  - El sujeto borrado no rompe nada: viaja como `(registro eliminado)` con `missing: true`.
 */
class PipelinePresenter
{
    /** Nombre que se muestra cuando el cliente o el lead de una oportunidad ya no existe. */
    const NOMBRE_ELIMINADO = '(registro eliminado)';

    /**
     * Memo de `slug => label` del catálogo de estados de lead, para no consultarlo por fila.
     *
     * @var array<string, string>|null
     */
    private $etiquetas_de_estado = null;

    /* ------------------------------------------------------------------------------------------
     | Enumeraciones
     |----------------------------------------------------------------------------------------- */

    /**
     * Las enumeraciones del módulo con su etiqueta en español (`GET pipelines/meta`). La SPA no
     * las escribe de nuevo: las lee de acá.
     *
     * @return array<string, array<int, array{value: string, label: string}>>
     */
    public function meta()
    {
        return [
            'stage_types'    => $this->opciones(PipelineStage::TYPE_LABELS),
            'field_types'    => $this->opciones(PipelineFieldsService::FIELD_TYPE_LABELS),
            'channels'       => $this->opciones(PipelineActivity::CHANNEL_LABELS),
            'activity_types' => $this->opciones(PipelineActivity::TYPE_LABELS),
        ];
    }

    /**
     * Estados del pipeline de LEADS, para el filtro de candidatos: `[{slug, label}]` en el orden
     * del catálogo (o los defaults si la tabla está vacía, igual que `LeadPipelineStatus`).
     *
     * Sin los de `LeadPipelineStatus::SLUGS_HIDDEN_FROM_SELECT` (hoy `mail2_enviado`), con el mismo
     * criterio que el resto del admin: siguen en el catálogo por historia, pero se sacaron "de
     * filtro y de asignación".
     *
     * @return array<int, array{slug: string, label: string}>
     */
    public function estados_de_lead()
    {
        $filas = LeadPipelineStatus::query()->orderBy('sort_order')->orderBy('id')->get(['slug', 'label']);

        $crudos = [];
        if ($filas->isEmpty()) {
            foreach (LeadPipelineStatus::DEFAULT_STATUSES as $slug => $label) {
                $crudos[] = ['slug' => (string) $slug, 'label' => (string) $label];
            }
        } else {
            foreach ($filas as $fila) {
                $crudos[] = ['slug' => (string) $fila->slug, 'label' => (string) $fila->label];
            }
        }

        $estados = [];
        foreach ($crudos as $estado) {
            if (! in_array($estado['slug'], LeadPipelineStatus::SLUGS_HIDDEN_FROM_SELECT, true)) {
                $estados[] = $estado;
            }
        }

        return $estados;
    }

    /* ------------------------------------------------------------------------------------------
     | Pipelines y etapas
     |----------------------------------------------------------------------------------------- */

    /**
     * Un pipeline con sus etapas (ordenadas) y los conteos.
     *
     * @param Pipeline $pipeline
     *
     * @return array<string, mixed>
     */
    public function pipeline(Pipeline $pipeline)
    {
        $lista = $this->pipelines(new EloquentCollection([$pipeline]));

        return $lista[0];
    }

    /**
     * Varios pipelines con una sola consulta de conteos para todos.
     *
     * `counts` sale de sumar los conteos de cada etapa según su `type`: el estado de una
     * oportunidad ES el tipo de su etapa, así que no hay otra cuenta posible.
     *
     * @param EloquentCollection $pipelines Colección de Pipeline.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pipelines(EloquentCollection $pipelines)
    {
        if ($pipelines->isEmpty()) {
            return [];
        }

        $pipelines->loadMissing('stages');

        $conteos = $this->conteos_por_etapa($pipelines->pluck('id')->all());

        $salida = [];

        foreach ($pipelines as $pipeline) {
            $etapas = [];
            $counts = ['open' => 0, 'won' => 0, 'lost' => 0, 'total' => 0];

            foreach ($pipeline->stages as $etapa) {
                $cantidad = isset($conteos[$etapa->id]) ? $conteos[$etapa->id] : 0;
                $etapas[] = $this->stage($etapa, $cantidad);

                if (isset($counts[$etapa->type])) {
                    $counts[$etapa->type] += $cantidad;
                }
                $counts['total'] += $cantidad;
            }

            $salida[] = [
                'id'           => (int) $pipeline->id,
                'name'         => (string) $pipeline->name,
                'description'  => $pipeline->description,
                'lost_reasons' => $pipeline->motivos_de_perdida(),
                'sort_order'   => (int) $pipeline->sort_order,
                'archived_at'  => self::fecha($pipeline->archived_at),
                'stages'       => $etapas,
                'counts'       => $counts,
            ];
        }

        return $salida;
    }

    /**
     * Una etapa. `opportunities_count` = oportunidades que están HOY en ella.
     *
     * @param PipelineStage $etapa
     * @param int|null      $cantidad Si ya se contó (lista); null = se cuenta acá.
     *
     * @return array<string, mixed>
     */
    public function stage(PipelineStage $etapa, $cantidad = null)
    {
        if ($cantidad === null) {
            $cantidad = PipelineOpportunity::query()->where('stage_id', $etapa->id)->count();
        }

        return [
            'id'                  => (int) $etapa->id,
            'pipeline_id'         => (int) $etapa->pipeline_id,
            'name'                => (string) $etapa->name,
            'color'               => (string) $etapa->color,
            'type'                => (string) $etapa->type,
            'sort_order'          => (int) $etapa->sort_order,
            'fields'              => self::definicion_ordenada($etapa->definicion_de_campos()),
            'opportunities_count' => (int) $cantidad,
        ];
    }

    /**
     * La definición de campos con las claves de cada campo en el orden del contrato:
     * `{key, label, type, required, agenda, options}`.
     *
     * 🔴 No es cosmético: la columna JSON de MySQL NO conserva el orden de las claves de un objeto
     * (las guarda ordenadas por largo y después alfabéticamente), así que lo que vuelve de la base
     * es `{key, type, label, agenda, options, required}`. El presenter es el único productor de la
     * forma y la devuelve siempre igual, venga de donde venga.
     *
     * @param array<int, array<string, mixed>> $definicion
     *
     * @return array<int, array<string, mixed>>
     */
    public static function definicion_ordenada(array $definicion)
    {
        $ordenada = [];

        foreach ($definicion as $campo) {
            if (! is_array($campo)) {
                continue;
            }

            $ordenada[] = [
                'key'      => isset($campo['key']) ? (string) $campo['key'] : '',
                'label'    => isset($campo['label']) ? (string) $campo['label'] : '',
                'type'     => isset($campo['type']) ? (string) $campo['type'] : '',
                'required' => ! empty($campo['required']),
                'agenda'   => ! empty($campo['agenda']),
                'options'  => isset($campo['options']) && is_array($campo['options']) ? array_values($campo['options']) : [],
            ];
        }

        return $ordenada;
    }

    /**
     * El `data` de una actividad con las claves en el orden del contrato. Misma razón que
     * `definicion_ordenada()`: la columna JSON de MySQL reordena las claves de los objetos.
     *
     *  - `created` / `stage_change`: lista de `{key, label, type, value}`.
     *  - `next_action`: `{from, to, note}`.
     *  - `owner`: `{from, to}`.
     *
     * @param string $tipo
     * @param mixed  $data
     *
     * @return mixed
     */
    private static function data_ordenada($tipo, $data)
    {
        if (! is_array($data)) {
            return $data;
        }

        if ($tipo === PipelineActivity::TYPE_CREATED || $tipo === PipelineActivity::TYPE_STAGE_CHANGE) {
            $foto = [];
            foreach ($data as $campo) {
                if (! is_array($campo)) {
                    continue;
                }
                $foto[] = [
                    'key'   => isset($campo['key']) ? (string) $campo['key'] : '',
                    'label' => isset($campo['label']) ? (string) $campo['label'] : '',
                    'type'  => isset($campo['type']) ? (string) $campo['type'] : '',
                    'value' => array_key_exists('value', $campo) ? $campo['value'] : null,
                ];
            }

            return $foto;
        }

        if ($tipo === PipelineActivity::TYPE_NEXT_ACTION) {
            return [
                'from' => array_key_exists('from', $data) ? $data['from'] : null,
                'to'   => array_key_exists('to', $data) ? $data['to'] : null,
                'note' => array_key_exists('note', $data) ? $data['note'] : null,
            ];
        }

        if ($tipo === PipelineActivity::TYPE_OWNER) {
            return [
                'from' => array_key_exists('from', $data) ? $data['from'] : null,
                'to'   => array_key_exists('to', $data) ? $data['to'] : null,
            ];
        }

        return $data;
    }

    /* ------------------------------------------------------------------------------------------
     | Oportunidades
     |----------------------------------------------------------------------------------------- */

    /**
     * Una oportunidad. Mismas opciones que `opportunities()`.
     *
     * @param PipelineOpportunity  $oportunidad
     * @param array<string, mixed> $opciones
     *
     * @return array<string, mixed>
     */
    public function opportunity(PipelineOpportunity $oportunidad, array $opciones = [])
    {
        $lista = $this->opportunities(new EloquentCollection([$oportunidad]), $opciones);

        return $lista[0];
    }

    /**
     * Una lista de oportunidades, con la última actividad de todas en UNA consulta.
     *
     * Opciones:
     *  - `contacto` (bool, default false): `phone` / `email` del sujeto. Solo en la ficha y en las
     *    respuestas de las acciones sobre UNA oportunidad; en el tablero y los listados van null.
     *  - `pipeline`: qué se agrega bajo la clave `pipeline`:
     *      null (default)       → nada;
     *      'id_nombre'          → `{id, name}` (agenda);
     *      'id_nombre_archivo'  → `{id, name, archived_at}` (pestaña del cliente / lead);
     *      'completo'           → el Pipeline entero, con etapas y conteos (ficha).
     *  - `ahora` (Carbon): el reloj para `agenda_bucket` y `days_in_stage` (default
     *    `AppTime::now()`, uno solo para toda la lista).
     *
     * @param EloquentCollection   $oportunidades Colección de PipelineOpportunity.
     * @param array<string, mixed> $opciones
     *
     * @return array<int, array<string, mixed>>
     */
    public function opportunities(EloquentCollection $oportunidades, array $opciones = [])
    {
        if ($oportunidades->isEmpty()) {
            return [];
        }

        $contacto = ! empty($opciones['contacto']);
        $modo     = isset($opciones['pipeline']) ? $opciones['pipeline'] : null;
        $ahora    = isset($opciones['ahora']) && $opciones['ahora'] instanceof Carbon ? $opciones['ahora'] : AppTime::now();

        $oportunidades->loadMissing(['stage', 'client', 'lead', 'owner']);
        if ($modo !== null) {
            $oportunidades->loadMissing('pipeline');
        }

        $ultimas = $this->ultimas_actividades($oportunidades->pluck('id')->all());

        /* El pipeline completo se arma una vez por pipeline distinto, no una por oportunidad. */
        $pipelines_completos = [];
        if ($modo === 'completo') {
            $distintos = new EloquentCollection();
            foreach ($oportunidades as $oportunidad) {
                if ($oportunidad->pipeline !== null && ! $distintos->contains('id', $oportunidad->pipeline->id)) {
                    $distintos->push($oportunidad->pipeline);
                }
            }
            foreach ($this->pipelines($distintos) as $json) {
                $pipelines_completos[$json['id']] = $json;
            }
        }

        $salida = [];

        foreach ($oportunidades as $oportunidad) {
            $ultima = isset($ultimas[$oportunidad->id]) ? $ultimas[$oportunidad->id] : null;
            $json   = $this->armar_oportunidad($oportunidad, $ultima, $contacto, $ahora);

            if ($modo !== null) {
                $json['pipeline'] = $this->pipeline_de_la_oportunidad($oportunidad, $modo, $pipelines_completos);
            }

            $salida[] = $json;
        }

        return $salida;
    }

    /* ------------------------------------------------------------------------------------------
     | Actividades
     |----------------------------------------------------------------------------------------- */

    /**
     * Una actividad del historial.
     *
     * @param PipelineActivity $actividad
     *
     * @return array<string, mixed>
     */
    public function activity(PipelineActivity $actividad)
    {
        $admin = $actividad->admin;

        return [
            'id'              => (int) $actividad->id,
            'opportunity_id'  => (int) $actividad->opportunity_id,
            'type'            => (string) $actividad->type,
            'admin'           => $admin ? ['id' => (int) $admin->id, 'name' => (string) $admin->name] : null,
            'from_stage_id'   => self::entero_o_null($actividad->from_stage_id),
            'from_stage_name' => $actividad->from_stage_name,
            'to_stage_id'     => self::entero_o_null($actividad->to_stage_id),
            'to_stage_name'   => $actividad->to_stage_name,
            'body'            => $actividad->body,
            'channel'         => $actividad->channel,
            'data'            => self::data_ordenada((string) $actividad->type, $actividad->data),
            'occurred_at'     => self::fecha($actividad->occurred_at),
            'created_at'      => self::fecha($actividad->created_at),
        ];
    }

    /**
     * Varias actividades (con su admin en una sola consulta).
     *
     * @param EloquentCollection $actividades Colección de PipelineActivity.
     *
     * @return array<int, array<string, mixed>>
     */
    public function activities(EloquentCollection $actividades)
    {
        if ($actividades->isEmpty()) {
            return [];
        }

        $actividades->loadMissing('admin');

        $salida = [];
        foreach ($actividades as $actividad) {
            $salida[] = $this->activity($actividad);
        }

        return $salida;
    }

    /* ------------------------------------------------------------------------------------------
     | Sujetos
     |----------------------------------------------------------------------------------------- */

    /**
     * Resumen de un cliente como sujeto.
     *
     * @param Client $cliente
     * @param bool   $contacto Si viajan `phone` / `email`.
     *
     * @return array<string, mixed>
     */
    public function subject_de_cliente(Client $cliente, $contacto)
    {
        return [
            'type'               => PipelineOpportunity::SUBJECT_CLIENT,
            'id'                 => (int) $cliente->id,
            'name'               => (string) $cliente->name,
            'secondary'          => self::texto_o_null($cliente->company_name),
            'is_active'          => (bool) $cliente->is_active,
            'status'             => null,
            'status_label'       => null,
            'promoted_client_id' => null,
            'phone'              => $contacto ? self::texto_o_null($cliente->phone) : null,
            'email'              => $contacto ? self::texto_o_null($cliente->email) : null,
            'missing'            => false,
        ];
    }

    /**
     * Resumen de un lead como sujeto.
     *
     * `name` = la empresa si está, si no el contacto, si no "Lead #id"; `secondary` = el contacto
     * solo cuando el nombre salió de la empresa. `is_active` es de clientes: en un lead va null.
     *
     * @param Lead $lead
     * @param bool $contacto Si viajan `phone` / `email`.
     *
     * @return array<string, mixed>
     */
    public function subject_de_lead(Lead $lead, $contacto)
    {
        $empresa        = trim((string) $lead->company_name);
        $nombre_persona = trim((string) $lead->contact_name);

        if ($empresa !== '') {
            $nombre     = $empresa;
            $secundario = $nombre_persona !== '' ? $nombre_persona : null;
        } elseif ($nombre_persona !== '') {
            $nombre     = $nombre_persona;
            $secundario = null;
        } else {
            $nombre     = 'Lead #' . $lead->id;
            $secundario = null;
        }

        $estado = self::texto_o_null($lead->status);

        return [
            'type'               => PipelineOpportunity::SUBJECT_LEAD,
            'id'                 => (int) $lead->id,
            'name'               => $nombre,
            'secondary'          => $secundario,
            'is_active'          => null,
            'status'             => $estado,
            'status_label'       => $this->etiqueta_de_estado($estado),
            'promoted_client_id' => self::entero_o_null($lead->promoted_client_id),
            'phone'              => $contacto ? self::texto_o_null($lead->phone) : null,
            'email'              => $contacto ? self::texto_o_null($lead->email) : null,
            'missing'            => false,
        ];
    }

    /**
     * El sujeto de una oportunidad cuyo cliente o lead ya no existe.
     *
     * @param string|null $tipo
     * @param int|null    $id
     *
     * @return array<string, mixed>
     */
    public function subject_eliminado($tipo, $id)
    {
        return [
            'type'               => $tipo,
            'id'                 => self::entero_o_null($id),
            'name'               => self::NOMBRE_ELIMINADO,
            'secondary'          => null,
            'is_active'          => null,
            'status'             => null,
            'status_label'       => null,
            'promoted_client_id' => null,
            'phone'              => null,
            'email'              => null,
            'missing'            => true,
        ];
    }

    /* ------------------------------------------------------------------------------------------
     | Formato
     |----------------------------------------------------------------------------------------- */

    /**
     * Una fecha como hora local `Y-m-d H:i:s`, o null. Única forma de fecha que sale del módulo.
     *
     * @param mixed $valor
     *
     * @return string|null
     */
    public static function fecha($valor)
    {
        if ($valor instanceof \DateTimeInterface) {
            return Carbon::instance($valor)->setTimezone((string) config('app.timezone'))->format('Y-m-d H:i:s');
        }

        return null;
    }

    /* ------------------------------------------------------------------------------------------
     | Interno
     |----------------------------------------------------------------------------------------- */

    /**
     * La forma Opportunity del contrato.
     *
     * @param PipelineOpportunity   $oportunidad
     * @param PipelineActivity|null $ultima
     * @param bool                  $contacto
     * @param Carbon                $ahora
     *
     * @return array<string, mixed>
     */
    private function armar_oportunidad(PipelineOpportunity $oportunidad, $ultima, $contacto, Carbon $ahora)
    {
        $etapa = $oportunidad->stage;
        $owner = $oportunidad->owner;

        $dias_en_etapa = null;
        if ($oportunidad->stage_entered_at !== null) {
            $dias_en_etapa = max(0, (int) $oportunidad->stage_entered_at->diffInDays($ahora, false));
        }

        return [
            'id'               => (int) $oportunidad->id,
            'pipeline_id'      => (int) $oportunidad->pipeline_id,
            'stage_id'         => (int) $oportunidad->stage_id,
            'stage'            => $etapa ? [
                'id'         => (int) $etapa->id,
                'name'       => (string) $etapa->name,
                'color'      => (string) $etapa->color,
                'type'       => (string) $etapa->type,
                'sort_order' => (int) $etapa->sort_order,
            ] : null,
            'subject_type'     => $oportunidad->subject_type,
            'client_id'        => self::entero_o_null($oportunidad->client_id),
            'lead_id'          => self::entero_o_null($oportunidad->lead_id),
            'subject'          => $this->sujeto_de($oportunidad, $contacto),
            'owner_admin_id'   => self::entero_o_null($oportunidad->owner_admin_id),
            'owner'            => $owner ? ['id' => (int) $owner->id, 'name' => (string) $owner->name] : null,
            'next_action_at'   => self::fecha($oportunidad->next_action_at),
            'next_action_note' => $oportunidad->next_action_note,
            'agenda_bucket'    => PipelineAgenda::balde($etapa ? $etapa->type : null, $oportunidad->next_action_at, $ahora),
            'stage_entered_at' => self::fecha($oportunidad->stage_entered_at),
            'days_in_stage'    => $dias_en_etapa,
            'closed_at'        => self::fecha($oportunidad->closed_at),
            'lost_reason'      => $oportunidad->lost_reason,
            'last_activity'    => $ultima ? [
                'id'          => (int) $ultima->id,
                'type'        => (string) $ultima->type,
                'body'        => $ultima->body,
                'admin_name'  => $ultima->admin ? (string) $ultima->admin->name : null,
                'occurred_at' => self::fecha($ultima->occurred_at),
            ] : null,
            'created_at'       => self::fecha($oportunidad->created_at),
            'updated_at'       => self::fecha($oportunidad->updated_at),
        ];
    }

    /**
     * El sujeto de una oportunidad (cliente, lead o eliminado).
     *
     * @param PipelineOpportunity $oportunidad
     * @param bool                $contacto
     *
     * @return array<string, mixed>
     */
    private function sujeto_de(PipelineOpportunity $oportunidad, $contacto)
    {
        if ($oportunidad->client_id !== null) {
            $cliente = $oportunidad->client;

            return $cliente
                ? $this->subject_de_cliente($cliente, $contacto)
                : $this->subject_eliminado(PipelineOpportunity::SUBJECT_CLIENT, $oportunidad->client_id);
        }

        if ($oportunidad->lead_id !== null) {
            $lead = $oportunidad->lead;

            return $lead
                ? $this->subject_de_lead($lead, $contacto)
                : $this->subject_eliminado(PipelineOpportunity::SUBJECT_LEAD, $oportunidad->lead_id);
        }

        return $this->subject_eliminado(null, null);
    }

    /**
     * Lo que va bajo `pipeline` según el modo.
     *
     * @param PipelineOpportunity               $oportunidad
     * @param string                            $modo
     * @param array<int, array<string, mixed>>  $completos   Pipelines completos ya armados, por id.
     *
     * @return array<string, mixed>|null
     */
    private function pipeline_de_la_oportunidad(PipelineOpportunity $oportunidad, $modo, array $completos)
    {
        $pipeline = $oportunidad->pipeline;

        if ($pipeline === null) {
            return null;
        }

        if ($modo === 'completo') {
            return isset($completos[$pipeline->id]) ? $completos[$pipeline->id] : $this->pipeline($pipeline);
        }

        $breve = ['id' => (int) $pipeline->id, 'name' => (string) $pipeline->name];

        if ($modo === 'id_nombre_archivo') {
            $breve['archived_at'] = self::fecha($pipeline->archived_at);
        }

        return $breve;
    }

    /**
     * La actividad más reciente de cada oportunidad, en UNA consulta para toda la lista.
     *
     * "Más reciente" = mayor `occurred_at`, y ante un empate el mayor `id` (el mismo orden que la
     * ficha). Se junta contra el máximo por oportunidad y el empate se resuelve en memoria (las filas
     * vienen por `id` ascendente y `keyBy` se queda con la última), en vez de una subconsulta
     * correlacionada que MySQL correría una vez por fila.
     *
     * @param array<int, int> $ids
     *
     * @return array<int, PipelineActivity> Por `opportunity_id`.
     */
    private function ultimas_actividades(array $ids)
    {
        if ($ids === []) {
            return [];
        }

        $maximos = DB::table('pipeline_activities')
            ->select('opportunity_id', DB::raw('MAX(occurred_at) AS ultima'))
            ->whereIn('opportunity_id', $ids)
            ->groupBy('opportunity_id');

        return PipelineActivity::query()
            ->with('admin:id,name')
            ->joinSub($maximos, 'ultimas', function ($join) {
                $join->on('ultimas.opportunity_id', '=', 'pipeline_activities.opportunity_id')
                    ->on('ultimas.ultima', '=', 'pipeline_activities.occurred_at');
            })
            ->select('pipeline_activities.*')
            ->orderBy('pipeline_activities.id')
            ->get()
            ->keyBy('opportunity_id')
            ->all();
    }

    /**
     * Oportunidades de hoy por etapa, para uno o varios pipelines, en una consulta.
     *
     * @param array<int, int> $pipeline_ids
     *
     * @return array<int, int> `stage_id => cantidad`
     */
    private function conteos_por_etapa(array $pipeline_ids)
    {
        if ($pipeline_ids === []) {
            return [];
        }

        $filas = PipelineOpportunity::query()
            ->select('stage_id', DB::raw('COUNT(*) AS cantidad'))
            ->whereIn('pipeline_id', $pipeline_ids)
            ->groupBy('stage_id')
            ->get();

        $conteos = [];
        foreach ($filas as $fila) {
            $conteos[(int) $fila->stage_id] = (int) $fila->cantidad;
        }

        return $conteos;
    }

    /**
     * Etiqueta de un estado de lead, con el catálogo leído una sola vez por presenter.
     *
     * @param string|null $slug
     *
     * @return string|null
     */
    private function etiqueta_de_estado($slug)
    {
        if ($slug === null) {
            return null;
        }

        if ($this->etiquetas_de_estado === null) {
            $this->etiquetas_de_estado = LeadPipelineStatus::query()->pluck('label', 'slug')->all();
        }

        if (isset($this->etiquetas_de_estado[$slug])) {
            return (string) $this->etiquetas_de_estado[$slug];
        }

        if (isset(LeadPipelineStatus::DEFAULT_STATUSES[$slug])) {
            return LeadPipelineStatus::DEFAULT_STATUSES[$slug];
        }

        return LeadPipelineStatus::humanize_slug($slug);
    }

    /**
     * `[{value, label}]` desde un mapa `valor => etiqueta`.
     *
     * @param array<string, string> $mapa
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function opciones(array $mapa)
    {
        $opciones = [];
        foreach ($mapa as $valor => $etiqueta) {
            $opciones[] = ['value' => (string) $valor, 'label' => (string) $etiqueta];
        }

        return $opciones;
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
        if ($valor === null) {
            return null;
        }

        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }

    /**
     * Entero, o null.
     *
     * @param mixed $valor
     *
     * @return int|null
     */
    private static function entero_o_null($valor)
    {
        return $valor === null ? null : (int) $valor;
    }
}

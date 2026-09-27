<?php

namespace App\Services\Pipelines;

use App\Helpers\AppTime;
use App\Models\Pipeline;
use App\Models\PipelineActivity;
use App\Models\PipelineOpportunity;
use App\Models\PipelineStage;
use Illuminate\Support\Facades\DB;

/**
 * El ABM de pipelines y etapas del CRM (misión pipelines-crm, 27/9/2026), con sus reglas.
 *
 * Las reglas que viven acá y no en el controlador, con su porqué:
 *  - 🔴 El `type` de una etapa no cambia mientras tenga oportunidades adentro. El estado de cada
 *    oportunidad SALE del tipo de su etapa: cambiarlo con gente adentro cerraría o reabriría de
 *    golpe oportunidades que nadie movió, sin actividad en el historial y con `closed_at` mintiendo.
 *  - Un pipeline tiene siempre al menos una etapa abierta: sin ella no hay dónde dar de alta ni
 *    adónde reabrir.
 *  - Una etapa abierta nueva va antes de la primera cerrada; una ganada o perdida, al final. Así el
 *    tablero queda "trabajo en curso a la izquierda, resultados a la derecha" sin que nadie ordene.
 *  - Un pipeline con oportunidades no se borra: se archiva (el historial es lo que vale).
 *
 * Los cambios de etapas toman el lock de la fila del pipeline, el mismo que toman el alta masiva y
 * el mover: así un cambio de tipo no se cruza con una oportunidad entrando a esa etapa.
 */
class PipelineConfigService
{
    /** Etapas con las que nace un pipeline creado sin etapas. */
    const ETAPAS_POR_DEFECTO = [
        ['name' => 'Por contactar', 'type' => PipelineStage::TYPE_OPEN, 'color' => '#adb5bd'],
        ['name' => 'Ganado',        'type' => PipelineStage::TYPE_WON,  'color' => '#198754'],
        ['name' => 'Perdido',       'type' => PipelineStage::TYPE_LOST, 'color' => '#dc3545'],
    ];

    /**
     * @var PipelineFieldsService
     */
    private $campos;

    /**
     * @param PipelineFieldsService $campos
     */
    public function __construct(PipelineFieldsService $campos)
    {
        $this->campos = $campos;
    }

    /* ------------------------------------------------------------------------------------------
     | Pipelines
     |----------------------------------------------------------------------------------------- */

    /**
     * Crea un pipeline. Sin `stages` nace con "Por contactar" / "Ganado" / "Perdido"; con `stages`
     * exige al menos una abierta y respeta el orden en que vinieron.
     *
     * @param array<string, mixed> $datos    Ya validados en forma: name, description?, lost_reasons?, stages?.
     * @param int|null             $admin_id Quién lo crea.
     *
     * @return Pipeline Recién creado, con `stages` cargadas.
     *
     * @throws PipelineRuleException
     */
    public function crear_pipeline(array $datos, $admin_id)
    {
        $etapas = $this->etapas_para_crear(array_key_exists('stages', $datos) ? $datos['stages'] : null);

        return DB::transaction(function () use ($datos, $admin_id, $etapas) {
            $pipeline = Pipeline::create([
                'name'                => trim((string) $datos['name']),
                'description'         => self::texto_o_null(isset($datos['description']) ? $datos['description'] : null),
                'lost_reasons'        => self::motivos(isset($datos['lost_reasons']) ? $datos['lost_reasons'] : []),
                'sort_order'          => ((int) Pipeline::query()->max('sort_order')) + 1,
                'created_by_admin_id' => $admin_id,
            ]);

            foreach ($etapas as $orden => $etapa) {
                PipelineStage::create([
                    'pipeline_id' => $pipeline->id,
                    'name'        => $etapa['name'],
                    'color'       => $etapa['color'],
                    'type'        => $etapa['type'],
                    'sort_order'  => $orden,
                    'fields'      => $etapa['fields'],
                ]);
            }

            return $pipeline->fresh('stages');
        });
    }

    /**
     * Edita nombre, descripción, motivos de pérdida y/o el archivado. Solo toca lo que vino.
     *
     * @param Pipeline             $pipeline
     * @param array<string, mixed> $datos Ya validados en forma.
     *
     * @return Pipeline Recargado, con `stages`.
     */
    public function actualizar_pipeline(Pipeline $pipeline, array $datos)
    {
        if (array_key_exists('name', $datos) && $datos['name'] !== null) {
            $pipeline->name = trim((string) $datos['name']);
        }

        if (array_key_exists('description', $datos)) {
            $pipeline->description = self::texto_o_null($datos['description']);
        }

        if (array_key_exists('lost_reasons', $datos)) {
            $pipeline->lost_reasons = self::motivos($datos['lost_reasons']);
        }

        if (array_key_exists('archived', $datos) && $datos['archived'] !== null) {
            if ($datos['archived']) {
                /* Archivar dos veces no corre la fecha: vale la primera. */
                if ($pipeline->archived_at === null) {
                    $pipeline->archived_at = AppTime::now();
                }
            } else {
                $pipeline->archived_at = null;
            }
        }

        $pipeline->save();

        return $pipeline->fresh('stages');
    }

    /**
     * Borra un pipeline vacío (con sus etapas). Con oportunidades, 422: se archiva.
     *
     * @param Pipeline $pipeline
     *
     * @return void
     *
     * @throws PipelineRuleException
     */
    public function borrar_pipeline(Pipeline $pipeline)
    {
        DB::transaction(function () use ($pipeline) {
            Pipeline::query()->whereKey($pipeline->id)->lockForUpdate()->first();

            if (PipelineOpportunity::query()->where('pipeline_id', $pipeline->id)->exists()) {
                throw new PipelineRuleException('El pipeline tiene oportunidades: archivalo en vez de borrarlo.');
            }

            /* Sin oportunidades no debería haber actividades; se borran igual por si quedó alguna
               huérfana, para no dejar filas apuntando a un pipeline que ya no existe. */
            PipelineActivity::query()->where('pipeline_id', $pipeline->id)->delete();
            PipelineStage::query()->where('pipeline_id', $pipeline->id)->delete();
            $pipeline->delete();
        });
    }

    /**
     * Reordena las etapas. `$ids` tiene que ser exactamente el conjunto de etapas del pipeline,
     * cada una una vez: un orden parcial dejaría etapas con posiciones viejas mezcladas con nuevas.
     *
     * @param Pipeline        $pipeline
     * @param array<int, int> $ids En el orden nuevo.
     *
     * @return Pipeline Recargado, con `stages`.
     *
     * @throws PipelineRuleException
     */
    public function ordenar_etapas(Pipeline $pipeline, array $ids)
    {
        return DB::transaction(function () use ($pipeline, $ids) {
            Pipeline::query()->whereKey($pipeline->id)->lockForUpdate()->first();

            $actuales = PipelineStage::query()->where('pipeline_id', $pipeline->id)->pluck('id')->map(function ($id) {
                return (int) $id;
            })->all();

            $pedidos = array_map('intval', array_values($ids));

            $ordenados_actuales = $actuales;
            $ordenados_pedidos  = $pedidos;
            sort($ordenados_actuales);
            sort($ordenados_pedidos);

            if ($ordenados_actuales !== $ordenados_pedidos) {
                throw new PipelineRuleException('Mandá todas las etapas del pipeline, cada una una sola vez.');
            }

            foreach ($pedidos as $orden => $id) {
                PipelineStage::query()->whereKey($id)->update(['sort_order' => $orden]);
            }

            return $pipeline->fresh('stages');
        });
    }

    /* ------------------------------------------------------------------------------------------
     | Etapas
     |----------------------------------------------------------------------------------------- */

    /**
     * Agrega una etapa. Abierta → antes de la primera cerrada; ganada o perdida → al final. Se
     * renumeran todas para que el orden quede contiguo.
     *
     * @param Pipeline             $pipeline
     * @param array<string, mixed> $datos Ya validados en forma: name, color?, type?, fields?.
     *
     * @return PipelineStage
     *
     * @throws PipelineRuleException
     */
    public function crear_etapa(Pipeline $pipeline, array $datos)
    {
        $tipo   = isset($datos['type']) && $datos['type'] !== null ? (string) $datos['type'] : PipelineStage::TYPE_OPEN;
        $campos = $this->campos->normalizar_definicion(isset($datos['fields']) ? $datos['fields'] : null, 'fields');

        return DB::transaction(function () use ($pipeline, $datos, $tipo, $campos) {
            Pipeline::query()->whereKey($pipeline->id)->lockForUpdate()->first();

            $etapas = PipelineStage::query()
                ->where('pipeline_id', $pipeline->id)
                ->orderBy('sort_order')->orderBy('id')
                ->get()
                ->all();

            $posicion = count($etapas);
            if ($tipo === PipelineStage::TYPE_OPEN) {
                foreach ($etapas as $indice => $etapa) {
                    if ($etapa->is_closed()) {
                        $posicion = $indice;
                        break;
                    }
                }
            }

            $nueva = PipelineStage::create([
                'pipeline_id' => $pipeline->id,
                'name'        => trim((string) $datos['name']),
                'color'       => self::color(isset($datos['color']) ? $datos['color'] : null),
                'type'        => $tipo,
                'sort_order'  => $posicion,
                'fields'      => $campos,
            ]);

            array_splice($etapas, $posicion, 0, [$nueva]);

            foreach ($etapas as $orden => $etapa) {
                if ((int) $etapa->sort_order !== $orden) {
                    $etapa->sort_order = $orden;
                    $etapa->save();
                }
            }

            return $nueva->fresh();
        });
    }

    /**
     * Edita una etapa: nombre, color, tipo y/o campos. Solo toca lo que vino.
     *
     * @param PipelineStage        $etapa
     * @param array<string, mixed> $datos Ya validados en forma.
     *
     * @return PipelineStage Recargada.
     *
     * @throws PipelineRuleException
     */
    public function actualizar_etapa(PipelineStage $etapa, array $datos)
    {
        $campos = null;
        if (array_key_exists('fields', $datos)) {
            $campos = $this->campos->normalizar_definicion($datos['fields'], 'fields');
        }

        return DB::transaction(function () use ($etapa, $datos, $campos) {
            Pipeline::query()->whereKey($etapa->pipeline_id)->lockForUpdate()->first();

            $etapa = PipelineStage::query()->whereKey($etapa->id)->first();
            if ($etapa === null) {
                throw PipelineRuleException::no_existe('La etapa');
            }

            $tipo_nuevo = array_key_exists('type', $datos) && $datos['type'] !== null ? (string) $datos['type'] : null;

            if ($tipo_nuevo !== null && $tipo_nuevo !== $etapa->type) {
                if (PipelineOpportunity::query()->where('stage_id', $etapa->id)->exists()) {
                    throw new PipelineRuleException('No se puede cambiar el tipo de una etapa con oportunidades adentro: el estado de cada oportunidad sale de su etapa. Movelas a otra etapa primero.');
                }

                if ($etapa->is_open() && ! $this->hay_otra_abierta($etapa)) {
                    throw new PipelineRuleException('Es la única etapa abierta del pipeline: un pipeline necesita al menos una etapa abierta.');
                }

                $etapa->type = $tipo_nuevo;
            }

            if (array_key_exists('name', $datos) && $datos['name'] !== null) {
                $etapa->name = trim((string) $datos['name']);
            }

            if (array_key_exists('color', $datos) && $datos['color'] !== null) {
                $etapa->color = self::color($datos['color']);
            }

            if ($campos !== null) {
                $etapa->fields = $campos;
            }

            $etapa->save();

            return $etapa->fresh();
        });
    }

    /**
     * Borra una etapa vacía. Con oportunidades adentro, o si es la última abierta, 422.
     *
     * @param PipelineStage $etapa
     *
     * @return void
     *
     * @throws PipelineRuleException
     */
    public function borrar_etapa(PipelineStage $etapa)
    {
        DB::transaction(function () use ($etapa) {
            Pipeline::query()->whereKey($etapa->pipeline_id)->lockForUpdate()->first();

            /* Se relee con el lock tomado: el tipo pudo cambiar entre que se buscó y ahora. */
            $etapa = PipelineStage::query()->whereKey($etapa->id)->first();
            if ($etapa === null) {
                throw PipelineRuleException::no_existe('La etapa');
            }

            if (PipelineOpportunity::query()->where('stage_id', $etapa->id)->exists()) {
                throw new PipelineRuleException('La etapa tiene oportunidades: movelas a otra etapa antes de borrarla.');
            }

            if ($etapa->is_open() && ! $this->hay_otra_abierta($etapa)) {
                throw new PipelineRuleException('Es la única etapa abierta del pipeline: un pipeline necesita al menos una etapa abierta.');
            }

            /* Las actividades que la nombran quedan: guardan la foto del nombre, no una referencia. */
            $etapa->delete();
        });
    }

    /* ------------------------------------------------------------------------------------------
     | Interno
     |----------------------------------------------------------------------------------------- */

    /**
     * Las etapas con las que nace un pipeline, validadas y normalizadas.
     *
     * @param mixed $crudas `stages` del payload (ya validado en forma) o null.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws PipelineRuleException
     */
    private function etapas_para_crear($crudas)
    {
        if ($crudas === null) {
            $etapas = [];
            foreach (self::ETAPAS_POR_DEFECTO as $etapa) {
                $etapa['fields'] = [];
                $etapas[] = $etapa;
            }

            return $etapas;
        }

        $etapas   = [];
        $errores  = [];
        $abiertas = 0;

        foreach (array_values((array) $crudas) as $indice => $cruda) {
            try {
                $campos = $this->campos->normalizar_definicion(
                    isset($cruda['fields']) ? $cruda['fields'] : null,
                    'stages.' . $indice . '.fields'
                );
            } catch (PipelineRuleException $e) {
                $errores = array_merge($errores, $e->errors());
                continue;
            }

            $tipo = isset($cruda['type']) && $cruda['type'] !== null ? (string) $cruda['type'] : PipelineStage::TYPE_OPEN;
            if ($tipo === PipelineStage::TYPE_OPEN) {
                $abiertas++;
            }

            $etapas[] = [
                'name'   => trim((string) $cruda['name']),
                'type'   => $tipo,
                'color'  => self::color(isset($cruda['color']) ? $cruda['color'] : null),
                'fields' => $campos,
            ];
        }

        if ($errores !== []) {
            throw PipelineRuleException::validacion($errores);
        }

        if ($abiertas === 0) {
            throw PipelineRuleException::validacion([
                'stages' => ['El pipeline necesita al menos una etapa abierta.'],
            ]);
        }

        return $etapas;
    }

    /**
     * Si el pipeline de `$etapa` tiene OTRA etapa abierta además de ella.
     *
     * @param PipelineStage $etapa
     *
     * @return bool
     */
    private function hay_otra_abierta(PipelineStage $etapa)
    {
        return PipelineStage::query()
            ->where('pipeline_id', $etapa->pipeline_id)
            ->where('type', PipelineStage::TYPE_OPEN)
            ->where('id', '!=', $etapa->id)
            ->exists();
    }

    /**
     * Motivos de pérdida normalizados: texto recortado, sin vacíos ni repetidos.
     *
     * @param mixed $crudos
     *
     * @return array<int, string>
     */
    private static function motivos($crudos)
    {
        $motivos = [];

        foreach ((array) $crudos as $crudo) {
            if (! is_string($crudo) && ! is_int($crudo) && ! is_float($crudo)) {
                continue;
            }

            $texto = trim((string) $crudo);

            if ($texto !== '' && ! in_array($texto, $motivos, true)) {
                $motivos[] = $texto;
            }
        }

        return $motivos;
    }

    /**
     * Color en minúsculas, o el default si no vino.
     *
     * @param mixed $color Ya validado como #rrggbb, o null.
     *
     * @return string
     */
    private static function color($color)
    {
        if (! is_string($color) || trim($color) === '') {
            return PipelineStage::DEFAULT_COLOR;
        }

        return strtolower(trim($color));
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
}

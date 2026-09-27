<?php

namespace App\Services\Pipelines;

use App\Helpers\AppTime;
use App\Models\Admin;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Pipeline;
use App\Models\PipelineActivity;
use App\Models\PipelineOpportunity;
use App\Models\PipelineStage;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Las escrituras sobre oportunidades del CRM (misión pipelines-crm, 27/9/2026): alta masiva,
 * mover de etapa, notas, próxima acción, responsable y borrar. Toda regla de negocio del módulo
 * vive acá (o en `PipelineConfigService` para el ABM), no en los controladores.
 *
 * Tres invariantes que sostiene esta clase y que no hay que "simplificar":
 *
 * 1. 🔴 UNA SOLA OPORTUNIDAD ABIERTA POR SUJETO Y PIPELINE. MySQL no tiene índices únicos parciales
 *    y "abierta" depende del tipo de la etapa, así que no hay índice que lo garantice. Lo garantiza
 *    el lock de la fila del pipeline (`lockForUpdate()`), que toman el alta masiva y el mover antes
 *    de mirar si ya hay otra abierta: dos admins agregando al mismo cliente a la vez se serializan
 *    y el segundo ve la oportunidad del primero.
 *
 * 2. 🔴 EL ESTADO SALE DE LA ETAPA. Mover a una ganada / perdida cierra (`closed_at`) y limpia la
 *    próxima acción; volver a una abierta reabre y limpia `closed_at` y `lost_reason`. No hay otra
 *    columna que diga "abierta": si la hubiera, algún día diría otra cosa que la etapa.
 *
 * 3. 🔴 MOVER + NOTA ES UNA SOLA ACCIÓN, en una transacción, con UNA actividad `stage_change` que
 *    guarda la foto de lo cargado. La respuesta es la oportunidad RECARGADA de la base, no la
 *    instancia en memoria (clase "relación de Eloquent que queda vieja en memoria").
 *
 * 🔴 Este módulo NO manda ningún mensaje: ni WhatsApp, ni mail, ni nada. Solo registra.
 */
class PipelineOpportunityService
{
    /** Largo máximo de una nota y de la nota que acompaña un movimiento. */
    const MAX_NOTA = 5000;

    /** Largo máximo del motivo de pérdida y de la nota de la próxima acción (columnas string 255). */
    const MAX_CORTO = 255;

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
     | Alta masiva
     |----------------------------------------------------------------------------------------- */

    /**
     * Da de alta una oportunidad por sujeto en la etapa inicial.
     *
     * - Etapa inicial: la que vino (tiene que ser una abierta de ESTE pipeline) o, si no vino, la
     *   primera abierta por orden.
     * - Responsable: el que vino (puede ser null = sin responsable) o, si la clave no vino, el
     *   admin logueado. Esa distinción la resuelve el controlador y llega acá ya decidida.
     * - Cada alta deja una actividad `created` con la etapa inicial y la nota.
     * - Salteados: `not_found` (el cliente / lead no existe) y `already_open` (ya tiene una
     *   abierta en este pipeline; también el segundo de un sujeto repetido en el mismo pedido).
     *
     * @param Pipeline                                 $pipeline
     * @param array<int, array{type: string, id: int}> $sujetos
     * @param int|null                                 $etapa_id       Null = primera abierta.
     * @param int|null                                 $owner_admin_id Ya resuelto (null = sin responsable).
     * @param string|null                              $nota
     * @param int|null                                 $admin_id       Quién hace el alta.
     *
     * @return array{creadas: array<int, int>, salteados: array<int, array{type: string, id: int, reason: string}>}
     *
     * @throws PipelineRuleException
     */
    public function alta_masiva(Pipeline $pipeline, array $sujetos, $etapa_id, $owner_admin_id, $nota, $admin_id)
    {
        $nota = self::texto_o_null($nota);

        return DB::transaction(function () use ($pipeline, $sujetos, $etapa_id, $owner_admin_id, $nota, $admin_id) {
            /* 🔴 El lock que serializa "¿ya tiene una abierta?" (ver el docblock de la clase). */
            Pipeline::query()->whereKey($pipeline->id)->lockForUpdate()->first();

            $etapa = $this->etapa_inicial($pipeline, $etapa_id);

            $ids_clientes = [];
            $ids_leads    = [];
            foreach ($sujetos as $sujeto) {
                if ($sujeto['type'] === PipelineOpportunity::SUBJECT_CLIENT) {
                    $ids_clientes[] = (int) $sujeto['id'];
                } else {
                    $ids_leads[] = (int) $sujeto['id'];
                }
            }

            $existentes = [];
            if ($ids_clientes !== []) {
                foreach (Client::query()->whereIn('id', array_unique($ids_clientes))->pluck('id') as $id) {
                    $existentes[PipelineOpportunity::SUBJECT_CLIENT . ':' . (int) $id] = true;
                }
            }
            if ($ids_leads !== []) {
                foreach (Lead::query()->whereIn('id', array_unique($ids_leads))->pluck('id') as $id) {
                    $existentes[PipelineOpportunity::SUBJECT_LEAD . ':' . (int) $id] = true;
                }
            }

            $abiertas = $this->claves_abiertas($pipeline->id, $ids_clientes, $ids_leads);

            $ahora     = AppTime::now();
            $creadas   = [];
            $salteados = [];

            foreach ($sujetos as $sujeto) {
                $tipo  = (string) $sujeto['type'];
                $id    = (int) $sujeto['id'];
                $clave = $tipo . ':' . $id;

                if (! isset($existentes[$clave])) {
                    $salteados[] = ['type' => $tipo, 'id' => $id, 'reason' => 'not_found'];
                    continue;
                }

                if (isset($abiertas[$clave])) {
                    $salteados[] = ['type' => $tipo, 'id' => $id, 'reason' => 'already_open'];
                    continue;
                }

                $oportunidad = PipelineOpportunity::create([
                    'pipeline_id'         => $pipeline->id,
                    'stage_id'            => $etapa->id,
                    'client_id'           => $tipo === PipelineOpportunity::SUBJECT_CLIENT ? $id : null,
                    'lead_id'             => $tipo === PipelineOpportunity::SUBJECT_LEAD ? $id : null,
                    'owner_admin_id'      => $owner_admin_id,
                    'next_action_at'      => null,
                    'next_action_note'    => null,
                    'stage_entered_at'    => $ahora,
                    'closed_at'           => null,
                    'lost_reason'         => null,
                    'created_by_admin_id' => $admin_id,
                ]);

                PipelineActivity::create([
                    'opportunity_id'  => $oportunidad->id,
                    'pipeline_id'     => $pipeline->id,
                    'admin_id'        => $admin_id,
                    'type'            => PipelineActivity::TYPE_CREATED,
                    'from_stage_id'   => null,
                    'from_stage_name' => null,
                    'to_stage_id'     => $etapa->id,
                    'to_stage_name'   => $etapa->name,
                    'body'            => $nota,
                    'channel'         => null,
                    'data'            => [],
                    'occurred_at'     => $ahora,
                ]);

                $abiertas[$clave] = true;
                $creadas[]        = (int) $oportunidad->id;
            }

            return ['creadas' => $creadas, 'salteados' => $salteados];
        });
    }

    /* ------------------------------------------------------------------------------------------
     | Mover
     |----------------------------------------------------------------------------------------- */

    /**
     * Mueve una oportunidad de etapa, con sus campos y su nota, en una sola acción.
     *
     * Reglas (el orden importa: primero lo que invalida el pedido entero, después los campos):
     *  1. La etapa tiene que ser de ESTE pipeline; la misma etapa → 422 "Ya está en esa etapa".
     *  2. Los `fields` se validan contra la definición de la etapa DESTINO.
     *  3. Destino perdida → `lost_reason` obligatorio.
     *  4. Reabrir (destino abierta desde una cerrada) → 422 si el sujeto ya tiene OTRA abierta en
     *     el pipeline. Si reabre: `closed_at` y `lost_reason` en null.
     *  5. Destino ganada / perdida: `closed_at = ahora`, próxima acción en null (lo que venga en el
     *     payload se ignora), `lost_reason` el del payload (ganada → null).
     *  6. Destino abierta: si la etapa tiene campo agenda y vino con valor, ese valor es la próxima
     *     acción (nota: la del payload o el nombre de la etapa). Si no, y el payload TRAE la clave
     *     `next_action_at` (aunque sea null), se aplica con `next_action_note`; si no la trae, se
     *     conserva la que tenía.
     *  7. `stage_id` y `stage_entered_at = ahora`, y una actividad `stage_change` con la foto.
     *
     * @param PipelineOpportunity  $oportunidad
     * @param array<string, mixed> $datos    stage_id, fields?, note?, lost_reason?, next_action_at?, next_action_note?
     * @param int|null             $admin_id
     *
     * @return array{0: PipelineOpportunity, 1: PipelineActivity} Recargadas de la base.
     *
     * @throws PipelineRuleException
     */
    public function mover(PipelineOpportunity $oportunidad, array $datos, $admin_id)
    {
        return DB::transaction(function () use ($oportunidad, $datos, $admin_id) {
            /* Mismo lock que el alta masiva: la reapertura también mira "¿hay otra abierta?". */
            Pipeline::query()->whereKey($oportunidad->pipeline_id)->lockForUpdate()->first();

            $op = PipelineOpportunity::query()->whereKey($oportunidad->id)->lockForUpdate()->first();
            if ($op === null) {
                throw PipelineRuleException::no_existe('La oportunidad');
            }

            $destino = PipelineStage::query()
                ->where('pipeline_id', $op->pipeline_id)
                ->whereKey((int) $datos['stage_id'])
                ->first();

            if ($destino === null) {
                throw new PipelineRuleException('La etapa elegida no es de este pipeline.');
            }

            if ((int) $destino->id === (int) $op->stage_id) {
                throw new PipelineRuleException('Ya está en esa etapa.');
            }

            /* Campos, motivo y fecha juntan sus errores en un solo 422. */
            $errores = [];
            $valores = $this->campos->valores_o_errores($destino, array_key_exists('fields', $datos) ? $datos['fields'] : null, $errores);

            $motivo = null;
            if ($destino->type === PipelineStage::TYPE_LOST) {
                $motivo = self::texto_o_null(isset($datos['lost_reason']) ? $datos['lost_reason'] : null);
                if ($motivo === null) {
                    $errores['lost_reason'][] = 'Para pasar a «' . $destino->name . '» elegí el motivo de pérdida.';
                } elseif (mb_strlen($motivo) > self::MAX_CORTO) {
                    $errores['lost_reason'][] = 'El motivo de pérdida no puede tener más de ' . self::MAX_CORTO . ' caracteres.';
                }
            }

            $trae_proxima = array_key_exists('next_action_at', $datos);
            $proxima      = null;
            if ($trae_proxima && $datos['next_action_at'] !== null) {
                $proxima = PipelineFieldsService::parsear_fecha_de_la_spa($datos['next_action_at']);
                if ($proxima === null) {
                    $errores['next_action_at'][] = 'La fecha de la próxima acción tiene que tener formato AAAA-MM-DD o AAAA-MM-DD HH:MM.';
                }
            }

            if ($errores !== []) {
                throw PipelineRuleException::validacion($errores);
            }

            $origen = PipelineStage::query()->whereKey($op->stage_id)->first();
            $reabre = $destino->is_open() && $origen !== null && $origen->is_closed();

            if ($reabre) {
                $otra = $this->otra_abierta($op);
                if ($otra !== null) {
                    throw new PipelineRuleException(
                        'No se puede reabrir: ' . ($op->client_id !== null ? 'el cliente' : 'el lead')
                        . ' ya tiene otra oportunidad abierta en este pipeline (#' . $otra . ').'
                    );
                }
            }

            $ahora = AppTime::now();

            if ($destino->is_closed()) {
                $op->closed_at        = $ahora;
                $op->next_action_at   = null;
                $op->next_action_note = null;
                $op->lost_reason      = $destino->type === PipelineStage::TYPE_LOST ? $motivo : null;
            } else {
                if ($reabre) {
                    $op->closed_at = null;
                }
                $op->lost_reason = null;

                $agenda       = $this->campos->valor_de_agenda($destino, $valores);
                $nota_proxima = self::texto_o_null(isset($datos['next_action_note']) ? $datos['next_action_note'] : null);

                if ($agenda !== null) {
                    $op->next_action_at   = $agenda;
                    $op->next_action_note = $nota_proxima !== null ? $nota_proxima : $destino->name;
                } elseif ($trae_proxima) {
                    $op->next_action_at   = $proxima;
                    $op->next_action_note = $nota_proxima;
                }
            }

            $origen_id     = $op->stage_id;
            $origen_nombre = $origen !== null ? $origen->name : null;

            $op->stage_id         = $destino->id;
            $op->stage_entered_at = $ahora;
            $op->save();

            $actividad = PipelineActivity::create([
                'opportunity_id'  => $op->id,
                'pipeline_id'     => $op->pipeline_id,
                'admin_id'        => $admin_id,
                'type'            => PipelineActivity::TYPE_STAGE_CHANGE,
                'from_stage_id'   => $origen_id,
                'from_stage_name' => $origen_nombre,
                'to_stage_id'     => $destino->id,
                'to_stage_name'   => $destino->name,
                'body'            => self::texto_o_null(isset($datos['note']) ? $datos['note'] : null),
                'channel'         => null,
                'data'            => $this->campos->foto($destino, $valores),
                'occurred_at'     => $ahora,
            ]);

            return [$op->fresh(), $actividad->fresh()];
        });
    }

    /* ------------------------------------------------------------------------------------------
     | Notas
     |----------------------------------------------------------------------------------------- */

    /**
     * Agrega una nota suelta al historial (con canal y fecha opcionales).
     *
     * La fecha puede ser anterior a ahora (la llamada fue a la mañana y se carga a la tarde), pero no
     * futura: una nota cuenta algo que ya pasó. Se tolera hasta el final del minuto corriente, porque
     * la SPA manda minutos y "ahora" en la pantalla puede ser el minuto que el servidor todavía no
     * terminó.
     *
     * @param PipelineOpportunity $oportunidad
     * @param string              $cuerpo
     * @param string|null         $canal
     * @param string|null         $ocurrio  `Y-m-d H:i` (null = ahora).
     * @param int|null            $admin_id
     *
     * @return PipelineActivity Recargada.
     *
     * @throws PipelineRuleException
     */
    public function agregar_nota(PipelineOpportunity $oportunidad, $cuerpo, $canal, $ocurrio, $admin_id)
    {
        $ahora   = AppTime::now();
        $momento = $ahora;

        if ($ocurrio !== null) {
            $momento = PipelineFieldsService::parsear_fecha_de_la_spa($ocurrio);

            if ($momento === null) {
                throw PipelineRuleException::validacion(['occurred_at' => ['La fecha de la nota tiene que tener formato AAAA-MM-DD HH:MM.']]);
            }

            if ($momento->gt($ahora->copy()->endOfMinute())) {
                throw PipelineRuleException::validacion(['occurred_at' => ['La fecha de la nota no puede ser futura.']]);
            }
        }

        return DB::transaction(function () use ($oportunidad, $cuerpo, $canal, $momento, $admin_id) {
            $actividad = PipelineActivity::create([
                'opportunity_id'  => $oportunidad->id,
                'pipeline_id'     => $oportunidad->pipeline_id,
                'admin_id'        => $admin_id,
                'type'            => PipelineActivity::TYPE_NOTE,
                'from_stage_id'   => null,
                'from_stage_name' => null,
                'to_stage_id'     => null,
                'to_stage_name'   => null,
                'body'            => trim((string) $cuerpo),
                'channel'         => self::texto_o_null($canal),
                'data'            => null,
                'occurred_at'     => $momento,
            ]);

            /* La oportunidad "se movió" (tiene algo nuevo): su updated_at lo refleja. */
            $oportunidad->touch();

            return $actividad->fresh();
        });
    }

    /* ------------------------------------------------------------------------------------------
     | Próxima acción y responsable
     |----------------------------------------------------------------------------------------- */

    /**
     * Cambia el responsable y/o la próxima acción. Solo toca las claves que vinieron.
     *
     * Deja una actividad `owner` si cambió el responsable (`data = {from, to}` con los NOMBRES,
     * como foto) y una `next_action` si cambió la fecha o la nota
     * (`data = {from, to, note}` con las fechas en `Y-m-d H:i:s`). Si no cambió nada, no deja nada.
     *
     * Una oportunidad cerrada no lleva próxima acción: ponerle una es 422 (para retomarla se la
     * mueve a una etapa abierta, que es lo que la reabre).
     *
     * @param PipelineOpportunity  $oportunidad
     * @param array<string, mixed> $datos    owner_admin_id?, next_action_at?, next_action_note?
     * @param int|null             $admin_id Quién hace el cambio.
     *
     * @return array{0: PipelineOpportunity, 1: array<int, PipelineActivity>} La oportunidad
     *         recargada y las actividades nuevas, de la más nueva a la más vieja (el orden de la ficha).
     *
     * @throws PipelineRuleException
     */
    public function actualizar(PipelineOpportunity $oportunidad, array $datos, $admin_id)
    {
        $trae_fecha = array_key_exists('next_action_at', $datos);
        $trae_nota  = array_key_exists('next_action_note', $datos);

        $fecha = null;
        if ($trae_fecha && $datos['next_action_at'] !== null) {
            $fecha = PipelineFieldsService::parsear_fecha_de_la_spa($datos['next_action_at']);
            if ($fecha === null) {
                throw PipelineRuleException::validacion(['next_action_at' => ['La fecha de la próxima acción tiene que tener formato AAAA-MM-DD o AAAA-MM-DD HH:MM.']]);
            }
        }

        $nota = $trae_nota ? self::texto_o_null($datos['next_action_note']) : null;

        return DB::transaction(function () use ($oportunidad, $datos, $admin_id, $trae_fecha, $trae_nota, $fecha, $nota) {
            $op = PipelineOpportunity::query()->whereKey($oportunidad->id)->lockForUpdate()->first();
            if ($op === null) {
                throw PipelineRuleException::no_existe('La oportunidad');
            }

            $etapa   = PipelineStage::query()->whereKey($op->stage_id)->first();
            $cerrada = $etapa !== null && $etapa->is_closed();

            if ($cerrada && (($trae_fecha && $fecha !== null) || ($trae_nota && $nota !== null))) {
                throw new PipelineRuleException('La oportunidad está cerrada y no lleva próxima acción. Para retomarla, movela a una etapa abierta.');
            }

            $ahora = AppTime::now();

            /* Responsable. */
            $cambio_de_responsable = null;
            if (array_key_exists('owner_admin_id', $datos)) {
                $nuevo = $datos['owner_admin_id'] === null ? null : (int) $datos['owner_admin_id'];

                if ($nuevo !== $op->owner_admin_id) {
                    $cambio_de_responsable = [
                        'from' => $this->nombre_de_admin($op->owner_admin_id),
                        'to'   => $this->nombre_de_admin($nuevo),
                    ];
                    $op->owner_admin_id = $nuevo;
                }
            }

            /* Próxima acción. */
            $fecha_antes = PipelinePresenter::fecha($op->next_action_at);
            $nota_antes  = $op->next_action_note;

            if ($trae_fecha) {
                $op->next_action_at = $fecha;
            }
            if ($trae_nota) {
                $op->next_action_note = $nota;
            }

            $fecha_despues = PipelinePresenter::fecha($op->next_action_at);
            $nota_despues  = $op->next_action_note;

            $cambio_de_proxima = null;
            if ($fecha_antes !== $fecha_despues || $nota_antes !== $nota_despues) {
                $cambio_de_proxima = ['from' => $fecha_antes, 'to' => $fecha_despues, 'note' => $nota_despues];
            }

            $op->save();

            $nuevas = [];

            if ($cambio_de_responsable !== null) {
                $nuevas[] = $this->actividad_simple($op, PipelineActivity::TYPE_OWNER, $cambio_de_responsable, $admin_id, $ahora);
            }

            if ($cambio_de_proxima !== null) {
                $nuevas[] = $this->actividad_simple($op, PipelineActivity::TYPE_NEXT_ACTION, $cambio_de_proxima, $admin_id, $ahora);
            }

            /* De la más nueva a la más vieja, como las muestra la ficha. */
            $nuevas = array_reverse($nuevas);

            return [$op->fresh(), $nuevas];
        });
    }

    /* ------------------------------------------------------------------------------------------
     | Borrar
     |----------------------------------------------------------------------------------------- */

    /**
     * Borra una oportunidad y todo su historial.
     *
     * @param PipelineOpportunity $oportunidad
     *
     * @return void
     */
    public function borrar(PipelineOpportunity $oportunidad)
    {
        DB::transaction(function () use ($oportunidad) {
            PipelineActivity::query()->where('opportunity_id', $oportunidad->id)->delete();
            $oportunidad->delete();
        });
    }

    /**
     * Borra una actividad. Solo las notas: el historial de etapas, altas, próxima acción y
     * responsable es lo que cuenta el embudo y no se borra.
     *
     * @param PipelineActivity $actividad
     *
     * @return void
     *
     * @throws PipelineRuleException
     */
    public function borrar_actividad(PipelineActivity $actividad)
    {
        if ($actividad->type !== PipelineActivity::TYPE_NOTE) {
            throw new PipelineRuleException('Solo se pueden borrar las notas: el historial de etapas no se borra.');
        }

        $actividad->delete();
    }

    /* ------------------------------------------------------------------------------------------
     | Interno
     |----------------------------------------------------------------------------------------- */

    /**
     * La etapa inicial de un alta: la pedida (abierta y de este pipeline) o la primera abierta.
     *
     * @param Pipeline $pipeline
     * @param int|null $etapa_id
     *
     * @return PipelineStage
     *
     * @throws PipelineRuleException
     */
    private function etapa_inicial(Pipeline $pipeline, $etapa_id)
    {
        if ($etapa_id !== null) {
            $etapa = PipelineStage::query()
                ->where('pipeline_id', $pipeline->id)
                ->whereKey((int) $etapa_id)
                ->first();

            if ($etapa === null || ! $etapa->is_open()) {
                throw new PipelineRuleException('La etapa inicial tiene que ser una etapa abierta de este pipeline.');
            }

            return $etapa;
        }

        $etapa = PipelineStage::query()
            ->where('pipeline_id', $pipeline->id)
            ->where('type', PipelineStage::TYPE_OPEN)
            ->orderBy('sort_order')->orderBy('id')
            ->first();

        if ($etapa === null) {
            /* No debería pasar: el ABM no deja un pipeline sin etapas abiertas. */
            throw new PipelineRuleException('El pipeline no tiene ninguna etapa abierta donde dar de alta.');
        }

        return $etapa;
    }

    /**
     * Claves `tipo:id` de los sujetos (de la lista) que ya tienen una oportunidad abierta en el
     * pipeline.
     *
     * @param int             $pipeline_id
     * @param array<int, int> $ids_clientes
     * @param array<int, int> $ids_leads
     *
     * @return array<string, bool>
     */
    private function claves_abiertas($pipeline_id, array $ids_clientes, array $ids_leads)
    {
        if ($ids_clientes === [] && $ids_leads === []) {
            return [];
        }

        $filas = PipelineOpportunity::query()
            ->join('pipeline_stages', 'pipeline_stages.id', '=', 'pipeline_opportunities.stage_id')
            ->where('pipeline_opportunities.pipeline_id', $pipeline_id)
            ->where('pipeline_stages.type', PipelineStage::TYPE_OPEN)
            ->where(function ($query) use ($ids_clientes, $ids_leads) {
                if ($ids_clientes !== []) {
                    $query->orWhereIn('pipeline_opportunities.client_id', array_values(array_unique($ids_clientes)));
                }
                if ($ids_leads !== []) {
                    $query->orWhereIn('pipeline_opportunities.lead_id', array_values(array_unique($ids_leads)));
                }
            })
            ->get(['pipeline_opportunities.client_id', 'pipeline_opportunities.lead_id']);

        $claves = [];
        foreach ($filas as $fila) {
            if ($fila->client_id !== null) {
                $claves[PipelineOpportunity::SUBJECT_CLIENT . ':' . (int) $fila->client_id] = true;
            }
            if ($fila->lead_id !== null) {
                $claves[PipelineOpportunity::SUBJECT_LEAD . ':' . (int) $fila->lead_id] = true;
            }
        }

        return $claves;
    }

    /**
     * Id de OTRA oportunidad abierta del mismo sujeto en el mismo pipeline, o null.
     *
     * @param PipelineOpportunity $op
     *
     * @return int|null
     */
    private function otra_abierta(PipelineOpportunity $op)
    {
        $query = PipelineOpportunity::query()
            ->join('pipeline_stages', 'pipeline_stages.id', '=', 'pipeline_opportunities.stage_id')
            ->where('pipeline_opportunities.pipeline_id', $op->pipeline_id)
            ->where('pipeline_stages.type', PipelineStage::TYPE_OPEN)
            ->where('pipeline_opportunities.id', '!=', $op->id);

        if ($op->client_id !== null) {
            $query->where('pipeline_opportunities.client_id', $op->client_id);
        } else {
            $query->where('pipeline_opportunities.lead_id', $op->lead_id);
        }

        $id = $query->value('pipeline_opportunities.id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Una actividad `owner` o `next_action` (sin etapa, sin cuerpo).
     *
     * @param PipelineOpportunity  $op
     * @param string               $tipo
     * @param array<string, mixed> $data
     * @param int|null             $admin_id
     * @param Carbon               $ahora
     *
     * @return PipelineActivity Recargada.
     */
    private function actividad_simple(PipelineOpportunity $op, $tipo, array $data, $admin_id, Carbon $ahora)
    {
        $actividad = PipelineActivity::create([
            'opportunity_id'  => $op->id,
            'pipeline_id'     => $op->pipeline_id,
            'admin_id'        => $admin_id,
            'type'            => $tipo,
            'from_stage_id'   => null,
            'from_stage_name' => null,
            'to_stage_id'     => null,
            'to_stage_name'   => null,
            'body'            => null,
            'channel'         => null,
            'data'            => $data,
            'occurred_at'     => $ahora,
        ]);

        return $actividad->fresh();
    }

    /**
     * Nombre de un admin (foto para el historial), o null.
     *
     * @param int|null $admin_id
     *
     * @return string|null
     */
    private function nombre_de_admin($admin_id)
    {
        if ($admin_id === null) {
            return null;
        }

        $nombre = Admin::query()->whereKey($admin_id)->value('name');

        return $nombre === null ? null : (string) $nombre;
    }

    /**
     * Texto recortado, o null si está vacío o no es texto.
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

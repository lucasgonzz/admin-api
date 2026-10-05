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
use Illuminate\Support\Facades\Log;

/**
 * Las escrituras sobre oportunidades del CRM (misión pipelines-crm, 27/9/2026): alta masiva,
 * mover de etapa, notas, próxima acción, responsable y borrar. Toda regla de negocio del módulo
 * vive acá (o en `PipelineConfigService` para el ABM), no en los controladores.
 *
 * Cuatro invariantes que sostiene esta clase y que no hay que "simplificar":
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
 * 4. 🔴 GOOGLE CALENDAR SE SINCRONIZA DESPUÉS DEL COMMIT, NUNCA ADENTRO DE LA TRANSACCIÓN (misión
 *    pipelines-calendario-proxima-accion, 5/10/2026). La próxima acción de una oportunidad tiene un
 *    evento en el calendario del admin que la fijó, si lo tiene vinculado (`PipelineCalendarSync`).
 *    Las transacciones de acá tienen el lock de la fila del pipeline: una llamada a Google con el
 *    lock tomado frenaría a todos los demás admins. Y el sync es best-effort y nunca propaga: un
 *    Google caído no puede romper el guardado del admin.
 *
 * 🔴 Este módulo NO manda ningún mensaje: ni WhatsApp, ni mail, ni nada. Solo registra (el evento
 * del calendario es un recordatorio en el calendario del propio admin, sin invitados).
 */
class PipelineOpportunityService
{
    /** Largo máximo de una nota y de la nota que acompaña un movimiento. */
    const MAX_NOTA = 5000;

    /** Largo máximo del motivo de pérdida y de la nota de la próxima acción (columnas string 255). */
    const MAX_CORTO = 255;

    /** R1.1: la próxima acción la fija el campo agenda de la etapa destino. */
    const REGLA_AGENDA = 'agenda';

    /** R1.2: la próxima acción es la que trae el payload (`next_action_at`, aunque sea null). */
    const REGLA_PAYLOAD = 'payload';

    /** R1.3: sin agenda ni clave en el payload, se conserva solo la manual que no venció. */
    const REGLA_CONSERVAR = 'conservar';

    /** Una nota de próxima acción sin fecha no tiene dónde vivir: la agenda se ordena por fecha. */
    const ERROR_NOTA_SIN_FECHA = 'Poné la fecha de la próxima acción.';

    /** La fecha de la próxima acción vino, pero mal formada (o en ISO). */
    const ERROR_FORMATO_PROXIMA = 'La fecha de la próxima acción tiene que tener formato AAAA-MM-DD o AAAA-MM-DD HH:MM.';

    /**
     * Tope de oportunidades de un alta masiva a las que se les crea el evento de Google Calendar.
     *
     * 🔴 La cola es `sync` en producción: cada evento es una llamada HTTP a Google dentro del request
     * del admin (más el refresco del token, que se reutiliza). Un alta de 500 sujetos con una
     * etapa con agenda dejaría el request colgado minutos. Pasado el tope no se hace nada más: se
     * loguea cuántas quedaron sin evento. No es un error: el evento es un recordatorio, y la
     * próxima acción de esas oportunidades sigue guardada en el CRM.
     */
    const MAX_EVENTOS_EN_ALTA_MASIVA = 25;

    /**
     * @var PipelineFieldsService
     */
    private $campos;

    /**
     * @var PipelineCalendarSync
     */
    private $calendario;

    /**
     * @param PipelineFieldsService $campos
     * @param PipelineCalendarSync  $calendario Sincroniza la próxima acción con Google Calendar (best-effort, nunca propaga).
     */
    public function __construct(PipelineFieldsService $campos, PipelineCalendarSync $calendario)
    {
        $this->campos     = $campos;
        $this->calendario = $calendario;
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
     * - Cada alta deja una actividad `created` con la etapa inicial, la nota y la foto de los
     *   campos cargados.
     * - Los campos de la etapa inicial (ronda de arreglos R2) se validan IGUAL que al mover: mismo
     *   servicio, mismos obligatorios y formatos, mismas claves de error `fields.<key>`, un solo
     *   juego de valores para todos los sujetos. Sin esto se podía dar de alta directo en "Reunión
     *   agendada" sin la fecha de la reunión. Un campo agenda con valor fija la próxima acción
     *   (`source = agenda`, nota = el nombre de la etapa), igual que la regla R1.1 del mover.
     * - Salteados: `not_found` (el cliente / lead no existe) y `already_open` (ya tiene una
     *   abierta en este pipeline; también el segundo de un sujeto repetido en el mismo pedido).
     *
     * @param Pipeline                                 $pipeline
     * @param array<int, array{type: string, id: int}> $sujetos
     * @param int|null                                 $etapa_id       Null = primera abierta.
     * @param int|null                                 $owner_admin_id Ya resuelto (null = sin responsable).
     * @param string|null                              $nota
     * @param int|null                                 $admin_id       Quién hace el alta.
     * @param mixed                                    $valores        `fields` del pedido (objeto `{key: valor}` o null).
     *
     * @return array{creadas: array<int, int>, salteados: array<int, array{type: string, id: int, reason: string}>}
     *
     * @throws PipelineRuleException
     */
    public function alta_masiva(Pipeline $pipeline, array $sujetos, $etapa_id, $owner_admin_id, $nota, $admin_id, $valores = null)
    {
        $nota = self::texto_o_null($nota);

        $resultado = DB::transaction(function () use ($pipeline, $sujetos, $etapa_id, $owner_admin_id, $nota, $admin_id, $valores) {
            /* 🔴 El lock que serializa "¿ya tiene una abierta?" (ver el docblock de la clase). */
            Pipeline::query()->whereKey($pipeline->id)->lockForUpdate()->first();

            $etapa = $this->etapa_inicial($pipeline, $etapa_id);

            /* R2: los campos de la etapa inicial, antes de mirar los sujetos (el 422 es del pedido
               entero, no de un sujeto). */
            $limpios = $this->campos->validar_valores($etapa, $valores);
            $foto    = $this->campos->foto($etapa, $limpios);
            $agenda  = $this->campos->valor_de_agenda($etapa, $limpios);

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
                    'next_action_at'      => $agenda,
                    'next_action_note'    => $agenda !== null ? $etapa->name : null,
                    'next_action_source'  => $agenda !== null ? PipelineOpportunity::SOURCE_AGENDA : null,
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
                    'data'            => $foto,
                    'occurred_at'     => $ahora,
                ]);

                $abiertas[$clave] = true;
                $creadas[]        = (int) $oportunidad->id;
            }

            return ['creadas' => $creadas, 'salteados' => $salteados];
        });

        /* 🔴 Google Calendar va DESPUÉS del commit, nunca adentro de la transacción: tiene tomado el
           lock de la fila del pipeline y una llamada a Google con el lock tomado frena a todos los
           demás admins. Si algo falla ahí, el alta ya quedó hecha (el sync nunca propaga). */
        $this->sincronizar_calendario_del_alta($resultado['creadas'], $admin_id);

        return $resultado;
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
     *     payload se ignora, NI SE VALIDA NI SE PARSEA), `lost_reason` el del payload (ganada → null).
     *  6. Destino abierta, la próxima acción (ronda de arreglos R1):
     *     R1.1 si la etapa tiene campo agenda y vino con valor → ese valor (`date` → 00:00:00),
     *          `source = agenda`, nota = `next_action_note` del payload o el nombre de la etapa. Lo
     *          que venga en `next_action_at` se ignora sin validarlo.
     *     R1.2 si no, y el payload TRAE la clave `next_action_at` (aunque sea null) → se aplica con
     *          su nota; `source = manual` si hay fecha, null si se borró. Nota sin fecha → 422.
     *     R1.3 si no la trae → se conserva SOLO si `source = manual` y todavía no venció
     *          (`PipelineOpportunity::proxima_accion_se_conserva()`); si no, se borra entera. Una
     *          nota de próxima acción sin clave `next_action_at` → 422 (no hay fecha a la que
     *          pegarla).
     *     🔴 Antes de R1 se conservaba siempre, y la fecha de la reunión de "Reunión agendada"
     *     quedaba pegada al pasar a "Reunión hecha": al día siguiente la oportunidad figuraba
     *     vencida en el tablero, los chips y la agenda.
     *  7. `stage_id` y `stage_entered_at = ahora`, y una actividad `stage_change` con la foto.
     *  8. Si la próxima acción cambió por R1.2, R1.3 o por cerrar, una actividad `next_action`
     *     más (`{from, to, note}`, como la del PUT) con el mismo `occurred_at`. Por R1.1 no: la
     *     fecha ya está en la foto del `stage_change`.
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
        $antes = ['fecha' => null, 'nota' => null];

        $resultado = DB::transaction(function () use ($oportunidad, $datos, $admin_id, &$antes) {
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

            /* La próxima acción (R1). Qué regla aplica se decide ANTES de validar nada de ella: la
               clave `next_action_at` solo se valida y se parsea si se va a aplicar (R1.2). */
            $campos_crudos = array_key_exists('fields', $datos) ? $datos['fields'] : null;
            $nota_proxima  = self::texto_o_null(isset($datos['next_action_note']) ? $datos['next_action_note'] : null);
            $regla         = null;
            $proxima       = null;

            if ($destino->is_open()) {
                if ($this->campos->agenda_vino_con_valor($destino, $campos_crudos)) {
                    $regla = self::REGLA_AGENDA;
                } elseif (array_key_exists('next_action_at', $datos)) {
                    $regla = self::REGLA_PAYLOAD;

                    if ($datos['next_action_at'] !== null) {
                        $proxima = PipelineFieldsService::parsear_fecha_de_la_spa($datos['next_action_at']);
                        if ($proxima === null) {
                            $errores['next_action_at'][] = self::ERROR_FORMATO_PROXIMA;
                        }
                    } elseif ($nota_proxima !== null) {
                        $errores['next_action_at'][] = self::ERROR_NOTA_SIN_FECHA;
                    }
                } else {
                    $regla = self::REGLA_CONSERVAR;

                    if ($nota_proxima !== null) {
                        $errores['next_action_at'][] = self::ERROR_NOTA_SIN_FECHA;
                    }
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

            $fecha_antes = PipelinePresenter::fecha($op->next_action_at);
            $nota_antes  = $op->next_action_note;

            /* Cómo estaba la próxima acción, para decidir DESPUÉS del commit si el calendario tiene
               algo que hacer (ver `sincronizar_calendario()`). */
            $antes = ['fecha' => $fecha_antes, 'nota' => $nota_antes];

            if ($destino->is_closed()) {
                $op->closed_at   = $ahora;
                $op->lost_reason = $destino->type === PipelineStage::TYPE_LOST ? $motivo : null;
                $this->borrar_proxima_accion($op);
            } else {
                if ($reabre) {
                    $op->closed_at = null;
                }
                $op->lost_reason = null;

                if ($regla === self::REGLA_AGENDA) {
                    $op->next_action_at     = $this->campos->valor_de_agenda($destino, $valores);
                    $op->next_action_note   = $nota_proxima !== null ? $nota_proxima : $destino->name;
                    $op->next_action_source = PipelineOpportunity::SOURCE_AGENDA;
                } elseif ($regla === self::REGLA_PAYLOAD) {
                    $op->next_action_at     = $proxima;
                    $op->next_action_note   = $proxima !== null ? $nota_proxima : null;
                    $op->next_action_source = $proxima !== null ? PipelineOpportunity::SOURCE_MANUAL : null;
                } elseif (! $op->proxima_accion_se_conserva($ahora)) {
                    $this->borrar_proxima_accion($op);
                }
            }

            /* Cambio de próxima acción por R1.2, R1.3 o por cerrar: se registra aparte (paso 8). */
            $cambio_de_proxima = null;
            if ($regla !== self::REGLA_AGENDA) {
                $fecha_despues = PipelinePresenter::fecha($op->next_action_at);
                if ($fecha_antes !== $fecha_despues || $nota_antes !== $op->next_action_note) {
                    $cambio_de_proxima = ['from' => $fecha_antes, 'to' => $fecha_despues, 'note' => $op->next_action_note];
                }
            }

            $origen_id     = $op->stage_id;
            $origen_nombre = $origen !== null ? $origen->name : null;

            $op->stage_id         = $destino->id;
            $op->stage_entered_at = $ahora;
            $op->save();

            /* 🔴 La de próxima acción se crea ANTES que el cambio de etapa, a propósito: tienen el
               mismo `occurred_at` y el empate lo gana el id mayor, así la "última actividad" de la
               tarjeta sigue siendo el movimiento (con la nota que escribió el operador) y no el
               efecto secundario sobre la próxima acción. */
            if ($cambio_de_proxima !== null) {
                $this->actividad_simple($op, PipelineActivity::TYPE_NEXT_ACTION, $cambio_de_proxima, $admin_id, $ahora);
            }

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

        /* 🔴 Después del commit (el 422 de arriba sale de la transacción y no llega hasta acá). La
           respuesta es la misma que antes: el sync no la toca y nunca propaga. */
        $this->sincronizar_calendario($resultado[0], $admin_id, $antes);

        return $resultado;
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
     * La próxima acción (ronda de arreglos R1), solo si el payload la toca:
     *  - con fecha → `source = manual` (la cargó una persona, aunque sea la misma fecha que había
     *    puesto la agenda de la etapa);
     *  - `next_action_at: null` → se borra ENTERA (fecha, nota y origen), salvo que en el mismo
     *    payload venga una nota, que es el 422 de abajo;
     *  - solo `next_action_note` → cambia la nota sobre la fecha que ya tenía;
     *  - una nota que queda sin fecha → 422 `errors.next_action_at` "Poné la fecha de la próxima
     *    acción." (la agenda se ordena por fecha: una nota sola no aparece en ningún lado).
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
                throw PipelineRuleException::validacion(['next_action_at' => [self::ERROR_FORMATO_PROXIMA]]);
            }
        }

        $nota = $trae_nota ? self::texto_o_null($datos['next_action_note']) : null;

        $antes = ['fecha' => null, 'nota' => null];

        $resultado = DB::transaction(function () use ($oportunidad, $datos, $admin_id, $trae_fecha, $trae_nota, $fecha, $nota, &$antes) {
            $op = PipelineOpportunity::query()->whereKey($oportunidad->id)->lockForUpdate()->first();
            if ($op === null) {
                throw PipelineRuleException::no_existe('La oportunidad');
            }

            /* Cómo estaba la próxima acción ANTES de tocar nada, para decidir DESPUÉS del commit si
               el calendario tiene algo que hacer (ver `sincronizar_calendario()`). */
            $antes = ['fecha' => PipelinePresenter::fecha($op->next_action_at), 'nota' => $op->next_action_note];

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

            /* Próxima acción (R1): solo si el payload la toca. Un PUT que cambia solo el
               responsable no mira la próxima acción, aunque haya quedado rara de antes. */
            $cambio_de_proxima = null;
            if ($trae_fecha || $trae_nota) {
                $fecha_antes = PipelinePresenter::fecha($op->next_action_at);
                $nota_antes  = $op->next_action_note;

                $fecha_nueva = $trae_fecha ? $fecha : $op->next_action_at;

                if ($trae_nota) {
                    $nota_nueva = $nota;
                } elseif ($trae_fecha && $fecha === null) {
                    /* Borrar la fecha borra la próxima acción entera. */
                    $nota_nueva = null;
                } else {
                    $nota_nueva = $op->next_action_note;
                }

                if ($nota_nueva !== null && $fecha_nueva === null) {
                    throw PipelineRuleException::validacion(['next_action_at' => [self::ERROR_NOTA_SIN_FECHA]]);
                }

                if ($trae_fecha) {
                    $fuente_nueva = $fecha !== null ? PipelineOpportunity::SOURCE_MANUAL : null;
                } else {
                    $fuente_nueva = $fecha_nueva !== null ? $op->next_action_source : null;
                }

                $op->next_action_at     = $fecha_nueva;
                $op->next_action_note   = $nota_nueva;
                $op->next_action_source = $fuente_nueva;

                $fecha_despues = PipelinePresenter::fecha($op->next_action_at);
                if ($fecha_antes !== $fecha_despues || $nota_antes !== $nota_nueva) {
                    $cambio_de_proxima = ['from' => $fecha_antes, 'to' => $fecha_despues, 'note' => $nota_nueva];
                }
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

        /* 🔴 Después del commit (los 422 de arriba salen de la transacción y no llegan hasta acá). */
        $this->sincronizar_calendario($resultado[0], $admin_id, $antes);

        return $resultado;
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
        /* El evento hay que anotarlo ANTES de borrar la fila: después las columnas ya no existen. */
        $evento_admin = $oportunidad->next_action_calendar_admin_id;
        $evento_id    = $oportunidad->next_action_calendar_event_id;
        $fecha        = PipelinePresenter::fecha($oportunidad->next_action_at);

        DB::transaction(function () use ($oportunidad) {
            PipelineActivity::query()->where('opportunity_id', $oportunidad->id)->delete();
            $oportunidad->delete();
        });

        /* Después del commit, como en el resto (nunca Google adentro de la transacción). */
        if ($evento_admin !== null && $evento_id !== null) {
            $this->calendario->borrar_evento((int) $evento_admin, (string) $evento_id, $fecha);
        }
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
     * Borra la próxima acción ENTERA: fecha, nota y origen. Nunca una sola de las tres: una nota o
     * un origen sin fecha es un estado que ninguna pantalla sabe mostrar.
     *
     * @param PipelineOpportunity $op
     *
     * @return void
     */
    private function borrar_proxima_accion(PipelineOpportunity $op)
    {
        $op->next_action_at     = null;
        $op->next_action_note   = null;
        $op->next_action_source = null;
    }

    /**
     * Le avisa al calendario que la próxima acción puede haber cambiado (`mover` y `actualizar`),
     * y solo si de verdad cambió.
     *
     * 🔴 Se llama SOLO si la fecha o la nota de la próxima acción cambiaron respecto de `$antes`, o si
     * quedó un evento colgado de una oportunidad sin próxima acción (un borrado anterior que Google
     * rechazó: se reintenta). Sin esto, mover una oportunidad que conserva su próxima acción manual,
     * o cambiarle solo el responsable, dispararía una llamada a Google por nada. La contracara
     * conocida: si la nota está vacía (el título del evento es el nombre de la etapa) y la
     * oportunidad se mueve conservando la acción, el título y la etapa de la descripción del evento
     * quedan con los de cuando se fijó; se actualizan la próxima vez que cambie la acción.
     *
     * @param PipelineOpportunity               $op       Recargada, ya con el resultado final.
     * @param int|null                          $admin_id El admin que hace el cambio.
     * @param array{fecha: string|null, nota: string|null} $antes Fecha (`Y-m-d H:i:s`) y nota de antes del cambio.
     *
     * @return void
     */
    private function sincronizar_calendario(PipelineOpportunity $op, $admin_id, array $antes)
    {
        $fecha_ahora = PipelinePresenter::fecha($op->next_action_at);

        $cambio   = $fecha_ahora !== $antes['fecha'] || $op->next_action_note !== $antes['nota'];
        $colgado  = $fecha_ahora === null && $op->next_action_calendar_event_id !== null;

        if (! $cambio && ! $colgado) {
            return;
        }

        $this->calendario->sincronizar($op, $admin_id, $antes['fecha']);
    }

    /**
     * Crea el evento de las oportunidades que un alta masiva acaba de crear CON próxima acción (las
     * de una etapa inicial con campo agenda), hasta `MAX_EVENTOS_EN_ALTA_MASIVA`.
     *
     * @param array<int, int> $ids      Ids de las oportunidades creadas, en orden de creación.
     * @param int|null        $admin_id Quién hace el alta.
     *
     * @return void
     */
    private function sincronizar_calendario_del_alta(array $ids, $admin_id)
    {
        if ($ids === []) {
            return;
        }

        $con_proxima = PipelineOpportunity::query()
            ->whereIn('id', $ids)
            ->whereNotNull('next_action_at')
            ->orderBy('id')
            ->get();

        if ($con_proxima->isEmpty()) {
            return;
        }

        foreach ($con_proxima->take(self::MAX_EVENTOS_EN_ALTA_MASIVA) as $oportunidad) {
            $this->calendario->sincronizar($oportunidad, $admin_id);
        }

        $sin_evento = $con_proxima->count() - self::MAX_EVENTOS_EN_ALTA_MASIVA;
        if ($sin_evento > 0) {
            Log::channel('disponibilidad')->info(
                PipelineCalendarSync::LOG . ' Alta masiva: se crearon ' . self::MAX_EVENTOS_EN_ALTA_MASIVA
                . ' eventos (el tope) y ' . $sin_evento . ' oportunidades con próxima acción quedaron sin evento en el calendario.'
                . ' admin_id=' . ($admin_id === null ? 'null' : $admin_id)
            );
        }
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

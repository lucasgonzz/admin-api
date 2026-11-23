<?php

namespace App\Services\Pipelines;

use App\Models\Lead;
use App\Models\Pipeline;
use App\Models\PipelineOpportunity;
use App\Models\PipelineStage;
use App\Services\AdminTaskAssignmentResolver;
use Database\Seeders\PipelineLeadSeeder;
use Illuminate\Support\Facades\Log;

/**
 * El tablero de Juan (misión embudo-juan-pipeline, E1): las 8 columnas del pipeline «Leads» y lo que las llena SOLO.
 *
 * Columnas (claves estables de `pipeline_stages.stage_key`): `interesados`, `esperando_llamada` («Volver a llamar»),
 * `en_prueba_sola`, `implementacion_acordada`, `en_implementacion`, `en_seguimiento` («Para cerrar»), `cerrado`
 * («Cliente») y `no_avanza`.
 *
 * Entrada automática a «Interesados» (idempotente: si el lead ya tiene una tarjeta abierta en el pipeline, no se crea otra):
 *  - el lead pasa a `link_enviado` o a `implementacion_pedida` (esta además vuelve a Interesados a quien estaba esperando);
 *  - el lead crea su prueba (la alta escribe el estado por query builder, así que la llama de forma explícita);
 *  - negocio grande: `cantidad_empleados >= 3` en un lead que todavía no avanzó.
 * Y cuando el lead pasa a `cerrado_ganado` su tarjeta abierta va a «Cliente».
 *
 * Todo pasa por `PipelineOpportunityService` (alta y mover), nunca por SQL directo, así quedan la actividad y el funnel.
 *
 * 🔴 Cada entrada pública se traga sus errores y los loguea, igual que `PipelineLeadFunnelService`: `Lead::saved` corre en
 * el webhook de WhatsApp, en `LeadAiService` y en el scheduler, y un error del CRM no puede tirar nada de eso.
 */
class TableroDeJuanService
{
    /** Columna «Interesados». */
    const ETAPA_INTERESADOS = PipelineLeadSeeder::ETAPA_INTERESADOS;

    /** Columna «Volver a llamar». */
    const ETAPA_VOLVER_A_LLAMAR = PipelineLeadSeeder::ETAPA_ESPERANDO_LLAMADA;

    /** Columna «En prueba por su cuenta». */
    const ETAPA_EN_PRUEBA_SOLA = 'en_prueba_sola';

    /** Columna «Implementación acordada». */
    const ETAPA_IMPLEMENTACION_ACORDADA = 'implementacion_acordada';

    /** Columna «En implementación». */
    const ETAPA_EN_IMPLEMENTACION = 'en_implementacion';

    /** Columna «Para cerrar». */
    const ETAPA_PARA_CERRAR = PipelineLeadSeeder::ETAPA_EN_SEGUIMIENTO;

    /** Columna «Cliente» (ganada). */
    const ETAPA_CLIENTE = PipelineLeadSeeder::ETAPA_CERRADO;

    /** Columna «No avanza» (perdida). */
    const ETAPA_NO_AVANZA = PipelineLeadSeeder::ETAPA_NO_AVANZA;

    /** Desde cuántos empleados un negocio se considera grande y entra solo al tablero. */
    const EMPLEADOS_NEGOCIO_GRANDE = 3;

    /** Estados del lead desde los que un negocio grande entra al tablero (los que todavía no avanzaron). */
    const ESTADOS_DEL_NEGOCIO_GRANDE = ['nuevo', 'contactado', 'calificado', 'link_enviado', 'implementacion_pedida'];

    /** @var PipelineOpportunityService */
    private $oportunidades;

    /**
     * @param PipelineOpportunityService $oportunidades Alta y movimiento de tarjetas del CRM.
     */
    public function __construct(PipelineOpportunityService $oportunidades)
    {
        $this->oportunidades = $oportunidades;
    }

    /**
     * El lead cambió de estado (lo llama `Lead::saved` cuando `status` cambió).
     *
     * @param Lead $lead
     *
     * @return void
     */
    public function al_cambiar_estado(Lead $lead)
    {
        try {
            switch ((string) $lead->status) {
                case 'link_enviado':
                    $this->entrar_en_interesados($lead, 'le mandaron el link de la prueba');
                    break;
                case 'implementacion_pedida':
                    $this->al_pedir_la_implementacion($lead);
                    break;
                case 'cerrado_ganado':
                    $this->mover_a($lead, self::ETAPA_CLIENTE);
                    break;
                default:
                    break;
            }
        } catch (\Throwable $error) {
            $this->avisar('no se pudo actualizar el tablero por el estado del lead', $error, ['lead_id' => $lead->id]);
        }
    }

    /**
     * Cambió `cantidad_empleados` del lead (lo llama `Lead::saved`): un negocio grande entra solo.
     *
     * @param Lead $lead
     *
     * @return void
     */
    public function al_cambiar_empleados(Lead $lead)
    {
        try {
            if ((int) $lead->cantidad_empleados < self::EMPLEADOS_NEGOCIO_GRANDE) {
                return;
            }

            if (! in_array((string) $lead->status, self::ESTADOS_DEL_NEGOCIO_GRANDE, true)) {
                return;
            }

            $this->entrar_en_interesados($lead, 'negocio con ' . (int) $lead->cantidad_empleados . ' empleados');
        } catch (\Throwable $error) {
            $this->avisar('no se pudo dar de alta el negocio grande', $error, ['lead_id' => $lead->id]);
        }
    }

    /**
     * La alta de la prueba escribe el estado del lead por query builder (no dispara `Lead::saved`): el servicio de alta
     * llama a este método de forma explícita.
     *
     * @param Lead $lead
     *
     * @return PipelineOpportunity|null
     */
    public function al_crear_la_prueba(Lead $lead)
    {
        try {
            return $this->entrar_en_interesados($lead, 'creó su prueba');
        } catch (\Throwable $error) {
            $this->avisar('no se pudo dar de alta al lead que creó su prueba', $error, ['lead_id' => $lead->id]);

            return null;
        }
    }

    /**
     * Da de alta la tarjeta del lead en «Interesados» si no tiene una abierta en el pipeline.
     *
     * La nota del alta es «Entró solo: <motivo>» y el responsable es el primer admin de la tarea del embudo (Juan).
     *
     * @param Lead   $lead
     * @param string $motivo Por qué entró, para la nota.
     *
     * @return PipelineOpportunity|null La tarjeta (nueva o la que ya estaba abierta); null si no hay pipeline.
     */
    public function entrar_en_interesados(Lead $lead, string $motivo)
    {
        $pipeline = $this->pipeline();
        if ($pipeline === null) {
            return null;
        }

        $abierta = $this->tarjeta_abierta($lead, $pipeline);
        if ($abierta !== null) {
            return $abierta;
        }

        $etapa = $this->etapa($pipeline, self::ETAPA_INTERESADOS);
        if ($etapa === null) {
            return null;
        }

        $ids_de_juan = AdminTaskAssignmentResolver::for_embudo_task();

        $this->oportunidades->alta_masiva(
            $pipeline,
            [['type' => PipelineOpportunity::SUBJECT_LEAD, 'id' => (int) $lead->id]],
            (int) $etapa->id,
            $ids_de_juan !== [] ? (int) $ids_de_juan[0] : null,
            'Entró solo: ' . $motivo,
            null
        );

        return $this->tarjeta_abierta($lead, $pipeline);
    }

    /**
     * Mueve la tarjeta abierta del lead a otra columna (por `PipelineOpportunityService::mover`).
     *
     * @param Lead                 $lead
     * @param string               $clave    `stage_key` destino.
     * @param array<string, mixed> $extra    Datos del movimiento (`note`, `next_action_at`, `next_action_note`, `lost_reason`…).
     * @param int|null             $admin_id Quién mueve (null = el sistema).
     *
     * @return PipelineOpportunity|null La tarjeta ya movida; null si no hay tarjeta abierta, o pipeline, o ya estaba ahí.
     */
    public function mover_a(Lead $lead, string $clave, array $extra = [], $admin_id = null)
    {
        $pipeline = $this->pipeline();
        if ($pipeline === null) {
            return null;
        }

        $tarjeta = $this->tarjeta_abierta($lead, $pipeline);
        $etapa   = $this->etapa($pipeline, $clave);
        if ($tarjeta === null || $etapa === null || (int) $tarjeta->stage_id === (int) $etapa->id) {
            return $tarjeta;
        }

        $resultado = $this->oportunidades->mover($tarjeta, array_merge(['stage_id' => (int) $etapa->id], $extra), $admin_id);

        return $resultado[0];
    }

    /**
     * El pipeline «Leads».
     *
     * @return Pipeline|null
     */
    public function pipeline()
    {
        return Pipeline::query()->where('slug', PipelineLeadSeeder::SLUG)->first();
    }

    /**
     * La etapa del pipeline por su clave estable.
     *
     * @param Pipeline $pipeline
     * @param string   $clave
     *
     * @return PipelineStage|null
     */
    public function etapa(Pipeline $pipeline, string $clave)
    {
        return PipelineStage::query()->where('pipeline_id', $pipeline->id)->where('stage_key', $clave)->first();
    }

    /**
     * La tarjeta abierta del lead en el pipeline (la más vieja si hubiera más de una).
     *
     * @param Lead          $lead
     * @param Pipeline|null $pipeline Null = el pipeline de leads.
     *
     * @return PipelineOpportunity|null
     */
    public function tarjeta_abierta(Lead $lead, Pipeline $pipeline = null)
    {
        $pipeline = $pipeline !== null ? $pipeline : $this->pipeline();
        if ($pipeline === null) {
            return null;
        }

        return PipelineOpportunity::query()
            ->where('pipeline_id', $pipeline->id)
            ->where('lead_id', (int) $lead->id)
            ->whereNull('closed_at')
            ->orderBy('id')
            ->first();
    }

    /**
     * El lead pidió la implementación: entra a Interesados y, si estaba esperando (en prueba por su cuenta o para volver a
     * llamar), vuelve a Interesados con la nota «Pidió la implementación» para que Juan lo vea.
     *
     * @param Lead $lead
     *
     * @return void
     */
    private function al_pedir_la_implementacion(Lead $lead)
    {
        $pipeline = $this->pipeline();
        if ($pipeline === null) {
            return;
        }

        $abierta = $this->tarjeta_abierta($lead, $pipeline);
        if ($abierta === null) {
            $this->entrar_en_interesados($lead, 'pidió la implementación');

            return;
        }

        $actual = PipelineStage::query()->find((int) $abierta->stage_id);
        if ($actual !== null && in_array((string) $actual->stage_key, [self::ETAPA_EN_PRUEBA_SOLA, self::ETAPA_VOLVER_A_LLAMAR], true)) {
            $this->mover_a($lead, self::ETAPA_INTERESADOS, ['note' => 'Pidió la implementación']);
        }
    }

    /**
     * Deja un aviso en el log sin romper a quien llamó.
     *
     * @param string               $mensaje
     * @param \Throwable           $error
     * @param array<string, mixed> $contexto
     *
     * @return void
     */
    private function avisar(string $mensaje, \Throwable $error, array $contexto)
    {
        Log::channel('daily')->warning('TableroDeJuanService: ' . $mensaje . '.', array_merge($contexto, ['error' => $error->getMessage()]));
    }
}

<?php

namespace App\Services\Pipelines;

use App\Models\Admin;
use App\Models\Lead;
use App\Models\LeadCall;
use App\Models\LeadMessage;
use App\Models\LeadPipelineStatus;
use App\Models\Pipeline;
use App\Models\PipelineOpportunity;
use App\Models\PipelineStage;
use App\Services\LeadBroadcastService;
use Database\Seeders\PipelineLeadSeeder;
use Illuminate\Support\Facades\Log;

/**
 * La automatización del embudo "Leads" (misión embudo-lead-pipelines, 13/10/2026): lo único que
 * hace que el tablero de Tomás se llene y se mueva solo, sin que nadie arrastre una tarjeta.
 *
 * Tres entradas, y UNA sola regla de movimiento:
 *
 *  - `al_cambiar_estado_del_lead()` — desde `Lead::saved`: el lead pasó a `closer_activo` (confirmó
 *    interés después de la demo) → nace la oportunidad en "Interesados".
 *  - `al_guardar_llamada()` — desde `LeadCall::saved`: apareció la fecha de la videollamada o la
 *    llamada terminó → la oportunidad se mueve.
 *  - `al_mover_oportunidad()` — desde `PipelineOpportunityService::mover()`: la oportunidad entró a
 *    una etapa perdida → se cierra el lead (decisión D3 del plan del 13/10/2026).
 *
 * Que la regla de movimiento viva en un solo método (`sincronizar_por_llamadas()`) y que el alta la
 * llame es a propósito; está explicado abajo, en el docblock de ese método.
 *
 * 🔴 ESTE SERVICIO NO PUEDE ROMPER UN `save()` DE LEAD. `Lead::saved` corre en el webhook de
 * WhatsApp, en `LeadAiService`, en el scheduler de seguimientos y en media docena de lugares más,
 * ninguno de los cuales sabe que existe un CRM. Por eso cada entrada envuelve TODO en
 * `try/catch (\Throwable)` y deja un `Log`: una excepción del CRM nunca propaga al que guardó el
 * lead. Un tablero que se queda sin mover es un problema que se ve y se arregla; un webhook de
 * WhatsApp que empieza a tirar 500 por una tarjeta es otra cosa.
 *
 * 🔴 Y NO SE CONSTRUYE PIDIENDO `PipelineOpportunityService`. Lo resuelve con `app()` en el momento
 * de usarlo, y el otro hace lo mismo con este: si los dos se pidieran por constructor, el
 * contenedor entraría en bucle al resolver cualquiera de los dos.
 */
class PipelineLeadFunnelService
{
    /** Prefijo de los logs de este servicio, para poder grepearlos juntos. */
    const LOG = '[embudo-lead]';

    /** El estado del lead que dispara el alta: "confirmó que quiere la llamada". */
    const ESTADO_QUE_DA_DE_ALTA = 'closer_activo';

    /** El estado al que pasa un lead cuando su oportunidad entra a una etapa perdida (D3). */
    const ESTADO_PERDIDO = 'cerrado_perdido';

    /** El `estado` de una `LeadCall` que ya ocurrió y tiene su resumen. */
    const LLAMADA_COMPLETADA = 'completada';

    /** Nota de la actividad del alta automática. */
    const NOTA_DEL_ALTA = 'Confirmó interés después de la demo';

    /**
     * Candado de reentrada. Cerrar el lead desde una etapa perdida lo GUARDA, y eso vuelve a
     * disparar `Lead::saved` → esta misma clase. Hoy no habría bucle (el estado nuevo no es
     * `closer_activo`), pero el candado lo garantiza aunque mañana alguien agregue una regla.
     *
     * @var bool
     */
    private static $corriendo = false;

    /* ----------------------------------------------------------------------------------------------
     | Entradas
     |--------------------------------------------------------------------------------------------- */

    /**
     * El lead cambió de estado (lo llama `Lead::saved` solo si `wasChanged('status')`).
     *
     * Si pasó a `closer_activo`, da de alta la oportunidad en "Interesados" y después la deja donde
     * diga el estado real de sus llamadas. Cualquier otro estado no hace nada: el embudo no sigue el
     * pipeline comercial del lead paso a paso, solo entra cuando el lead confirmó interés.
     *
     * @param Lead $lead
     *
     * @return void
     */
    public function al_cambiar_estado_del_lead(Lead $lead)
    {
        if (self::$corriendo) {
            return;
        }

        if ((string) $lead->status !== self::ESTADO_QUE_DA_DE_ALTA) {
            return;
        }

        self::$corriendo = true;

        try {
            $pipeline = $this->pipeline();
            if ($pipeline === null) {
                return;
            }

            $this->dar_de_alta($pipeline, $lead);
            $this->sincronizar_por_llamadas($lead, $pipeline);
        } catch (\Throwable $error) {
            $this->avisar('no se pudo dar de alta la oportunidad del lead', $error, ['lead_id' => $lead->id]);
        } finally {
            self::$corriendo = false;
        }
    }

    /**
     * Se guardó una llamada del closer (lo llama `LeadCall::saved`).
     *
     * @param LeadCall $llamada
     *
     * @return void
     */
    public function al_guardar_llamada(LeadCall $llamada)
    {
        if (self::$corriendo) {
            return;
        }

        self::$corriendo = true;

        try {
            $pipeline = $this->pipeline();
            if ($pipeline === null) {
                return;
            }

            $lead = $llamada->lead;
            if ($lead === null) {
                return;
            }

            $this->sincronizar_por_llamadas($lead, $pipeline);
        } catch (\Throwable $error) {
            $this->avisar('no se pudo mover la oportunidad por la llamada', $error, [
                'lead_call_id' => $llamada->id,
                'lead_id'      => $llamada->lead_id,
            ]);
        } finally {
            self::$corriendo = false;
        }
    }

    /**
     * Se movió una oportunidad (lo llama `PipelineOpportunityService::mover()` después del commit).
     *
     * Decisión D3 del plan del 13/10/2026: la etapa **perdida** cierra el lead; la **ganada** no le
     * toca el estado, porque eso lo hace la promoción a cliente (`PromoteLeadService`), que es la
     * que crea el `Client` y el contrato. Marcar `cerrado_ganado` acá dejaría un lead "ganado" sin
     * cliente detrás.
     *
     * @param PipelineOpportunity $oportunidad Ya recargada de la base, con su etapa nueva.
     *
     * @return void
     */
    public function al_mover_oportunidad(PipelineOpportunity $oportunidad)
    {
        if (self::$corriendo) {
            return;
        }

        self::$corriendo = true;

        try {
            /* El pipeline "Agentes" (y cualquier otro) no tiene nada que ver con el estado comercial
               de un lead: solo el embudo "Leads" lo cierra. */
            $pipeline = $oportunidad->pipeline;
            if ($pipeline === null || (string) $pipeline->slug !== PipelineLeadSeeder::SLUG) {
                return;
            }

            /* Un pipeline no restringe el tipo de sujeto: puede haber un cliente adentro del embudo
               de leads, y un cliente no tiene `leads.status`. */
            if ($oportunidad->lead_id === null) {
                return;
            }

            $etapa = $oportunidad->stage;
            if ($etapa === null || (string) $etapa->type !== PipelineStage::TYPE_LOST) {
                return;
            }

            $lead = $oportunidad->lead;
            if ($lead === null) {
                return;
            }

            $this->cerrar_lead_como_perdido($lead, $etapa, $oportunidad);
        } catch (\Throwable $error) {
            $this->avisar('no se pudo cerrar el lead por la etapa perdida', $error, [
                'opportunity_id' => $oportunidad->id,
                'lead_id'        => $oportunidad->lead_id,
            ]);
        } finally {
            self::$corriendo = false;
        }
    }

    /* ----------------------------------------------------------------------------------------------
     | Alta
     |--------------------------------------------------------------------------------------------- */

    /**
     * Da de alta la oportunidad del lead en la primera etapa abierta, si no tiene una abierta ya.
     *
     * 🔴 Pasa por `PipelineOpportunityService::alta_masiva()` y no crea la fila a mano. Eso hereda,
     * gratis y sin repetirlo, el lock que serializa el "¿ya tiene una abierta?", el salteo
     * `already_open`, la actividad `created` del historial y la resolución de la etapa inicial. Un
     * `PipelineOpportunity::create()` acá sería una segunda implementación de las mismas reglas, que
     * es exactamente cómo se desincronizan.
     *
     * La etapa va en `null` a propósito: `etapa_inicial()` resuelve la primera abierta, que en este
     * pipeline es "Interesados". Responsable: el closer. `admin_id` null, porque el alta no la hizo
     * una persona.
     *
     * @param Pipeline $pipeline
     * @param Lead     $lead
     *
     * @return void
     */
    private function dar_de_alta(Pipeline $pipeline, Lead $lead)
    {
        $servicio = app(PipelineOpportunityService::class);

        $resultado = $servicio->alta_masiva(
            $pipeline,
            [['type' => PipelineOpportunity::SUBJECT_LEAD, 'id' => (int) $lead->id]],
            null,
            $this->id_del_closer(),
            self::NOTA_DEL_ALTA,
            null,
            null
        );

        if ($resultado['creadas'] !== []) {
            Log::channel('daily')->info(self::LOG . ' oportunidad creada por `closer_activo`.', [
                'lead_id'        => (int) $lead->id,
                'opportunity_id' => $resultado['creadas'][0],
            ]);
        }
    }

    /* ----------------------------------------------------------------------------------------------
     | Movimiento
     |--------------------------------------------------------------------------------------------- */

    /**
     * Deja la oportunidad abierta del lead en la etapa que corresponda al estado de sus llamadas.
     *
     * Las reglas, en orden:
     *  1. alguna llamada en `completada` → "En seguimiento" (la llamada ya se hizo y hay resumen);
     *  2. si no, la última llamada tiene `scheduled_at` → "Esperando la llamada";
     *  3. si no, no mueve.
     *
     * 🔴 SOLO HACIA ADELANTE (`sort_order` del destino mayor que el de la etapa actual). Dos cosas
     * dependen de esto: una llamada nueva agendada no arrastra de vuelta a un lead que ya está en
     * seguimiento, y un movimiento manual de Tomás a una etapa más avanzada (o a una etapa propia
     * que agregó al final) no se lo deshace una llamada que se guarda después.
     *
     * 🔴 Y POR ESO EL ALTA TAMBIÉN LLAMA A ESTE MÉTODO. El camino real más frecuente es
     * `CallSummaryService::process_transcript_for_call()`, que PRIMERO pone la llamada en
     * `completada` y DESPUÉS pasa el lead a `closer_activo`. O sea: cuando corre el hook de la
     * llamada todavía no hay oportunidad que mover, y cuando nace la oportunidad la llamada ya
     * terminó. Sin esta llamada desde el alta, toda oportunidad de ese camino —el principal— se
     * quedaría clavada en "Interesados" para siempre, sin ningún error que lo denunciara.
     *
     * @param Lead          $lead
     * @param Pipeline|null $pipeline Si ya se resolvió; null = se busca acá.
     *
     * @return void
     */
    private function sincronizar_por_llamadas(Lead $lead, Pipeline $pipeline = null)
    {
        if ($pipeline === null) {
            $pipeline = $this->pipeline();
        }
        if ($pipeline === null) {
            return;
        }

        $oportunidad = $this->oportunidad_abierta($pipeline, $lead);
        if ($oportunidad === null) {
            /* Sin oportunidad abierta no se crea nada: el alta tiene su propio disparador
               (`closer_activo`). Una llamada de un lead que nunca confirmó interés, o de uno cuya
               oportunidad Tomás ya cerró, no tiene por qué reabrir el embudo. */
            return;
        }

        $llamadas = $lead->calls()->get();
        if ($llamadas->isEmpty()) {
            return;
        }

        $clave   = null;
        $nota    = null;
        $valores = null;

        $completada = $llamadas->first(function (LeadCall $llamada) {
            return (string) $llamada->estado === self::LLAMADA_COMPLETADA;
        });

        $ultima = $llamadas->last();

        if ($completada !== null) {
            $clave = PipelineLeadSeeder::ETAPA_EN_SEGUIMIENTO;
            $nota  = 'La videollamada ya se hizo.';
        } elseif ($ultima !== null && $ultima->scheduled_at !== null) {
            $clave   = PipelineLeadSeeder::ETAPA_ESPERANDO_LLAMADA;
            $nota    = 'El lead agendó la videollamada.';
            $valores = [PipelineLeadSeeder::CAMPO_FECHA_LLAMADA => $ultima->scheduled_at->format('Y-m-d H:i')];

            if ($ultima->meet_url !== null && trim((string) $ultima->meet_url) !== '') {
                $nota .= ' ' . trim((string) $ultima->meet_url);
            }
        }

        if ($clave === null) {
            return;
        }

        $destino = $this->etapa_por_clave($pipeline, $clave);
        if ($destino === null) {
            Log::channel('daily')->warning(self::LOG . ' no existe la etapa destino: no se mueve.', [
                'lead_id'   => (int) $lead->id,
                'stage_key' => $clave,
            ]);

            return;
        }

        $actual = $oportunidad->stage;
        if ($actual === null || (int) $destino->id === (int) $actual->id) {
            return;
        }

        if ((int) $destino->sort_order <= (int) $actual->sort_order) {
            return;
        }

        $datos = ['stage_id' => (int) $destino->id, 'note' => $nota];
        if ($valores !== null) {
            $datos['fields'] = $valores;
        }

        app(PipelineOpportunityService::class)->mover($oportunidad, $datos, null);

        Log::channel('daily')->info(self::LOG . ' oportunidad movida por el estado de la llamada.', [
            'lead_id'        => (int) $lead->id,
            'opportunity_id' => (int) $oportunidad->id,
            'desde'          => (string) $actual->name,
            'hacia'          => (string) $destino->name,
        ]);
    }

    /* ----------------------------------------------------------------------------------------------
     | Cierre del lead (D3)
     |--------------------------------------------------------------------------------------------- */

    /**
     * Cierra el lead como perdido y le apaga la automatización.
     *
     * 🔴 Esto NO reimplementa "cerrar un lead": copia, paso por paso, lo que hace
     * `ClaudeLeadsPipelineController::aplicar_cambio()` para un estado terminal, que es el único
     * lugar donde está escrito completo —los cuatro flags de seguimiento, el `LeadMessage` de
     * evento con `is_status_event` para que no toque `last_message_at` ni el badge de sin leer, y el
     * broadcast para que la lista del panel se entere sola—. Y suma lo que pidió Lucas para este
     * camino: apagar `claude_auto_reply`, porque si el embudo lo da por perdido el agente no tiene
     * que seguir contestándole solo.
     *
     * Si el lead ya estaba cerrado (perdido o ganado), no hace nada: mover la tarjeta de vuelta a
     * "No avanza" no vuelve a escribir el mismo evento en la conversación.
     *
     * @param Lead                $lead
     * @param PipelineStage       $etapa
     * @param PipelineOpportunity $oportunidad
     *
     * @return void
     */
    private function cerrar_lead_como_perdido(Lead $lead, PipelineStage $etapa, PipelineOpportunity $oportunidad)
    {
        $anterior = (string) $lead->status;

        if ($anterior === self::ESTADO_PERDIDO) {
            return;
        }

        $lead->status                     = self::ESTADO_PERDIDO;
        $lead->claude_auto_reply          = false;
        $lead->requiere_seguimiento       = false;
        $lead->tiene_sugerencia_pendiente = false;
        $lead->tiene_seguimiento_sin_ver  = false;
        $lead->pendiente_revision_at      = null;
        $lead->save();

        $texto = 'Estado cambiado de "' . LeadPipelineStatus::label_for($anterior)
            . '" a "' . LeadPipelineStatus::label_for(self::ESTADO_PERDIDO)
            . '" por el embudo de Pipelines: la oportunidad pasó a "' . $etapa->name . '".';

        $motivo = trim((string) $oportunidad->lost_reason);
        if ($motivo !== '') {
            $texto .= ' Motivo: ' . $motivo;
        }

        LeadMessage::create([
            'lead_id'               => $lead->id,
            'sender'                => 'sistema',
            'content'               => $texto,
            'status'                => 'enviado',
            'is_followup'           => false,
            'is_status_event'       => true,
            'requiere_verificacion' => false,
        ]);

        LeadBroadcastService::emit_conversation_updated((int) $lead->id);

        Log::channel('daily')->info(self::LOG . ' lead cerrado como perdido por la etapa de la oportunidad.', [
            'lead_id'        => (int) $lead->id,
            'opportunity_id' => (int) $oportunidad->id,
            'desde'          => $anterior,
        ]);
    }

    /* ----------------------------------------------------------------------------------------------
     | Interno
     |--------------------------------------------------------------------------------------------- */

    /**
     * El pipeline "Leads", o null si todavía no se sembró.
     *
     * Null es un caso normal y no un error: una base recién creada, o una producción donde el
     * seeder de `pendientes.json` todavía no corrió. Con null, todas las entradas de este servicio
     * terminan sin hacer nada y el admin funciona exactamente como antes de la misión.
     *
     * @return Pipeline|null
     */
    private function pipeline()
    {
        return Pipeline::query()
            ->where('slug', PipelineLeadSeeder::SLUG)
            ->whereNull('archived_at')
            ->first();
    }

    /**
     * La etapa del pipeline con esa clave estable, o la que lleve el nombre que sembró el seeder.
     *
     * 🔴 El respaldo por nombre existe para un pipeline `lead` armado a mano, o sembrado antes de
     * que existiera `pipeline_stages.stage_key`. Y si no encuentra ninguna de las dos devuelve null
     * y el llamador NO mueve: una etapa que Tomás borró no se adivina con la de al lado.
     *
     * @param Pipeline $pipeline
     * @param string   $clave
     *
     * @return PipelineStage|null
     */
    private function etapa_por_clave(Pipeline $pipeline, $clave)
    {
        $etapa = PipelineStage::query()
            ->where('pipeline_id', $pipeline->id)
            ->where('stage_key', $clave)
            ->orderBy('sort_order')->orderBy('id')
            ->first();

        if ($etapa !== null) {
            return $etapa;
        }

        $nombres = [
            PipelineLeadSeeder::ETAPA_INTERESADOS        => 'Interesados',
            PipelineLeadSeeder::ETAPA_ESPERANDO_LLAMADA  => 'Esperando la llamada',
            PipelineLeadSeeder::ETAPA_EN_SEGUIMIENTO     => 'En seguimiento',
            PipelineLeadSeeder::ETAPA_CERRADO            => 'Cerrado',
            PipelineLeadSeeder::ETAPA_NO_AVANZA          => 'No avanza',
        ];

        if (! isset($nombres[$clave])) {
            return null;
        }

        return PipelineStage::query()
            ->where('pipeline_id', $pipeline->id)
            ->where('name', $nombres[$clave])
            ->orderBy('sort_order')->orderBy('id')
            ->first();
    }

    /**
     * La oportunidad ABIERTA del lead en el pipeline, o null.
     *
     * @param Pipeline $pipeline
     * @param Lead     $lead
     *
     * @return PipelineOpportunity|null
     */
    private function oportunidad_abierta(Pipeline $pipeline, Lead $lead)
    {
        return PipelineOpportunity::query()
            ->join('pipeline_stages', 'pipeline_stages.id', '=', 'pipeline_opportunities.stage_id')
            ->where('pipeline_opportunities.pipeline_id', $pipeline->id)
            ->where('pipeline_opportunities.lead_id', $lead->id)
            ->where('pipeline_stages.type', PipelineStage::TYPE_OPEN)
            ->orderByDesc('pipeline_opportunities.id')
            ->select('pipeline_opportunities.*')
            ->first();
    }

    /**
     * Id del primer admin marcado como closer, o null.
     *
     * Mismo criterio que `CloserNotificationService`: el responsable de la tarjeta es el mismo que
     * recibe el aviso por WhatsApp. Sin closer cargado la oportunidad nace sin responsable, que es
     * un estado que el módulo soporta (`owner_admin_id` nullable) y que se ve en el tablero.
     *
     * @return int|null
     */
    private function id_del_closer()
    {
        $closer = Admin::query()->where('is_closer', true)->orderBy('id')->first();

        return $closer === null ? null : (int) $closer->id;
    }

    /**
     * Deja el error en el log sin propagarlo.
     *
     * @param string               $que
     * @param \Throwable           $error
     * @param array<string, mixed> $contexto
     *
     * @return void
     */
    private function avisar($que, \Throwable $error, array $contexto)
    {
        Log::channel('daily')->warning(
            self::LOG . ' ' . $que . ': ' . $error->getMessage(),
            array_merge($contexto, ['excepcion' => get_class($error)])
        );
    }
}

<?php

namespace App\Services;

use App\Models\AgentImplementation;
use App\Models\AgentImplementationEvent;
use App\Models\AgentImplementationStage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Manda un WhatsApp de la implementación de Agentes de IA por el TICKET DE SOPORTE del dueño, por el
 * número de Kapso del admin (misión `implementacion-agentes-ia`, 28/10/2026). Es el HERMANO de
 * `ImplementationSupportWhatsappService` (el de la implementación del sistema): mismos frenos, misma
 * idempotencia, otro lugar donde se guarda.
 *
 * 🔴 POR QUÉ ES UN HERMANO Y NO UNA COPIA. Lo que no depende de la implementación —a quién va
 * (`resolver_contacto`), qué pasaría si se manda (`plan`: ventana, plantilla, adónde cae la respuesta,
 * Martín atendiendo o con un entrante pendiente), el envío en sí (`enviar`, que es
 * `SupportWhatsappOpenerService::open()`) y la huella del contenido— se le PIDE al servicio del sistema,
 * sin tocarlo. Dos definiciones de «cuándo se puede mandar un mensaje por el ticket sin pisarle la
 * conversación a Martín» se desincronizarían sin que nada lo denuncie. Lo único propio de acá es dónde vive
 * la operación (`agent_implementation_stages.data['whatsapp_ops']`) y el evento `whatsapp` de la bitácora.
 *
 * El freno propio de este hermano: el circuito de Codex APAGADO es 422 `codex_apagado`. Con el circuito
 * apagado nadie atiende el ticket (Martín no corre), así que lo que conteste el cliente quedaría sin
 * respuesta; el envío del sistema no lo necesita porque ahí la respuesta caería al hilo de la
 * implementación (y lo frena `respuesta_al_hilo`).
 */
class AgentImplementationSupportWhatsappService
{
    /** Clave del `data` de la etapa donde viven las operaciones (la misma que en el servicio del sistema). */
    const OPS_KEY = AgentImplementationStage::DATA_KEY_WHATSAPP_OPS;

    /** «Acción» con la que se calcula la huella del contenido (este envío no tiene acciones del checklist). */
    const HUELLA_ACTION = 'agentes';

    /**
     * El servicio del sistema, del que se reusan contacto, plan, envío y huella.
     *
     * @var ImplementationSupportWhatsappService
     */
    private $system_service;

    /**
     * El registro de eventos de la implementación de agentes.
     *
     * @var AgentImplementationService
     */
    private $implementations;

    /**
     * @param ImplementationSupportWhatsappService $system_service  El envío por soporte del sistema.
     * @param AgentImplementationService           $implementations Las reglas de la implementación de agentes.
     */
    public function __construct(ImplementationSupportWhatsappService $system_service, AgentImplementationService $implementations)
    {
        $this->system_service  = $system_service;
        $this->implementations = $implementations;
    }

    /**
     * El servicio del sistema (para que el controlador resuelva contacto y plan con la MISMA cuenta).
     *
     * @return ImplementationSupportWhatsappService
     */
    public function system_service()
    {
        return $this->system_service;
    }

    /**
     * La huella del contenido: texto, etapa y destinatario (con la acción fija `agentes`).
     *
     * @param string $texto    El texto.
     * @param int    $etapa    La etapa donde se registra.
     * @param string $telefono El teléfono E.164 del destinatario.
     *
     * @return string sha1.
     */
    public function huella($texto, $etapa, $telefono)
    {
        return $this->system_service->huella($texto, self::HUELLA_ACTION, $etapa, $telefono);
    }

    /**
     * Busca una operación por su `operation_id` en TODAS las etapas de la implementación (la misma clave
     * con otra etapa tiene que dar 409, no un segundo envío).
     *
     * @param AgentImplementation $implementation La implementación.
     * @param string              $operation_id   La clave.
     * @param bool                $lock           true = `lockForUpdate()` de las etapas.
     *
     * @return array{etapa: int, operacion: array<string, mixed>}|null
     */
    public function find_operation(AgentImplementation $implementation, $operation_id, $lock = false)
    {
        $query = AgentImplementationStage::where('agent_implementation_id', $implementation->id)->orderBy('stage_number');

        if ($lock) {
            $query->lockForUpdate();
        }

        foreach ($query->get() as $stage) {
            $data = is_array($stage->data) ? $stage->data : [];
            $ops  = isset($data[self::OPS_KEY]) && is_array($data[self::OPS_KEY]) ? $data[self::OPS_KEY] : [];

            if (isset($ops[$operation_id]) && is_array($ops[$operation_id])) {
                return ['etapa' => (int) $stage->stage_number, 'operacion' => $ops[$operation_id]];
            }
        }

        return null;
    }

    /**
     * Reserva la operación ANTES de mandar (`sending`), con la implementación bloqueada: la búsqueda y la
     * reserva son una sola operación, así dos llamadas simultáneas con la misma clave no mandan dos veces.
     * Si el proceso muere después de reservar, la clave queda en `sending` y contesta 409 `envio_en_curso`
     * (a propósito: no se sabe si salió; se mira el ticket).
     *
     * @param AgentImplementation $implementation La implementación.
     * @param int                 $etapa          La etapa donde se guarda.
     * @param string              $operation_id   La clave.
     * @param string              $huella         La huella del contenido.
     *
     * @return array{reservada: bool, existente: array<string, mixed>|null, sin_etapa: bool}
     */
    public function reserve(AgentImplementation $implementation, $etapa, $operation_id, $huella)
    {
        return DB::transaction(function () use ($implementation, $etapa, $operation_id, $huella) {
            AgentImplementation::where('id', $implementation->id)->lockForUpdate()->first();

            $previous = $this->find_operation($implementation, $operation_id, true);
            if ($previous !== null) {
                return ['reservada' => false, 'existente' => $previous, 'sin_etapa' => false];
            }

            $stage = AgentImplementationStage::where('agent_implementation_id', $implementation->id)
                ->where('stage_number', (int) $etapa)
                ->lockForUpdate()
                ->first();

            if ($stage === null) {
                return ['reservada' => false, 'existente' => null, 'sin_etapa' => true];
            }

            $data = is_array($stage->data) ? $stage->data : [];
            $ops  = isset($data[self::OPS_KEY]) && is_array($data[self::OPS_KEY]) ? $data[self::OPS_KEY] : [];

            $ops[$operation_id] = [
                'huella'       => $huella,
                'etapa'        => (int) $etapa,
                'estado'       => ImplementationSupportWhatsappService::ESTADO_ENVIANDO,
                'reservada_at' => now()->toISOString(),
            ];

            $data[self::OPS_KEY] = $ops;
            $stage->data         = $data;
            $stage->save();

            return ['reservada' => true, 'existente' => null, 'sin_etapa' => false];
        });
    }

    /**
     * Cierra la operación con su resultado y, si el cliente recibió el mensaje (`sent` o `partial`), deja
     * el evento `whatsapp` en la bitácora con el texto (recortado a 1000). 🔴 Un `failed` o `unknown` no
     * deja evento: Martín leería que se le dijo algo que el cliente no recibió.
     *
     * @param AgentImplementation  $implementation La implementación.
     * @param int                  $etapa          La etapa de la operación.
     * @param string               $operation_id   La clave.
     * @param string               $texto          El texto mandado.
     * @param array<string, mixed> $result         Lo que devolvió `ImplementationSupportWhatsappService::enviar()`.
     *
     * @return array<string, mixed> La operación tal como quedó guardada.
     */
    public function register(AgentImplementation $implementation, $etapa, $operation_id, $texto, array $result)
    {
        return DB::transaction(function () use ($implementation, $etapa, $operation_id, $texto, $result) {
            $stage = AgentImplementationStage::where('agent_implementation_id', $implementation->id)
                ->where('stage_number', (int) $etapa)
                ->lockForUpdate()
                ->first();

            /* La etapa existía al reservar; si alguien la borró en el medio no hay dónde anotar. El mensaje
               ya salió (o no): no se tira, se loguea y se devuelve el resultado igual. */
            if ($stage === null) {
                Log::channel('daily')->warning('AgentImplementationSupportWhatsappService: la etapa desapareció antes de registrar el envío.', [
                    'agent_implementation_id' => (int) $implementation->id,
                    'etapa'                   => (int) $etapa,
                    'operation_id'            => (string) $operation_id,
                ]);

                return $result;
            }

            $data     = is_array($stage->data) ? $stage->data : [];
            $ops      = isset($data[self::OPS_KEY]) && is_array($data[self::OPS_KEY]) ? $data[self::OPS_KEY] : [];
            $previous = isset($ops[$operation_id]) && is_array($ops[$operation_id]) ? $ops[$operation_id] : [];

            $operation           = array_merge($previous, $result, ['terminada_at' => now()->toISOString()]);
            $ops[$operation_id]  = $operation;
            $data[self::OPS_KEY] = $ops;
            $stage->data         = $data;
            $stage->save();

            $delivered = in_array($result['estado'], [
                ImplementationSupportWhatsappService::ESTADO_ENVIADO,
                ImplementationSupportWhatsappService::ESTADO_PARCIAL,
            ], true);

            if ($delivered) {
                $this->implementations->add_event(
                    $implementation,
                    $texto,
                    (int) $etapa,
                    AgentImplementationEvent::KIND_WHATSAPP,
                    AgentImplementationEvent::SOURCE_CLAUDE
                );
            }

            return $operation;
        });
    }
}

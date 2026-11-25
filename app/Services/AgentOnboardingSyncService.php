<?php

namespace App\Services;

use App\Models\AgentImplementation;
use App\Models\AgentImplementationEvent;
use App\Models\AgentImplementationStage;
use App\Services\IaApp\IaEmpresaCliente;
use Illuminate\Support\Facades\Log;

/**
 * Marca solo el hito `onboarding_completado` de la etapa 1 de una implementación de Agentes de IA (misión
 * onboarding-conversacional-whatsapp, 23-24/11/2026, contrato C1).
 *
 * Hasta hoy el hito sólo lo registraba Claude o Martín a mano. Ahora el admin le pregunta a la empresa del cliente
 * (`GET api/admin-sync/agente-contexto/estado`, el mismo patrón que `InicializarAgenteIaJob`: el admin consulta, la
 * empresa no tiene cliente HTTP hacia acá) si el dueño ya contó su negocio, ya sea en el formulario «Conozcámonos»
 * de la app o con los 13 temas esenciales por WhatsApp. La cuenta la hace la empresa y el admin sólo la copia.
 *
 * 🔴 Es tolerante por diseño: una empresa con versión vieja (404/405), sin la extensión (409), con otra clave (401),
 * caída o que contesta algo que no es el contrato NO escribe nada ni lanza (el hito sigue pudiéndose registrar a mano,
 * como hoy). Y es idempotente: con el hito ya marcado (por quien sea) no vuelve a preguntar ni a escribir.
 */
class AgentOnboardingSyncService
{
    /** Ruta del contrato C1 en la empresa del cliente. */
    const RUTA_ESTADO = 'api/admin-sync/agente-contexto/estado';

    /** El hito de la etapa 1 que se marca. */
    const HITO = 'onboarding_completado';

    /** Qué pasó con una implementación. */
    const MARCADO = 'marcado';

    const YA_ESTABA = 'ya_estaba';

    const SIN_DATO = 'sin_dato';

    const NO_APLICA = 'no_aplica';

    const ERROR = 'error';

    /** @var IaEmpresaCliente */
    protected $empresa;

    /** @var AgentImplementationService */
    protected $implementaciones;

    /**
     * @param IaEmpresaCliente           $empresa
     * @param AgentImplementationService $implementaciones
     */
    public function __construct(IaEmpresaCliente $empresa, AgentImplementationService $implementaciones)
    {
        $this->empresa          = $empresa;
        $this->implementaciones = $implementaciones;
    }

    /**
     * Consulta a la empresa del cliente y, si el dueño ya contó su negocio, marca el hito.
     *
     * Sólo mira implementaciones abiertas del flujo de entrega personal (versión 2 o más): las cerradas y las del
     * flujo viejo no se consultan.
     *
     * @param AgentImplementation $impl La implementación.
     *
     * @return string `marcado` | `ya_estaba` | `sin_dato` | `no_aplica` | `error`.
     */
    public function sincronizar(AgentImplementation $impl)
    {
        if ((string) $impl->status !== AgentImplementation::STATUS_IN_PROGRESS
            || (int) $impl->flujo_version < AgentImplementation::FLUJO_ENTREGA_PERSONAL) {
            return self::NO_APLICA;
        }

        try {
            $etapa = AgentImplementationStage::where('agent_implementation_id', $impl->id)->where('stage_number', 1)->first();
            $data  = $etapa !== null && is_array($etapa->data) ? $etapa->data : [];

            if (isset($data[self::HITO]) && $data[self::HITO] === true) {
                return self::YA_ESTABA;
            }

            $client = $impl->client;

            if ($client === null) {
                return self::SIN_DATO;
            }

            $respuesta = $this->empresa->pedir($client, 'GET', self::RUTA_ESTADO, [], 15);
            $json      = $respuesta['json'];

            if ($respuesta['status'] !== 200 || ! is_array($json) || ! isset($json['onboarding_completado']) || $json['onboarding_completado'] !== true) {
                // 404/405: empresa con una versión sin el onboarding; 409: sin la extensión; 401: otra clave;
                // sin conexión: sin status. En todos, el hito queda como está (a mano sigue valiendo).
                Log::info('AgentOnboardingSyncService: la empresa del cliente no informa el onboarding completo.', [
                    'implementation_id' => (int) $impl->id,
                    'client_id'         => (int) $client->id,
                    'status'            => $respuesta['status'],
                    'motivo'            => $respuesta['motivo'],
                ]);

                return self::SIN_DATO;
            }

            $por = isset($json['onboarding_completado_por']) && in_array($json['onboarding_completado_por'], ['app', 'whatsapp'], true)
                ? (string) $json['onboarding_completado_por']
                : 'empresa';

            $datos = [self::HITO => true, 'onboarding_completado_por' => $por];

            if (isset($json['onboarding_completado_en']) && is_string($json['onboarding_completado_en']) && $json['onboarding_completado_en'] !== '') {
                $datos['onboarding_completado_en'] = mb_substr($json['onboarding_completado_en'], 0, 40);
            }

            $nota = $por === 'whatsapp'
                ? 'El dueño completó el contexto del negocio por WhatsApp (13 de 13 temas esenciales).'
                : 'El dueño completó el contexto del negocio en «Conozcámonos» de la app.';

            $this->implementaciones->update_stage(
                $impl,
                1,
                $etapa !== null ? (string) $etapa->status : AgentImplementationStage::STATUS_PENDING,
                $datos,
                $nota,
                AgentImplementationEvent::SOURCE_SISTEMA
            );

            return self::MARCADO;
        } catch (\Throwable $e) {
            Log::warning('AgentOnboardingSyncService: no se pudo sincronizar el onboarding.', [
                'implementation_id' => (int) $impl->id,
                'error'             => mb_substr($e->getMessage(), 0, 300),
            ]);

            return self::ERROR;
        }
    }
}

<?php

namespace App\Console\Commands;

use App\Models\AgentImplementation;
use App\Services\AgentOnboardingSyncService;
use Illuminate\Console\Command;

/**
 * Cada media hora le pregunta a la empresa de cada cliente con una implementación de Agentes de IA abierta si el
 * dueño ya contó su negocio, y marca solo el hito `onboarding_completado` (misión
 * onboarding-conversacional-whatsapp, contrato C1). Ver AgentOnboardingSyncService.
 *
 * Recorre en lotes de 50 (`chunkById`) las implementaciones abiertas del flujo de entrega personal. Un error en una no
 * frena las demás; el comando siempre termina con 0.
 */
class SincronizarOnboardingDeAgentes extends Command
{
    /** @var string */
    protected $signature = 'agentes:sincronizar-onboarding';

    /** @var string */
    protected $description = 'Marca el hito onboarding_completado de las implementaciones de Agentes de IA cuando la empresa del cliente informa que el dueño ya contó su negocio.';

    /**
     * @param AgentOnboardingSyncService $servicio
     *
     * @return int
     */
    public function handle(AgentOnboardingSyncService $servicio)
    {
        $cuentas = [];

        AgentImplementation::where('status', AgentImplementation::STATUS_IN_PROGRESS)
            ->where('flujo_version', '>=', AgentImplementation::FLUJO_ENTREGA_PERSONAL)
            ->chunkById(50, function ($lote) use ($servicio, &$cuentas) {
                foreach ($lote as $impl) {
                    $resultado          = $servicio->sincronizar($impl);
                    $cuentas[$resultado] = ($cuentas[$resultado] ?? 0) + 1;
                }
            });

        $this->info('Onboarding de agentes: ' . (count($cuentas) ? json_encode($cuentas) : 'nada para revisar'));

        return 0;
    }
}

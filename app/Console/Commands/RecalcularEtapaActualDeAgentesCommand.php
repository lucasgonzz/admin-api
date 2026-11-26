<?php

namespace App\Console\Commands;

use App\Models\AgentImplementation;
use App\Services\AgentImplementationService;
use Illuminate\Console\Command;

/**
 * Recalcula `current_stage` de las implementaciones de Agentes de IA que están en curso (misión
 * agenda-openclaw-empuje, 25/11/2026).
 *
 * La regla de la etapa actual cambió (ahora mide el avance real: la etapa más alta iniciada) y las
 * implementaciones que ya estaban en producción, como Pablo y Fernando, siguen guardadas en la etapa 1. Este
 * comando las pone al día. Se corre una vez al desplegar; es idempotente.
 *
 * `--ver` solo imprime qué cambiaría, sin escribir. No recorre owners ni usuarios: lee la tabla
 * `agent_implementations` directo, así que la regla de «solo owners» de los seeders no aplica.
 */
class RecalcularEtapaActualDeAgentesCommand extends Command
{
    /** Nombre y opciones del comando. */
    protected $signature = 'agentes:recalcular-etapa-actual {--ver : Solo muestra el antes y el después, sin guardar}';

    /** Descripción que muestra `artisan list`. */
    protected $description = 'Recalcula la etapa actual de las implementaciones de agentes en curso según el avance real.';

    /**
     * Recorre las implementaciones en curso e imprime `id <n> (<cliente>): <antes> -> <después>`.
     *
     * Con `--ver` no guarda. Sin `--ver` guarda con {@see AgentImplementationService::refresh_current_stage()},
     * que ya respeta que Lite sea siempre la etapa 1.
     *
     * @param AgentImplementationService $service Quien recalcula y guarda la etapa actual.
     *
     * @return int Código de salida (0 = bien).
     */
    public function handle(AgentImplementationService $service)
    {
        // Si solo se mira, no se escribe nada.
        $solo_ver = (bool) $this->option('ver');

        // Cuántas cambian (o cambiarían) de etapa.
        $cambian = 0;

        AgentImplementation::query()
            ->where('status', AgentImplementation::STATUS_IN_PROGRESS)
            ->with('client:id,name')
            ->chunkById(100, function ($implementations) use ($service, $solo_ver, &$cambian) {
                foreach ($implementations as $implementation) {
                    // Etapa guardada hoy.
                    $antes = (int) $implementation->current_stage;

                    // Etapa que corresponde según el avance real (Lite = 1).
                    $despues = $this->etapa_que_corresponde($service, $implementation, $solo_ver);

                    if ($antes !== $despues) {
                        $cambian++;
                    }

                    $cliente = $implementation->client !== null ? (string) $implementation->client->name : 'sin cliente';

                    $this->line('id ' . $implementation->id . ' (' . $cliente . '): ' . $antes . ' -> ' . $despues);
                }
            });

        $this->info(($solo_ver ? 'Cambiarían: ' : 'Actualizadas: ') . $cambian);

        return 0;
    }

    /**
     * La etapa que corresponde a una implementación; la guarda salvo que sea `--ver`.
     *
     * @param AgentImplementationService $service        Servicio que calcula y guarda.
     * @param AgentImplementation        $implementation La implementación.
     * @param bool                       $solo_ver       true = no escribir.
     *
     * @return int
     */
    private function etapa_que_corresponde(AgentImplementationService $service, AgentImplementation $implementation, $solo_ver)
    {
        if (! $solo_ver) {
            return (int) $service->refresh_current_stage($implementation);
        }

        // Lite no tiene etapas 2 a 8: queda siempre en la 1.
        if ($implementation->paquete === AgentImplementation::PAQUETE_LITE) {
            return AgentImplementation::FIRST_STAGE;
        }

        return (int) AgentImplementation::compute_current_stage($implementation->stages()->get(['stage_number', 'status']));
    }
}

<?php

namespace App\Console\Commands;

use App\Services\ImplementationFollowups\ImplementationFollowupService;
use Illuminate\Console\Command;

/**
 * El reloj del seguimiento proactivo de las implementaciones (misión cruzada `seguimiento-de-implementaciones`,
 * 11/11/2026): altas automáticas, contacto del cliente, fin de pausa, frenado y terminado. NO manda nada: los
 * mensajes los escribe Martín por el monitor (`support-codex/implementation-followups/*`).
 *
 * Corre cada 15 minutos (Kernel) y es idempotente: una segunda corrida seguida no cambia nada. Con el kill switch
 * `implementation_followups_enabled = 0` no hace nada.
 *
 * Uso: `php artisan implementaciones:seguimientos`.
 */
class ImplementacionesSeguimientosCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'implementaciones:seguimientos';

    /**
     * @var string
     */
    protected $description = 'Lleva el reloj del seguimiento proactivo de las implementaciones (altas, pausas, frenado, terminado)';

    /**
     * @param ImplementationFollowupService $servicio
     *
     * @return int
     */
    public function handle(ImplementationFollowupService $servicio)
    {
        $cuenta = $servicio->procesar();

        if (! $cuenta['habilitado']) {
            $this->line('Seguimiento de implementaciones apagado (implementation_followups_enabled = 0): no se hizo nada.');

            return 0;
        }

        $this->line(sprintf(
            'Seguimientos: %d altas, %d con contacto nuevo del cliente, %d reanudados, %d frenados, %d terminados.',
            $cuenta['creados'],
            $cuenta['contactos'],
            $cuenta['reanudados'],
            $cuenta['frenados'],
            $cuenta['terminados']
        ));

        return 0;
    }
}

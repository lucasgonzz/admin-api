<?php

namespace App\Console\Commands;

use App\Services\LeadCompromisoService;
use Illuminate\Console\Command;

/**
 * El reloj del motor de compromisos (misión leads-referidos-compromisos, etapa E2): corre cada minuto en segundo plano.
 *
 * Toma los compromisos vigentes cuya hora llegó (hasta 20 por corrida, con un candado por compromiso contra corridas dobles) y
 * los ejecuta —una tarea para Juan o un mensaje de Martín— o, si cambió algo en el lead desde que se acordaron, los re-evalúa con
 * IA. También recupera los que quedaron colgados. Con el motor apagado (`lead_compromisos_activo`) no hace nada.
 */
class LeadsCompromisosCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'leads:compromisos';

    /**
     * @var string
     */
    protected $description = 'Ejecuta o re-evalúa los compromisos de contacto con leads cuya hora llegó';

    /**
     * Una corrida del motor.
     *
     * @param LeadCompromisoService $servicio
     *
     * @return int
     */
    public function handle(LeadCompromisoService $servicio): int
    {
        $estadisticas = $servicio->correr();

        $this->info('Recuperados: ' . $estadisticas['recuperados'] . ' · tomados: ' . $estadisticas['tomados'] . ' · procesados: ' . $estadisticas['ejecutados']);

        return 0;
    }
}

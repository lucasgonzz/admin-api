<?php

namespace App\Console\Commands;

use App\Services\FranjaHorariaDeSeguimientos;
use App\Services\LeadFollowupService;
use Illuminate\Console\Command;

/**
 * Evalúa leads activos y dispara sugerencias IA o pausa según {@see LeadFollowupService}.
 */
class CheckLeadFollowups extends Command
{
    /**
     * @var string
     */
    protected $signature = 'leads:check-followups';

    /**
     * @var string
     */
    protected $description = 'Procesa seguimientos automáticos de leads (reglas + Claude)';

    /**
     * @param LeadFollowupService $followup_service
     *
     * @return int
     */
    public function handle(LeadFollowupService $followup_service)
    {
        $stats = $followup_service->process_all_active_leads();

        /* Franja horaria de los seguimientos automáticos (misión asistente-arreglos-segunda-prueba, N11,
           19/10/2026): fuera de ella el servicio no procesa a nadie, y la salida lo dice para que unos
           ceros de madrugada no parezcan un motor roto. `empty()` por si el servicio no trae la clave. */
        $fuera_de_franja = ! empty($stats['fuera_de_franja']);
        $franja          = sprintf(
            '%02d:00 a %02d:00',
            FranjaHorariaDeSeguimientos::HORA_DE_INICIO,
            FranjaHorariaDeSeguimientos::HORA_DE_FIN
        );
        $this->info('Fuera de la franja horaria (' . $franja . '): ' . ($fuera_de_franja ? 'sí, no se procesó ningún lead.' : 'no.'));

        $this->info('Leads procesados: '.$stats['processed']);
        $this->info('Sugerencias generadas: '.$stats['suggestions']);
        $this->info('Leads pausados: '.$stats['paused']);
        $this->info('Errores: '.$stats['errors']);

        return 0;
    }
}

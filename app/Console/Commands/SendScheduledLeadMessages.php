<?php

namespace App\Console\Commands;

use App\Services\LeadScheduledMessageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Manda los mensajes de WhatsApp que un operador dejó programados y ya cumplieron su hora.
 *
 * Corre cada minuto (ver `Kernel::schedule()`). Todo lo que decide qué sale y qué no —el lead que
 * pasó a ser cliente, el que quedó marcado como que no recibe mensajes, el que respondió antes, y
 * la revalidación de la ventana de 24 hs de Meta— vive en
 * {@see \App\Services\LeadScheduledMessageService::despachar_uno()}. Este comando no tiene ninguna
 * regla propia a propósito: el mismo despacho lo puede disparar un test o una consola sin depender
 * de artisan.
 */
class SendScheduledLeadMessages extends Command
{
    /**
     * Nombre del comando artisan.
     *
     * @var string
     */
    protected $signature = 'leads:send-scheduled-messages';

    /**
     * Descripción del comando para `php artisan list`.
     *
     * @var string
     */
    protected $description = 'Envía los mensajes de WhatsApp programados a leads cuya hora ya se cumplió';

    /**
     * Despacha los vencidos y deja el resumen en la consola y en el log.
     *
     * @param LeadScheduledMessageService $service
     *
     * @return int
     */
    public function handle(LeadScheduledMessageService $service): int
    {
        $resumen = $service->despachar_vencidos();

        if ((int) $resumen['despachados'] === 0) {
            /* Sin nada vencido no se loguea: el comando corre 1.440 veces por día y un log por
               corrida tapa el laravel.log de un cliente en una semana. */
            return 0;
        }

        $linea = 'Mensajes programados despachados: ' . (int) $resumen['despachados']
            . ' (enviados: ' . (int) $resumen['enviados']
            . ', cancelados: ' . (int) $resumen['cancelados']
            . ', con error: ' . (int) $resumen['errores'] . ').';

        $this->info($linea);
        Log::channel('daily')->info('SendScheduledLeadMessages: ' . $linea, $resumen);

        return 0;
    }
}

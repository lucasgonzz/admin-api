<?php

namespace App\Console\Commands;

use App\Services\PendingApprovalPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Conciliador por minuto de los avisos push de «mensaje esperando tu aprobación» (misión push-mensajes-por-aprobar).
 *
 * Llama a {@see PendingApprovalPushService::run()}, imprime los contadores y sale SIEMPRE con 0: un fallo del aviso no
 * tiene que marcar como rota la corrida del scheduler ni tapar a los demás comandos.
 */
class NotifyPendingApprovals extends Command
{
    /** @var string Nombre del comando. */
    protected $signature = 'pending-approvals:notify';

    /** @var string Descripción. */
    protected $description = 'Avisa por push los mensajes (leads, soporte y chat de la demo) que esperan aprobación de una persona';

    /**
     * Corre una vuelta del conciliador.
     *
     * @return int Siempre 0.
     */
    public function handle(): int
    {
        try {
            /* Servicio resuelto del contenedor: los tests ligan ahí una subclase sin red. */
            $counters = app(PendingApprovalPushService::class)->run();

            $this->line('pending-approvals:notify ' . json_encode($counters));
        } catch (\Throwable $e) {
            Log::channel('daily')->error('pending-approvals:notify: falló la vuelta.', ['error' => $e->getMessage()]);
            $this->error('pending-approvals:notify: ' . $e->getMessage());
        }

        return 0;
    }
}

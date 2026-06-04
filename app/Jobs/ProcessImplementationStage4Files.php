<?php

namespace App\Jobs;

use App\Services\ImplementationConversationService;
use App\Services\ImplementationStage4Scheduler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Procesa los archivos acumulados en la Etapa 4 tras el período de espera (debounce).
 *
 * El job se despacha con un token de programación. Al ejecutarse, verifica que
 * el token siga siendo el vigente para esa implementación: si el cliente envió más
 * archivos después de que este job fue encolado, el token habrá cambiado y el job
 * se descarta sin hacer nada (el nuevo token ya tiene su propio job encolado).
 */
class ProcessImplementationStage4Files implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @var int ID de la implementación cuyos archivos se procesarán.
     */
    private $implementation_id;

    /**
     * @var int Token de programación; debe coincidir con caché al ejecutar.
     */
    private $schedule_token;

    /**
     * @param int $implementation_id ID de la implementación.
     * @param int $schedule_token    Token de debounce asignado al encolar.
     */
    public function __construct(int $implementation_id, int $schedule_token)
    {
        $this->implementation_id = $implementation_id;
        $this->schedule_token    = $schedule_token;
    }

    /**
     * Verifica el token y delega el procesamiento al servicio de conversación.
     *
     * @param ImplementationStage4Scheduler       $scheduler Inyectado para verificar el token.
     * @param ImplementationConversationService   $service   Servicio principal de conversación.
     *
     * @return void
     */
    public function handle(
        ImplementationStage4Scheduler $scheduler,
        ImplementationConversationService $service
    ): void {
        // Verificar que el token siga siendo el vigente: si el cliente mandó más archivos,
        // este job ya es obsoleto y debe abortarse sin procesar.
        if (! $scheduler->is_schedule_token_current($this->implementation_id, $this->schedule_token)) {
            Log::channel('daily')->debug('ProcessImplementationStage4Files: omitido (token de debounce obsoleto).', [
                'implementation_id' => $this->implementation_id,
                'schedule_token'    => $this->schedule_token,
            ]);

            return;
        }

        // Token vigente: procesar los archivos acumulados.
        $service->process_stage4_pending_files($this->implementation_id);
    }
}

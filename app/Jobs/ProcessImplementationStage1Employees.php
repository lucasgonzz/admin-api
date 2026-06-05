<?php

namespace App\Jobs;

use App\Services\ImplementationConversationService;
use App\Services\ImplementationStage1EmployeesScheduler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Envía la pregunta de confirmación de fin de carga de empleados tras el período
 * de inactividad (debounce) de la Etapa 1.
 *
 * El job se despacha con un token de programación. Al ejecutarse, verifica que el
 * token siga siendo el vigente para esa implementación: si el cliente envió más
 * mensajes de empleados después de que este job fue encolado, el token habrá
 * cambiado y el job se descarta sin hacer nada.
 */
class ProcessImplementationStage1Employees implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @var int ID de la implementación cuya confirmación de empleados se procesará.
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
     * Verifica el token y delega el envío de la pregunta de confirmación al servicio.
     *
     * @param ImplementationStage1EmployeesScheduler $scheduler Inyectado para verificar el token.
     * @param ImplementationConversationService      $service   Servicio principal de conversación.
     *
     * @return void
     */
    public function handle(
        ImplementationStage1EmployeesScheduler $scheduler,
        ImplementationConversationService $service
    ): void {
        // Verificar que el token siga siendo el vigente: si el cliente mandó más mensajes
        // de empleados luego de que este job fue encolado, es obsoleto y debe abortarse.
        if (! $scheduler->is_schedule_token_current($this->implementation_id, $this->schedule_token)) {
            Log::channel('daily')->debug('ProcessImplementationStage1Employees: omitido (token de debounce obsoleto).', [
                'implementation_id' => $this->implementation_id,
                'schedule_token'    => $this->schedule_token,
            ]);

            return;
        }

        // Token vigente: enviar pregunta de confirmación al cliente.
        $service->process_stage1_employees_debounce($this->implementation_id);
    }
}

<?php

namespace App\Jobs;

use App\Models\Implementation;
use App\Services\ImplementationConversationService;
use App\Services\ImplementationSettings;
use App\Services\ImplementationUserSetupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Procesa el formulario de configuración de implementación después de ser enviado.
 *
 * Se despacha con un delay configurable (ImplementationSettings::get_form_contact_delay_seconds)
 * para dar tiempo entre el envío del formulario y el primer contacto automático por WhatsApp.
 *
 * Flujo:
 * 1. Dispara ImplementationUserSetupService para configurar empresa-api con los datos del formulario.
 * 2. Llama a ImplementationConversationService::handle_form_submitted para enviar el mensaje WhatsApp
 *    de confirmación al cliente y avanzar al stage 2.
 */
class ProcessImplementationFormSubmit implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @var int ID de la implementación cuyo formulario fue enviado.
     */
    private $implementation_id;

    /**
     * @param int $implementation_id ID de la implementación.
     */
    public function __construct(int $implementation_id)
    {
        // Guardar el ID para cargarlo fresco al ejecutarse (evita objetos stale en queue).
        $this->implementation_id = $implementation_id;

        // Aplicar el delay configurado en settings antes de procesar el formulario.
        $delay_seconds = ImplementationSettings::get_form_contact_delay_seconds();
        $this->delay(now()->addSeconds($delay_seconds));
    }

    /**
     * Ejecuta el procesamiento del formulario enviado.
     *
     * Recarga la implementación desde BD para evitar datos stale del momento del dispatch.
     *
     * @return void
     */
    public function handle(): void
    {
        // Recargar la implementación fresca desde BD.
        $implementation = Implementation::find($this->implementation_id);

        if ($implementation === null) {
            Log::channel('daily')->warning('ProcessImplementationFormSubmit: implementación no encontrada.', [
                'implementation_id' => $this->implementation_id,
            ]);

            return;
        }

        Log::channel('daily')->info('ProcessImplementationFormSubmit: procesando formulario enviado.', [
            'implementation_id' => $this->implementation_id,
        ]);

        // Configurar la empresa en empresa-api con los datos del formulario (best-effort).
        try {
            (new ImplementationUserSetupService())->trigger_user_setup($implementation);
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('ProcessImplementationFormSubmit: fallo trigger_user_setup.', [
                'implementation_id' => $this->implementation_id,
                'error'             => $exception->getMessage(),
            ]);
        }

        // Enviar mensaje de confirmación al cliente y avanzar a la siguiente etapa.
        try {
            (new ImplementationConversationService())->handle_form_submitted($implementation);
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('ProcessImplementationFormSubmit: fallo handle_form_submitted.', [
                'implementation_id' => $this->implementation_id,
                'error'             => $exception->getMessage(),
            ]);
        }
    }
}

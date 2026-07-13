<?php

namespace App\Jobs;

use App\Models\Implementation;
use App\Services\ImplementationConversationService;
use App\Services\ImplementationSettings;
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
 * 1. Llama a ImplementationConversationService::handle_form_submitted para enviar el mensaje WhatsApp
 *    de confirmación al cliente y avanzar al stage 2.
 *
 * Nota: el UserSetup (ImplementationUserSetupService::trigger_user_setup) ya NO se dispara acá.
 * Se disparaba dos veces (acá y en handle_stage_advance() al entrar a la Etapa 2), y además
 * pegarle a la client_api en la Etapa 1 fallaba porque el sistema recién se instala en la Etapa 2.
 * Queda a cargo únicamente de ImplementationConversationService::handle_stage_advance().
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

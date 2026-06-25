<?php

namespace App\Jobs;

use App\Models\AdminSetting;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\CloserAlertService;
use App\Services\WhatsappSendService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Fallback-1 del flujo de alerta del closer.
 *
 * Se encola desde CloserAlertService::fire_alert() con un delay configurable
 * (closer_alert_delay_minutes, default 5 min). Si el closer no aceptó la alerta
 * en ese tiempo, avisa al lead que el closer se demoró y encola el fallback-2
 * (CloserAbandonRescheduleJob) con otro delay configurable.
 */
class CloserDelayMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @var int ID del lead destinatario.
     */
    private $lead_id;

    /**
     * @param int $lead_id ID del lead para el cual verificar si el closer aceptó.
     */
    public function __construct(int $lead_id)
    {
        $this->lead_id = $lead_id;
    }

    /**
     * Verifica si el closer ya aceptó. Si no, envía el mensaje de demora al lead
     * y encola el job de abandono para reagendar.
     *
     * @param WhatsappSendService $whatsapp Servicio de envío de WhatsApp.
     *
     * @return void
     */
    public function handle(WhatsappSendService $whatsapp): void
    {
        // Cargar el lead; si fue eliminado, salir silenciosamente.
        $lead = Lead::query()->find($this->lead_id);
        if ($lead === null) {
            Log::channel('daily')->warning('CloserDelayMessageJob: lead no encontrado.', [
                'lead_id' => $this->lead_id,
            ]);

            return;
        }

        // Si el closer ya aceptó en el tiempo de gracia, no hacer nada.
        if ($lead->closer_alert_accepted_at !== null) {
            Log::channel('daily')->info('CloserDelayMessageJob: closer aceptó a tiempo, skip.', [
                'lead_id' => $lead->id,
            ]);

            return;
        }

        // Anti-duplicado: si ya se envió el mensaje de demora, no repetir.
        if ($lead->closer_delay_message_sent_at !== null) {
            return;
        }

        // Construir saludo personalizado con el nombre del lead si está disponible.
        $name = trim((string) ($lead->contact_name ?? ''));
        $saludo = $name !== '' ? ", {$name}" : '';

        // Cuerpo del mensaje de demora enviado al lead por WhatsApp.
        $body = "Hola{$saludo}! El equipo está terminando con otra llamada... ni bien salga se pone en contacto con vos, no debería demorar mucho.";

        // Enviar mensaje al lead.
        $message_id = $whatsapp->send_text((string) $lead->phone, $body);

        if ($message_id === null) {
            Log::channel('daily')->warning('CloserDelayMessageJob: fallo al enviar WhatsApp al lead.', [
                'lead_id' => $lead->id,
            ]);
        }

        // Registrar el mensaje en la conversación del lead.
        LeadMessage::create([
            'lead_id'             => $lead->id,
            'sender'              => 'sistema',
            'content'             => $body,
            'status'              => 'enviado',
            'system_message_kind' => CloserAlertService::KIND_CLOSER_DELAY_NOTICE,
            'sent_at'             => now(),
        ]);

        // Guardar timestamp de envío del aviso de demora.
        $lead->closer_delay_message_sent_at = now();
        $lead->save();

        // Encolar fallback-2: reagendar si el closer sigue sin aparecer.
        $abandon_minutes = (int) AdminSetting::get('closer_alert_abandon_minutes', 20);
        CloserAbandonRescheduleJob::dispatch($lead->id)->delay(now()->addMinutes($abandon_minutes));

        Log::channel('daily')->info('CloserDelayMessageJob: mensaje de demora enviado al lead.', [
            'lead_id'         => $lead->id,
            'abandon_minutes' => $abandon_minutes,
        ]);
    }
}

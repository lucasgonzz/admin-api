<?php

namespace App\Jobs;

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
 * Fallback-2 del flujo de alerta del closer.
 *
 * Se encola desde CloserDelayMessageJob con un delay adicional configurable
 * (closer_alert_abandon_minutes, default 20 min). Si el closer sigue sin
 * aparecer, envía al lead un mensaje de disculpa indicando que se reagendará,
 * resetea la demo y devuelve el lead al estado "calificado" para que el agente
 * retome el flujo de agendamiento.
 */
class CloserAbandonRescheduleJob implements ShouldQueue
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
     * Verifica si el closer aceptó en algún momento. Si no, envía el mensaje de
     * reagendado al lead, resetea los campos de demo y retrocede el estado a "calificado".
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
            Log::channel('daily')->warning('CloserAbandonRescheduleJob: lead no encontrado.', [
                'lead_id' => $this->lead_id,
            ]);

            return;
        }

        // Si el closer aceptó en algún momento, no reagendar.
        if ($lead->closer_alert_accepted_at !== null) {
            Log::channel('daily')->info('CloserAbandonRescheduleJob: closer aceptó, skip reagendado.', [
                'lead_id' => $lead->id,
            ]);

            return;
        }

        // Anti-duplicado: si ya se tomó la decisión de reagendar, no repetir.
        if ($lead->closer_no_show_rescheduled_at !== null) {
            return;
        }

        // Construir saludo personalizado con el nombre del lead si está disponible.
        $name = trim((string) ($lead->contact_name ?? ''));
        $saludo = $name !== '' ? ", {$name}" : '';

        // Mensaje de disculpa y reagendado enviado al lead por WhatsApp.
        $body = "Hola{$saludo}... mirá, la reunión con el otro cliente se extendió más de lo esperado y lamentablemente no vamos a poder hacer la llamada hoy. Te pido disculpas. ¿Te queda bien que la coordinemos para mañana? Decime un horario que te venga bien y te lo confirmo.";

        // Enviar mensaje al lead.
        $message_id = $whatsapp->send_text((string) $lead->phone, $body);

        if ($message_id === null) {
            Log::channel('daily')->warning('CloserAbandonRescheduleJob: fallo al enviar WhatsApp de reagendado al lead.', [
                'lead_id' => $lead->id,
            ]);
        }

        // Registrar el mensaje en la conversación del lead.
        LeadMessage::create([
            'lead_id'             => $lead->id,
            'sender'              => 'sistema',
            'content'             => $body,
            'status'              => 'enviado',
            'system_message_kind' => CloserAlertService::KIND_CLOSER_NO_SHOW_RESCHEDULE,
            'sent_at'             => now(),
        ]);

        // Retroceder el lead a "calificado" para que el agente retome el flujo de agendamiento.
        $lead->status = 'calificado';

        // Limpiar la demo para que se pueda agendar nuevamente.
        $lead->demo_date       = null;
        $lead->demo_start_time = null;

        // Registrar el momento de reagendado.
        $lead->closer_no_show_rescheduled_at = now();

        $lead->save();

        Log::channel('daily')->info('CloserAbandonRescheduleJob: lead reagendado por no-aparición del closer.', [
            'lead_id' => $lead->id,
        ]);
    }
}

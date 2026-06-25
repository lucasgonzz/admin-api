<?php

namespace App\Services;

use App\Events\CloserCallAlert;
use App\Jobs\CloserDelayMessageJob;
use App\Models\Admin;
use App\Models\AdminSetting;
use App\Models\Lead;
use Illuminate\Support\Facades\Log;

/**
 * Orquesta el flujo de alerta "Tomar llamada" del closer.
 *
 * Tres momentos clave:
 * 1. fire_alert(): dispara modal en el panel + WhatsApp al closer. Se llama cuando demo_terminada_confirmada pasa a true.
 * 2. accept_alert(): el closer aceptó → enviar link de Meet al lead por WhatsApp.
 * 3. Los fallbacks automáticos (aviso de demora + reagendado) se gestionan desde CloserDelayMessageJob y CloserAbandonRescheduleJob.
 *
 * Constantes de kind de sistema para LeadMessage:
 */
class CloserAlertService
{
    /**
     * Kind registrado en LeadMessage cuando se avisa al lead que el closer se demoró.
     */
    const KIND_CLOSER_DELAY_NOTICE = 'closer_delay_notice';

    /**
     * Kind registrado en LeadMessage cuando el sistema decide reagendar por no-aparición del closer.
     */
    const KIND_CLOSER_NO_SHOW_RESCHEDULE = 'closer_no_show_reschedule';

    /**
     * @var WhatsappSendService
     */
    private $whatsapp;

    /**
     * @param WhatsappSendService|null $whatsapp Servicio de envío; si null se instancia internamente.
     */
    public function __construct(?WhatsappSendService $whatsapp = null)
    {
        $this->whatsapp = $whatsapp ?? new WhatsappSendService();
    }

    /**
     * Dispara la alerta inicial al closer: broadcast al panel + WhatsApp.
     * Encola el job de fallback para el aviso de demora al lead.
     *
     * Debe llamarse inmediatamente cuando demo_terminada_confirmada pasa a true.
     *
     * @param Lead $lead Lead que terminó la demo.
     *
     * @return void
     */
    public function fire_alert(Lead $lead): void
    {
        // Anti-duplicado: si ya se disparó la alerta para este lead, no repetir.
        if ($lead->closer_alert_sent_at !== null) {
            Log::channel('daily')->info('CloserAlertService: alerta ya disparada anteriormente, skip.', [
                'lead_id' => $lead->id,
            ]);

            return;
        }

        // Marcar timestamp de disparo de alerta.
        $lead->closer_alert_sent_at = now();
        $lead->save();

        // Broadcast del evento para el modal en admin-spa (canal closer-alerts).
        try {
            broadcast(new CloserCallAlert($lead));
        } catch (\Throwable $e) {
            Log::channel('daily')->warning('CloserAlertService: fallo en broadcast CloserCallAlert.', [
                'lead_id' => $lead->id,
                'error'   => $e->getMessage(),
            ]);
        }

        // Notificar al closer por WhatsApp.
        $this->notify_closer_whatsapp($lead);

        // Encolar job de fallback-1: aviso de demora al lead después de N minutos.
        $delay_minutes = (int) AdminSetting::get('closer_alert_delay_minutes', 5);
        CloserDelayMessageJob::dispatch($lead->id)->delay(now()->addMinutes($delay_minutes));

        Log::channel('daily')->info('CloserAlertService: alerta disparada.', [
            'lead_id'       => $lead->id,
            'delay_minutes' => $delay_minutes,
        ]);
    }

    /**
     * El closer aceptó la alerta: enviar link de Meet al lead por WhatsApp.
     * Registra el timestamp de aceptación para cancelar los fallbacks automáticos.
     *
     * @param Lead $lead Lead cuya alerta fue aceptada por el closer.
     *
     * @return void
     */
    public function accept_alert(Lead $lead): void
    {
        // Solo aceptar una vez; anti-duplicado si el closer toca el botón varias veces.
        if ($lead->closer_alert_accepted_at !== null) {
            return;
        }

        // Registrar momento de aceptación.
        $lead->closer_alert_accepted_at = now();
        $lead->save();

        // Si hay link de Meet, enviarlo al lead por WhatsApp.
        $meet_url = trim((string) ($lead->meet_url ?? ''));
        if ($meet_url !== '') {
            $name   = trim((string) ($lead->contact_name ?? ''));
            $saludo = $name !== '' ? "¡{$name}! " : '¡';

            $this->whatsapp->send_text(
                (string) $lead->phone,
                "¡{$saludo}Perfecto! Ya me estoy uniendo a la reunión. Entrá desde este link: {$meet_url}"
            );
        }

        Log::channel('daily')->info('CloserAlertService: alerta aceptada por el closer.', [
            'lead_id'  => $lead->id,
            'meet_url' => $meet_url,
        ]);
    }

    /**
     * Envía una notificación por WhatsApp al closer avisando que el lead terminó la demo.
     * Busca el primer admin marcado como closer con phone_number configurado.
     *
     * @param Lead $lead Lead que terminó la demo.
     *
     * @return void
     */
    private function notify_closer_whatsapp(Lead $lead): void
    {
        // Buscar el admin closer con número de teléfono configurado.
        $closer = Admin::query()->where('is_closer', true)->whereNotNull('phone_number')->first();

        if ($closer === null) {
            Log::channel('daily')->warning('CloserAlertService: no hay closer con phone_number configurado.', [
                'lead_id' => $lead->id,
            ]);

            return;
        }

        // Nombre de referencia del lead para el mensaje.
        $name = trim((string) ($lead->contact_name ?? ''));
        if ($name === '') {
            $name = trim((string) ($lead->company_name ?? ''));
        }
        if ($name === '') {
            $name = 'el lead';
        }

        // URL del panel del closer en admin-spa.
        $panel_url = rtrim((string) config('app.url'), '/') . '/closer';

        $message_id = $this->whatsapp->send_text(
            (string) $closer->phone_number,
            "🔔 {$name} terminó la demo. Entrá al panel para tomar la llamada: {$panel_url}"
        );

        if ($message_id === null) {
            Log::channel('daily')->warning('CloserAlertService: fallo al enviar WhatsApp al closer.', [
                'lead_id'   => $lead->id,
                'closer_id' => $closer->id,
            ]);
        }
    }
}

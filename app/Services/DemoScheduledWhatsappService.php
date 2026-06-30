<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\Lead;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Servicio responsable de notificar por WhatsApp a los admins suscritos
 * cuando se confirma y persiste una demo agendada (alta o reagendado).
 *
 * Usa send_template() para no depender de la ventana de 24hs de Meta.
 * Las plantillas deben estar aprobadas en Meta Business Manager.
 *
 * Variables de las plantillas (en orden):
 *   {{1}} → Nombre del lead (o empresa, o "Lead #ID" si no tiene ninguno)
 *   {{2}} → Fecha de la demo en formato legible (ej. "20/06/2026")
 *   {{3}} → Hora de inicio de la demo en formato HH:MM
 *   {{4}} → Link directo al lead en admin-spa (abre el modal de conversación)
 */
class DemoScheduledWhatsappService
{
    /**
     * Nombre de la plantilla de alta de demo (primera vez que se agenda).
     * Debe coincidir exactamente con el nombre registrado en Meta Business Manager.
     *
     * @var string
     */
    const TEMPLATE_NAME = 'demo_agendada_admin';

    /**
     * Nombre de la plantilla de reagendado de demo (cambio de horario).
     * Se usa cuando el lead cancela la demo existente y agenda una nueva.
     * Mismo set de variables que TEMPLATE_NAME; el texto del body distingue la situación.
     *
     * @var string
     */
    const TEMPLATE_NAME_REAGENDADO = 'cc_admin_demo_reagendada';

    /**
     * Instancia del servicio de envío de WhatsApp.
     *
     * @var WhatsappSendService
     */
    private $sender;

    /**
     * Constructor: recibe el servicio de envío por inyección.
     *
     * @param WhatsappSendService $sender Servicio que ejecuta el envío real vía Meta API.
     */
    public function __construct(WhatsappSendService $sender)
    {
        $this->sender = $sender;
    }

    /**
     * Notifica a todos los admins suscritos que se agendó (o reagendó) una demo.
     *
     * Busca admins con notify_demo_scheduled_whatsapp = true y phone_number cargado.
     * Para cada uno, envía la plantilla con los datos del lead y la demo.
     * Los errores individuales se logean pero no cortan el flujo de los demás admins.
     *
     * Si $is_reagendado = true, se usa TEMPLATE_NAME_REAGENDADO para que el admin
     * entienda que es un cambio de horario y no una demo nueva.
     *
     * @param Lead   $lead          Lead que agendó la demo.
     * @param string $demo_date     Fecha de la demo en formato Y-m-d.
     * @param string $demo_start    Hora de inicio en formato HH:MM.
     * @param bool   $is_reagendado true si el lead ya tenía una demo previa y cambió el horario.
     *
     * @return array<int, string> Nombres de los admins efectivamente notificados.
     */
    public function notify(Lead $lead, string $demo_date, string $demo_start, bool $is_reagendado = false): array
    {
        /* Elegir el template según si es un alta nueva o un cambio de horario. */
        $template_name = $is_reagendado ? self::TEMPLATE_NAME_REAGENDADO : self::TEMPLATE_NAME;
        /* Obtener admins que tienen activo el flag de notificación y tienen teléfono cargado. */
        $admins = Admin::where('notify_demo_scheduled_whatsapp', true)
            ->whereNotNull('phone_number')
            ->where('phone_number', '!=', '')
            ->get();

        /* Si no hay admins suscritos, loguear y salir sin error. */
        if ($admins->isEmpty()) {
            Log::info('DemoScheduledWhatsappService: sin admins suscritos con teléfono.', [
                'lead_id' => $lead->id,
            ]);
            return [];
        }

        /* Acumula los nombres de admins a los que se envió exitosamente. */
        $notified = [];

        /* Identificador legible del lead: nombre > empresa > "Lead #ID". */
        $nombre_lead = '';
        if (! empty($lead->contact_name)) {
            $nombre_lead = $lead->contact_name;
        } elseif (! empty($lead->company_name)) {
            $nombre_lead = $lead->company_name;
        } else {
            $nombre_lead = "Lead #{$lead->id}";
        }

        /* Fecha en formato d/m/Y (sin nombre de día, según el cuerpo real de la plantilla). */
        $fecha_legible = $this->format_date_legible($demo_date);

        /* Link directo al modal del lead en admin-spa (abre automáticamente vía query param lead_id). */
        $admin_spa_url = rtrim((string) config('services.admin_spa.url'), '/');
        $link_lead     = $admin_spa_url . '/leads?lead_id=' . $lead->id;

        /* Enviar notificación a cada admin suscrito. */
        foreach ($admins as $admin) {
            try {
                $this->sender->send_template(
                    (string) $admin->phone_number,
                    $template_name,
                    [$nombre_lead, $fecha_legible, $demo_start, $link_lead]
                );

                /* Registrar al admin como notificado exitosamente. */
                $notified[] = $admin->name;

                Log::info('DemoScheduledWhatsappService: notificación enviada.', [
                    'lead_id'       => $lead->id,
                    'admin_id'      => $admin->id,
                    'demo_date'     => $demo_date,
                    'demo_start'    => $demo_start,
                    'is_reagendado' => $is_reagendado,
                ]);
            } catch (\Throwable $e) {
                /* Loguear el error pero continuar con los demás admins. */
                Log::error('DemoScheduledWhatsappService: error al notificar admin.', [
                    'lead_id'  => $lead->id,
                    'admin_id' => $admin->id,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        return $notified;
    }

    /**
     * Convierte una fecha en formato Y-m-d a formato legible d/m/Y.
     *
     * Ejemplo: "2026-06-20" → "20/06/2026"
     * Si la conversión falla, devuelve la fecha original como fallback.
     *
     * @param string $demo_date Fecha en formato Y-m-d.
     *
     * @return string Fecha formateada o la cadena original si falla.
     */
    private function format_date_legible(string $demo_date): string
    {
        try {
            $carbon = Carbon::createFromFormat('Y-m-d', $demo_date, 'America/Argentina/Buenos_Aires');

            $dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
            $nombre_dia = ucfirst($dias[$carbon->dayOfWeek]);

            return $nombre_dia . ' ' . $carbon->format('d/m/Y');
        } catch (\Throwable $e) {
            return $demo_date;
        }
    }
}

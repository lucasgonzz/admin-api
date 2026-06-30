<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\Lead;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Servicio central de notificaciones WhatsApp a admins durante el ciclo de la demo.
 *
 * Centraliza todos los eventos del ciclo (check de ingreso enviado, ingreso confirmado,
 * no ingreso, fin confirmado, pendiente de terminar). El único flag de suscripción
 * utilizado es `notify_demo_scheduled_whatsapp`; no existe distinción entre closer y admin.
 *
 * Usa send_template() para evitar depender de la ventana de 24hs de Meta.
 * Todos los templates deben estar aprobados en Meta Business Manager antes de usarse.
 *
 * Templates requeridos:
 *   - cc_admin_demo_checkin_enviado    Variables: {{1}} nombre, {{2}} hora demo, {{3}} link
 *   - cc_admin_demo_ingreso_ok         Variables: {{1}} nombre, {{2}} hora ingreso (HH:MM), {{3}} link
 *   - cc_admin_demo_no_ingreso         Variables: {{1}} nombre, {{2}} motivo, {{3}} link
 *   - cc_admin_demo_terminada          Variables: {{1}} nombre, {{2}} rubro, {{3}} link
 *   - cc_admin_demo_pendiente_terminar Variables: {{1}} nombre, {{2}} hora demo, {{3}} link
 */
class DemoCicloAdminNotificationService
{
    /**
     * Nombre del template para notificar que se envió el check de ingreso al lead.
     *
     * @var string
     */
    const TEMPLATE_CHECK_INGRESO_ENVIADO = 'cc_admin_demo_checkin_enviado';

    /**
     * Nombre del template para notificar que el lead confirmó su ingreso a la demo.
     *
     * @var string
     */
    const TEMPLATE_INGRESO_OK = 'cc_admin_demo_ingreso_ok';

    /**
     * Nombre del template para notificar que el lead no ingresó a la demo.
     *
     * @var string
     */
    const TEMPLATE_NO_INGRESO = 'cc_admin_demo_no_ingreso';

    /**
     * Nombre del template para notificar que el lead confirmó que terminó la demo.
     * Incluye el rubro del lead para que quien vaya a cerrar tenga contexto.
     *
     * @var string
     */
    const TEMPLATE_TERMINADA = 'cc_admin_demo_terminada';

    /**
     * Nombre del template para notificar que el lead está en demo_pendiente_de_terminar
     * (no confirmó el fin dentro del tiempo límite).
     *
     * @var string
     */
    const TEMPLATE_PENDIENTE_TERMINAR = 'cc_admin_demo_pendiente_terminar';

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
     * Notifica a los admins suscritos que se envió el check de ingreso al lead.
     *
     * Evento 3 del ciclo: el comando CheckDemoIngress acaba de enviar el mensaje
     * de verificación al lead y transitó el estado a ingresando_demo.
     *
     * Variables de la plantilla: {{1}} nombre lead, {{2}} hora demo, {{3}} link.
     *
     * @param Lead $lead Lead al que se envió el check de ingreso.
     *
     * @return array<int, string> Nombres de los admins efectivamente notificados.
     */
    public function notify_check_ingreso_enviado(Lead $lead): array
    {
        /* Obtener admins suscritos con teléfono cargado. */
        $admins = $this->get_subscribed_admins();

        if ($admins->isEmpty()) {
            Log::info('DemoCicloAdminNotificationService: sin admins suscritos (check_ingreso_enviado).', [
                'lead_id' => $lead->id,
            ]);

            return [];
        }

        /* Identificador legible del lead. */
        $nombre_lead = $this->resolve_lead_name($lead);

        /* Hora de inicio de la demo (formato HH:MM) o guión si no está disponible. */
        $hora_demo = $lead->demo_start_time ?? '-';

        /* Link directo al modal del lead en admin-spa. */
        $link = $this->build_lead_link($lead);

        /* Acumula los nombres de admins a los que se envió exitosamente. */
        $notified = [];

        /* Enviar a cada admin suscrito. Los errores individuales no cortan el loop. */
        foreach ($admins as $admin) {
            try {
                $this->sender->send_template(
                    (string) $admin->phone_number,
                    self::TEMPLATE_CHECK_INGRESO_ENVIADO,
                    [$nombre_lead, $hora_demo, $link]
                );

                /* Registrar al admin como notificado exitosamente. */
                $notified[] = $admin->name;

                Log::info('DemoCicloAdminNotificationService: check_ingreso_enviado notificado.', [
                    'lead_id'  => $lead->id,
                    'admin_id' => $admin->id,
                ]);
            } catch (\Throwable $e) {
                Log::error('DemoCicloAdminNotificationService: error al notificar check_ingreso_enviado.', [
                    'lead_id'  => $lead->id,
                    'admin_id' => $admin->id,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        return $notified;
    }

    /**
     * Notifica a los admins suscritos que el lead confirmó su ingreso a la demo.
     *
     * Evento 4 del ciclo: Claude infirió de la respuesta del lead que ya entró.
     * Se llama después del save() del lead, por lo que demo_ingreso_confirmado_at
     * ya está persistido y disponible para leer la hora real de confirmación.
     *
     * Variables de la plantilla: {{1}} nombre lead, {{2}} hora de ingreso (HH:MM), {{3}} link.
     *
     * @param Lead $lead Lead que confirmó su ingreso a la demo.
     *
     * @return array<int, string> Nombres de los admins efectivamente notificados.
     */
    public function notify_ingreso_confirmado(Lead $lead): array
    {
        /* Obtener admins suscritos con teléfono cargado. */
        $admins = $this->get_subscribed_admins();

        if ($admins->isEmpty()) {
            Log::info('DemoCicloAdminNotificationService: sin admins suscritos (ingreso_confirmado).', [
                'lead_id' => $lead->id,
            ]);

            return [];
        }

        /* Identificador legible del lead. */
        $nombre_lead = $this->resolve_lead_name($lead);

        /*
         * Hora de confirmación de ingreso formateada como HH:MM.
         * Se lee de demo_ingreso_confirmado_at, que ya fue persistido antes de esta llamada.
         * Fallback: '-' si por alguna razón el campo es null.
         */
        $hora_ingreso = '-';
        if ($lead->demo_ingreso_confirmado_at !== null) {
            try {
                $hora_ingreso = Carbon::parse($lead->demo_ingreso_confirmado_at)
                    ->setTimezone('America/Argentina/Buenos_Aires')
                    ->format('H:i');
            } catch (\Throwable $e) {
                /* Mantener el fallback '-' si la fecha no es parseable. */
            }
        }

        /* Link directo al modal del lead en admin-spa. */
        $link = $this->build_lead_link($lead);

        /* Acumula los nombres de admins a los que se envió exitosamente. */
        $notified = [];

        /* Enviar a cada admin suscrito. Los errores individuales no cortan el loop. */
        foreach ($admins as $admin) {
            try {
                $this->sender->send_template(
                    (string) $admin->phone_number,
                    self::TEMPLATE_INGRESO_OK,
                    [$nombre_lead, $hora_ingreso, $link]
                );

                /* Registrar al admin como notificado exitosamente. */
                $notified[] = $admin->name;

                Log::info('DemoCicloAdminNotificationService: ingreso_confirmado notificado.', [
                    'lead_id'  => $lead->id,
                    'admin_id' => $admin->id,
                ]);
            } catch (\Throwable $e) {
                Log::error('DemoCicloAdminNotificationService: error al notificar ingreso_confirmado.', [
                    'lead_id'  => $lead->id,
                    'admin_id' => $admin->id,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        return $notified;
    }

    /**
     * Notifica a los admins suscritos que el lead no ingresó a la demo.
     *
     * Evento 5 del ciclo. Puede dispararse por dos razones distintas:
     *  - Timeout: el lead no respondió al check de ingreso (CheckDemoIngresoTimeout).
     *  - Inferencia: Claude detectó que el lead dijo que no puede entrar (marcar_no_ingreso).
     * El motivo se pasa como parámetro para que el admin entienda el contexto.
     *
     * Variables de la plantilla: {{1}} nombre lead, {{2}} motivo, {{3}} link.
     *
     * @param Lead   $lead   Lead que no ingresó a la demo.
     * @param string $motivo Motivo breve del no ingreso (ej. "no respondió al check de ingreso").
     *
     * @return array<int, string> Nombres de los admins efectivamente notificados.
     */
    public function notify_no_ingreso(Lead $lead, string $motivo): array
    {
        /* Obtener admins suscritos con teléfono cargado. */
        $admins = $this->get_subscribed_admins();

        if ($admins->isEmpty()) {
            Log::info('DemoCicloAdminNotificationService: sin admins suscritos (no_ingreso).', [
                'lead_id' => $lead->id,
            ]);

            return [];
        }

        /* Identificador legible del lead. */
        $nombre_lead = $this->resolve_lead_name($lead);

        /* Motivo limpio: si está vacío, usar texto genérico de fallback. */
        $motivo_limpio = $motivo !== '' ? $motivo : 'no ingresó a la demo';

        /* Link directo al modal del lead en admin-spa. */
        $link = $this->build_lead_link($lead);

        /* Acumula los nombres de admins a los que se envió exitosamente. */
        $notified = [];

        /* Enviar a cada admin suscrito. Los errores individuales no cortan el loop. */
        foreach ($admins as $admin) {
            try {
                $this->sender->send_template(
                    (string) $admin->phone_number,
                    self::TEMPLATE_NO_INGRESO,
                    [$nombre_lead, $motivo_limpio, $link]
                );

                /* Registrar al admin como notificado exitosamente. */
                $notified[] = $admin->name;

                Log::info('DemoCicloAdminNotificationService: no_ingreso notificado.', [
                    'lead_id'  => $lead->id,
                    'admin_id' => $admin->id,
                    'motivo'   => $motivo,
                ]);
            } catch (\Throwable $e) {
                Log::error('DemoCicloAdminNotificationService: error al notificar no_ingreso.', [
                    'lead_id'  => $lead->id,
                    'admin_id' => $admin->id,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        return $notified;
    }

    /**
     * Notifica a los admins suscritos que el lead confirmó que terminó la demo.
     *
     * Evento 6 del ciclo. Incluye el rubro del lead para que quien suba a cerrar
     * tenga contexto inmediato. Cubre también el evento 8 (reanudación desde
     * demo_pendiente_de_terminar) ya que confirmar_fin_demo es válida en ambos estados.
     *
     * El rubro se resuelve en orden: demo_summary_structured['empresa'] > company_name > '-'.
     *
     * Variables de la plantilla: {{1}} nombre lead, {{2}} rubro, {{3}} link.
     *
     * @param Lead $lead Lead que confirmó el fin de la demo.
     *
     * @return array<int, string> Nombres de los admins efectivamente notificados.
     */
    public function notify_fin_confirmado(Lead $lead): array
    {
        /* Obtener admins suscritos con teléfono cargado. */
        $admins = $this->get_subscribed_admins();

        if ($admins->isEmpty()) {
            Log::info('DemoCicloAdminNotificationService: sin admins suscritos (fin_confirmado).', [
                'lead_id' => $lead->id,
            ]);

            return [];
        }

        /* Identificador legible del lead. */
        $nombre_lead = $this->resolve_lead_name($lead);

        /*
         * Rubro del lead para dar contexto al closer que va a cerrar.
         * Prioridad: demo_summary_structured['empresa'] > company_name > '-'.
         */
        $rubro = '-';
        $structured = $lead->demo_summary_structured;
        if (is_array($structured) && ! empty($structured['empresa'])) {
            $rubro = (string) $structured['empresa'];
        } elseif (! empty($lead->company_name)) {
            $rubro = (string) $lead->company_name;
        }

        /* Link directo al modal del lead en admin-spa. */
        $link = $this->build_lead_link($lead);

        /* Acumula los nombres de admins a los que se envió exitosamente. */
        $notified = [];

        /* Enviar a cada admin suscrito. Los errores individuales no cortan el loop. */
        foreach ($admins as $admin) {
            try {
                $this->sender->send_template(
                    (string) $admin->phone_number,
                    self::TEMPLATE_TERMINADA,
                    [$nombre_lead, $rubro, $link]
                );

                /* Registrar al admin como notificado exitosamente. */
                $notified[] = $admin->name;

                Log::info('DemoCicloAdminNotificationService: fin_confirmado notificado.', [
                    'lead_id'  => $lead->id,
                    'admin_id' => $admin->id,
                ]);
            } catch (\Throwable $e) {
                Log::error('DemoCicloAdminNotificationService: error al notificar fin_confirmado.', [
                    'lead_id'  => $lead->id,
                    'admin_id' => $admin->id,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        return $notified;
    }

    /**
     * Notifica a los admins suscritos que el lead está pendiente de terminar la demo.
     *
     * Evento 7 del ciclo: el comando CheckDemoFinTimeout detectó que el lead no
     * confirmó el fin de la demo dentro del tiempo límite y lo pasó a
     * demo_pendiente_de_terminar.
     *
     * Variables de la plantilla: {{1}} nombre lead, {{2}} hora demo, {{3}} link.
     *
     * @param Lead $lead Lead en estado demo_pendiente_de_terminar.
     *
     * @return array<int, string> Nombres de los admins efectivamente notificados.
     */
    public function notify_pendiente_terminar(Lead $lead): array
    {
        /* Obtener admins suscritos con teléfono cargado. */
        $admins = $this->get_subscribed_admins();

        if ($admins->isEmpty()) {
            Log::info('DemoCicloAdminNotificationService: sin admins suscritos (pendiente_terminar).', [
                'lead_id' => $lead->id,
            ]);

            return [];
        }

        /* Identificador legible del lead. */
        $nombre_lead = $this->resolve_lead_name($lead);

        /* Hora de inicio de la demo (formato HH:MM) o guión si no está disponible. */
        $hora_demo = $lead->demo_start_time ?? '-';

        /* Link directo al modal del lead en admin-spa. */
        $link = $this->build_lead_link($lead);

        /* Acumula los nombres de admins a los que se envió exitosamente. */
        $notified = [];

        /* Enviar a cada admin suscrito. Los errores individuales no cortan el loop. */
        foreach ($admins as $admin) {
            try {
                $this->sender->send_template(
                    (string) $admin->phone_number,
                    self::TEMPLATE_PENDIENTE_TERMINAR,
                    [$nombre_lead, $hora_demo, $link]
                );

                /* Registrar al admin como notificado exitosamente. */
                $notified[] = $admin->name;

                Log::info('DemoCicloAdminNotificationService: pendiente_terminar notificado.', [
                    'lead_id'  => $lead->id,
                    'admin_id' => $admin->id,
                ]);
            } catch (\Throwable $e) {
                Log::error('DemoCicloAdminNotificationService: error al notificar pendiente_terminar.', [
                    'lead_id'  => $lead->id,
                    'admin_id' => $admin->id,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        return $notified;
    }

    /**
     * Retorna la colección de admins con el flag de suscripción activo y teléfono cargado.
     *
     * El flag utilizado es `notify_demo_scheduled_whatsapp` para todas las notificaciones
     * del ciclo de demo: no hay distinción entre closer y admin genérico.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    private function get_subscribed_admins()
    {
        return Admin::where('notify_demo_scheduled_whatsapp', true)
            ->whereNotNull('phone_number')
            ->where('phone_number', '!=', '')
            ->get();
    }

    /**
     * Resuelve el nombre legible del lead con fallback progresivo.
     *
     * Prioridad: contact_name > company_name > "Lead #ID".
     *
     * @param Lead $lead Lead del cual resolver el nombre.
     *
     * @return string Identificador legible del lead.
     */
    private function resolve_lead_name(Lead $lead): string
    {
        if (! empty($lead->contact_name)) {
            return $lead->contact_name;
        }

        if (! empty($lead->company_name)) {
            return $lead->company_name;
        }

        return "Lead #{$lead->id}";
    }

    /**
     * Construye el link directo al modal del lead en admin-spa.
     *
     * El query param lead_id hace que admin-spa abra el modal del lead automáticamente
     * al cargar la vista de leads (ver prompt 049).
     *
     * @param Lead $lead Lead al que apunta el link.
     *
     * @return string URL completa con query param lead_id.
     */
    private function build_lead_link(Lead $lead): string
    {
        $admin_spa_url = rtrim((string) config('services.admin_spa.url'), '/');

        return $admin_spa_url . '/leads?lead_id=' . $lead->id;
    }
}

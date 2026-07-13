<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\AdminSetting;

/**
 * Convierte a MINUTOS el valor ya guardado (si existe) de la demora de auto-envío de respaldo
 * en el tramo de agendamiento (`lead_whatsapp_verificacion_agendamiento_auto_send_delay_seconds`).
 *
 * Decisión de Lucas (13/7/2026): esa demora específica pasa a medirse en minutos porque es un
 * número poco natural para cargar en segundos desde el panel. El resto de las demoras de
 * LeadWhatsappOnboardingSettings (bienvenida, sugerencia IA, auto-envío general) NO cambian.
 */
class ConvertVerificacionAgendamientoDelayToMinutes extends Migration
{
    /**
     * Lee el valor viejo en segundos (si había uno guardado) y lo convierte a minutos,
     * redondeando siempre hacia arriba y sin dejarlo nunca en 0 si el original era mayor a 0.
     *
     * @return void
     */
    public function up()
    {
        // Valor histórico en segundos; null si nunca se guardó config personalizada.
        $old_seconds = AdminSetting::get('lead_whatsapp_verificacion_agendamiento_auto_send_delay_seconds');

        // Sin config previa: no hacemos nada, el seed nuevo siembra el default (30 minutos).
        if ($old_seconds === null) {
            return;
        }

        // Conversión: ceil hacia arriba, nunca 0 si el valor viejo ya era mayor a 0
        // (0 significa "auto-envío inmediato sin espera humana", un cambio de comportamiento
        // que no queremos introducir por un redondeo).
        $minutes = ((int) $old_seconds === 0) ? 0 : max(1, (int) ceil((int) $old_seconds / 60));

        // Se guarda bajo la key nueva en minutos; la key vieja en segundos NO se borra
        // (mismo criterio no destructivo que el resto de migraciones de admin_settings).
        AdminSetting::set('lead_whatsapp_verificacion_agendamiento_auto_send_delay_minutes', (string) $minutes);
    }

    /**
     * No se revierte: no se restauran admin_settings en el rollback (mismo patrón que las
     * demás migraciones de settings del repo), para no perder configuración personalizada.
     *
     * @return void
     */
    public function down()
    {
        // No-op: no eliminamos ni restauramos admin_settings en el rollback.
    }
}

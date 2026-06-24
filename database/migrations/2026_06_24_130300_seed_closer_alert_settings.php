<?php

use App\Models\AdminSetting;
use Illuminate\Database\Migrations\Migration;

/**
 * Siembra settings de timing para alertas "Tomar llamada" del panel del closer.
 */
class SeedCloserAlertSettings extends Migration
{
    /**
     * Inicializa closer_alert_delay_minutes y closer_alert_abandon_minutes si no existen.
     *
     * @return void
     */
    public function up()
    {
        if (AdminSetting::get('closer_alert_delay_minutes') === null) {
            AdminSetting::set('closer_alert_delay_minutes', '5');
        }

        if (AdminSetting::get('closer_alert_abandon_minutes') === null) {
            AdminSetting::set('closer_alert_abandon_minutes', '20');
        }
    }

    /**
     * No elimina settings al revertir (datos de configuración persistentes).
     *
     * @return void
     */
    public function down()
    {
        // Sin rollback destructivo de admin_settings.
    }
}

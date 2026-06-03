<?php

namespace Database\Seeders;

use App\Models\AdminSetting;
use App\Services\LeadWhatsappOnboardingSettings;
use Illuminate\Database\Seeder;

/**
 * Siembra configuraciones globales por defecto del panel admin.
 */
class AdminSettingSeeder extends Seeder
{
    /**
     * Inserta valores iniciales si aún no existen.
     *
     * @return void
     */
    public function run()
    {
        if (AdminSetting::get('support_alert_minutes') === null) {
            AdminSetting::set('support_alert_minutes', '30');
        }

        LeadWhatsappOnboardingSettings::seed_defaults_if_missing();
    }
}

<?php

namespace Database\Seeders;

use App\Models\AdminSetting;
use Illuminate\Database\Seeder;

/**
 * Siembra los settings del formulario público de configuración de implementación.
 *
 * Ejecutar en bases de datos existentes que no pasaron por AdminSettingSeeder completo:
 * php artisan db:seed --class=AddImplementationFormSettingsSeeder
 */
class AddImplementationFormSettingsSeeder extends Seeder
{
    /**
     * Inserta los valores por defecto si aún no existen.
     *
     * @return void
     */
    public function run()
    {
        // Delay entre envío del formulario y primer contacto WhatsApp (segundos); default: 60.
        if (AdminSetting::where('key', 'implementation_form_contact_delay_seconds')->doesntExist()) {
            AdminSetting::set('implementation_form_contact_delay_seconds', '60');
        }

        // URL base del formulario público de configuración en admin-spa; default: vacío.
        if (AdminSetting::where('key', 'implementation_form_url')->doesntExist()) {
            AdminSetting::set('implementation_form_url', '');
        }
    }
}

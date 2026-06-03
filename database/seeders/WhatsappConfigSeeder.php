<?php

namespace Database\Seeders;

use App\Models\WhatsappConfig;
use Illuminate\Database\Seeder;

/**
 * Siembra configuración placeholder de Kapso para desarrollo y testing.
 */
class WhatsappConfigSeeder extends Seeder
{
    /**
     * Inserta un registro activo con credenciales de ejemplo si aún no existe.
     *
     * @return void
     */
    public function run()
    {
        // Evita duplicar el registro activo en ejecuciones repetidas del seeder.
        if (WhatsappConfig::getActive()) {
            return;
        }

        WhatsappConfig::create([
            'kapso_api_key'   => 'cebf2e65f32f92f8437cce4e5582ba9a21ed4cc318d8fbef548c2e8d35271357',
            'phone_number_id' => '1135644799636575',
            'webhook_secret'  => 'ffb6e70a95b832e0dddfb84adfb4c20e22d74dac1cf02c0c438d7d9bc04bb20b',
            'is_active'       => true,
        ]);
    }
}

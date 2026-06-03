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
            'phone_number_id' => '597907523413541',
            'webhook_secret'  => '5507a23d8c7807e883ec45ebce03b5ecd119691efd629eb360780df975a2b79c',
            'is_active'       => true,
        ]);
    }
}

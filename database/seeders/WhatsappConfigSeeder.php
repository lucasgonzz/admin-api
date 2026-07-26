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

        // Repositorio público: la api_key y el webhook_secret de Kapso NUNCA se hardcodean acá.
        // Se leen del .env (config/services.php → 'kapso'); si no están cargados, quedan en null
        // y se avisa por consola sin frenar el resto de los seeders.
        $kapso_api_key = config('services.kapso.api_key');
        $kapso_webhook_secret = config('services.kapso.webhook_secret');

        if (empty($kapso_api_key) || empty($kapso_webhook_secret)) {
            if ($this->command) {
                $this->command->warn(
                    'WhatsappConfigSeeder: KAPSO_API_KEY y/o KAPSO_WEBHOOK_SECRET no están definidos en el .env. '
                    . 'Se siembra el registro con valores null (WhatsApp saliente no va a funcionar hasta cargarlos).'
                );
            }
        }

        WhatsappConfig::create([
            'kapso_api_key'   => $kapso_api_key ?: null,
            'phone_number_id' => '1135644799636575',
            'webhook_secret'  => $kapso_webhook_secret ?: null,
            'is_active'       => true,
            'test_mode'       => true,
        ]);
    }
}

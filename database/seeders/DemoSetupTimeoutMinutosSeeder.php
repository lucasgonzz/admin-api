<?php

namespace Database\Seeders;

use App\Models\AdminSetting;
use App\Services\LeadDemoSettings;
use Illuminate\Database\Seeder;

/**
 * Siembra `demo_setup_timeout_minutos`, la setting que usa `leads:vencer-demo-setups-colgados`
 * para decidir cuándo un setup en `ejecutandose` se da por muerto (misión 60, pieza 3).
 *
 * 🔴 Va en un seeder suelto y no depende de un `migrate:fresh`, como toda `ExtencionEmpresa` o
 * setting nueva de este proyecto: en producción la base no se vacía, así que una clave que sólo
 * naciera con las migraciones no existiría nunca allá. Sin la clave el getter cae al default de la
 * clase y el comando igual funciona, pero la setting no aparecería en el panel para poder tocarla.
 *
 * Idempotente: `seed_defaults_if_missing()` escribe únicamente las claves que están en null, así que
 * correrlo dos veces no pisa nada, y si alguien ya eligió un valor a mano se lo respeta.
 */
class DemoSetupTimeoutMinutosSeeder extends Seeder
{
    /**
     * Ejecuta el seeder.
     *
     * @return void
     */
    public function run()
    {
        LeadDemoSettings::seed_defaults_if_missing();

        $valor = AdminSetting::get(LeadDemoSettings::KEY_SETUP_TIMEOUT_MINUTOS, null);

        if ($this->command !== null) {
            $this->command->info('DemoSetupTimeoutMinutosSeeder: demo_setup_timeout_minutos = ' . (int) $valor . ' minutos');
        }
    }
}

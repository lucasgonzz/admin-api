<?php

namespace Database\Seeders;

use App\Services\LeadDemoSettings;
use Illuminate\Database\Seeder;

/**
 * Siembra el valor por defecto de demo_pendiente_terminar_timeout_minutos si no existe.
 * Idempotente: solo inserta si la clave está ausente.
 */
class DemoPendienteTerminarTimeoutSeeder extends Seeder
{
    /**
     * Ejecuta el seeder: delega en LeadDemoSettings para no duplicar lógica.
     *
     * @return void
     */
    public function run()
    {
        LeadDemoSettings::seed_defaults_if_missing();
        $this->command->info('DemoPendienteTerminarTimeoutSeeder: OK');
    }
}

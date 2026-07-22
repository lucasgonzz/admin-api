<?php

namespace Database\Seeders;

use App\Models\EnvTemplate;
use Illuminate\Database\Seeder;

/**
 * Seeder standalone para sembrar/actualizar la plantilla .env de tienda-api
 * (scope='tienda') en bases de admin-api ya en producción.
 *
 * Reutiliza la definición de EnvTemplateTiendaSeeder (mismo patrón que
 * EcommerceImplementationStageConfigStandaloneSeeder) y usa updateOrCreate por
 * key+scope, por lo que es idempotente: se puede correr manualmente en producción
 * (`php artisan db:seed --class=EnvTemplateTiendaStandaloneSeeder`) sin duplicar
 * filas ni tocar el resto de los seeders del DatabaseSeeder.
 */
class EnvTemplateTiendaStandaloneSeeder extends Seeder
{
    /**
     * Siembra o actualiza en producción la plantilla .env de tienda-api.
     *
     * @return void
     */
    public function run()
    {
        foreach (EnvTemplateTiendaSeeder::rows() as $row) {
            EnvTemplate::updateOrCreate(
                ['key' => $row['key'], 'scope' => 'tienda'],
                $row
            );
        }

        if (isset($this->command)) {
            $this->command->info('EnvTemplateTiendaStandaloneSeeder: plantilla .env de tienda-api sembrada.');
        }
    }
}

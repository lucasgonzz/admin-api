<?php

namespace Database\Seeders;

use App\Models\Demo;
use Illuminate\Database\Seeder;

/**
 * Seeder base de demos disponibles para asignación de leads.
 */
class DemoSeeder extends Seeder
{
    /**
     * Ejecuta carga de demo inicial para entorno local.
     *
     * @return void
     */
    public function run()
    {
        /**
         * Datos iniciales de demo solicitados para ERP + ecommerce.
         */
        $demo_attributes = [
            'erp_spa_url' => 'empresa.local:8080',
            'erp_api_url' => 'empresa.local:8000',
            'ecommerce_spa_url' => 'tienda.local:8081',
            'ecommerce_api_url' => 'tienda.local:8001',
        ];

        // Evita duplicados cuando el seeder se corre más de una vez.
        Demo::firstOrCreate($demo_attributes, $demo_attributes);
    }
}

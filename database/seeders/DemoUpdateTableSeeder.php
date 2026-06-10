<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

/**
 * Seeder standalone de verificación para la tabla demo_updates.
 * Solo comprueba que la tabla exista; no inserta registros porque
 * los DemoUpdates son generados en tiempo de ejecución desde admin-spa.
 *
 * No se agrega a DatabaseSeeder ni a DemoSetup: este recurso pertenece
 * exclusivamente al flujo de admin y no requiere datos semilla.
 */
class DemoUpdateTableSeeder extends Seeder
{
    /**
     * Verifica que la tabla demo_updates esté creada.
     * Lanza una excepción si la migración no fue ejecutada.
     *
     * @return void
     */
    public function run()
    {
        // Verificación de existencia de la tabla como smoke-test post-migración.
        if (! Schema::hasTable('demo_updates')) {
            throw new \RuntimeException(
                'La tabla demo_updates no existe. '
                . 'Ejecutá: php artisan migrate'
            );
        }

        $this->command->info('Tabla demo_updates verificada correctamente.');
    }
}

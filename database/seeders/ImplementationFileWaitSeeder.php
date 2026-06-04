<?php

namespace Database\Seeders;

use App\Models\AdminSetting;
use Illuminate\Database\Seeder;

/**
 * Configura el tiempo de espera (en segundos) antes de procesar los archivos
 * recibidos en la Etapa 4 del flujo de implementación.
 *
 * Usa updateOrCreate para ser idempotente en re-ejecuciones.
 */
class ImplementationFileWaitSeeder extends Seeder
{
    /**
     * Inserta o actualiza el setting 'implementation_file_wait_seconds'.
     *
     * @return void
     */
    public function run()
    {
        // Tiempo de espera por defecto: 15 segundos.
        // El admin puede ajustarlo desde el panel de Cuenta > Implementaciones.
        AdminSetting::updateOrCreate(
            ['key' => 'implementation_file_wait_seconds'],
            ['value' => '15']
        );

        $this->command->info('ImplementationFileWaitSeeder: setting implementation_file_wait_seconds configurado → 15 segundos.');
    }
}

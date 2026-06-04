<?php

namespace Database\Seeders;

use App\Models\Admin;
use App\Models\AdminSetting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * Configura el admin asignado por defecto a nuevas implementaciones.
 *
 * Busca al admin cuyo nombre es 'Martin' y guarda su ID en admin_settings
 * bajo la clave 'implementation_assigned_admin_id'. Usa updateOrCreate para
 * ser idempotente en re-ejecuciones.
 */
class ImplementationDefaultAdminSeeder extends Seeder
{
    /**
     * Inserta o actualiza el setting de admin por defecto para implementaciones.
     *
     * @return void
     */
    public function run()
    {
        // Buscar el admin responsable de implementaciones por nombre.
        $admin = Admin::where('name', 'Martin')->first();

        if ($admin === null) {
            // Si no existe, registrar aviso y salir sin error para no romper el seed completo.
            Log::channel('daily')->warning('ImplementationDefaultAdminSeeder: admin Martin no encontrado; setting no configurado.');
            $this->command->warn('ImplementationDefaultAdminSeeder: admin Martin no encontrado; omitiendo setting.');
            return;
        }

        // Persistir o actualizar el ID del admin por defecto para implementaciones.
        AdminSetting::updateOrCreate(
            ['key' => 'implementation_assigned_admin_id'],
            ['value' => (string) $admin->id]
        );

        $this->command->info("ImplementationDefaultAdminSeeder: admin por defecto configurado → {$admin->name} (ID {$admin->id}).");
    }
}

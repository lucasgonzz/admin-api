<?php

namespace Database\Seeders;

use App\Models\Admin;
use App\Models\TaskTemplate;
use Illuminate\Database\Seeder;

/**
 * Inserta las plantillas de tareas predefinidas para el proceso 'lead_a_cliente'.
 * Usa updateOrCreate por (proceso + titulo) para ser idempotente en re-ejecuciones.
 */
class TaskTemplateSeeder extends Seeder
{
    /**
     * Ejecuta el seeder de plantillas de tareas.
     *
     * @return void
     */
    public function run()
    {
        /**
         * Plantillas del proceso 'lead_a_cliente'.
         * Se crean automáticamente al promover un Lead a Client en admin-api.
         */
        $templates = [
            [
                'proceso'     => 'lead_a_cliente',
                'titulo'      => 'Reunión de kickoff con el equipo',
                'descripcion' => 'Reunión interna para coordinar la implementación del nuevo cliente.',
                'checklist'   => [
                    'Revisar notas de la videollamada de venta',
                    'Definir fecha de instalación',
                    'Asignar responsables',
                ],
                'asignado_a'  => 'Lucas',
                'prioridad'   => 1,
                'orden'       => 1,
                'activa'      => true,
            ],
            [
                'proceso'     => 'lead_a_cliente',
                'titulo'      => 'Instalar sistemas al cliente',
                'descripcion' => 'Instalación del sistema de gestión y ecommerce según lo contratado.',
                'checklist'   => [
                    'Crear subdominio ERP',
                    'Crear subdominio ecommerce si aplica',
                    'Configurar base de datos',
                    'Verificar acceso del cliente',
                ],
                'asignado_a'  => 'Martin',
                'prioridad'   => 1,
                'orden'       => 2,
                'activa'      => true,
            ],
            [
                'proceso'     => 'lead_a_cliente',
                'titulo'      => 'Enviar pasos de implementación al cliente',
                'descripcion' => 'Comunicar al cliente los pasos que debe seguir para arrancar.',
                'checklist'   => [
                    'Enviar mail con pasos de migración de datos',
                    'Confirmar formato del Excel de productos',
                    'Explicar pasos de vinculación con AFIP',
                ],
                'asignado_a'  => 'Martin',
                'prioridad'   => 1,
                'orden'       => 3,
                'activa'      => true,
            ],
        ];

        foreach ($templates as $template_data) {
            // Resolver admin por nombre legacy del seeder → assigned_admin_id.
            $admin_name = $template_data['asignado_a'] ?? null;
            unset($template_data['asignado_a']);
            if ($admin_name) {
                $admin = Admin::where('name', $admin_name)->first();
                if ($admin) {
                    $template_data['assigned_admin_id'] = $admin->id;
                    $template_data['asignado_a']         = $admin->name;
                }
            }

            // Clave de unicidad: proceso + titulo para idempotencia.
            TaskTemplate::updateOrCreate(
                [
                    'proceso' => $template_data['proceso'],
                    'titulo'  => $template_data['titulo'],
                ],
                $template_data
            );
        }
    }
}

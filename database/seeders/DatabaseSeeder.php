<?php

namespace Database\Seeders;

use App\Models\EnvTemplate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        /**
         * Seeders base para disponer de datos mínimos del panel de admin-api.
         */
        $this->call([
            AdminUserSeeder::class,
            // DemoVersionSeeder::class,
            // Credenciales SSH globales (shared_hosting + vps) para deployments.
            ClientSshCredentialSeeder::class,
            ClientSeeder::class,
            DemoSeeder::class,
            LeadPipelineStatusSeeder::class,
            LeadSeeder::class,
            FollowupRulesSeeder::class,
            // Plantillas Meta aprobadas para seguimientos automáticos directos.
            FollowupTemplatesSeeder::class,
            // Desactiva las plantillas de demo_realizada y mail2_enviado (no creadas en Meta).
            FollowupTemplatesDesactivarDemoRealizadaCierreSeeder::class,
            ProtocolEntriesSeeder::class,
            // Plantillas de tareas automáticas para procesos internos.
            TaskTemplateSeeder::class,
            ImplementationStageConfigSeeder::class,
            // Catálogo de las 5 etapas del flujo de implementación de la tienda online.
            EcommerceImplementationStageConfigSeeder::class,
            AiSystemPromptSeeder::class,
            /* Identidad del agente Martín inyectada dinámicamente en el system prompt de Claude. */
            AgentIdentitySeeder::class,
            AdminSettingSeeder::class,
            // Admin por defecto asignado a nuevas implementaciones (requiere AdminUserSeeder).
            ImplementationDefaultAdminSeeder::class,
            // Tiempo de espera antes de procesar archivos recibidos en la Etapa 4.
            ImplementationFileWaitSeeder::class,
        ]);

        // Plantilla base de variables .env: solo siembra si la tabla está vacía.
        if (EnvTemplate::count() === 0) {
            $this->call([
                EnvTemplateSeeder::class,
            ]);
        }

        // Configuración placeholder de Kapso solo en entornos de desarrollo.
        if (App::environment(['local', 'testing'])) {
            $this->call([
                WhatsappConfigSeeder::class,
            ]);
        }
    }
}

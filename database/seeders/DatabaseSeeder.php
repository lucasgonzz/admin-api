<?php

namespace Database\Seeders;

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
            ProtocolEntriesSeeder::class,
            // Plantillas de tareas automáticas para procesos internos.
            TaskTemplateSeeder::class,
            ImplementationStageConfigSeeder::class,
            AiSystemPromptSeeder::class,
            AdminSettingSeeder::class,
        ]);

        // Configuración placeholder de Kapso solo en entornos de desarrollo.
        if (App::environment(['local', 'testing'])) {
            $this->call([
                WhatsappConfigSeeder::class,
            ]);
        }
    }
}

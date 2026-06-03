<?php

namespace Database\Seeders;

use App\Models\LeadPipelineStatus;
use Illuminate\Database\Seeder;

/**
 * Carga el catálogo inicial de estados del pipeline de leads.
 */
class LeadPipelineStatusSeeder extends Seeder
{
    /**
     * Ejecuta el seed de estados por defecto.
     *
     * @return void
     */
    public function run()
    {
        LeadPipelineStatus::seed_defaults_if_empty();
        LeadPipelineStatus::sync_default_colors();
    }
}

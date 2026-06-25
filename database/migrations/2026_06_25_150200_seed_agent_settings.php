<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\AdminSetting;

/**
 * Inserta las admin_settings de configuración del agente analizador.
 * Usa firstOrCreate para no sobreescribir valores ya customizados.
 */
class SeedAgentSettings extends Migration
{
    /**
     * Ejecuta el seed de configuraciones del agente.
     *
     * @return void
     */
    public function up()
    {
        // Presupuesto diario Meta Ads en USD; el agente lo usa para calcular costo por lead.
        AdminSetting::firstOrCreate(
            ['key' => 'meta_daily_budget_usd'],
            ['value' => '7']
        );

        // Hora en formato 24hs (sin minutos) a la que se genera el reporte diario del agente.
        AdminSetting::firstOrCreate(
            ['key' => 'agent_report_hour'],
            ['value' => '8']
        );

        // Días de retención de archivos markdown de reporte antes de eliminarlos.
        AdminSetting::firstOrCreate(
            ['key' => 'agent_report_retention_days'],
            ['value' => '90']
        );
    }

    /**
     * No se eliminan settings en el rollback para evitar pérdida de configuración personalizada.
     *
     * @return void
     */
    public function down()
    {
        // No eliminar settings: podrían haber sido personalizadas por el operador.
    }
}

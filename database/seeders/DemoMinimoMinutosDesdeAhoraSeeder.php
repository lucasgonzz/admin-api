<?php

namespace Database\Seeders;

use App\Models\AdminSetting;
use App\Services\LeadDemoSettings;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * Baja el margen mínimo para ofrecer un horario de HOY de 15 a 5 minutos (misión 46, pieza 1).
 *
 * 🔴 Cambiar la constante DEFAULT_DEMO_MINIMO_MINUTOS_DESDE_AHORA no alcanza, y por eso este
 * seeder existe: `seed_defaults_if_missing()` escribe la clave sólo cuando está en null, y en
 * producción hace rato que está escrita en 15. Sin este paso el default nuevo lo verían únicamente
 * las instalaciones que nunca corrieron el seeder anterior — o sea ninguna.
 *
 * Idempotente y conservador: pisa el valor guardado sólo si es null o exactamente 15 (el default
 * viejo, o sea "nadie lo eligió"). Cualquier otro número lo dejó alguien a mano desde el panel, así
 * que no se toca y queda registrado en el log — un seeder que pisa una decisión humana es peor que
 * uno que no corre.
 */
class DemoMinimoMinutosDesdeAhoraSeeder extends Seeder
{
    /** Valor viejo: el único que este seeder se permite pisar además de null. */
    private const VALOR_VIEJO = 15;

    /** Valor nuevo. */
    private const VALOR_NUEVO = 5;

    /**
     * Ejecuta el seeder.
     *
     * @return void
     */
    public function run()
    {
        // Primero los defaults generales: si esta instalación es nueva, la clave nace ya en 5 y el
        // bloque de abajo no encuentra nada que corregir.
        LeadDemoSettings::seed_defaults_if_missing();

        $actual = AdminSetting::get(LeadDemoSettings::KEY_DEMO_MINIMO_MINUTOS_DESDE_AHORA, null);

        if ($actual === null || (int) $actual === self::VALOR_VIEJO) {
            AdminSetting::set(LeadDemoSettings::KEY_DEMO_MINIMO_MINUTOS_DESDE_AHORA, (string) self::VALOR_NUEVO);

            if ($this->command !== null) {
                $this->command->info('DemoMinimoMinutosDesdeAhoraSeeder: margen mínimo actualizado a ' . self::VALOR_NUEVO . ' minutos');
            }

            return;
        }

        $mensaje = 'DemoMinimoMinutosDesdeAhoraSeeder: el margen mínimo está en ' . (int) $actual
            . ' minutos (elegido a mano). No se toca.';

        Log::info($mensaje);

        if ($this->command !== null) {
            $this->command->info($mensaje);
        }
    }
}

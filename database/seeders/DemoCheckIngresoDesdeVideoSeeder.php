<?php

namespace Database\Seeders;

use App\Models\AdminSetting;
use App\Services\LeadDemoSettings;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * Sube `demo_check_ingreso_minutos_post` de 5 a 10 y siembra las dos ventanas de silencio nuevas
 * (misión demo-agendado-directo, 10/9/2026).
 *
 * La setting cambió de significado: hasta el 4/9/2026 eran los minutos después del INICIO del turno
 * para preguntar "¿pudiste entrar?"; desde esta misión son los minutos después de que el lead
 * TERMINÓ EL VIDEO de introducción (CheckDemoIngresoPostVideo). Lucas pidió diez. Y cambiar la
 * constante no alcanza, por el mismo motivo que DemoMinimoMinutosDesdeAhoraSeeder:
 * `seed_defaults_if_missing()` escribe la clave sólo cuando está en null, y en producción está
 * escrita en 5 desde junio.
 *
 * Idempotente y conservador: pisa el valor sólo si es null o exactamente 5 (el default viejo, o
 * sea "nadie lo eligió"). Otro número lo dejó alguien a mano y no se toca. Las dos ventanas de
 * silencio nacen con `seed_defaults_if_missing()` (10 y 30) sólo si no existen.
 */
class DemoCheckIngresoDesdeVideoSeeder extends Seeder
{
    /** Valor viejo: el único que este seeder se permite pisar además de null. */
    private const VALOR_VIEJO = 5;

    /** Valor nuevo. */
    private const VALOR_NUEVO = 10;

    /**
     * Ejecuta el seeder.
     *
     * @return void
     */
    public function run()
    {
        // Siembra lo que falte (incluidas las dos ventanas de silencio nuevas) sin pisar nada.
        LeadDemoSettings::seed_defaults_if_missing();

        $actual = AdminSetting::get(LeadDemoSettings::KEY_CHECK_INGRESO_MINUTOS_POST, null);

        if ($actual === null || (int) $actual === self::VALOR_VIEJO) {
            AdminSetting::set(LeadDemoSettings::KEY_CHECK_INGRESO_MINUTOS_POST, (string) self::VALOR_NUEVO);

            if ($this->command !== null) {
                $this->command->info('DemoCheckIngresoDesdeVideoSeeder: check de ingreso a ' . self::VALOR_NUEVO . ' minutos después del video');
            }

            return;
        }

        $mensaje = 'DemoCheckIngresoDesdeVideoSeeder: el check de ingreso está en ' . (int) $actual
            . ' minutos (elegido a mano). No se toca.';

        Log::info($mensaje);

        if ($this->command !== null) {
            $this->command->info($mensaje);
        }
    }
}

<?php

namespace Database\Seeders;

use App\Models\AdminSetting;
use App\Services\AsistenteWhatsappSettings;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * Deja escrito qué plantilla de Meta usa el aviso de actualización cuando la ventana de 24 hs del
 * dueño está cerrada (misión aviso-de-actualizacion-al-cliente, 18/9/2026).
 *
 * El código no adivina la plantilla: la lee del `AdminSetting`
 * `asistente_actualizacion_template_name`, a propósito, para que una plantilla que Meta todavía
 * no aprobó no se intente mandar. Esa fila no tiene pantalla, así que va por seeder y lo corre el
 * deploy — que es exactamente el momento en que llega el código que la necesita.
 * `cc_sistema_actualizado` se creó en Meta el 18/9/2026, en español (AR), categoría Utilidad.
 *
 * Idempotente y conservador: escribe la clave sólo si está vacía. Un nombre distinto lo dejó
 * alguien a mano (o una plantilla nueva reemplazó a esta) y no se toca.
 */
class PlantillaDelAvisoDeActualizacionSeeder extends Seeder
{
    /** Nombre con el que la plantilla quedó creada en Meta. */
    private const PLANTILLA = 'cc_sistema_actualizado';

    /** Idioma con el que se creó en Meta. */
    private const IDIOMA = 'es_AR';

    /**
     * Ejecuta el seeder.
     *
     * @return void
     */
    public function run()
    {
        $actual = trim((string) AdminSetting::get(AsistenteWhatsappSettings::KEY_ACTUALIZACION_TEMPLATE_NAME, ''));

        if ($actual !== '') {
            $mensaje = 'PlantillaDelAvisoDeActualizacionSeeder: la plantilla ya está en `' . $actual
                . '` (elegida a mano). No se toca.';
            Log::info($mensaje);
            $this->avisar($mensaje);

            return;
        }

        AdminSetting::set(AsistenteWhatsappSettings::KEY_ACTUALIZACION_TEMPLATE_NAME, self::PLANTILLA);

        if (trim((string) AdminSetting::get(AsistenteWhatsappSettings::KEY_ACTUALIZACION_TEMPLATE_LANGUAGE, '')) === '') {
            AdminSetting::set(AsistenteWhatsappSettings::KEY_ACTUALIZACION_TEMPLATE_LANGUAGE, self::IDIOMA);
        }

        $this->avisar('PlantillaDelAvisoDeActualizacionSeeder: el aviso de actualización manda por `'
            . self::PLANTILLA . '` (' . self::IDIOMA . ') fuera de la ventana de 24 hs.');
    }

    /**
     * Escribe en la consola si el seeder corre desde artisan.
     *
     * @param string $mensaje
     *
     * @return void
     */
    private function avisar(string $mensaje): void
    {
        if ($this->command !== null) {
            $this->command->info($mensaje);
        }
    }
}

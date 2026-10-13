<?php

namespace Database\Seeders;

use App\Models\AdminSetting;
use App\Services\AsistenteWhatsappSettings;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * Vuelve a conectar el canal de soporte por WhatsApp (misión whatsapp-dos-numeros, 13/10/2026).
 *
 * El 16/9/2026 Lucas lo desconectó con una razón concreta: *"actualmente los clientes se comunican
 * a otro número por soporte, así que simplemente dejá desconectada la parte de tickets de soporte;
 * en el futuro, cuando consiga otro número, se pondrá en marcha"*. Ese futuro es esta misión: el
 * asistente de los dueños se muda a un número propio y el +54 9 3444 54-4199 vuelve a ser el número
 * por el que un cliente abre ticket. Este seeder es el que lo prende en producción.
 *
 * 🔴 **No está registrado en `DatabaseSeeder` a propósito.** `DatabaseSeeder` corre en cada
 * instalación nueva y en `local`/`testing`, y el default de
 * `AsistenteWhatsappSettings::tickets_habilitados()` es APAGADO por decisión de producto, no por
 * descuido. Un seeder de esta clase —el que da vuelta un interruptor para una instalación concreta
 * y en un momento concreto— se corre una vez, por el deploy, con la entrada en el `pendientes.json`
 * del repo de conocimiento. Metido en `DatabaseSeeder` haría dos cosas que nadie pidió: prender el
 * canal en toda instalación nueva, y prenderlo en la base de testing de cada slot, donde las
 * pruebas que ya existen afirman el comportamiento con el canal apagado.
 *
 * Idempotente: `AdminSetting::set()` es un `updateOrCreate` por clave, así que correrlo dos veces
 * deja la misma fila. Y es deliberadamente CIEGO al valor anterior, al revés de
 * `PlantillaDelAvisoDeActualizacionSeeder`: ahí un valor distinto al esperado lo había elegido una
 * persona y no se tocaba; acá el valor anterior es justamente el que hay que cambiar.
 */
class PrenderTicketsDeSoporteSeeder extends Seeder
{
    /**
     * Ejecuta el seeder.
     *
     * @return void
     */
    public function run()
    {
        $antes = AdminSetting::get(AsistenteWhatsappSettings::KEY_TICKETS_ENABLED, null);
        $estaba = AsistenteWhatsappSettings::tickets_habilitados();

        AdminSetting::set(AsistenteWhatsappSettings::KEY_TICKETS_ENABLED, '1');

        $mensaje = 'PrenderTicketsDeSoporteSeeder: `'
            . AsistenteWhatsappSettings::KEY_TICKETS_ENABLED . '` quedó en `1`. '
            . 'Un mensaje de un cliente por WhatsApp vuelve a abrir (o continuar) un ticket de soporte. '
            . 'Antes estaba en ' . ($antes === null ? 'null (sin fila, o sea apagado por default)' : '`' . $antes . '`')
            . ', o sea ' . ($estaba ? 'prendido' : 'apagado') . '.';

        Log::info($mensaje);

        if ($this->command !== null) {
            $this->command->info($mensaje);
        }
    }
}

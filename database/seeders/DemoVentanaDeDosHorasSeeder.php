<?php

namespace Database\Seeders;

use App\Models\AdminSetting;
use App\Services\LeadDemoSettings;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * Fija en producción la reserva de la demo en DOS horas (misión demo-dos-horas-y-experiencia,
 * 13/10/2026): `demo_ventana_extendida_max_horas = 2` y `demo_bloqueo_real_minutos = 120`.
 *
 * 🔴 Por qué hace falta un seeder y no alcanza con cambiar los defaults en
 * `LeadDemoSettings`. Es la misma razón de siempre en este repo (ver
 * `DemoMinimoMinutosDesdeAhoraSeeder` y `SubirDemoSetupTimeoutMinutosSeeder`):
 * `seed_defaults_if_missing()` escribe una clave **sólo cuando está en null**, que es lo correcto
 * para sembrar un default pero no sirve para corregir uno. En la base de producción las dos filas
 * están escritas desde hace meses (6 horas y 180 minutos), así que el default nuevo no lo vería
 * ninguna instalación que ya exista — o sea, ninguna.
 *
 * 🔴 Y por qué este PISA el valor guardado, a diferencia de los dos seeders citados, que respetan
 * lo que alguien haya elegido a mano. Es el pedido explícito de Lucas para esta misión: los dos
 * números dejaron de ser un parámetro interno y pasaron a ser lo que el agente le DICE al lead
 * ("dura menos de una hora; la tenés reservada dos horas, hasta las HH:MM", armado por
 * `LeadAiService::frase_de_la_reserva()` con estos mismos valores). Si la base quedara en 6 horas,
 * el agente prometería dos y el sistema reservaría seis: no es un valor "elegido", es una
 * promesa desalineada. Y el campo quedó editable desde el panel en esta misma misión, así que
 * cambiarlos después de este seeder es un click.
 *
 * Qué NO toca: `demo_duracion_minutos`, que sigue en 60 y mide otra cosa (gobierna el check de
 * fin, el hold del closer, el resumen y la grilla "actual"). Tocarlo movería el ciclo entero de la
 * demo, que no es lo que pidió nadie.
 *
 * Idempotente: correrlo dos veces deja los mismos dos valores y no escribe nada nuevo la segunda
 * vez (se informa por log y por consola cuál era el valor anterior, o que ya estaba puesto).
 */
class DemoVentanaDeDosHorasSeeder extends Seeder
{
    /** Horas que se le reservan al lead, y que el agente le comunica. */
    private const HORAS_DE_RESERVA = 2;

    /**
     * Minutos de bloqueo real de la instancia desde el inicio. Es el mismo número que
     * HORAS_DE_RESERVA expresado en minutos, a propósito: el piso del bloqueo y el techo de la
     * ventana tienen que cerrar juntos para que la instancia se libere cuando se terminó lo que se
     * le prometió al lead.
     */
    private const MINUTOS_DE_BLOQUEO_REAL = self::HORAS_DE_RESERVA * 60;

    /**
     * Ejecuta el seeder.
     *
     * @return void
     */
    public function run()
    {
        /* Primero los defaults generales: en una instalación nueva las dos claves nacen ya con los
         * valores de esta misión y el bloque de abajo no encuentra nada que corregir. */
        LeadDemoSettings::seed_defaults_if_missing();

        $this->fijar(
            LeadDemoSettings::KEY_VENTANA_EXTENDIDA_MAX_HORAS,
            self::HORAS_DE_RESERVA,
            'reserva de la instancia (horas)'
        );

        $this->fijar(
            LeadDemoSettings::KEY_BLOQUEO_REAL_MINUTOS,
            self::MINUTOS_DE_BLOQUEO_REAL,
            'bloqueo real de la instancia (minutos)'
        );
    }

    /**
     * Escribe una clave de settings al valor pedido, pisando lo que haya, y deja constancia de lo
     * que había antes.
     *
     * @param string $clave     Clave de `admin_settings`.
     * @param int    $valor     Valor nuevo.
     * @param string $etiqueta  Nombre legible para el log.
     *
     * @return void
     */
    private function fijar(string $clave, int $valor, string $etiqueta): void
    {
        $actual = AdminSetting::get($clave, null);

        if ($actual !== null && (int) $actual === $valor) {
            $mensaje = 'DemoVentanaDeDosHorasSeeder: ' . $etiqueta . ' ya está en ' . $valor . '. No se toca.';
            Log::info($mensaje);
            if ($this->command !== null) {
                $this->command->info($mensaje);
            }

            return;
        }

        AdminSetting::set($clave, (string) $valor);

        $mensaje = 'DemoVentanaDeDosHorasSeeder: ' . $etiqueta . ' pasó de '
            . ($actual === null ? 'sin valor' : (string) $actual) . ' a ' . $valor . '.';
        Log::info($mensaje);
        if ($this->command !== null) {
            $this->command->info($mensaje);
        }
    }
}

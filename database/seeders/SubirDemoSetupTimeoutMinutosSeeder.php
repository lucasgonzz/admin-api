<?php

namespace Database\Seeders;

use App\Models\AdminSetting;
use App\Services\LeadDemoSettings;
use Illuminate\Database\Seeder;

/**
 * Sube `demo_setup_timeout_minutos` a 25 en las bases donde todavía vale menos (misión cruzada del
 * 25/8/2026).
 *
 * 🔴 Por qué existe aparte de `DemoSetupTimeoutMinutosSeeder`, que ya siembra esa misma clave.
 * Aquel llama a `LeadDemoSettings::seed_defaults_if_missing()`, que escribe **únicamente las claves
 * que están en null**. Es lo correcto para sembrar un default —así no le pisa a nadie un valor
 * elegido a mano—, pero justamente por eso no sirve para CORREGIR uno: en la base de producción
 * `demo_setup_timeout_minutos` ya vale `10`, así que cambiar el default en código no cambia nada
 * allá. Y la clave todavía no es editable desde el panel (`LeadDemoSettings::to_array()` no la
 * expone y admin-spa no la manda en el PUT de settings), o sea que tampoco hay forma de arreglarlo
 * a mano. Sin este seeder, el arreglo se queda en el repo y producción sigue con el número viejo.
 *
 * 🔴 Y por qué el número importa: medido el 25/8/2026 contra `empresa_testing_s1`, una corrida
 * SANA de `DemoSetupHelper::run()` tarda **565,7 s — 9 minutos y 26 segundos**. Con el umbral en
 * 10 minutos, `leads:vencer-demo-setups-colgados` declaraba muerto un armado que estaba andando
 * bien, el panel devolvía el botón "Correr demo setup ahora" y el segundo click le hacía un
 * `migrate:fresh` a la base que la primera corrida estaba sembrando. El umbral corto no era una
 * red contra ese bug: era una de sus causas.
 *
 * **Sólo sube, nunca baja.** Si alguien ya eligió un valor mayor —25 es un piso medido, no un
 * techo, y en el hosting compartido puede hacer falta más— se lo respeta y el seeder no toca nada.
 * Idempotente por construcción: correrlo dos veces sobre una base que ya está en 25 no escribe.
 */
class SubirDemoSetupTimeoutMinutosSeeder extends Seeder
{
    /**
     * Piso medido para el vencimiento del demo setup, en minutos. Es el mismo número que
     * `LeadDemoSettings::DEFAULT_SETUP_TIMEOUT_MINUTOS`, que es privada; la duplicación es
     * deliberada y está acotada a esta constante para no aflojar la visibilidad de la otra por un
     * seeder de una sola corrida.
     */
    private const PISO_MINUTOS = 25;

    /**
     * Ejecuta el seeder.
     *
     * @return void
     */
    public function run()
    {
        /* Red de seguridad para una base donde la clave nunca se sembró: sin esto, un `get()` que
         * devuelve null se castearía a 0, entraría por el `<` y quedaría escrito igual — pero por
         * el camino equivocado y sin dejar sembradas las demás settings del ciclo de demo. */
        LeadDemoSettings::seed_defaults_if_missing();

        $actual = AdminSetting::get(LeadDemoSettings::KEY_SETUP_TIMEOUT_MINUTOS, null);

        if ($actual !== null && (int) $actual >= self::PISO_MINUTOS) {
            if ($this->command !== null) {
                $this->command->info(
                    'SubirDemoSetupTimeoutMinutosSeeder: demo_setup_timeout_minutos ya está en '
                    . (int) $actual . ' minutos (>= ' . self::PISO_MINUTOS . '). No se toca.'
                );
            }

            return;
        }

        AdminSetting::set(LeadDemoSettings::KEY_SETUP_TIMEOUT_MINUTOS, (string) self::PISO_MINUTOS);

        if ($this->command !== null) {
            $this->command->info(
                'SubirDemoSetupTimeoutMinutosSeeder: demo_setup_timeout_minutos pasó de '
                . ($actual === null ? 'sin valor' : (int) $actual . ' minutos')
                . ' a ' . self::PISO_MINUTOS . ' minutos.'
            );
        }
    }
}

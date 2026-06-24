<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Seeder standalone idempotente para producción: variantes de welcome A/B.
 *
 * No trunca la tabla; reutiliza MessageVariantSeeder::variant_definitions().
 */
class StandaloneMessageVariantSeeder extends Seeder
{
    /**
     * Siembra variantes iniciales en bases existentes (seguro re-ejecutar).
     *
     * @return void
     */
    public function run()
    {
        (new MessageVariantSeeder())->run();

        if (isset($this->command)) {
            $this->command->info('StandaloneMessageVariantSeeder: variantes de welcome sembradas.');
        }
    }
}

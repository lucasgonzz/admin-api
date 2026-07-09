<?php

namespace Database\Seeders;

use App\Models\ComerciocityAfipConfig;
use Illuminate\Database\Seeder;

/**
 * Siembra la fila única de configuración fiscal (AFIP) de ComercioCity con
 * los valores operativos actuales: Monotributista, ambiente de homologación.
 * Idempotente: no pisa datos si la fila ya existe (Lucas la carga/edita
 * después desde admin-spa).
 */
class ComerciocityAfipConfigSeeder extends Seeder
{
    /**
     * Crea la fila de configuración solo si todavía no existe.
     *
     * @return void
     */
    public function run()
    {
        ComerciocityAfipConfig::firstOrCreate([], [
            'condicion_iva' => 'Monotributista',
            'afip_produccion' => false,
        ]);
    }
}

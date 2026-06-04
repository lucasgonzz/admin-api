<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Documentación del campo import_status en implementation_stages.data (Etapa 4).
 *
 * No inserta ni migra datos: import_status se inicializa en runtime cuando
 * ImplementationImportService::process_files detecta archivos listos para analizar,
 * y se actualiza en ImplementationImportService::execute_import durante la carga.
 *
 * Estructura esperada por categoría (articles | clients | suppliers):
 *   status: pending | importing | success | failed
 *   error: string|null
 *   imported_at: ISO8601|null
 *
 * Referencia de migración: 2026_06_04_300000_implementation_stage4_import_status_in_data.php
 */
class ImplementationStage4ImportStatusSeeder extends Seeder
{
    /**
     * Sin datos que sembrar: solo referencia documental.
     *
     * @return void
     */
    public function run()
    {
        // No hay filas que crear: el JSON data se completa en el flujo de WhatsApp / importación.
        if ($this->command !== null) {
            $this->command->info(
                'ImplementationStage4ImportStatusSeeder: documentación de import_status en stage.data (sin datos a insertar).'
            );
        }
    }
}

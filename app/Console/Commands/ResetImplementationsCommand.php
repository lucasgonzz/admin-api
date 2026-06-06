<?php

namespace App\Console\Commands;

use App\Models\Admin;
use App\Models\Client;
use App\Models\Implementation;
use App\Models\ImplementationMessage;
use App\Models\ImplementationStage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Comando de desarrollo/testing para resetear todas las implementaciones
 * y crear una nueva en etapa 3 lista para avanzar.
 *
 * Uso exclusivo en entornos de desarrollo. No ejecutar en producción.
 */
class ResetImplementationsCommand extends Command
{
    /**
     * Firma del comando artisan.
     *
     * @var string
     */
    protected $signature = 'implementacion:reset';

    /**
     * Descripción visible en el listado de comandos artisan.
     *
     * @var string
     */
    protected $description = 'Elimina todas las implementaciones y crea una nueva en etapa 3 lista para avanzar';

    /**
     * Número total de stages que se crean por implementación.
     *
     * @var int
     */
    const TOTAL_STAGES = 8;

    /**
     * Etapa en la que queda posicionada la nueva implementación.
     *
     * @var int
     */
    const TARGET_STAGE = 3;

    /**
     * Ejecuta el comando: limpia tablas, crea implementación y stages iniciales.
     *
     * @return int Código de salida (0 = éxito).
     */
    public function handle()
    {
        DB::transaction(function () {

            /* ----------------------------------------------------------------
             * Paso 1: eliminar todos los registros en orden para respetar
             * las dependencias (mensajes → stages → implementaciones).
             * ---------------------------------------------------------------- */

            // Mensajes de WhatsApp vinculados a implementaciones.
            ImplementationMessage::query()->delete();

            // Etapas instanciadas de cada implementación.
            ImplementationStage::query()->delete();

            // Implementaciones base (tabla raíz).
            Implementation::query()->delete();

            /* ----------------------------------------------------------------
             * Paso 2: obtener entidades de referencia para la nueva implementación.
             * ---------------------------------------------------------------- */

            // Primer cliente activo disponible en el sistema.
            $client = Client::where('phone', '+5493444622139')->first();

            // Primer admin registrado en el sistema.
            $admin = Admin::first();

            /* ----------------------------------------------------------------
             * Paso 3: crear la implementación en etapa 3 (in_progress).
             * ---------------------------------------------------------------- */
            $implementation = Implementation::create([
                'client_id'               => $client->id,
                'assigned_admin_id'       => $admin->id,
                'current_stage'           => self::TARGET_STAGE,
                'status'                  => 'in_progress',
                'started_at'              => now(),
                // Necesario para que la etapa 4 pueda enviar el mensaje de apertura al contacto de migración.
                'migration_contact_phone' => $client->phone,
            ]);

            /* ----------------------------------------------------------------
             * Paso 4: crear los 8 stages de la implementación.
             * Las etapas 1, 2 y 3 se marcan como completadas.
             * Las etapas 4 a 8 quedan pendientes.
             * ---------------------------------------------------------------- */
            for ($stage_number = 1; $stage_number <= self::TOTAL_STAGES; $stage_number++) {

                // Determina si esta etapa ya fue completada (≤ TARGET_STAGE).
                $is_completed = $stage_number <= self::TARGET_STAGE;

                ImplementationStage::create([
                    'implementation_id' => $implementation->id,
                    'stage_number'      => $stage_number,
                    'status'            => $is_completed ? 'completed' : 'pending',
                    'completed_at'      => $is_completed ? now() : null,
                ]);
            }

            /* ----------------------------------------------------------------
             * Paso 5: imprimir resumen en consola.
             * ---------------------------------------------------------------- */

            // Nombre legible del cliente (company_name o name como fallback).
            $client_name = $client->resolve_display_name();

            $this->line('✅ Implementaciones reseteadas.');
            $this->line("📋 Nueva implementación ID: {$implementation->id} — Cliente: {$client_name}");
            $this->line('🎯 Lista para avanzar de etapa 3 → 4');
        });

        return 0;
    }
}

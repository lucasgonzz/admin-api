<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Services\LeadDocNumberGenerator;
use Illuminate\Console\Command;

/**
 * Asigna doc_number a leads existentes que aún no tienen documento cargado.
 *
 * Usa el mismo formato aleatorio de 5 dígitos que el alta automática por WhatsApp.
 */
class AssignLeadDocNumbersCommand extends Command
{
    /**
     * Nombre del comando artisan.
     *
     * @var string
     */
    protected $signature = 'leads:assign-doc-numbers {--dry-run : Lista cambios sin persistirlos}';

    /**
     * Descripción del comando para `php artisan list`.
     *
     * @var string
     */
    protected $description = 'Asigna documento aleatorio de 5 dígitos a leads existentes sin doc_number';

    /**
     * Procesa leads sin documento y asigna un número aleatorio de 5 dígitos.
     *
     * @return int Código de salida (0 = éxito).
     */
    public function handle(): int
    {
        // Modo simulación: solo muestra qué leads se actualizarían.
        $dry_run = (bool) $this->option('dry-run');

        // Leads candidatos: doc_number nulo, vacío o solo espacios.
        $leads = Lead::query()
            ->where(function ($query) {
                $query->whereNull('doc_number')
                    ->orWhere('doc_number', '')
                    ->orWhereRaw("TRIM(doc_number) = ''");
            })
            ->orderBy('id')
            ->get();

        if ($leads->isEmpty()) {
            $this->info('No hay leads sin documento para procesar.');

            return 0;
        }

        $this->info('Leads sin documento encontrados: '.$leads->count());

        if ($dry_run) {
            $this->warn('Modo dry-run: no se guardarán cambios.');
        }

        // Contadores para el resumen final.
        $assigned_count = 0;
        $skipped_count = 0;
        $rows = [];

        foreach ($leads as $lead) {
            $lead_id = (int) $lead->id;
            if ($lead_id <= 0) {
                $skipped_count++;
                continue;
            }

            if ($dry_run) {
                // Vista previa: número aleatorio de ejemplo (no se persiste en dry-run).
                $preview_doc_number = LeadDocNumberGenerator::generate_unique_random();

                $rows[] = [
                    'id'         => $lead_id,
                    'contacto'   => (string) ($lead->contact_name ?? ''),
                    'telefono'   => (string) ($lead->phone ?? ''),
                    'doc_number' => $preview_doc_number,
                ];

                $assigned_count++;
                continue;
            }

            // Reutiliza la misma lógica del webhook (no sobrescribe si ya tiene valor).
            if (LeadDocNumberGenerator::assign_to_lead_if_empty($lead)) {
                $rows[] = [
                    'id'         => $lead_id,
                    'contacto'   => (string) ($lead->contact_name ?? ''),
                    'telefono'   => (string) ($lead->phone ?? ''),
                    'doc_number' => (string) $lead->doc_number,
                ];
                $assigned_count++;
            } else {
                $skipped_count++;
            }
        }

        $this->table(
            ['ID', 'Contacto', 'Teléfono', 'doc_number'],
            $rows
        );

        if ($dry_run) {
            $this->info('Dry-run: se asignarían '.$assigned_count.' documento(s).');
        } else {
            $this->info('Documentos asignados: '.$assigned_count.'.');
            if ($skipped_count > 0) {
                $this->warn('Leads omitidos: '.$skipped_count.'.');
            }
        }

        return 0;
    }
}

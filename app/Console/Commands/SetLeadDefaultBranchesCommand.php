<?php

namespace App\Console\Commands;

use App\Models\Lead;
use Illuminate\Console\Command;

/**
 * Backfill: setea use_deposits y address_1/2/3 a los valores por defecto
 * en leads existentes que aún no tienen esos campos configurados.
 *
 * Uso: php artisan leads:set-default-branches [--dry-run] [--force]
 */
class SetLeadDefaultBranchesCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'leads:set-default-branches
                            {--dry-run : Muestra cambios sin persistirlos}
                            {--force  : Sobreescribe address_1/2/3 aunque ya tengan valor}';

    /**
     * @var string
     */
    protected $description = 'Setea use_deposits=true y address_1/2/3 a Sucursal 1/2/3 en leads existentes sin esos valores';

    /**
     * @return int
     */
    public function handle()
    {
        $dry_run = (bool) $this->option('dry-run');
        $force   = (bool) $this->option('force');

        $leads = Lead::query()->orderBy('id')->get();

        if ($leads->isEmpty()) {
            $this->info('No hay leads para procesar.');
            return 0;
        }

        $this->info('Total de leads: ' . $leads->count());

        if ($dry_run) {
            $this->warn('Modo dry-run: no se guardarán cambios.');
        }
        if ($force) {
            $this->warn('Modo --force: address_1/2/3 se sobreescriben aunque ya tengan valor.');
        }

        $updated = 0;
        $rows    = [];

        foreach ($leads as $lead) {
            $updates = [];

            // use_deposits siempre a true
            if (! $lead->use_deposits) {
                $updates['use_deposits'] = true;
            }

            // address_1/2/3: solo si vacío, o si --force
            if ($force || empty($lead->address_1)) {
                $updates['address_1'] = 'Sucursal 1';
            }
            if ($force || empty($lead->address_2)) {
                $updates['address_2'] = 'Sucursal 2';
            }
            if ($force || empty($lead->address_3)) {
                $updates['address_3'] = 'Sucursal 3';
            }

            if (empty($updates)) {
                continue;
            }

            $rows[] = [
                (string) $lead->id,
                (string) ($lead->contact_name ? $lead->contact_name : '—'),
                isset($updates['use_deposits']) ? 'false → true' : 'true (sin cambio)',
                isset($updates['address_1'])    ? ($lead->address_1 ? $lead->address_1 . ' → Suc.1' : '(vacío) → Suc.1') : (string) ($lead->address_1 ? $lead->address_1 : ''),
                isset($updates['address_2'])    ? ($lead->address_2 ? $lead->address_2 . ' → Suc.2' : '(vacío) → Suc.2') : (string) ($lead->address_2 ? $lead->address_2 : ''),
                isset($updates['address_3'])    ? ($lead->address_3 ? $lead->address_3 . ' → Suc.3' : '(vacío) → Suc.3') : (string) ($lead->address_3 ? $lead->address_3 : ''),
            ];

            if (! $dry_run) {
                $lead->update($updates);
                $updated++;
            } else {
                $updated++;
            }
        }

        if (! empty($rows)) {
            $this->table(
                ['ID', 'Contacto', 'use_deposits', 'address_1', 'address_2', 'address_3'],
                $rows
            );
        }

        if ($dry_run) {
            $this->info('Dry-run: se actualizarían ' . $updated . ' lead(s).');
        } else {
            $this->info('Leads actualizados: ' . $updated . '.');
        }

        if ($updated === 0 && ! $dry_run) {
            $this->info('Todos los leads ya tenían los valores por defecto. Nada que actualizar.');
        }

        return 0;
    }
}

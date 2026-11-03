<?php

namespace App\Console\Commands;

use App\Services\Prueba\PruebaCicloService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * `pruebas:vencer`: los clientes en prueba cuya fecha ya pasó quedan `prueba_vencida` y su instancia
 * se duerme (decisión D4 del plan de `prueba-alta-instancia`, 3/11/2026). Es la red del evento
 * `prueba.vencida` que emite el ERP (C2): si el ERP no lo manda, el admin vence igual.
 *
 * Corre cada diez minutos. Cada cliente por separado: una falla del VPS en uno no frena a los demás.
 */
class PruebasVencerCommand extends Command
{
    protected $signature = 'pruebas:vencer';

    protected $description = 'Vence las pruebas gratuitas cuya fecha pasó y duerme sus instancias.';

    public function handle(PruebaCicloService $ciclo): int
    {
        $clientes = PruebaCicloService::clientes_por_vencer();
        $vencidos = 0;
        $fallas   = 0;

        foreach ($clientes as $client) {
            try {
                $ciclo->vencer($client);
                $vencidos++;
                $this->line('Vencida: cliente ' . $client->id . ' (' . $client->resolve_display_name() . ').');
            } catch (\Throwable $e) {
                $fallas++;
                Log::error('pruebas:vencer: no se pudo vencer la prueba.', ['client_id' => $client->id, 'error' => $e->getMessage()]);
                $this->error('Cliente ' . $client->id . ': ' . $e->getMessage());
            }
        }

        $this->info('Vencidas: ' . $vencidos . '. Fallas: ' . $fallas . '.');

        return $fallas > 0 ? 1 : 0;
    }
}

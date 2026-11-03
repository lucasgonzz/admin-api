<?php

namespace App\Console\Commands;

use App\Models\TrialInstance;
use App\Services\Prueba\PruebaCicloService;
use App\Services\Prueba\PruebaSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * `pruebas:borrar [--instancia=prueba-NN] [--ver]`: borra las instancias de las pruebas vencidas hace
 * más de `prueba_dias_gracia_borrado` días (30) sin contratar (decisión D7 del plan de
 * `prueba-alta-instancia`, 3/11/2026): respaldo en el VPS, baja de los 4 sitios, la base y el
 * supervisor, los A records por la API, cliente `prueba_borrada`, lead `cerrado_perdido`.
 *
 * Con `--instancia` borra ESA (a mano, sin mirar la fecha). Con `--ver` solo lista lo que borraría.
 * Corre diario; cada instancia por separado.
 */
class PruebasBorrarCommand extends Command
{
    protected $signature = 'pruebas:borrar {--instancia= : Borrar esta instancia (prueba-NN) sin mirar la fecha} {--ver : Solo listar, sin borrar}';

    protected $description = 'Borra las instancias de las pruebas gratuitas vencidas hace más de la gracia sin contratar (con respaldo).';

    public function handle(PruebaCicloService $ciclo): int
    {
        $nombre = trim((string) $this->option('instancia'));

        if ($nombre !== '') {
            $instancia = TrialInstance::where('nombre', $nombre)->first();
            if ($instancia === null) {
                $this->error('No existe la instancia ' . $nombre . '.');

                return 1;
            }
            $instancias = collect([$instancia]);
        } else {
            $instancias = PruebaCicloService::instancias_por_borrar();
        }

        if ($instancias->isEmpty()) {
            $this->info('No hay instancias para borrar (gracia: ' . PruebaSettings::dias_gracia_borrado() . ' días).');

            return 0;
        }

        if ($this->option('ver')) {
            foreach ($instancias as $instancia) {
                $this->line($instancia->nombre . ' → ' . (string) $instancia->alias_slug . ' (cliente ' . $instancia->client_id . ', ' . $instancia->estado . ')');
            }

            return 0;
        }

        $borradas = 0;
        $fallas   = 0;

        foreach ($instancias as $instancia) {
            try {
                $respaldo = $ciclo->borrar($instancia, $nombre !== '' ? 'a mano' : 'vencida sin contratar');
                $borradas++;
                $this->line('Borrada ' . $instancia->nombre . ' (' . (string) $instancia->alias_slug . '); respaldo en ' . $respaldo . '.');
            } catch (\Throwable $e) {
                $fallas++;
                Log::error('pruebas:borrar: no se pudo borrar la instancia.', ['instancia' => $instancia->nombre, 'error' => $e->getMessage()]);
                $this->error($instancia->nombre . ': ' . $e->getMessage());
            }
        }

        $this->info('Borradas: ' . $borradas . '. Fallas: ' . $fallas . '.');

        return $fallas > 0 ? 1 : 0;
    }
}

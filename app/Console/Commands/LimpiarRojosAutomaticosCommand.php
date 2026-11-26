<?php

namespace App\Console\Commands;

use App\Services\SupportCodexService;
use Illuminate\Console\Command;

/**
 * Apaga el rojo de los tickets abiertos que quedaron en revisión por un motivo automático (dos turnos vacíos de
 * Martín o el cierre rechazado de antes del ticket 168): Lucas pidió rojo sólo cuando él tiene que destrabar algo
 * (misión martin-descripcion-y-actualizaciones, 25/11/2026). El caso queda como estaba, así el próximo entrante del
 * cliente lo reabre; no se manda nada a nadie. Idempotente. `--dry-run` sólo lista los ids.
 */
class LimpiarRojosAutomaticosCommand extends Command
{
    protected $signature = 'support-codex:limpiar-rojos-automaticos {--dry-run : Sólo lista los tickets, sin cambiar nada}';

    protected $description = 'Apaga el rojo de los tickets en revisión por turnos vacíos o por el cierre rechazado viejo.';

    /**
     * Ejecuta la limpieza (o la simula con --dry-run).
     *
     * @param SupportCodexService $service
     *
     * @return int
     */
    public function handle(SupportCodexService $service)
    {
        $ids = $service->clear_automatic_reviews((bool) $this->option('dry-run'));
        $this->line('Tickets: ' . ($ids === [] ? 'ninguno' : implode(',', $ids)));

        return 0;
    }
}

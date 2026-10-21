<?php

namespace App\Observers;

use App\Models\Lead;
use App\Services\LeadAtribucionService;

class ReservaAtribucionObserver
{
    public function created(Lead $lead): void
    {
        app(LeadAtribucionService::class)->registrar_reserva($lead);
    }

    public function saved(Lead $lead): void
    {
        // No acredita una reserva vieja por un cambio de nombre/estado: sólo un turno recién persistido.
        if ($lead->wasChanged(['demo_id', 'demo_date', 'demo_start_time'])) {
            app(LeadAtribucionService::class)->registrar_reserva($lead);
        }
    }
}

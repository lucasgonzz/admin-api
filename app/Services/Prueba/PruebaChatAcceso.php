<?php

namespace App\Services\Prueba;

use App\Models\Client;
use App\Models\Lead;
use App\Models\LeadPipelineStatus;

class PruebaChatAcceso
{
    public static function vigente(Lead $lead, Client $client): bool
    {
        if ($lead->status !== LeadPipelineStatus::PRUEBA_ACTIVA || (int) $lead->promoted_client_id !== (int) $client->id) {
            return false;
        }

        $instancia = PruebaAsistenteService::instancia_vigente($client);

        return $instancia !== null && $instancia->lead_id === (int) $lead->id;
    }
}

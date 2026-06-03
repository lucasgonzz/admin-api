<?php

namespace App\Services;

use App\Models\Lead;

/**
 * Formaliza la promoción comercial de un Lead: marca estado `cerrado_ganado` y guarda
 * la URL del empresa-api de producción. El registro {@see \App\Models\Client}
 * se crea al ejecutar {@see RunUserSetupService} (con claves y datos del lead).
 */
class PromoteLeadService
{
    /**
     * Marca el lead como cerrado ganado (cliente en pipeline) y persiste la API URL de producción.
     *
     * @param Lead   $lead    Prospecto a promover
     * @param string $api_url URL base del empresa-api instalado en el servidor del cliente
     *
     * @throws \InvalidArgumentException Si el lead ya está promovido
     *
     * @return Lead Lead refrescado
     */
    public function promote(Lead $lead, string $api_url)
    {
        if ($lead->status === 'cerrado_ganado') {
            throw new \InvalidArgumentException('El lead ya fue promovido a cliente.');
        }

        $lead->update([
            'api_url'             => rtrim(trim($api_url), '/'),
            'status'              => 'cerrado_ganado',
            'user_setup_status'   => 'pendiente',
        ]);

        return $lead->refresh();
    }
}

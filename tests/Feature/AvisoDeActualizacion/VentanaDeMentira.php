<?php

namespace Tests\Feature\AvisoDeActualizacion;

use App\Services\WhatsappSessionWindowService;

/**
 * Reemplazo de `WhatsappSessionWindowService` para los tests: la ventana de 24 hs de Meta está
 * abierta o cerrada porque el test lo dice, sin depender de que haya mensajes entrantes sembrados
 * en las tres tablas que el servicio real consulta.
 */
class VentanaDeMentira extends WhatsappSessionWindowService
{
    /**
     * Si la ventana está abierta. Cerrada por defecto, que es el caso real de la mayoría de los
     * upgrades: se cierran a cualquier hora y el dueño no escribió en las últimas 24 hs.
     *
     * @var bool
     */
    public $abierta = false;

    /**
     * @param string $phone
     *
     * @return array{open: bool, last_inbound_at: string|null, expires_at: string|null, origin: string|null}
     */
    public function window_state(string $phone): array
    {
        if (! $this->abierta) {
            return [
                'open'            => false,
                'last_inbound_at' => null,
                'expires_at'      => null,
                'origin'          => null,
            ];
        }

        return [
            'open'            => true,
            'last_inbound_at' => now()->subHour()->toIso8601String(),
            'expires_at'      => now()->addHours(23)->toIso8601String(),
            'origin'          => 'lead',
        ];
    }
}

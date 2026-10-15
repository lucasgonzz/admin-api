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
     * Propósito con el que se pidió la ventana, en orden. `null` es el llamado de siempre (sin
     * propósito): lo que se anota acá es lo que deja ver POR QUÉ NÚMERO midió el servicio de aviso.
     *
     * @var array<int, string|null>
     */
    public $propositos_pedidos = [];

    /**
     * @param string      $phone
     * @param string|null $proposito Segundo parámetro opcional del servicio real: sin él, PHP 7.4 tira
     *                               un E_WARNING por firma incompatible apenas se carga esta clase.
     *
     * @return array{open: bool, last_inbound_at: string|null, expires_at: string|null, origin: string|null}
     */
    public function window_state(string $phone, ?string $proposito = null): array
    {
        $this->propositos_pedidos[] = $proposito;

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

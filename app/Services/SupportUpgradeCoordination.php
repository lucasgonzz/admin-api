<?php

namespace App\Services;

/**
 * Interruptor de la coordinación de horarios de actualización desde soporte (misión
 * martin-descripcion-y-actualizaciones, 25/11/2026, contrato C4).
 *
 * Lucas: «que se deje de hacer esa dinámica de coordinar un horario con el negocio; la actualización va a ser
 * siempre ese día a la noche». Apagada (default) el admin no le ofrece a Martín `fix_ready_for_upgrade` ni
 * `upgrade_reconfirmation`, anuncia las capacidades de coordinación en false y rechaza nuevas propuestas de horario
 * con 409 `upgrade_coordination_disabled`. Las tablas, el tick del scheduler y los acuerdos ya aceptados no se tocan:
 * siguen su curso. Se deja inerte por configuración (y no se borra) para no romper acuerdos en vuelo.
 */
class SupportUpgradeCoordination
{
    /**
     * Indica si la coordinación de horarios está encendida (`services.support_codex.upgrade_coordination`).
     *
     * @return bool
     */
    public static function enabled(): bool
    {
        return (bool) config('services.support_codex.upgrade_coordination', false);
    }
}

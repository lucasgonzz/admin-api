<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Se lanza cuando Google responde con invalid_grant, indicando que el closer
 * revocó el acceso a su cuenta. El llamador debe capturarla para excluir
 * al closer del cálculo en vez de romper toda la respuesta de disponibilidad.
 */
class GoogleCalendarTokenRevokedException extends RuntimeException
{
    /**
     * @param int    $admin_id ID del admin cuyo token fue revocado.
     * @param string $message  Mensaje descriptivo del error.
     */
    public function __construct(int $admin_id, string $message = '')
    {
        // ID del admin propietario del token revocado, para logging.
        $this->admin_id = $admin_id;

        parent::__construct(
            $message ?: "Google Calendar token revocado para el admin_id={$admin_id}."
        );
    }

    /** @var int ID del admin propietario del token revocado. */
    public int $admin_id;
}

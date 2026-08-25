<?php

namespace App\Exceptions;

/**
 * Se tira cuando, al aplicar una aprobación humana, el horario que ese mensaje confirmaba ya no
 * está disponible (ya pasó, lo tomó otro lead, o no se pudo tomar el lock de la instancia).
 *
 * Antes de esto el sistema reescribía el texto aprobado in-place con un correctivo y lo enviaba
 * igual: el mensaje salía firmado "aprobado por <admin>" con un contenido que ese admin nunca
 * leyó. La regla desde el 25/8/2026 es la contraria: si el descarte es legítimo, no se le manda
 * NADA al lead y se le avisa al admin para que pida una sugerencia nueva.
 *
 * Extiende \InvalidArgumentException a propósito: los tres endpoints de aprobación de
 * LeadController ya la capturan y devuelven 422 con el mensaje de la excepción, así que este fix
 * no necesita tocar ni el controller ni las rutas.
 */
class HorarioYaNoDisponibleException extends \InvalidArgumentException
{
}

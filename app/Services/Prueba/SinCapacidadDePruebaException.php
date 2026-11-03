<?php

namespace App\Services\Prueba;

/**
 * El alta de una prueba no encontró lugar (sin instancia libre o tope de activas superado). Se lanza
 * ADENTRO de la transacción del alta para que nada de lo escrito (lead, cliente) quede a medias; el
 * controlador la convierte en el 409 `sin_capacidad` del contrato C6.
 */
class SinCapacidadDePruebaException extends \RuntimeException
{
}

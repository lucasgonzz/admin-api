<?php

namespace App\Exceptions;

/**
 * Se tira al APROBAR un mensaje que asigna una demo directa (`agendar_demo: {ahora: true}`,
 * misión demo-agendado-directo) cuando en ese instante las instancias de demo están todas
 * ocupadas.
 *
 * Es la tercera de la familia de AprobacionEnCursoException y HorarioYaNoDisponibleException, y
 * se parece a la primera y no a la segunda: es un estado TRANSITORIO y REINTENTABLE. La
 * instancia se libera cuando el lead que la ocupa termina su ventana; el mensaje sigue
 * `sugerido` con sus acciones pendientes intactas, el lead no se marca para intervención, no se
 * abre tarea ni queda bloque rojo. El aprobador ve el 422 con la hora en que se libera la
 * primera y vuelve a aprobar después. No se le envía nada al lead.
 *
 * Extiende \InvalidArgumentException por el mismo motivo que sus hermanas: los endpoints de
 * aprobación de LeadController ya la capturan y devuelven 422 con el mensaje.
 */
class SinDemoLibreException extends \InvalidArgumentException
{
}

<?php

namespace App\Exceptions;

/**
 * Se tira cuando una aprobación no pudo tomar el lock de la instancia de demo porque otra
 * aprobación sobre esa MISMA instancia está corriendo en este instante.
 *
 * 🔴 La diferencia con HorarioYaNoDisponibleException es la que importa, y por eso son dos clases
 * y no una:
 *
 * - HorarioYaNoDisponibleException es un descarte LEGÍTIMO y DEFINITIVO: el turno ya pasó, lo tomó
 *   otro lead, o la franja prometida ya no entra. Reintentar no cambia nada, así que el lead queda
 *   marcado para intervención humana, se abre una AdminTask, el mensaje pasa a `rechazado` y queda
 *   un bloque rojo en el hilo.
 * - AprobacionEnCursoException es contención TRANSITORIA y REINTENTABLE: el `block(5)` del lock
 *   venció, nada más. No es ninguno de los dos motivos de descarte que existen. Por eso no marca al
 *   lead, no crea tarea, no toca el status del mensaje y no deja bloque rojo: castigar
 *   permanentemente una conversación por un timeout de 5 segundos quema el lead por un problema de
 *   concurrencia que se resuelve apretando aprobar de nuevo.
 *
 * Lo único que comparten: tampoco acá se reescribe ni se envía nada al lead.
 *
 * Extiende \InvalidArgumentException por la misma razón que la otra: los tres endpoints de
 * aprobación de LeadController ya la capturan y devuelven 422 con el mensaje de la excepción, así
 * que no hay que tocar ni el controller ni las rutas.
 */
class AprobacionEnCursoException extends \InvalidArgumentException
{
}

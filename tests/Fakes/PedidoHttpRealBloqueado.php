<?php

namespace Tests\Fakes;

use RuntimeException;

/**
 * Lo que lanza `HttpFactorySinSalida` cuando un test intenta hacer un pedido HTTP que ningún
 * `Http::fake()` atiende.
 *
 * Es una clase propia (y no un `RuntimeException` pelado) para que se la pueda reconocer: el código
 * de la aplicación suele atrapar `\Throwable` y seguir, y un test que quiera afirmar "esto intentó
 * salir y se frenó" necesita distinguir esta excepción de cualquier otro error.
 */
class PedidoHttpRealBloqueado extends RuntimeException
{
}

<?php

namespace Tests\Fakes;

use Illuminate\Http\Client\Factory;

/**
 * El cliente HTTP de los tests: el de Laravel, con una sola diferencia — un pedido que NINGÚN
 * `Http::fake()` atiende no sale a internet: lanza `PedidoHttpRealBloqueado`.
 *
 * 🔴 POR QUÉ EXISTE (5/10/2026). Un test de `ClaudeImplementaciones` avanzó una implementación a la
 * etapa 2 sin falsear la llamada al user setup, y el POST salió de verdad a
 * `https://api-panchito.comerciocity.com/api/admin-sync/user-setup`. Del otro lado ese endpoint corre
 * `migrate:fresh`: vació la base de producción de un cliente real. Un test no puede ser capaz de eso.
 *
 * Esto es lo que en Laravel 9+ se llama `Http::preventStrayRequests()`. El admin corre sobre Laravel
 * 8.83, que no lo trae, y por eso está escrito acá. Lo instala el `TestCase` base en cada test.
 *
 * CÓMO FUNCIONA. Cada pedido pasa por una lista de "stubs" (los que registra `Http::fake()`), y
 * Laravel se queda con la primera respuesta que no sea nula; si todos devuelven nulo, deja que el
 * pedido siga hacia la red. Acá se envuelve esa lista en un único stub que hace exactamente lo mismo
 * (se invocan TODOS los stubs, igual que en el original, y gana el primero que responde) y, si
 * ninguno respondió, en vez de dejar seguir el pedido lo frena. Con un fake que cubre la URL, el
 * comportamiento es idéntico al de siempre.
 *
 * Cada pedido frenado queda anotado (`frenados()`): el `TestCase` los vuelca a
 * `storage/logs/http-frenado-en-tests.log` al terminar el test, para que un test que se tragó la
 * excepción —la aplicación atrapa `\Throwable` casi en todas partes— no pase desapercibido.
 *
 * Esto es la primera de varias capas; las otras viven en `phpunit.xml` (un proxy muerto para todo lo
 * que use Guzzle sin pasar por acá) y en `ImplementationUserSetupService` (el user setup no apunta a
 * un host que no sea `.test` cuando `APP_ENV=testing`).
 */
class HttpFactorySinSalida extends Factory
{
    /**
     * Los pedidos frenados desde el último `vaciar()`, como "POST https://…".
     *
     * @var array<int, string>
     */
    protected static $frenados = [];

    /**
     * @return array<int, string> Los pedidos que se frenaron desde el último `vaciar()`.
     */
    public static function frenados(): array
    {
        return self::$frenados;
    }

    /**
     * Borra la lista de pedidos frenados (el `TestCase` lo hace al arrancar cada test).
     *
     * @return void
     */
    public static function vaciar(): void
    {
        self::$frenados = [];
    }

    /**
     * Igual que `Factory::__call()`, salvo que la lista de stubs del pedido es un único stub que, si
     * ninguno de los registrados responde, frena el pedido.
     *
     * @param string $method     Método del `PendingRequest` (`post`, `get`, `withHeaders`…).
     * @param array  $parameters Argumentos.
     *
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        if (static::hasMacro($method)) {
            return $this->macroCall($method, $parameters);
        }

        $stubs = $this->stubCallbacks;

        $guarda = function ($request, $opciones) use ($stubs) {
            $respuesta = $stubs->map->__invoke($request, $opciones)->filter()->first();

            if ($respuesta !== null) {
                return $respuesta;
            }

            $pedido = $request->method() . ' ' . $request->url();

            self::$frenados[] = $pedido;

            throw new PedidoHttpRealBloqueado(
                'Pedido HTTP real bloqueado en un test: ' . $pedido . '. Ningún Http::fake() lo atiende y los '
                . 'tests de admin-api no salen a internet. Falsealo con Http::fake([...]).'
            );
        };

        return tap($this->newPendingRequest(), function ($pendiente) use ($guarda) {
            $pendiente->stub(collect([$guarda]));
        })->{$method}(...$parameters);
    }
}

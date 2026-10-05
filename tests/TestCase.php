<?php

namespace Tests;

use App\Models\AdminSetting;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\HttpFactorySinSalida;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * Cada test arranca con el memo de admin_settings vacío y SIN salida a internet.
     *
     * 🔴 El memo: no es decoración. Es una propiedad ESTÁTICA, así que sobrevive de un test al
     * siguiente dentro del mismo proceso de PHPUnit — y con DatabaseTransactions eso deja una
     * trampa fina. Un test que escribe un setting vacía el memo (lo hacen los eventos del modelo)
     * y después lo vuelve a leer, dejándolo memorizado; al terminar, el rollback borra la fila
     * pero NO el memo. El test siguiente leería un valor que en la base ya no existe.
     *
     * Limpiarlo acá vale para toda la suite, incluidos los tests que todavía no están escritos.
     * En producción no hay equivalente de esto: ahí cada request arranca con un proceso limpio, y
     * los jobs los corta AppServiceProvider::boot() con Queue::before().
     *
     * 🔴 La salida a internet: ver cerrar_la_salida_a_internet().
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->cerrar_la_salida_a_internet();

        AdminSetting::flush_memo();
    }

    /**
     * Anota los pedidos que el freno de HTTP detuvo durante el test, para que un test que se tragó la
     * excepción (la aplicación atrapa `\Throwable` casi en todas partes) no pase desapercibido.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->anotar_los_pedidos_frenados();

        parent::tearDown();
    }

    /**
     * Ningún test de admin-api sale a internet.
     *
     * 🔴 POR QUÉ (5/10/2026). Un test de `ClaudeImplementaciones` avanzó una implementación a la
     * etapa 2 sin falsear la llamada al user setup: el POST salió de verdad a
     * `https://api-panchito.comerciocity.com/api/admin-sync/user-setup`, que del otro lado corre
     * `migrate:fresh`, y vació la base de producción de un cliente real. La suite no tenía ningún
     * freno: el único que hay en Laravel 9+ (`Http::preventStrayRequests()`) no existe en la 8.83 del
     * admin.
     *
     * Lo que hace esto, y qué NO hace:
     *  - Cambia el cliente HTTP por `HttpFactorySinSalida`: un pedido que ningún `Http::fake()`
     *    atiende lanza `PedidoHttpRealBloqueado` en vez de salir. Con un fake que lo cubre, nada
     *    cambia. Un test que necesite una fábrica limpia (los stubs de `Http::fake()` se acumulan y
     *    gana el primero) tiene que hacer `Http::swap(new HttpFactorySinSalida())`, NO
     *    `Http::swap(new \Illuminate\Http\Client\Factory())`: esta última deja el test sin freno.
     *  - Vacía el token de Hostinger. Vive en una variable de entorno de ESTA máquina
     *    (`HOSTINGER_API_TOKEN`) y `env()` la lee aunque el test corra con `.env.testing`: sin esto,
     *    cada test que no lo pisa tiene el token REAL de la cuenta donde viven los clientes. Los que
     *    necesitan uno lo ponen ellos con `config([...])`, después de `parent::setUp()`.
     *  - NO cubre lo que no pasa por la fachada `Http`: SSH (phpseclib), los clientes SOAP de ARCA y
     *    Web Push. Los tres dependen de datos que el test mismo arma (credenciales, certificados,
     *    suscripciones), y los de SSH usan hosts `.test` o de loopback. El proxy muerto de
     *    `phpunit.xml` cubre además cualquier Guzzle que se arme sin pasar por la fachada.
     *
     * @return void
     */
    protected function cerrar_la_salida_a_internet(): void
    {
        HttpFactorySinSalida::vaciar();

        Http::swap(new HttpFactorySinSalida());

        config(['services.hostinger.api_token' => '']);
    }

    /**
     * Vuelca a `storage/logs/http-frenado-en-tests.log` los pedidos que `HttpFactorySinSalida` frenó
     * en este test (si hubo). Nunca rompe un test: es solo un registro.
     *
     * @return void
     */
    protected function anotar_los_pedidos_frenados(): void
    {
        $frenados = HttpFactorySinSalida::frenados();

        if ($frenados === []) {
            return;
        }

        try {
            $lineas = '';

            foreach ($frenados as $pedido) {
                $lineas .= date('Y-m-d H:i:s') . ' ' . static::class . '::' . $this->getName(false) . ' ' . $pedido . PHP_EOL;
            }

            file_put_contents(storage_path('logs/http-frenado-en-tests.log'), $lineas, FILE_APPEND);
        } catch (\Throwable $excepcion) {
            // Es un registro de auditoría: si no se puede escribir, el test sigue igual.
        }
    }
}

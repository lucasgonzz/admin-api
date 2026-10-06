<?php

namespace Tests\Unit;

use App\Services\ImplementationUserSetupService;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Http\Client\Factory as FabricaDeLaravel;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Fakes\HttpFactorySinSalida;
use Tests\Fakes\PedidoHttpRealBloqueado;
use Tests\TestCase;

/**
 * Los tests de admin-api NO salen a internet (misión `implementar-cliente`, 5/10/2026).
 *
 * Un test de `ClaudeImplementaciones` hizo un POST real a `https://api-panchito.comerciocity.com/api/
 * admin-sync/user-setup` y, como del otro lado ese endpoint corre `migrate:fresh`, vació la base de
 * producción de un cliente. Estos tests fijan las barreras que lo impiden, en la capa más baja: la que
 * instala el `TestCase` base y las de `phpunit.xml`.
 *
 * 🔴 TODOS los hosts de este archivo son `.test` o `.invalid`. Si una barrera se rompe, lo que estos tests
 * hagan no tiene que poder llegar a ningún servidor real: un test de la barrera que apunta a un host
 * real es exactamente el error que la barrera existe para impedir.
 */
class SalidaAInternetDeLosTestsTest extends TestCase
{
    /**
     * Vacía los pedidos frenados al terminar: acá se frenan a propósito, y no tienen que ensuciar el
     * registro de auditoría (`storage/logs/http-frenado-en-tests.log`) que mira a los demás tests.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        HttpFactorySinSalida::vaciar();

        parent::tearDown();
    }

    /**
     * @return void
     */
    public function test_el_cliente_http_de_cada_test_es_el_que_frena(): void
    {
        $this->assertInstanceOf(HttpFactorySinSalida::class, Http::getFacadeRoot());
    }

    /**
     * El caso del incidente: un pedido que ningún fake atiende NO sale.
     *
     * @return void
     */
    public function test_un_pedido_sin_fake_se_frena_y_no_sale(): void
    {
        try {
            Http::timeout(2)->acceptJson()->asJson()->post('https://api-guardia.ejemplo.test/api/admin-sync/user-setup', ['a' => 1]);

            $this->fail('El pedido sin fake salió: el freno no frenó.');
        } catch (PedidoHttpRealBloqueado $excepcion) {
            $this->assertStringContainsString('POST https://api-guardia.ejemplo.test/api/admin-sync/user-setup', $excepcion->getMessage());
        }

        $this->assertSame(['POST https://api-guardia.ejemplo.test/api/admin-sync/user-setup'], HttpFactorySinSalida::frenados());
    }

    /**
     * También se frenan los GET, y los que arman el pedido con otro método del `PendingRequest`.
     *
     * @return void
     */
    public function test_se_frena_cualquier_verbo(): void
    {
        foreach (['get', 'delete', 'put', 'patch', 'head'] as $verbo) {
            try {
                Http::{$verbo}('https://api-guardia.ejemplo.test/x');

                $this->fail('El ' . strtoupper($verbo) . ' sin fake salió.');
            } catch (PedidoHttpRealBloqueado $excepcion) {
                $this->assertStringContainsString(strtoupper($verbo) . ' https://api-guardia.ejemplo.test/x', $excepcion->getMessage());
            }
        }

        $this->assertCount(5, HttpFactorySinSalida::frenados());
    }

    /**
     * Con un fake que cubre la URL, todo anda como siempre.
     *
     * @return void
     */
    public function test_con_un_fake_que_lo_cubre_el_pedido_pasa_igual_que_siempre(): void
    {
        Http::fake(['https://api-guardia.ejemplo.test/*' => Http::response(['ok' => true], 201)]);

        $respuesta = Http::post('https://api-guardia.ejemplo.test/api/admin-sync/user-setup', ['a' => 1]);

        $this->assertSame(201, $respuesta->status());
        $this->assertTrue($respuesta->json('ok'));
        Http::assertSentCount(1);
        $this->assertSame([], HttpFactorySinSalida::frenados());
    }

    /**
     * Un fake de un host no habilita a los demás: es lo que pasó con el user setup, donde el test
     * falseaba una cosa y el pedido peligroso iba por otra.
     *
     * @return void
     */
    public function test_un_fake_de_otra_url_no_habilita_las_demas(): void
    {
        Http::fake(['https://permitido.ejemplo.test/*' => Http::response([], 200)]);

        $this->assertSame(200, Http::get('https://permitido.ejemplo.test/a')->status());

        $this->expectException(PedidoHttpRealBloqueado::class);

        Http::get('https://otro-host.ejemplo.test/a');
    }

    /**
     * El freno no cambia la semántica de los fakes: dada la misma lista de fakes, la fábrica que frena
     * y la de Laravel responden lo mismo y llaman a los mismos stubs en el mismo orden. (Los stubs se
     * acumulan, se invocan TODOS aunque responda el primero, y gana el primero que responde.)
     *
     * @return void
     */
    public function test_el_freno_no_cambia_la_semantica_de_los_fakes(): void
    {
        $armar = function ($fabrica, array &$visto) {
            $fabrica->fake(function ($pedido) use (&$visto) {
                $visto[] = 'a';

                return Http::response(['gana' => 'a'], 200);
            });

            $fabrica->fake(function ($pedido) use (&$visto) {
                $visto[] = 'b';

                return Http::response(['gana' => 'b'], 200);
            });

            return $fabrica->post('https://api-guardia.ejemplo.test/x', ['k' => 'v']);
        };

        $visto_laravel = [];
        $visto_freno   = [];

        $de_laravel = $armar(new FabricaDeLaravel(), $visto_laravel);
        $con_freno  = $armar(new HttpFactorySinSalida(), $visto_freno);

        $this->assertSame(['a', 'b'], $visto_laravel, 'Laravel invoca todos los stubs (si no, este test no prueba nada).');
        $this->assertSame($visto_laravel, $visto_freno);
        $this->assertSame($de_laravel->json(), $con_freno->json());
        $this->assertSame('a', $con_freno->json('gana'));
    }

    /**
     * Los `Http::sequence()` y `Http::fakeSequence()` siguen funcionando con el freno puesto.
     *
     * @return void
     */
    public function test_las_secuencias_siguen_andando(): void
    {
        Http::fake(['https://api-guardia.ejemplo.test/*' => Http::sequence()->push(['n' => 1], 200)->push(['n' => 2], 500)]);

        $this->assertSame(1, Http::get('https://api-guardia.ejemplo.test/a')->json('n'));
        $this->assertSame(500, Http::get('https://api-guardia.ejemplo.test/a')->status());
    }

    /**
     * La segunda barrera: Guzzle, armado a mano sin pasar por la fachada `Http`, sale por un proxy que no
     * existe. Se mira la configuración del cliente y no se hace ningún pedido.
     *
     * @return void
     */
    public function test_guzzle_sin_la_fachada_sale_por_un_proxy_muerto(): void
    {
        $proxy = (new GuzzleClient())->getConfig('proxy');

        $this->assertIsArray($proxy);
        $this->assertSame('http://127.0.0.1:9', $proxy['http']);
        $this->assertSame('http://127.0.0.1:9', $proxy['https']);
        $this->assertContains('localhost', $proxy['no']);
        $this->assertContains('127.0.0.1', $proxy['no']);
    }

    /**
     * El token real de Hostinger vive en una variable de entorno de la máquina de desarrollo; ningún test
     * puede verlo salvo que lo ponga él mismo.
     *
     * @return void
     */
    public function test_el_token_real_de_hostinger_no_se_cuela_a_los_tests(): void
    {
        $this->assertSame('', trim((string) config('services.hostinger.api_token')));
    }

    /**
     * El freno propio del user setup: qué hosts se aceptan con `APP_ENV=testing`.
     *
     * @return void
     */
    public function test_el_user_setup_solo_acepta_hosts_de_prueba_en_testing(): void
    {
        $this->assertSame('testing', app()->environment());

        $permitidos = [
            'https://api-panchito.ejemplo.test/api/admin-sync/user-setup',
            'http://api-panchito.test/api/admin-sync/user-setup',
            'https://api-timeout.test',
            'http://localhost:8000/api/admin-sync/user-setup',
            'http://127.0.0.1:8100/api/admin-sync/user-setup',
            'http://[::1]:8100/api/admin-sync/user-setup',
            'https://api-panchito.localhost/api/admin-sync/user-setup',
            'HTTPS://API-PANCHITO.EJEMPLO.TEST/api/admin-sync/user-setup',
        ];

        foreach ($permitidos as $url) {
            $this->assertTrue(ImplementationUserSetupService::destino_permitido_en_este_entorno($url), 'Tendría que aceptar ' . $url);
        }

        $negados = [
            'https://api-panchito.comerciocity.com/api/admin-sync/user-setup',
            'https://api-panchito.comerciocity.com',
            'https://panchito.test.com/api/admin-sync/user-setup',
            'https://api-panchito.comerciocity.com/x.test',
            'https://api-panchito.comerciocity.com/?next=http://x.test/',
            'https://test/api/admin-sync/user-setup',
            'https://notatest/api/admin-sync/user-setup',
            'https://api-panchito.comerciocity.test.evil.com/api/admin-sync/user-setup',
            'https://76.13.171.147/api/admin-sync/user-setup',
            '/api/admin-sync/user-setup',
            '',
        ];

        foreach ($negados as $url) {
            $this->assertFalse(ImplementationUserSetupService::destino_permitido_en_este_entorno($url), 'Tendría que negar «' . $url . '»');
        }
    }

    /**
     * 🔴 La alarma: un pedido frenado que el código bajo prueba SE TRAGÓ (como hace el servicio del incidente: atrapa todo y
     * sigue) hace FALLAR al test, con la lista de pedidos y qué hacer. Es lo que corre en el `tearDown()` del `TestCase`;
     * acá se llama a mano porque este test vacía la lista en su propio `tearDown()` (frena a propósito).
     *
     * @return void
     */
    public function test_un_pedido_frenado_que_el_codigo_se_trago_hace_fallar_al_test(): void
    {
        try {
            Http::post('https://api-trago.ejemplo.test/api/admin-sync/user-setup', ['a' => 1]);
        } catch (\Throwable $excepcion) {
            // Lo que hace la aplicación casi en todas partes: se lo traga y sigue. El test "pasaría".
        }

        $salto   = false;
        $mensaje = '';

        // La alarma también anota el pedido en el registro de auditoría (`http-frenado-en-tests.log`): acá se deja ese archivo
        // como estaba, porque su sola existencia después de una corrida es la señal de que ALGÚN test intentó salir.
        $registro = storage_path('logs/http-frenado-en-tests.log');
        $existia  = file_exists($registro);
        $tamano   = $existia ? filesize($registro) : 0;

        try {
            $this->exigir_que_no_se_haya_frenado_ningun_pedido();
        } catch (AssertionFailedError $falla) {
            $salto   = true;
            $mensaje = $falla->getMessage();
        } finally {
            if (! $existia) {
                @unlink($registro);
            } elseif (file_exists($registro)) {
                $manejador = fopen($registro, 'r+');
                ftruncate($manejador, $tamano);
                fclose($manejador);
            }
        }

        $this->assertTrue($salto, 'Un pedido frenado y tragado no hizo fallar al test: el freno es solo un registro.');
        $this->assertStringContainsString('POST https://api-trago.ejemplo.test/api/admin-sync/user-setup', $mensaje);
        $this->assertStringContainsString('Http::fake', $mensaje);
        $this->assertSame([], HttpFactorySinSalida::frenados(), 'La alarma tiene que vaciar la lista: si no, se le cuenta al test siguiente.');
    }

    /**
     * Sin pedidos frenados la alarma no salta (el caso de todos los tests de la suite).
     *
     * @return void
     */
    public function test_sin_pedidos_frenados_la_alarma_no_salta(): void
    {
        Http::fake(['https://api-ok.ejemplo.test/*' => Http::response([], 200)]);

        Http::get('https://api-ok.ejemplo.test/x');

        $this->exigir_que_no_se_haya_frenado_ningun_pedido();

        $this->assertSame([], HttpFactorySinSalida::frenados());
    }

    /**
     * 🔴 La otra mitad del freno del user setup: FUERA de `testing` puede llamar a cualquier host, como siempre. Si alguien
     * rompe la condición de entorno de `destino_permitido_en_este_entorno()`, el user setup de producción dejaría de salir
     * (se negaría a llamar a todos los clientes) y ningún otro test lo avisaría.
     *
     * @return void
     */
    public function test_fuera_de_testing_el_user_setup_puede_llamar_a_cualquier_host(): void
    {
        $original = $this->app['env'];

        try {
            foreach (['production', 'local', 'staging'] as $entorno) {
                $this->app['env'] = $entorno;

                $this->assertTrue(
                    ImplementationUserSetupService::destino_permitido_en_este_entorno('https://api-panchito.comerciocity.com/public/api/admin-sync/user-setup'),
                    'En "' . $entorno . '" el user setup tiene que poder llamar a un cliente real.'
                );
                $this->assertTrue(ImplementationUserSetupService::destino_permitido_en_este_entorno('https://76.13.171.147/api/admin-sync/user-setup'), $entorno);
            }
        } finally {
            $this->app['env'] = $original;
        }

        $this->assertSame('testing', app()->environment());
    }
}

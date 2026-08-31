<?php

namespace Tests\Feature;

use App\Services\HostingerApiClient;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\Fakes\HostingerApiClientFake;
use Tests\TestCase;

/**
 * Cliente HTTP de la API de Hostinger.
 *
 * Lo que se prueba acá no es que la API de Hostinger funcione —el token todavía no existe y no hay
 * una sola llamada real en toda la misión— sino las cuatro cosas del cliente que sí son nuestras y
 * que, si están mal, se descubren tarde y caro:
 *
 * 1. Que el token viaje en el header y NUNCA en la URL (Guzzle copia la URI adentro del mensaje de
 *    sus excepciones: un token en la query string termina escrito en laravel.log).
 * 2. Que las rutas se armen con el usuario y el dominio de config, que es la guarda G5.
 * 3. Que un error que el cliente no reconoce se clasifique como DESCONOCIDO y haga fallar al
 *    llamador, en vez de darse por bueno como "ya existía".
 * 4. Que el PUT de la zona DNS —la única operación irreversible de todo el aprovisionamiento— no
 *    pueda mandar overwrite:true ni siquiera si alguien se lo propone.
 */
class HostingerApiClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Config propia de la prueba: usuario y dominio distintos de los reales, para que un test
         * que pase por tener los defaults hardcodeados se caiga.
         */
        config([
            'services.hostinger.api_token'        => 'token-de-prueba-123',
            'services.hostinger.account_username' => 'u000000000',
            'services.hostinger.domain'           => 'dominio-de-prueba.test',
            'services.hostinger.base_url'         => 'https://hostinger.invalido.test',
            'services.hostinger.verify_ssl'       => true,
            'services.hostinger.ca_bundle'        => null,
            'services.hostinger.timeout'          => 5,
        ]);
    }

    public function test_manda_el_token_en_el_header_authorization_y_nunca_en_la_url(): void
    {
        Http::fake(['*' => Http::response([['uid' => 'a1b2', 'command' => 'php artisan schedule:run']], 200)]);

        $crons = (new HostingerApiClient())->list_crons();

        $this->assertSame('a1b2', $crons[0]['uid']);

        Http::assertSent(function ($request) {
            $this->assertSame('Bearer token-de-prueba-123', $request->header('Authorization')[0]);
            $this->assertSame('application/json', $request->header('Accept')[0]);

            /* Lo que de verdad importa: el token no está en la URL. */
            $this->assertStringNotContainsString('token-de-prueba-123', $request->url());
            $this->assertSame(
                'https://hostinger.invalido.test/api/hosting/v1/accounts/u000000000/cron-jobs',
                $request->url()
            );

            return true;
        });
    }

    public function test_arma_las_rutas_con_el_usuario_y_el_dominio_de_config(): void
    {
        $fake = new HostingerApiClientFake();

        $fake->list_crons();
        $fake->create_subdomain('api-lacava', 'lacava/api', false);
        $fake->list_databases();
        $fake->get_dns_zone();
        $fake->create_dns_snapshot();
        $fake->delete_cron('uid con espacio');

        $rutas = array_column($fake->llamadas, 'ruta');

        $this->assertSame('/api/hosting/v1/accounts/u000000000/cron-jobs', $rutas[0]);
        $this->assertSame(
            '/api/hosting/v1/accounts/u000000000/websites/dominio-de-prueba.test/subdomains',
            $rutas[1]
        );
        $this->assertSame('/api/hosting/v1/accounts/u000000000/databases', $rutas[2]);
        $this->assertSame('/api/dns/v1/zones/dominio-de-prueba.test', $rutas[3]);
        $this->assertSame('/api/dns/v1/snapshots/dominio-de-prueba.test', $rutas[4]);

        /* El uid va escapado: un uid con caracteres raros no puede romper la ruta. */
        $this->assertSame('/api/hosting/v1/accounts/u000000000/cron-jobs/uid%20con%20espacio', $rutas[5]);
    }

    public function test_el_payload_del_subdominio_lleva_el_directory_y_el_flag_tal_cual_se_los_pasan(): void
    {
        $fake = new HostingerApiClientFake();

        $fake->create_subdomain('api-lacava', 'lacava/api', false);

        $body = $fake->llamadas[0]['body'];

        $this->assertSame('api-lacava', $body['subdomain']);
        /* 🔴 'lacava/api' y NUNCA 'lacava/api/public': el /public lo agrega ClientEmpresaApiUrlResolver. */
        $this->assertSame('lacava/api', $body['directory']);
        $this->assertFalse($body['is_using_public_directory']);
    }

    public function test_probar_token_falla_claro_sin_token_y_sin_llamar_a_nada(): void
    {
        config(['services.hostinger.api_token' => '']);

        $fake = new HostingerApiClientFake();

        $this->assertFalse($fake->token_configurado());

        try {
            $fake->probar_token();
            $this->fail('probar_token() tenía que fallar sin token configurado.');
        } catch (\RuntimeException $excepcion) {
            $this->assertStringContainsString('HOSTINGER_API_TOKEN', $excepcion->getMessage());
            $this->assertStringContainsString('Generate API token', $excepcion->getMessage());
        }

        /* Sin token no se sale a la red ni para preguntar. */
        $this->assertSame([], $fake->llamadas);
    }

    public function test_probar_token_sondea_con_un_get_de_crons_y_traduce_el_401(): void
    {
        $fake = new HostingerApiClientFake();
        $fake->fallar_con('/cron-jobs', 401, '{"message":"Unauthenticated."}');

        try {
            $fake->probar_token();
            $this->fail('probar_token() tenía que fallar con un 401.');
        } catch (\RuntimeException $excepcion) {
            $this->assertStringContainsString('rechazó el HOSTINGER_API_TOKEN', $excepcion->getMessage());
            $this->assertSame(401, $excepcion->getCode());
        }

        /* La sonda es una lectura pura y la llamada más barata de la API. */
        $this->assertCount(1, $fake->llamadas);
        $this->assertSame('GET', $fake->llamadas[0]['metodo']);
        $this->assertSame([], $fake->escrituras());
    }

    public function test_clasifica_ya_existe_solo_cuando_reconoce_el_mensaje(): void
    {
        $cliente = new HostingerApiClient();

        $ya_existe = new \RuntimeException(
            'La API de Hostinger respondió 409: {"message":"The subdomain already exists."}',
            409
        );

        $this->assertSame(HostingerApiClient::CLASIFICACION_YA_EXISTE, $cliente->clasificar_error($ya_existe));
        $this->assertTrue($cliente->es_error_de_ya_existe($ya_existe));
    }

    public function test_un_error_desconocido_no_se_toma_como_ya_existe(): void
    {
        $cliente = new HostingerApiClient();

        /*
         * 🔴 Este es el test que sostiene "nunca se adivina". Un 422 con un mensaje que el cliente
         * no conoce NO puede clasificarse como "ya existía": el llamador tiene que verificar contra
         * el proveedor o fallar. Si esto devolviera true, el pipeline seguiría de largo creyendo
         * que la base está creada cuando nunca se creó.
         */
        $desconocido = new \RuntimeException(
            'La API de Hostinger respondió 422: {"message":"Something we have never seen."}',
            422
        );

        $this->assertSame(HostingerApiClient::CLASIFICACION_DESCONOCIDA, $cliente->clasificar_error($desconocido));
        $this->assertFalse($cliente->es_error_de_ya_existe($desconocido));
    }

    public function test_los_errores_que_seguro_no_son_ya_existe_se_clasifican_aparte(): void
    {
        $cliente = new HostingerApiClient();

        $casos = [
            401 => 'La API de Hostinger respondió 401: {"message":"Unauthenticated."}',
            403 => 'La API de Hostinger respondió 403: {"message":"Forbidden."}',
            404 => 'La API de Hostinger respondió 404: {"message":"Not found."}',
            500 => 'La API de Hostinger respondió 500: {"message":"Server error."}',
            0   => 'Error de conexión con la API de Hostinger: cURL error 28: timeout',
        ];

        foreach ($casos as $codigo => $mensaje) {
            $excepcion = new \RuntimeException($mensaje, $codigo);

            $this->assertSame(
                HostingerApiClient::CLASIFICACION_OTRO_ERROR,
                $cliente->clasificar_error($excepcion),
                'El código ' . $codigo . ' tendría que clasificarse como otro_error.'
            );
            $this->assertFalse($cliente->es_error_de_ya_existe($excepcion));
        }
    }

    public function test_el_put_de_la_zona_dns_nunca_manda_overwrite_true(): void
    {
        $fake = new HostingerApiClientFake();

        $fake->put_dns_zone([
            ['name' => 'lacava', 'type' => 'A', 'records' => [['content' => '76.13.171.147']]],
        ]);

        $llamada = $fake->llamadas_de('PUT')[0];

        $this->assertSame('/api/dns/v1/zones/dominio-de-prueba.test', $llamada['ruta']);
        $this->assertArrayHasKey('overwrite', $llamada['body']);
        $this->assertFalse($llamada['body']['overwrite']);
        $this->assertCount(1, $llamada['body']['zone']);

        /*
         * 🔴 Guarda G6, medida de dos formas a propósito. La de arriba prueba lo que se manda; esta
         * prueba que no hay forma de mandar otra cosa: put_dns_zone() no tiene parámetro overwrite,
         * y el literal no aparece en el archivo. Si alguien "simplifica" agregando el parámetro
         * para reusar el método, este test se pone en rojo antes de que el PUT llegue a la zona
         * donde viven los subdominios de los ~40 clientes activos.
         */
        $reflexion = new ReflectionMethod(HostingerApiClient::class, 'put_dns_zone');
        $this->assertSame(1, $reflexion->getNumberOfParameters());

        $fuente = file_get_contents(app_path('Services/HostingerApiClient.php'));
        $this->assertStringNotContainsString("'overwrite' => true", $fuente);
    }

    public function test_el_payload_que_se_loguea_no_lleva_la_contrasena(): void
    {
        $fake = new HostingerApiClientFake();

        $redactado = $fake->redactar_payload_publico([
            'name'     => 'u767360347_lacava',
            'user'     => 'u767360347_lacava',
            'password' => 'Cl4ve-Secreta-De-La-Base',
            'anidado'  => ['token' => 'abc123', 'time' => '* * * * *'],
        ]);

        $this->assertSame('***', $redactado['password']);
        $this->assertSame('***', $redactado['anidado']['token']);

        /* Lo que no es secreto se loguea entero: es lo único que va a decir qué se mandó. */
        $this->assertSame('u767360347_lacava', $redactado['name']);
        $this->assertSame('* * * * *', $redactado['anidado']['time']);
    }

    public function test_filtra_los_crons_por_ruta_de_api_y_reconoce_los_de_cola(): void
    {
        $fake = new HostingerApiClientFake();
        $fake->responder('/cron-jobs', [
            ['uid' => '1', 'command' => '/usr/bin/php /home/u1/domains/x/public_html/lacava/api/artisan schedule:run'],
            ['uid' => '2', 'command' => '/usr/bin/php /home/u1/domains/x/public_html/otro/api/artisan schedule:run'],
            ['uid' => '3', 'command' => '/usr/bin/php /home/u1/domains/x/public_html/lacava/api/artisan check_stocks'],
        ]);

        $encontrados = $fake->crons_for_api_path('domains/x/public_html/lacava/api');

        $this->assertCount(2, $encontrados);
        $this->assertSame(['1', '3'], array_column($encontrados, 'uid'));

        /* Solo los de cola se consideran reemplazables; los comandos de negocio no se tocan. */
        $this->assertTrue($fake->es_cron_de_cola($encontrados[0]['command']));
        $this->assertFalse($fake->es_cron_de_cola($encontrados[1]['command']));
    }

    public function test_una_respuesta_vacia_no_es_un_error(): void
    {
        /* Un DELETE exitoso devuelve 204 sin cuerpo, y eso no puede tomarse como falla. */
        Http::fake(['*' => Http::response('', 204)]);

        (new HostingerApiClient())->delete_cron('a1b2');

        Http::assertSent(function ($request) {
            return $request->method() === 'DELETE';
        });
    }

    public function test_un_4xx_conserva_el_status_en_el_codigo_de_la_excepcion(): void
    {
        /*
         * Va en un test propio y no pegado al del 204: Http::fake() ACUMULA stubs en vez de
         * reemplazarlos, así que un segundo fake en el mismo test nunca llega a usarse.
         *
         * El código de la excepción es lo que después lee clasificar_error() para decidir si el
         * error puede o no ser un "ya existe". Si se pierde, toda la idempotencia queda ciega.
         */
        Http::fake(['*' => Http::response('{"message":"No encontrado"}', 404)]);

        try {
            (new HostingerApiClient())->list_databases();
            $this->fail('Un 404 tenía que lanzar excepción.');
        } catch (\RuntimeException $excepcion) {
            $this->assertSame(404, $excepcion->getCode());
            $this->assertStringContainsString('La API de Hostinger respondió 404', $excepcion->getMessage());
        }
    }
}

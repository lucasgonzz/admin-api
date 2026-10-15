<?php

namespace Tests\Feature\Recursos;

use App\Models\Admin;
use App\Models\Client;
use App\Models\ClientApi;
use App\Models\RecursosUsuario;
use App\Models\RecursosVideo;
use App\Services\RecursosCatalogoService;
use App\Services\RecursosSesionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Andamiaje común de los tests del centro de recursos (misión centro-de-recursos-clientes,
 * 13/10/2026): clientes con su API, videos del catálogo, usuarios del centro con sesión y un admin
 * logueado por Sanctum. Todo dentro de la transacción del test.
 *
 * 🔴 Nada sale a internet: los clientes usan hosts `.test` y cada llamada al `empresa-api` de un
 * cliente o a la IA se falsea con `Http::fake()` en el test que la necesita (el `TestCase` del
 * proyecto, además, frena y hace fallar cualquier pedido real).
 *
 * No termina en `Test.php` a propósito, así PHPUnit no lo corre como suite.
 */
abstract class BaseDelCentroDeRecursos extends TestCase
{
    use DatabaseTransactions;

    /**
     * Clave de ingesta de Claude para las rutas `claude/*`.
     */
    const CLAVE_CLAUDE = 'clave-de-prueba-recursos';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.claude_task_ingest.key' => self::CLAVE_CLAUDE]);

        // El catálogo vive en caché para siempre: cada test arranca sin lo que dejó el anterior.
        Cache::forget(RecursosCatalogoService::CACHE_CATALOGO);
        Cache::forget(RecursosCatalogoService::CACHE_INDICE);
    }

    /**
     * Cliente con una API activa en un host propio del test.
     *
     * @param string               $host      Host de su empresa-api (sin esquema).
     * @param array<string, mixed> $atributos Sobrescriben los del cliente.
     * @param string               $hosting   vps | shared_hosting.
     *
     * @return Client
     */
    protected function crear_cliente(string $host, array $atributos = [], string $hosting = 'vps'): Client
    {
        $client                  = new Client();
        $client->name            = 'Marcela Gómez';
        $client->company_name    = 'Ferretería El Tornillo';
        $client->slug            = 'recursos-' . Str::random(8);
        $client->api_key         = 'clave-api-' . Str::random(10);
        $client->inbound_api_key = 'clave-inbound';
        $client->is_active       = true;

        foreach ($atributos as $clave => $valor) {
            $client->{$clave} = $valor;
        }

        $client->save();

        $api               = new ClientApi();
        $api->client_id    = $client->id;
        $api->url          = 'https://' . $host;
        $api->path         = $client->slug . '/api';
        $api->spa_url      = 'https://spa-' . $host;
        $api->hosting_type = $hosting;
        $api->save();

        $client->active_client_api_id = $api->id;
        $client->save();

        return $client->fresh();
    }

    /**
     * Un video activo del catálogo.
     *
     * @param string               $codigo
     * @param array<string, mixed> $atributos
     *
     * @return RecursosVideo
     */
    protected function crear_video(string $codigo, array $atributos = []): RecursosVideo
    {
        return RecursosVideo::create(array_merge([
            'codigo'         => $codigo,
            'carpeta'        => 1,
            'carpeta_nombre' => 'Tus artículos (Listado)',
            'seccion'        => 'A · Lo básico',
            'orden'          => 1,
            'titulo'         => 'Video ' . $codigo,
            'para_que'       => 'Para qué sirve ' . $codigo,
            'cuando_no'      => 'Cuándo no conviene ' . $codigo,
            'relacionados'   => [],
            'transcripcion'  => 'Lo que dice el video ' . $codigo,
            'duracion'       => 60.0,
            'url'            => 'https://videos.test/recursos/' . $codigo . '-abc12345.mp4',
            'miniatura_url'  => 'https://videos.test/recursos/' . $codigo . '-abc12345.jpg',
            'version'        => 'abc12345',
            'activo'         => true,
        ], $atributos));
    }

    /**
     * Un usuario del centro de un cliente, con una sesión abierta. Devuelve [usuario, token].
     *
     * @param Client               $client
     * @param array<string, mixed> $atributos
     *
     * @return array{0: RecursosUsuario, 1: string}
     */
    protected function usuario_con_sesion(Client $client, array $atributos = []): array
    {
        $usuario = RecursosUsuario::create(array_merge([
            'client_id'       => $client->id,
            'empresa_user_id' => random_int(1000, 999999),
            'nombre'          => 'Juan Pérez',
            'email'           => null,
            'es_dueno'        => false,
        ], $atributos));

        $token = (new RecursosSesionService())->crear_sesion($usuario);

        return [$usuario, $token];
    }

    /**
     * Headers de la página con la sesión del centro.
     *
     * @param string $token
     *
     * @return array<string, string>
     */
    protected function con_sesion(string $token): array
    {
        return [
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
        ];
    }

    /**
     * Headers de Claude.
     *
     * @return array<string, string>
     */
    protected function de_claude(): array
    {
        return [
            'X-Claude-Task-Key' => self::CLAVE_CLAUDE,
            'Accept'            => 'application/json',
        ];
    }

    /**
     * Admin logueado por Sanctum (las rutas del panel viven bajo auth:sanctum).
     *
     * @return Admin
     */
    protected function admin_logueado(): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Admin de recursos';
        $admin->email    = 'recursos-' . Str::random(8) . '@test.local';
        $admin->password = bcrypt('secret');
        $admin->save();

        Sanctum::actingAs($admin);

        return $admin;
    }

    /**
     * Cuerpo de la respuesta como texto, con los acentos sin escapar.
     *
     * @param \Illuminate\Testing\TestResponse $respuesta
     *
     * @return string
     */
    protected function cuerpo($respuesta): string
    {
        return (string) json_encode($respuesta->json(), JSON_UNESCAPED_UNICODE);
    }
}

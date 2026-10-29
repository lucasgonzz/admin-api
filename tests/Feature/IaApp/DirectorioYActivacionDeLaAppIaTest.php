<?php

namespace Tests\Feature\IaApp;

use App\Jobs\InicializarAgenteIaJob;
use App\Models\IaDirectorio;
use App\Services\ClientAgentesIaService;
use App\Services\IaApp\IaDirectorioService;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * El directorio de dueños del login de la app (plan §4 A.1) y el hook de la activación de «Agentes de IA»
 * (plan §4 A.3) de la misión cruzada `app-ia-y-memoria` (29/10/2026).
 *
 * Lo que se protege:
 *  1. 🔴 El hook es best-effort: si encolar falla, la activación sigue contestando `ok`.
 *  2. El hook corre SOLO cuando un PUT dejó la extensión activa (no al quitarla ni al consultar).
 *  3. El job crea la memoria (C1.3) y sincroniza el directorio (C1.2); un 404 de una empresa vieja no es error.
 *  4. El directorio guarda el hash del usuario normalizado, nunca el usuario en claro, y un 404 no toca la fila.
 */
class DirectorioYActivacionDeLaAppIaTest extends BaseDeLaAppIa
{
    /* -------------------------------------------------------------------- Directorio */

    public function test_normaliza_el_usuario_antes_de_hashearlo(): void
    {
        $this->assertSame('20123456', IaDirectorioService::normalizar_usuario(' 20.123.456 '));
        $this->assertSame('juanperez', IaDirectorioService::normalizar_usuario('Juan . Perez'));
        $this->assertSame(IaDirectorioService::hash_usuario('20.123.456'), IaDirectorioService::hash_usuario('20123456'));
        $this->assertNull(IaDirectorioService::hash_usuario(' . '));
        $this->assertSame(64, strlen((string) IaDirectorioService::hash_usuario('x')));
    }

    public function test_sincronizar_escribe_la_fila_sin_el_usuario_en_claro(): void
    {
        $client = $this->crear_cliente('sincro');
        $this->fakear([$this->base($client) . '/api/admin-sync/ia-app/dueno' => Http::response([
            'doc_number' => '27.555.444', 'nombre' => 'Marta', 'negocio' => 'Sincro SRL', 'extension_activa' => true,
        ], 200)]);

        $resultado = app(IaDirectorioService::class)->sincronizar($client);

        $this->assertSame('ok', $resultado['estado']);
        $fila = IaDirectorio::where('client_id', $client->id)->first();
        $this->assertSame(IaDirectorioService::hash_usuario('27555444'), $fila->usuario_hash);
        $this->assertSame('Marta', $fila->nombre_dueno);
        $this->assertTrue($fila->extension_activa);
        $this->assertNotNull($fila->sincronizado_en);
        $this->assertStringNotContainsString('27555444', json_encode($fila->toArray()));
        $this->assertStringNotContainsString('27.555.444', json_encode($fila->toArray()));

        Http::assertSent(function (Request $pedido) use ($client) {
            return $pedido->method() === 'GET' && $pedido->header('X-Admin-Api-Key')[0] === $client->api_key;
        });
    }

    public function test_un_404_es_version_vieja_y_no_toca_la_fila(): void
    {
        $client = $this->crear_cliente('viejo');
        IaDirectorio::create(['client_id' => $client->id, 'usuario_hash' => 'previo', 'extension_activa' => true]);
        $this->fakear([$this->base($client) . '/*' => Http::response('', 404)]);

        $resultado = app(IaDirectorioService::class)->sincronizar($client);

        $this->assertSame('version_vieja', $resultado['estado']);
        $this->assertSame('previo', IaDirectorio::where('client_id', $client->id)->value('usuario_hash'));
    }

    public function test_un_200_sin_el_contrato_no_escribe_nada(): void
    {
        $client = $this->crear_cliente('saturado');
        $this->fakear([$this->base($client) . '/*' => Http::response('<html>saturado</html>', 200)]);

        $this->assertSame('sin_conexion', app(IaDirectorioService::class)->sincronizar($client)['estado']);
        $this->assertNull(IaDirectorio::where('client_id', $client->id)->first());
    }

    public function test_el_comando_por_slug_y_el_barrido_solo_de_activos(): void
    {
        $activo   = $this->crear_cliente('activo');
        $inactivo = $this->crear_cliente('inactivo', null, false);
        $dueno    = Http::response(['doc_number' => '1', 'nombre' => 'A', 'negocio' => 'A', 'extension_activa' => false], 200);
        $this->fakear([
            $this->base($activo) . '/*'   => $dueno,
            $this->base($inactivo) . '/*' => $dueno,
            // Los demás clientes activos que hubiera en la base de testing: versión vieja.
            '*' => Http::response('', 404),
        ]);

        $this->artisan('ia:directorio-sincronizar', ['--client' => $activo->slug])->assertExitCode(0);
        $this->assertNotNull(IaDirectorio::where('client_id', $activo->id)->first());

        $this->artisan('ia:directorio-sincronizar')->assertExitCode(0);
        $this->assertNull(IaDirectorio::where('client_id', $inactivo->id)->first(), 'El barrido no toca clientes inactivos.');

        $this->artisan('ia:directorio-sincronizar', ['--client' => 'no-existe-' . Str::random(5)])->assertExitCode(1);
    }

    /* -------------------------------------------------------------------- Hook de la activación */

    public function test_activar_agentes_ia_encola_la_inicializacion_en_database(): void
    {
        Queue::fake();
        $client = $this->crear_cliente('activa');
        $this->fakear([$this->base($client) . '/api/admin-sync/agentes-ia' => Http::response(['ok' => true, 'activa' => true], 200)]);

        $resultado = app(ClientAgentesIaService::class)->cambiar($client, true);

        $this->assertSame('ok', $resultado['estado']);
        // Cola default (sin nombre) de la conexión database: la que levanta el worker del scheduler.
        Queue::assertPushed(InicializarAgenteIaJob::class, function (InicializarAgenteIaJob $job) use ($client) {
            return $job->client_id() === (int) $client->id && $job->connection === 'database';
        });
    }

    public function test_quitar_o_consultar_no_encola_nada(): void
    {
        Queue::fake();
        $client = $this->crear_cliente('quita');
        $this->fakear([$this->base($client) . '/api/admin-sync/agentes-ia' => Http::response(['ok' => true, 'activa' => false], 200)]);

        app(ClientAgentesIaService::class)->cambiar($client, false);
        app(ClientAgentesIaService::class)->consultar($client);

        Queue::assertNotPushed(InicializarAgenteIaJob::class);
    }

    /**
     * 🔴 Si encolar falla, la activación igual queda `ok`.
     */
    public function test_si_encolar_falla_la_activacion_no_se_rompe(): void
    {
        $client = $this->crear_cliente('colacaida');
        $this->fakear([$this->base($client) . '/api/admin-sync/agentes-ia' => Http::response(['ok' => true, 'activa' => true], 200)]);

        $this->mock(Dispatcher::class, function ($mock) {
            $mock->shouldReceive('dispatch')->andThrow(new \RuntimeException('la cola no está'));
        });

        $resultado = app(ClientAgentesIaService::class)->cambiar($client, true);

        $this->assertSame('ok', $resultado['estado']);
        $this->assertTrue($resultado['activa']);
    }

    public function test_el_job_crea_la_memoria_y_sincroniza_el_directorio(): void
    {
        $client = $this->crear_cliente('job');
        $this->fakear([
            $this->base($client) . '/api/admin-sync/agente-contexto/inicializar' => Http::response(['creada' => true, 'archivos' => 16], 200),
            $this->base($client) . '/api/admin-sync/ia-app/dueno'                => Http::response([
                'doc_number' => 'pablo', 'nombre' => 'Pablo', 'negocio' => 'Job', 'extension_activa' => true,
            ], 200),
        ]);

        app()->call([new InicializarAgenteIaJob($client->id), 'handle']);

        Http::assertSent(function (Request $pedido) {
            return $pedido->method() === 'PUT' && Str::endsWith($pedido->url(), '/api/admin-sync/agente-contexto/inicializar');
        });
        $this->assertSame(IaDirectorioService::hash_usuario('pablo'), IaDirectorio::where('client_id', $client->id)->value('usuario_hash'));
    }

    public function test_el_job_ignora_una_empresa_vieja(): void
    {
        $client = $this->crear_cliente('jobviejo');
        $this->fakear([$this->base($client) . '/*' => Http::response('', 404)]);

        app()->call([new InicializarAgenteIaJob($client->id), 'handle']);

        Http::assertSentCount(2);
        $this->assertNull(IaDirectorio::where('client_id', $client->id)->first());
    }

    public function test_el_job_sin_conexion_no_sigue_con_el_directorio(): void
    {
        $client = $this->crear_cliente('jobcaido');
        $rutas = [];
        $this->fakear([$this->base($client) . '/*' => function (Request $pedido) use (&$rutas) {
            $rutas[] = $pedido->url();
            throw new ConnectionException('cURL error 7: Failed to connect');
        }]);

        // Sin cola detrás, `release()` no hace nada: lo que importa es que no siga ni lance.
        app()->call([new InicializarAgenteIaJob($client->id), 'handle']);

        // Un solo intento (la memoria) y sin pasar al directorio: se reintenta todo junto más tarde.
        $this->assertCount(1, $rutas);
        $this->assertStringEndsWith('/agente-contexto/inicializar', $rutas[0]);
        $this->assertNull(IaDirectorio::where('client_id', $client->id)->first());
    }
}

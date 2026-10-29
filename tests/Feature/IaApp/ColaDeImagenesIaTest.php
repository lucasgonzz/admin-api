<?php

namespace Tests\Feature\IaApp;

use App\Jobs\EntregarImagenIaJob;
use App\Models\Client;
use App\Models\IaImagenPedido;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * La cola de imágenes de la app del agente (misión cruzada `app-ia-y-memoria`, 29/10/2026): el alta desde el
 * empresa-api (contrato C2.1), la PC de Lucas (contrato C3) y la entrega al empresa-api (contrato C1.4).
 *
 * Lo que se protege:
 *  1. 🔴 La reserva: 423 si la tiene otro worker, 409 si ya no está pendiente, renovación con el lease propio,
 *     resultado y fallo solo con el lease vigente, e idempotencia de los dos.
 *  2. 🔴 Un sistema no puede pedir a nombre de otro (client_uuid ≠ el de la clave → 403).
 *  3. El ciclo de intentos: un fallo vuelve a pendiente; el segundo lo deja fallido y se le avisa al cliente.
 *  4. La entrega: multipart con la imagen, `entregada` con el ok, reintento con un 5xx y corte con un 404.
 */
class ColaDeImagenesIaTest extends BaseDeLaAppIa
{
    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    /**
     * El cuerpo del alta (contrato C2.1).
     *
     * @param Client               $client
     * @param array<string, mixed> $cambios
     *
     * @return array<string, mixed>
     */
    private function alta(Client $client, array $cambios = []): array
    {
        return array_merge([
            'client_uuid'   => $client->uuid,
            'uuid'          => (string) Str::uuid(),
            'prompt'        => 'Una placa para Instagram con la promo de taladros',
            'tamano'        => 'cuadrada',
            'estilo'        => 'fotográfico',
            'ai_message_id' => 501,
        ], $cambios);
    }

    /**
     * Un pedido pendiente, ya en la base.
     *
     * @param Client $client
     *
     * @return IaImagenPedido
     */
    private function pedido(Client $client): IaImagenPedido
    {
        return IaImagenPedido::create([
            'client_id' => $client->id, 'uuid' => (string) Str::uuid(), 'ai_message_id' => 1,
            'prompt' => 'p', 'tamano' => 'vertical', 'estado' => 'pendiente',
        ]);
    }

    /**
     * @param int    $id
     * @param string $worker
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function reservar(int $id, string $worker = 'pc-lucas', ?string $token = null)
    {
        $cuerpo = ['worker_id' => $worker];
        if ($token !== null) {
            $cuerpo['lease_token'] = $token;
        }

        return $this->postJson('/api/claude/ia-imagenes/' . $id . '/reservar', $cuerpo, $this->headers_claude());
    }

    /**
     * @param int    $id
     * @param string $token
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function resultado(int $id, string $token)
    {
        return $this->post('/api/claude/ia-imagenes/' . $id . '/resultado', [
            'lease_token' => $token,
            'imagen'      => UploadedFile::fake()->image('generada.png', 64, 64),
        ], $this->headers_claude());
    }

    /* -------------------------------------------------------------------- C2.1 */

    public function test_el_alta_es_201_y_el_mismo_uuid_otra_vez_es_200(): void
    {
        $client = $this->crear_cliente('alta');
        $cuerpo = $this->alta($client);

        $this->postJson('/api/inbound/ia-imagenes', $cuerpo)
            ->assertStatus(201)->assertExactJson(['ok' => true, 'estado' => 'pendiente']);
        $this->postJson('/api/inbound/ia-imagenes', $cuerpo)
            ->assertStatus(200)->assertExactJson(['ok' => true, 'estado' => 'pendiente']);

        $this->assertSame(1, IaImagenPedido::where('uuid', $cuerpo['uuid'])->count());
        $pedido = IaImagenPedido::where('uuid', $cuerpo['uuid'])->first();
        $this->assertSame((int) $client->id, $pedido->client_id);
        $this->assertSame('cuadrada', $pedido->tamano);
        $this->assertSame(501, $pedido->ai_message_id);
    }

    public function test_con_la_clave_exigida_un_sistema_no_pide_a_nombre_de_otro(): void
    {
        config(['services.admin_inbound_integration.require_api_key' => true]);
        $propio = $this->crear_cliente('propio');
        $ajeno  = $this->crear_cliente('ajeno');

        $this->postJson('/api/inbound/ia-imagenes', $this->alta($propio))->assertStatus(401);
        $this->postJson('/api/inbound/ia-imagenes', $this->alta($propio), ['X-Admin-Api-Key' => 'cualquiera'])->assertStatus(401);
        $this->postJson('/api/inbound/ia-imagenes', $this->alta($ajeno), ['X-Admin-Api-Key' => $propio->inbound_api_key])
            ->assertStatus(403)->assertJsonPath('code', 'cliente_no_coincide');
        $this->postJson('/api/inbound/ia-imagenes', $this->alta($propio), ['X-Admin-Api-Key' => $propio->inbound_api_key])
            ->assertStatus(201);
    }

    public function test_validacion_y_tope_diario(): void
    {
        $client = $this->crear_cliente('tope');

        $this->postJson('/api/inbound/ia-imagenes', $this->alta($client, ['tamano' => 'gigante']))
            ->assertStatus(422)->assertJsonPath('code', 'validacion');
        $this->postJson('/api/inbound/ia-imagenes', $this->alta($client, ['prompt' => str_repeat('a', 4001)]))
            ->assertStatus(422);

        config(['ia_app.imagenes.tope_diario_por_cliente' => 2]);
        $this->postJson('/api/inbound/ia-imagenes', $this->alta($client))->assertStatus(201);
        $this->postJson('/api/inbound/ia-imagenes', $this->alta($client))->assertStatus(201);
        $this->postJson('/api/inbound/ia-imagenes', $this->alta($client))
            ->assertStatus(429)->assertJsonPath('code', 'tope_diario');
    }

    /* -------------------------------------------------------------------- C3 */

    public function test_claude_ia_imagenes_exige_la_clave(): void
    {
        $this->getJson('/api/claude/ia-imagenes/pendientes')->assertStatus(401);
    }

    public function test_pendientes_reservar_423_409_y_renovacion(): void
    {
        $client = $this->crear_cliente('reserva');
        $pedido = $this->pedido($client);

        $lista = $this->getJson('/api/claude/ia-imagenes/pendientes', $this->headers_claude())->assertStatus(200);
        $this->assertContains($pedido->id, array_column($lista->json('pedidos'), 'id'));
        $item = collect($lista->json('pedidos'))->firstWhere('id', $pedido->id);
        $this->assertSame($client->slug, $item['client']['slug']);
        $this->assertSame('vertical', $item['tamano']);

        $reserva = $this->reservar($pedido->id)->assertStatus(200);
        $token   = $reserva->json('lease_token');
        $this->assertSame(64, strlen($token));
        $this->assertSame($pedido->id, $reserva->json('pedido.id'));
        $this->assertSame(hash('sha256', $token), $pedido->fresh()->lease_hash, 'El lease se guarda como sha256.');
        $this->assertSame(1, $pedido->fresh()->intentos);

        // Otro worker con la reserva vigente: 423. Y ya no aparece en pendientes.
        $this->reservar($pedido->id, 'otra-pc')->assertStatus(423)->assertJsonPath('code', 'reservado');
        $this->assertNotContains($pedido->id, array_column(
            $this->getJson('/api/claude/ia-imagenes/pendientes', $this->headers_claude())->json('pedidos'), 'id'
        ));

        // Renovación: mismo worker, su lease → mismo token, más tiempo, sin gastar un intento.
        $this->travel(10)->minutes();
        $renovada = $this->reservar($pedido->id, 'pc-lucas', $token)->assertStatus(200);
        $this->assertSame($token, $renovada->json('lease_token'));
        $this->assertTrue($pedido->fresh()->lease_expires_at->greaterThan(now()->addMinutes(14)));
        $this->assertSame(1, $pedido->fresh()->intentos);

        $this->reservar(999999999)->assertStatus(404)->assertJsonPath('code', 'pedido_inexistente');

        // Ya entregado el resultado, no se reserva más.
        $this->resultado($pedido->id, $token)->assertStatus(200);
        $this->reservar($pedido->id, 'otra-pc')->assertStatus(409)->assertJsonPath('code', 'no_pendiente');
    }

    public function test_resultado_guarda_la_imagen_encola_la_entrega_y_es_idempotente(): void
    {
        Queue::fake();
        $client = $this->crear_cliente('resultado');
        $pedido = $this->pedido($client);
        $token  = $this->reservar($pedido->id)->json('lease_token');

        $this->resultado($pedido->id, 'x' . substr($token, 1))->assertStatus(423)->assertJsonPath('code', 'lease_invalido');

        $this->resultado($pedido->id, $token)->assertStatus(200)->assertJsonPath('estado', 'lista');
        $pedido->refresh();
        $this->assertSame('lista', $pedido->estado);
        $this->assertSame('ia_imagenes/' . $client->id . '/' . $pedido->uuid . '.png', $pedido->imagen_path);
        Storage::disk('local')->assertExists($pedido->imagen_path);

        $this->resultado($pedido->id, $token)->assertStatus(200)->assertJsonPath('duplicado', true)->assertJsonPath('estado', 'lista');

        Queue::assertPushed(EntregarImagenIaJob::class, 1);
    }

    public function test_resultado_con_el_lease_vencido_es_423(): void
    {
        $client = $this->crear_cliente('vencido');
        $pedido = $this->pedido($client);
        $token  = $this->reservar($pedido->id)->json('lease_token');

        $this->travel(16)->minutes();

        $this->resultado($pedido->id, $token)->assertStatus(423);

        // Con el lease vencido vuelve a pendientes y otro worker lo toma (segundo intento).
        $this->assertContains($pedido->id, array_column(
            $this->getJson('/api/claude/ia-imagenes/pendientes', $this->headers_claude())->json('pedidos'), 'id'
        ));
        $this->reservar($pedido->id, 'otra-pc')->assertStatus(200);
        $this->assertSame(2, $pedido->fresh()->intentos);
    }

    public function test_un_fallo_vuelve_a_pendiente_y_el_segundo_lo_deja_fallido_y_avisa(): void
    {
        Queue::fake();
        $client = $this->crear_cliente('fallo');
        $pedido = $this->pedido($client);

        $token = $this->reservar($pedido->id)->json('lease_token');
        $this->postJson('/api/claude/ia-imagenes/' . $pedido->id . '/fallo', ['lease_token' => $token, 'error' => 'Codex no respondió'], $this->headers_claude())
            ->assertStatus(200)->assertJsonPath('estado', 'pendiente');
        // El mismo fallo otra vez (la PC no vio la respuesta): idempotente.
        $this->postJson('/api/claude/ia-imagenes/' . $pedido->id . '/fallo', ['lease_token' => $token, 'error' => 'Codex no respondió'], $this->headers_claude())
            ->assertStatus(200)->assertJsonPath('duplicado', true);
        Queue::assertNotPushed(EntregarImagenIaJob::class);

        $token = $this->reservar($pedido->id)->json('lease_token');
        $this->postJson('/api/claude/ia-imagenes/' . $pedido->id . '/fallo', ['lease_token' => $token, 'error' => 'Política de contenido'], $this->headers_claude())
            ->assertStatus(200)->assertJsonPath('estado', 'fallida');

        $pedido->refresh();
        $this->assertSame('fallida', $pedido->estado);
        $this->assertSame('Política de contenido', $pedido->error);
        Queue::assertPushed(EntregarImagenIaJob::class, 1);

        $this->postJson('/api/claude/ia-imagenes/' . $pedido->id . '/fallo', ['lease_token' => str_repeat('z', 64)], $this->headers_claude())
            ->assertStatus(423);
    }

    public function test_los_colgados_se_vencen_y_se_avisa_al_cliente(): void
    {
        Queue::fake();
        $client = $this->crear_cliente('colgado');
        $viejo  = $this->pedido($client);
        IaImagenPedido::where('id', $viejo->id)->update(['created_at' => now()->subHours(7)]);
        $nuevo = $this->pedido($client);

        $this->artisan('ia:imagenes-vencer')->assertExitCode(0);

        $this->assertSame('fallida', $viejo->fresh()->estado);
        $this->assertSame('No se pudo generar la imagen a tiempo.', $viejo->fresh()->error);
        $this->assertSame('pendiente', $nuevo->fresh()->estado);
        Queue::assertPushed(EntregarImagenIaJob::class, function (EntregarImagenIaJob $job) use ($viejo) {
            return $job->pedido_id() === (int) $viejo->id && $job->connection === 'database';
        });
    }

    /* -------------------------------------------------------------------- C1.4 · la entrega */

    /**
     * Un pedido con la imagen ya guardada.
     *
     * @param Client $client
     *
     * @return IaImagenPedido
     */
    private function pedido_listo(Client $client): IaImagenPedido
    {
        $pedido = $this->pedido($client);
        $ruta   = 'ia_imagenes/' . $client->id . '/' . $pedido->uuid . '.png';
        Storage::disk('local')->put($ruta, 'PNG-BYTES');
        $pedido->update(['estado' => 'lista', 'resultado' => 'lista', 'imagen_path' => $ruta, 'imagen_mime' => 'image/png']);

        return $pedido->fresh();
    }

    public function test_la_entrega_manda_la_imagen_multipart_y_queda_entregada(): void
    {
        $client = $this->crear_cliente('entrega');
        $pedido = $this->pedido_listo($client);
        $url    = $this->base($client) . '/api/admin-sync/ia-imagenes/' . $pedido->uuid . '/resultado';
        $this->fakear([$url => Http::response(['ok' => true, 'estado' => 'lista'], 200)]);

        app()->call([new EntregarImagenIaJob($pedido->id), 'handle']);

        Http::assertSent(function (Request $pedido_http) use ($url, $client) {
            $estado = collect($pedido_http->data())->firstWhere('name', 'estado');

            return $pedido_http->url() === $url
                && $pedido_http->isMultipart()
                && $pedido_http->hasFile('imagen', 'PNG-BYTES')
                && $estado !== null && $estado['contents'] === 'lista'
                && $pedido_http->header('X-Admin-Api-Key')[0] === $client->api_key;
        });
        $this->assertSame('entregada', $pedido->fresh()->estado);
        $this->assertNotNull($pedido->fresh()->entregado_en);
    }

    public function test_la_entrega_de_un_fallo_va_sin_imagen(): void
    {
        $client = $this->crear_cliente('entregafallo');
        $pedido = $this->pedido($client);
        $pedido->update(['estado' => 'fallida', 'resultado' => 'fallida', 'error' => 'No se pudo.']);
        $this->fakear([$this->base($client) . '/*' => Http::response(['ok' => true, 'estado' => 'fallida'], 200)]);

        app()->call([new EntregarImagenIaJob($pedido->id), 'handle']);

        Http::assertSent(function (Request $pedido_http) {
            $datos = collect($pedido_http->data());

            return $pedido_http->isMultipart()
                && ! $pedido_http->hasFile('imagen')
                && $datos->firstWhere('name', 'estado')['contents'] === 'fallida'
                && $datos->firstWhere('name', 'error')['contents'] === 'No se pudo.';
        });
        $this->assertSame('entregada', $pedido->fresh()->estado);
    }

    public function test_un_5xx_se_reintenta_y_un_404_corta(): void
    {
        $con_5xx = $this->crear_cliente('cincoxx');
        $con_404 = $this->crear_cliente('cuatrocero');
        $a       = $this->pedido_listo($con_5xx);
        $b       = $this->pedido_listo($con_404);
        $this->fakear([
            $this->base($con_5xx) . '/*' => Http::response('error', 503),
            $this->base($con_404) . '/*' => Http::response('', 404),
        ]);

        app()->call([new EntregarImagenIaJob($a->id), 'handle']);
        app()->call([new EntregarImagenIaJob($b->id), 'handle']);

        // Sin cola detrás `release()` no hace nada: queda en lista para el próximo intento.
        $this->assertSame('lista', $a->fresh()->estado);
        $this->assertSame(1, $a->fresh()->entrega_intentos);
        $this->assertStringContainsString('503', (string) $a->fresh()->entrega_error);

        $this->assertSame('entrega_fallida', $b->fresh()->estado);
    }

    public function test_un_pedido_ya_entregado_no_se_vuelve_a_mandar(): void
    {
        $client = $this->crear_cliente('yaentregado');
        $pedido = $this->pedido_listo($client);
        $pedido->update(['estado' => 'entregada']);
        $this->fakear([]);

        app()->call([new EntregarImagenIaJob($pedido->id), 'handle']);

        Http::assertNothingSent();
    }
}

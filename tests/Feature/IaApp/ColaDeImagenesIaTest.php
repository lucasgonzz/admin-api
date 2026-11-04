<?php

namespace Tests\Feature\IaApp;

use App\Jobs\EntregarImagenIaJob;
use App\Jobs\GenerarImagenOpenAiJob;
use App\Models\AiImagePlan;
use App\Models\Client;
use App\Models\IaImagenPedido;
use App\Services\IaApp\IaImagenesService;
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

    /**
     * El header de la clave del sistema (`clients.inbound_api_key`), que C2.1 exige siempre.
     *
     * @param Client $client
     *
     * @return array<string, string>
     */
    private function clave(Client $client): array
    {
        return ['X-Admin-Api-Key' => (string) $client->inbound_api_key];
    }

    /* -------------------------------------------------------------------- C2.1 */

    public function test_openai_reserva_el_tope_mensual_y_cobra_una_vez_aunque_se_repita_el_job(): void
    {
        $client = $this->cliente_con_plan_openai('openai-cupo', '0.10');
        $cuerpo = $this->alta($client, ['requiere_openai' => true]);
        $archivo = UploadedFile::fake()->image('generada.png', 64, 64);
        $png = file_get_contents($archivo->getRealPath());
        $this->fakear(['api.openai.com/*' => Http::response([
            'data' => [['b64_json' => base64_encode($png)]],
            'usage' => ['input_tokens' => 12, 'output_tokens' => 100],
        ])]);

        $this->postJson('/api/inbound/ia-imagenes', $cuerpo, $this->clave($client))
            ->assertStatus(201)->assertJsonPath('proveedor', 'openai');
        $this->postJson('/api/inbound/ia-imagenes', $cuerpo, $this->clave($client))
            ->assertOk()->assertJsonPath('proveedor', 'openai');
        $this->postJson('/api/inbound/ia-imagenes', $this->alta($client), $this->clave($client))
            ->assertStatus(429)->assertJsonPath('code', 'tope_mensual_imagenes');

        $pedido = IaImagenPedido::where('uuid', $cuerpo['uuid'])->firstOrFail();
        $this->assertSame(65000, $pedido->costo_reservado_microusd);
        $this->reservar($pedido->id)->assertStatus(409);
        $this->getJson('/api/claude/ia-imagenes/pendientes', $this->headers_claude())
            ->assertExactJson(['pedidos' => []]);

        $job = new GenerarImagenOpenAiJob($pedido->id);
        $job->handle(app(IaImagenesService::class));
        $job->handle(app(IaImagenesService::class));
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://api.openai.com/v1/images/generations'
                && $request['quality'] === 'medium' && $request['model'] === 'gpt-image-2';
        });

        $pedido->refresh();
        $this->assertSame(65000, $pedido->costo_cobrado_microusd);
        $this->assertNull($pedido->costo_reservado_microusd);
        $this->assertSame(100, $pedido->uso_openai['output_tokens']);
        Storage::disk('local')->assertExists($pedido->imagen_path);
        Queue::assertPushed(EntregarImagenIaJob::class, 1);
    }

    public function test_openai_edita_con_referencia_y_libera_el_saldo_de_un_rechazo_definitivo(): void
    {
        $client = $this->cliente_con_plan_openai('openai-edicion', '0.30', 'high');
        $archivo = UploadedFile::fake()->image('referencia.png', 64, 64);
        $referencia = base64_encode(file_get_contents($archivo->getRealPath()));
        $cuerpo = $this->alta($client, ['referencia_base64' => $referencia, 'requiere_openai' => true]);
        $this->fakear(['api.openai.com/*' => Http::response([], 400)]);

        $this->postJson('/api/inbound/ia-imagenes', $cuerpo, $this->clave($client))
            ->assertStatus(201)->assertJsonPath('referencia_recibida', true);
        $pedido = IaImagenPedido::where('uuid', $cuerpo['uuid'])->firstOrFail();
        $this->assertSame(230000, $pedido->costo_reservado_microusd);
        $this->assertSame('high', $pedido->calidad);
        (new GenerarImagenOpenAiJob($pedido->id))->handle(app(IaImagenesService::class));
        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://api.openai.com/v1/images/edits'
                && strpos($request->body(), 'name="image[]"') !== false;
        });

        $pedido->refresh();
        $this->assertSame('fallida', $pedido->estado);
        $this->assertNull($pedido->costo_reservado_microusd);
        $this->assertNull($pedido->referencia_base64);
        $this->postJson('/api/inbound/ia-imagenes', $cuerpo, $this->clave($client))
            ->assertOk()->assertJsonPath('referencia_recibida', true);
        $this->postJson('/api/inbound/ia-imagenes', $this->alta($client), $this->clave($client))->assertStatus(201);
    }

    public function test_openai_conserva_la_reserva_de_un_resultado_incierto_y_no_repite_el_proveedor(): void
    {
        $client = $this->cliente_con_plan_openai('openai-incierto', '0.10');
        $cuerpo = $this->alta($client);
        $this->fakear(['api.openai.com/*' => Http::response([], 503)]);

        $this->postJson('/api/inbound/ia-imagenes', $cuerpo, $this->clave($client))->assertStatus(201);
        $pedido = IaImagenPedido::where('uuid', $cuerpo['uuid'])->firstOrFail();
        $job = new GenerarImagenOpenAiJob($pedido->id);
        $job->handle(app(IaImagenesService::class));
        $job->handle(app(IaImagenesService::class));

        Http::assertSentCount(1);
        $pedido->refresh();
        $this->assertSame('fallida', $pedido->estado);
        $this->assertSame(65000, $pedido->costo_reservado_microusd);
        $this->assertNull($pedido->costo_cobrado_microusd);
        $this->postJson('/api/inbound/ia-imagenes', $this->alta($client), $this->clave($client))
            ->assertStatus(429)->assertJsonPath('code', 'tope_mensual_imagenes');
    }

    public function test_la_sonda_no_crea_pedidos_y_openai_solo_admite_clientes_con_plan(): void
    {
        $client = $this->crear_cliente('openai-sin-plan');
        $consulta = ['client_uuid' => $client->uuid, 'consultar_capacidades' => true];
        $cuerpo = $this->alta($client);
        $ruta = '/api/inbound/ia-imagenes/openai';

        $this->postJson($ruta, $consulta, $this->clave($client))
            ->assertStatus(503)->assertJsonPath('code', 'imagenes_no_disponibles');
        $this->postJson($ruta, $cuerpo, $this->clave($client))
            ->assertStatus(503)->assertJsonPath('code', 'imagenes_no_disponibles');
        config(['ia_app.imagenes.openai_enabled' => true, 'ia_app.imagenes.openai_api_key' => 'simulada']);
        $this->postJson($ruta, $consulta, $this->clave($client))
            ->assertOk()->assertExactJson(['proveedor' => 'openai', 'acepta_referencia' => true]);
        $this->postJson($ruta, $cuerpo, $this->clave($client))
            ->assertStatus(422)->assertJsonPath('code', 'sin_plan_imagenes');
        $this->assertDatabaseMissing('ia_imagen_pedidos', ['client_id' => $client->id]);
    }

    public function test_el_presupuesto_de_imagenes_se_reinicia_al_empezar_el_mes_en_argentina(): void
    {
        $client = $this->cliente_con_plan_openai('openai-mes', '0.10');
        $headers = $this->clave($client);
        $this->travelTo(\Carbon\Carbon::parse('2026-11-01 02:59:00', 'UTC'));
        $primero = $this->alta($client);
        $this->postJson('/api/inbound/ia-imagenes', $primero, $headers)->assertStatus(201);
        $this->postJson('/api/inbound/ia-imagenes', $this->alta($client), $headers)->assertStatus(429);
        $this->assertDatabaseHas('ia_imagen_pedidos', ['uuid' => $primero['uuid'], 'periodo_consumo' => '2026-10']);

        $this->travelTo(\Carbon\Carbon::parse('2026-11-01 03:00:00', 'UTC'));
        $segundo = $this->alta($client);
        $this->postJson('/api/inbound/ia-imagenes', $segundo, $headers)->assertStatus(201);
        $this->assertDatabaseHas('ia_imagen_pedidos', ['uuid' => $segundo['uuid'], 'periodo_consumo' => '2026-11']);
        $this->travelBack();
    }

    public function test_el_barrido_recupera_un_pedido_pendiente_y_no_repite_un_reclamo_vencido(): void
    {
        $client = $this->cliente_con_plan_openai('openai-barrido', '0.10');
        $cuerpo = $this->alta($client);
        $this->postJson('/api/inbound/ia-imagenes', $cuerpo, $this->clave($client))->assertStatus(201);
        $pedido = IaImagenPedido::where('uuid', $cuerpo['uuid'])->firstOrFail();
        $pedido->update(['updated_at' => now()->subMinutes(20)]);
        $servicio = app(IaImagenesService::class);
        Queue::fake();

        $this->assertSame(1, $servicio->reencolar_generaciones_perdidas());
        $this->assertSame(0, $servicio->reencolar_generaciones_perdidas());
        Queue::assertPushedOn(GenerarImagenOpenAiJob::COLA, GenerarImagenOpenAiJob::class);

        $servicio->tomar_para_openai($pedido->id)->update(['lease_expires_at' => now()->subMinute()]);
        $this->assertSame(1, $servicio->vencer_colgados());
        $this->assertNull($servicio->tomar_para_openai($pedido->id));
        $this->assertSame(65000, $pedido->refresh()->costo_reservado_microusd);
    }

    private function cliente_con_plan_openai(string $slug, string $tope, string $calidad = 'medium'): Client
    {
        Queue::fake();
        config(['ia_app.imagenes.openai_enabled' => true, 'ia_app.imagenes.openai_api_key' => 'simulada']);
        $cliente = $this->crear_cliente($slug);
        $plan = AiImagePlan::create(['nombre' => $slug, 'precio_ars' => 10000, 'tope_usd_mensual' => $tope, 'activo' => true]);
        $cliente->ai_image_plan_id = $plan->id;
        $cliente->ai_image_quality = $calidad;
        $cliente->save();

        return $cliente;
    }

    public function test_el_alta_es_201_y_el_mismo_uuid_otra_vez_es_200(): void
    {
        $client = $this->crear_cliente('alta');
        $cuerpo = $this->alta($client);

        $this->postJson('/api/inbound/ia-imagenes', $cuerpo, $this->clave($client))
            ->assertStatus(201)->assertExactJson(['ok' => true, 'estado' => 'pendiente']);
        $this->postJson('/api/inbound/ia-imagenes', $cuerpo, $this->clave($client))
            ->assertStatus(200)->assertExactJson(['ok' => true, 'estado' => 'pendiente']);

        $this->assertSame(1, IaImagenPedido::where('uuid', $cuerpo['uuid'])->count());
        $pedido = IaImagenPedido::where('uuid', $cuerpo['uuid'])->first();
        $this->assertSame((int) $client->id, $pedido->client_id);
        $this->assertSame('cuadrada', $pedido->tamano);
        $this->assertSame(501, $pedido->ai_message_id);
    }

    /**
     * 🔴 Con el middleware en el modo SIN clave (resuelve por client_uuid), C2.1 exige la clave igual.
     */
    public function test_sin_la_clave_exigida_por_el_middleware_la_clave_se_exige_igual(): void
    {
        config(['services.admin_inbound_integration.require_api_key' => false]);
        $client = $this->crear_cliente('sinexigir');

        $this->postJson('/api/inbound/ia-imagenes', $this->alta($client))
            ->assertStatus(401)->assertJsonPath('code', 'clave_invalida');
        $this->postJson('/api/inbound/ia-imagenes', $this->alta($client), ['X-Admin-Api-Key' => 'otra-clave'])
            ->assertStatus(401)->assertJsonPath('code', 'clave_invalida');

        // Un cliente sin inbound_api_key cargada no entra con ninguna clave.
        $sin_clave = $this->crear_cliente('sinclave');
        $sin_clave->inbound_api_key = null;
        $sin_clave->save();
        $this->postJson('/api/inbound/ia-imagenes', $this->alta($sin_clave), ['X-Admin-Api-Key' => 'cualquiera'])
            ->assertStatus(401)->assertJsonPath('code', 'clave_invalida');
        $this->postJson('/api/inbound/ia-imagenes', $this->alta($sin_clave), ['X-Admin-Api-Key' => ''])
            ->assertStatus(401);

        $this->assertSame(0, IaImagenPedido::whereIn('client_id', [$client->id, $sin_clave->id])->count());

        $this->postJson('/api/inbound/ia-imagenes', $this->alta($client), $this->clave($client))->assertStatus(201);
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

        $this->postJson('/api/inbound/ia-imagenes', $this->alta($client, ['tamano' => 'gigante']), $this->clave($client))
            ->assertStatus(422)->assertJsonPath('code', 'validacion');
        $this->postJson('/api/inbound/ia-imagenes', $this->alta($client, ['prompt' => str_repeat('a', 4001)]), $this->clave($client))
            ->assertStatus(422);

        config(['ia_app.imagenes.tope_diario_por_cliente' => 2]);
        $this->postJson('/api/inbound/ia-imagenes', $this->alta($client), $this->clave($client))->assertStatus(201);
        $this->postJson('/api/inbound/ia-imagenes', $this->alta($client), $this->clave($client))->assertStatus(201);
        $this->postJson('/api/inbound/ia-imagenes', $this->alta($client), $this->clave($client))
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

    /* -------------------------------------------------------------------- Correcciones del revisor */

    public function test_un_archivo_que_no_es_imagen_renombrado_a_png_es_422(): void
    {
        $client = $this->crear_cliente('falsa');
        $pedido = $this->pedido($client);
        $token  = $this->reservar($pedido->id)->json('lease_token');

        $ruta = tempnam(sys_get_temp_dir(), 'falsa');
        file_put_contents($ruta, "esto no es una imagen, es texto con extension png\n");
        $falsa = new UploadedFile($ruta, 'generada.png', null, null, true);

        $this->post('/api/claude/ia-imagenes/' . $pedido->id . '/resultado', ['lease_token' => $token, 'imagen' => $falsa], $this->headers_claude())
            ->assertStatus(422)->assertJsonPath('code', 'validacion');

        $this->assertSame('reservado', $pedido->fresh()->estado);
        @unlink($ruta);
    }

    public function test_una_imagen_de_mas_de_15_mb_es_422(): void
    {
        $client = $this->crear_cliente('grande');
        $pedido = $this->pedido($client);
        $token  = $this->reservar($pedido->id)->json('lease_token');

        $grande = UploadedFile::fake()->create('generada.png', 15361, 'image/png');

        $this->post('/api/claude/ia-imagenes/' . $pedido->id . '/resultado', ['lease_token' => $token, 'imagen' => $grande], $this->headers_claude())
            ->assertStatus(422)->assertJsonPath('code', 'validacion');

        $this->assertNull($pedido->fresh()->imagen_path);
    }

    public function test_en_el_ultimo_intento_la_entrega_queda_fallida_sin_reencolar(): void
    {
        $client = $this->crear_cliente('ultimo');
        $pedido = $this->pedido_listo($client);
        $this->fakear([$this->base($client) . '/*' => Http::response('caido', 503)]);

        $cola = \Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $cola->shouldReceive('attempts')->andReturn(6);
        $cola->shouldNotReceive('release');

        $job = new EntregarImagenIaJob($pedido->id);
        $job->setJob($cola);
        app()->call([$job, 'handle']);

        $this->assertSame('entrega_fallida', $pedido->fresh()->estado);
        $this->assertStringContainsString('6 intentos', (string) $pedido->fresh()->entrega_error);
    }

    public function test_un_intento_intermedio_reencola_con_su_espera(): void
    {
        $client = $this->crear_cliente('intermedio');
        $pedido = $this->pedido_listo($client);
        $this->fakear([$this->base($client) . '/*' => Http::response('caido', 503)]);

        $cola = \Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $cola->shouldReceive('attempts')->andReturn(3);
        // Tercer intento: el próximo, a los 15 minutos.
        $cola->shouldReceive('release')->once()->with(900);

        $job = new EntregarImagenIaJob($pedido->id);
        $job->setJob($cola);
        app()->call([$job, 'handle']);

        $this->assertSame('lista', $pedido->fresh()->estado);
        $this->assertTrue($pedido->fresh()->entrega_proxima_en->greaterThan(now()->addMinutes(14)));
    }

    public function test_failed_deja_la_entrega_fallida(): void
    {
        $client = $this->crear_cliente('failed');
        $pedido = $this->pedido_listo($client);

        (new EntregarImagenIaJob($pedido->id))->failed(new \RuntimeException('se cayó el worker'));

        $this->assertSame('entrega_fallida', $pedido->fresh()->estado);
        $this->assertStringContainsString('RuntimeException', (string) $pedido->fresh()->entrega_error);

        // Uno ya entregado no se toca.
        $entregado = $this->pedido_listo($client);
        $entregado->update(['estado' => 'entregada']);
        (new EntregarImagenIaJob($entregado->id))->failed(new \RuntimeException('x'));
        $this->assertSame('entregada', $entregado->fresh()->estado);
    }

    public function test_vencer_reencola_las_entregas_perdidas_y_no_las_que_esperan_su_reintento(): void
    {
        Queue::fake();
        $client   = $this->crear_cliente('perdida');
        $perdida  = $this->pedido_listo($client);
        $espera   = $this->pedido_listo($client);
        $reciente = $this->pedido_listo($client);
        IaImagenPedido::where('id', $perdida->id)->update(['entrega_proxima_en' => now()->subMinutes(20), 'updated_at' => now()->subMinutes(20)]);
        IaImagenPedido::where('id', $espera->id)->update(['entrega_proxima_en' => now()->addMinutes(40), 'updated_at' => now()->subMinutes(20)]);
        IaImagenPedido::where('id', $reciente->id)->update(['entrega_proxima_en' => now()->subMinutes(3), 'updated_at' => now()->subMinutes(3)]);

        $this->artisan('ia:imagenes-vencer')->assertExitCode(0);

        Queue::assertPushed(EntregarImagenIaJob::class, 1);
        Queue::assertPushed(EntregarImagenIaJob::class, function (EntregarImagenIaJob $job) use ($perdida) {
            return $job->pedido_id() === (int) $perdida->id;
        });
        $this->assertTrue($perdida->fresh()->entrega_proxima_en->greaterThan(now()->subMinute()));
    }

    public function test_vencer_borra_del_disco_las_imagenes_entregadas_hace_mas_de_7_dias(): void
    {
        Queue::fake();
        $client = $this->crear_cliente('borrar');
        $vieja  = $this->pedido_listo($client);
        $nueva  = $this->pedido_listo($client);
        IaImagenPedido::where('id', $vieja->id)->update(['estado' => 'entregada', 'entregado_en' => now()->subDays(8)]);
        IaImagenPedido::where('id', $nueva->id)->update(['estado' => 'entregada', 'entregado_en' => now()->subDays(2)]);
        $ruta_vieja = $vieja->imagen_path;

        $this->artisan('ia:imagenes-vencer')->assertExitCode(0);

        Storage::disk('local')->assertMissing($ruta_vieja);
        Storage::disk('local')->assertExists($nueva->imagen_path);
        $this->assertNull($vieja->fresh()->imagen_path);
        $this->assertSame('entregada', $vieja->fresh()->estado, 'La fila queda.');
        Queue::assertNothingPushed();
    }

    public function test_una_respuesta_3xx_de_empresa_no_se_sigue_y_se_reintenta(): void
    {
        $client   = $this->crear_cliente('redirige');
        $pedido   = $this->pedido_listo($client);
        $opciones = [];
        $this->fakear([
            $this->base($client) . '/*' => function ($pedido_http, $opts) use (&$opciones) {
                $opciones[] = $opts;

                return Http::response('', 302, ['Location' => 'https://otro-host.test/robar']);
            },
            'https://otro-host.test/*' => Http::response(['ok' => true], 200),
        ]);

        app()->call([new EntregarImagenIaJob($pedido->id), 'handle']);

        $this->assertCount(1, $opciones);
        $this->assertFalse($opciones[0]['allow_redirects'], 'La entrega no sigue redirecciones (la clave del cliente viajaría).');
        Http::assertNotSent(function (Request $pedido_http) {
            return Str::contains($pedido_http->url(), 'otro-host.test');
        });
        $this->assertSame('lista', $pedido->fresh()->estado);
        $this->assertStringContainsString('302', (string) $pedido->fresh()->entrega_error);
    }
}

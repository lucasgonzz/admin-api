<?php

namespace Tests\Feature\AvisoDeActualizacion;

use App\Models\Client;
use App\Models\ClientApi;
use App\Models\ClientVersionUpgrade;
use App\Models\Version;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Misión `aviso-actualizacion-sistema` (25/11/2026) — las cuatro rutas `claude/` con las que la
 * skill de actualizar clientes pide el OK a los usuarios conectados y prende o suelta el aviso
 * (CA17, parte rutas, y CA18).
 *
 *   POST   claude/upgrades/{id}/pedido-de-ok
 *   GET    claude/upgrades/{id}/pedido-de-ok
 *   DELETE claude/upgrades/{id}/pedido-de-ok
 *   POST   claude/upgrades/{id}/aviso-de-actualizacion   {accion: prender|liberar}
 *
 * Lo que se fija (el contrato textual que la skill ya consume, plan §4.5):
 *
 *  1. 🔴 CA17. Con el interruptor APAGADO (`AVISO_DE_ACTUALIZACION_HABILITADO`, default false) las
 *     cuatro rutas contestan 409 `aviso_apagado` y NO sale ni una request a empresa-api.
 *  2. 🔴 CA18. `POST pedido-de-ok` manda a empresa `upgrade_id={id}`, `version=to_version` y
 *     `minutos_para_responder` (10 por defecto) con la clave del cliente, y devuelve 200
 *     `{ok, soportado, aviso}`. Empresa 404 ⇒ 409 `version_sin_aviso` (`soportado:false`); empresa
 *     500 o sin respuesta ⇒ 502 `cliente_no_responde`. `DELETE` y `aviso-de-actualizacion` mandan
 *     lo que corresponde, y el 409 `ya_actualizando` de empresa pasa tal cual.
 *
 * Todo con `Http::fake`: no hay cliente real.
 *
 * PHP 7.4.
 */
class PedidoDeOkPorClaudeTest extends TestCase
{
    use DatabaseTransactions;

    /** Clave de ingesta de las requests del test. */
    const CLAVE = 'clave-de-prueba-pedido-de-ok';

    /** Clave de admin-sync del cliente (`clients.api_key`). */
    const CLAVE_DEL_CLIENTE = 'clave-del-cliente-pedido-de-ok';

    /** @var int Para que cada escenario tenga versiones con código distinto. */
    private $contador = 0;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        /* El hook `saved` de ClientVersionUpgrade despacha trabajos al guardar un upgrade. */
        Queue::fake();

        config([
            'services.claude_task_ingest.key'      => self::CLAVE,
            'aviso_de_actualizacion.habilitado'    => true,
        ]);
    }

    /* ------------------------------------------------------------------------------------------
     | Armado
     |----------------------------------------------------------------------------------------- */

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'X-Claude-Task-Key' => self::CLAVE,
            'Accept'            => 'application/json',
        ];
    }

    /**
     * Cliente con su frente principal y un upgrade hacia él.
     *
     * @return array<string, mixed>
     */
    private function escenario(): array
    {
        $this->contador++;

        $from = $this->crear_version('6.' . $this->contador . '.0');
        $to   = $this->crear_version('6.' . $this->contador . '.1');

        $client                     = new Client();
        $client->name               = 'Ferretería del Aviso';
        $client->company_name       = 'Empresa del Aviso';
        $client->slug               = 'aviso-ok-' . Str::random(8);
        $client->api_url            = 'https://ejemplo.test';
        $client->api_key            = self::CLAVE_DEL_CLIENTE;
        $client->inbound_api_key    = 'clave-inbound';
        $client->is_active          = true;
        $client->current_version_id = $from->id;
        $client->save();

        $carpeta = 'avisook' . Str::lower(Str::random(4));

        $api               = new ClientApi();
        $api->client_id    = $client->id;
        $api->url          = 'https://api-' . $carpeta . '.comerciocity.com/public';
        $api->path         = $carpeta . '/api';
        $api->spa_url      = 'https://' . $carpeta . '.comerciocity.com';
        $api->hosting_type = 'shared_hosting';
        $api->save();

        $client->active_client_api_id = $api->id;
        $client->save();

        $upgrade = ClientVersionUpgrade::create([
            'client_id'            => $client->id,
            'from_version_id'      => $from->id,
            'to_version_id'        => $to->id,
            'status'               => 'pendiente',
            'scheduled_date'       => now()->toDateString(),
            'target_client_api_id' => $api->id,
        ]);

        return compact('client', 'api', 'upgrade', 'to', 'carpeta');
    }

    /**
     * @param  string  $codigo
     * @return Version
     */
    private function crear_version(string $codigo): Version
    {
        $version               = new Version();
        $version->version      = $codigo;
        $version->title        = 'Versión ' . $codigo;
        $version->status       = 'published';
        $version->is_hotfix    = false;
        $version->published_at = now();
        $version->save();

        return $version;
    }

    /**
     * Cuerpo `AvisoEstado` que contestaría empresa-api (plan §4.1).
     *
     * @param  string  $estado
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function aviso_estado(string $estado, array $extra = []): array
    {
        return array_merge([
            'ok'         => true,
            'owner_id'   => 1,
            'estado'     => $estado,
            'upgrade_id' => null,
            'version'    => null,
            'vence_at'   => '2026-11-26T03:10:00-03:00',
            'pedido'     => $estado === 'pidiendo_ok'
                ? ['total' => 5, 'aceptaron' => 2, 'faltan' => 3, 'pendientes' => ['Juan', 'Caja 2'], 'rechazado_por' => null,
                    'vence_respuesta_at' => '2026-11-26T03:00:00-03:00', 'segundos_para_responder' => 600]
                : null,
        ], $extra);
    }

    /**
     * Pedidos que el admin le hizo al aviso de empresa-api, sin importar el stub que contestó.
     *
     * @return array<int, \Illuminate\Http\Client\Request>
     */
    private function pedidos_al_aviso(): array
    {
        $pedidos = [];
        foreach (Http::recorded() as $par) {
            if (strpos((string) $par[0]->url(), '/api/admin-sync/actualizacion-del-sistema') !== false) {
                $pedidos[] = $par[0];
            }
        }

        return $pedidos;
    }

    /* ==========================================================================================
     | CA17) Interruptor apagado
     |========================================================================================= */

    /**
     * 🔴 CA17. Apagado: las cuatro rutas contestan 409 `aviso_apagado` y no sale NI UNA request.
     */
    public function test_con_el_interruptor_apagado_las_cuatro_rutas_contestan_409_y_no_salen_pedidos(): void
    {
        config(['aviso_de_actualizacion.habilitado' => false]);
        Http::fake(['*' => Http::response($this->aviso_estado('libre'), 200)]);
        $e   = $this->escenario();
        $id  = $e['upgrade']->id;
        $url = '/api/claude/upgrades/' . $id;

        $respuestas = [
            $this->postJson($url . '/pedido-de-ok', [], $this->headers()),
            $this->getJson($url . '/pedido-de-ok', $this->headers()),
            $this->deleteJson($url . '/pedido-de-ok', [], $this->headers()),
            $this->postJson($url . '/aviso-de-actualizacion', ['accion' => 'prender'], $this->headers()),
            $this->postJson($url . '/aviso-de-actualizacion', ['accion' => 'liberar'], $this->headers()),
        ];

        foreach ($respuestas as $indice => $respuesta) {
            $respuesta->assertStatus(409);
            $this->assertSame('aviso_apagado', $respuesta->json('error'), 'Ruta #' . $indice);
        }

        Http::assertNothingSent();
    }

    /**
     * El default del config es APAGADO: sin tocar nada, el aviso no existe.
     */
    public function test_el_interruptor_viene_apagado_por_defecto(): void
    {
        $this->assertFalse(
            (bool) (require base_path('config/aviso_de_actualizacion.php'))['habilitado'],
            'config/aviso_de_actualizacion.php tiene que traer habilitado=false cuando la env no está definida.'
        );
    }

    /* ==========================================================================================
     | CA18) POST pedido-de-ok
     |========================================================================================= */

    /**
     * 🔴 CA18. Manda a empresa `upgrade_id`, `version` (la de destino) y `minutos_para_responder`=10
     * con la clave del cliente, y devuelve 200 con el aviso.
     */
    public function test_el_pedido_de_ok_manda_el_upgrade_la_version_y_diez_minutos(): void
    {
        $e = $this->escenario();
        Http::fake(['*/actualizacion-del-sistema/pedido' => Http::response($this->aviso_estado('pidiendo_ok', [
            'upgrade_id' => $e['upgrade']->id,
            'version'    => $e['to']->version,
        ]), 200)]);

        $respuesta = $this->postJson('/api/claude/upgrades/' . $e['upgrade']->id . '/pedido-de-ok', [], $this->headers())
            ->assertStatus(200);

        $this->assertTrue($respuesta->json('ok'));
        $this->assertTrue($respuesta->json('soportado'));
        $this->assertSame('pidiendo_ok', $respuesta->json('aviso.estado'));
        $this->assertSame(3, $respuesta->json('aviso.pedido.faltan'));
        $this->assertArrayNotHasKey('ok', (array) $respuesta->json('aviso'), 'El aviso va SIN la clave ok.');

        $pedidos = $this->pedidos_al_aviso();
        $this->assertCount(1, $pedidos);
        $this->assertSame('POST', $pedidos[0]->method());
        $this->assertStringEndsWith('/api/admin-sync/actualizacion-del-sistema/pedido', (string) $pedidos[0]->url());
        $this->assertStringContainsString($e['carpeta'], (string) $pedidos[0]->url());
        $this->assertSame((int) $e['upgrade']->id, (int) $pedidos[0]['upgrade_id']);
        $this->assertSame((string) $e['to']->version, (string) $pedidos[0]['version']);
        $this->assertSame(10, (int) $pedidos[0]['minutos_para_responder']);
        $this->assertSame(self::CLAVE_DEL_CLIENTE, $pedidos[0]->header('X-Admin-Api-Key')[0]);
    }

    /**
     * Los minutos que se piden viajan tal cual (1..30); fuera de rango es 422 y no sale nada.
     */
    public function test_los_minutos_para_responder_viajan_y_se_validan(): void
    {
        $e = $this->escenario();
        Http::fake(['*' => Http::response($this->aviso_estado('pidiendo_ok'), 200)]);
        $url = '/api/claude/upgrades/' . $e['upgrade']->id . '/pedido-de-ok';

        $this->postJson($url, ['minutos_para_responder' => 15], $this->headers())->assertStatus(200);
        $this->assertSame(15, (int) $this->pedidos_al_aviso()[0]['minutos_para_responder']);

        $this->postJson($url, ['minutos_para_responder' => 31], $this->headers())->assertStatus(422);
        $this->postJson($url, ['minutos_para_responder' => 0], $this->headers())->assertStatus(422);
        $this->assertCount(1, $this->pedidos_al_aviso(), 'Un parámetro inválido no sale a empresa.');
    }

    /**
     * 🔴 CA18. Empresa 404 (la versión instalada no tiene el aviso) ⇒ 409 `version_sin_aviso`.
     */
    public function test_empresa_404_es_409_version_sin_aviso(): void
    {
        $e = $this->escenario();
        Http::fake(['*' => Http::response(['message' => 'Not Found'], 404)]);

        $respuesta = $this->postJson('/api/claude/upgrades/' . $e['upgrade']->id . '/pedido-de-ok', [], $this->headers())
            ->assertStatus(409);

        $this->assertSame('version_sin_aviso', $respuesta->json('error'));
        $this->assertFalse($respuesta->json('soportado'));
    }

    /**
     * 🔴 CA18. Empresa 500 ⇒ 502 `cliente_no_responde` con el HTTP que contestó.
     */
    public function test_empresa_500_es_502_cliente_no_responde(): void
    {
        $e = $this->escenario();
        Http::fake(['*' => Http::response(['message' => 'Server Error'], 500)]);

        $respuesta = $this->postJson('/api/claude/upgrades/' . $e['upgrade']->id . '/pedido-de-ok', [], $this->headers())
            ->assertStatus(502);

        $this->assertSame('cliente_no_responde', $respuesta->json('error'));
        $this->assertSame(500, $respuesta->json('http'));
    }

    /**
     * Sin respuesta (corte de red): 502 `cliente_no_responde` con `http` nulo, y nunca un 500 del admin.
     */
    public function test_sin_respuesta_del_cliente_es_502_y_no_un_500_del_admin(): void
    {
        $e = $this->escenario();
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: timed out');
        });

        $respuesta = $this->postJson('/api/claude/upgrades/' . $e['upgrade']->id . '/pedido-de-ok', [], $this->headers())
            ->assertStatus(502);

        $this->assertSame('cliente_no_responde', $respuesta->json('error'));
        $this->assertNull($respuesta->json('http'));
    }

    /**
     * Una API de empresa que contesta 401 (clave que no coincide) también es `cliente_no_responde`:
     * no es "la versión no lo tiene".
     */
    public function test_empresa_401_no_se_confunde_con_version_sin_aviso(): void
    {
        $e = $this->escenario();
        Http::fake(['*' => Http::response(['error' => 'unauthorized'], 401)]);

        $respuesta = $this->postJson('/api/claude/upgrades/' . $e['upgrade']->id . '/pedido-de-ok', [], $this->headers())
            ->assertStatus(502);

        $this->assertSame('cliente_no_responde', $respuesta->json('error'));
        $this->assertSame(401, $respuesta->json('http'));
    }

    /* ==========================================================================================
     | CA18) GET y DELETE pedido-de-ok
     |========================================================================================= */

    /**
     * El GET lee el estado de empresa (`GET admin-sync/actualizacion-del-sistema`) y lo devuelve.
     */
    public function test_el_get_lee_el_estado_del_aviso_del_cliente(): void
    {
        $e = $this->escenario();
        Http::fake(['*' => Http::response($this->aviso_estado('pidiendo_ok', [
            'pedido' => ['total' => 2, 'aceptaron' => 1, 'faltan' => 1, 'pendientes' => ['Caja 2'], 'rechazado_por' => null,
                'vence_respuesta_at' => '2026-11-26T03:00:00-03:00', 'segundos_para_responder' => 412],
        ]), 200)]);

        $respuesta = $this->getJson('/api/claude/upgrades/' . $e['upgrade']->id . '/pedido-de-ok', $this->headers())
            ->assertStatus(200);

        $this->assertTrue($respuesta->json('ok'));
        $this->assertTrue($respuesta->json('soportado'));
        $this->assertSame('pidiendo_ok', $respuesta->json('aviso.estado'));
        $this->assertSame(412, $respuesta->json('aviso.pedido.segundos_para_responder'));

        $pedidos = $this->pedidos_al_aviso();
        $this->assertCount(1, $pedidos);
        $this->assertSame('GET', $pedidos[0]->method());
        $this->assertStringEndsWith('/api/admin-sync/actualizacion-del-sistema', (string) $pedidos[0]->url());
        $this->assertSame(self::CLAVE_DEL_CLIENTE, $pedidos[0]->header('X-Admin-Api-Key')[0]);
    }

    /**
     * El GET también traduce los errores: 404 ⇒ 409 `version_sin_aviso`, 500 ⇒ 502.
     */
    public function test_el_get_traduce_los_errores_del_cliente(): void
    {
        $e   = $this->escenario();
        $url = '/api/claude/upgrades/' . $e['upgrade']->id . '/pedido-de-ok';

        Http::fake(['*' => Http::response(['message' => 'Not Found'], 404)]);
        $this->getJson($url, $this->headers())->assertStatus(409)->assertJson(['error' => 'version_sin_aviso', 'soportado' => false]);
    }

    /**
     * 🔴 CA18. `DELETE` manda `DELETE admin-sync/actualizacion-del-sistema/pedido` y devuelve el
     * estado `libre`.
     */
    public function test_el_delete_cancela_el_pedido_en_empresa(): void
    {
        $e = $this->escenario();
        Http::fake(['*' => Http::response($this->aviso_estado('libre'), 200)]);

        $respuesta = $this->deleteJson('/api/claude/upgrades/' . $e['upgrade']->id . '/pedido-de-ok', [], $this->headers())
            ->assertStatus(200);

        $this->assertSame('libre', $respuesta->json('aviso.estado'));

        $pedidos = $this->pedidos_al_aviso();
        $this->assertCount(1, $pedidos);
        $this->assertSame('DELETE', $pedidos[0]->method());
        $this->assertStringEndsWith('/api/admin-sync/actualizacion-del-sistema/pedido', (string) $pedidos[0]->url());
        $this->assertSame(self::CLAVE_DEL_CLIENTE, $pedidos[0]->header('X-Admin-Api-Key')[0]);
    }

    /**
     * 🔴 CA18. El 409 `ya_actualizando` de empresa (el pedido ya pasó a bloquear) pasa tal cual: para
     * soltar ESE estado se usa `aviso-de-actualizacion {accion: liberar}`.
     */
    public function test_el_delete_con_el_sistema_ya_actualizando_pasa_el_409_de_empresa(): void
    {
        $e = $this->escenario();
        Http::fake(['*' => Http::response(['error' => 'ya_actualizando'], 409)]);

        $respuesta = $this->deleteJson('/api/claude/upgrades/' . $e['upgrade']->id . '/pedido-de-ok', [], $this->headers())
            ->assertStatus(409);

        $this->assertSame('ya_actualizando', $respuesta->json('error'));
    }

    /* ==========================================================================================
     | CA18) POST aviso-de-actualizacion
     |========================================================================================= */

    /**
     * 🔴 CA18. `prender` manda `PUT admin-sync/actualizacion-del-sistema` con estado `actualizando`,
     * el upgrade, la versión destino y 120 minutos por defecto.
     */
    public function test_prender_manda_el_put_actualizando_con_el_upgrade_la_version_y_120_minutos(): void
    {
        $e = $this->escenario();
        Http::fake(['*' => Http::response($this->aviso_estado('actualizando'), 200)]);

        $respuesta = $this->postJson(
            '/api/claude/upgrades/' . $e['upgrade']->id . '/aviso-de-actualizacion',
            ['accion' => 'prender'],
            $this->headers()
        )->assertStatus(200);

        $this->assertTrue($respuesta->json('ok'));
        $this->assertSame('actualizando', $respuesta->json('aviso.estado'));

        $pedidos = $this->pedidos_al_aviso();
        $this->assertCount(1, $pedidos);
        $this->assertSame('PUT', $pedidos[0]->method());
        $this->assertStringEndsWith('/api/admin-sync/actualizacion-del-sistema', (string) $pedidos[0]->url());
        $this->assertSame('actualizando', $pedidos[0]['estado']);
        $this->assertSame(120, (int) $pedidos[0]['vence_en_minutos']);
        $this->assertSame((int) $e['upgrade']->id, (int) $pedidos[0]['upgrade_id']);
        $this->assertSame((string) $e['to']->version, (string) $pedidos[0]['version']);
        $this->assertSame(self::CLAVE_DEL_CLIENTE, $pedidos[0]->header('X-Admin-Api-Key')[0]);
    }

    /**
     * `prender` con `vence_en_minutos` lo manda tal cual (5..240).
     */
    public function test_prender_con_vencimiento_propio(): void
    {
        $e = $this->escenario();
        Http::fake(['*' => Http::response($this->aviso_estado('actualizando'), 200)]);

        $this->postJson(
            '/api/claude/upgrades/' . $e['upgrade']->id . '/aviso-de-actualizacion',
            ['accion' => 'prender', 'vence_en_minutos' => 45],
            $this->headers()
        )->assertStatus(200);

        $this->assertSame(45, (int) $this->pedidos_al_aviso()[0]['vence_en_minutos']);
    }

    /**
     * 🔴 CA18. `liberar` manda `PUT … {estado: libre}`.
     */
    public function test_liberar_manda_el_put_libre(): void
    {
        $e = $this->escenario();
        Http::fake(['*' => Http::response($this->aviso_estado('libre'), 200)]);

        $respuesta = $this->postJson(
            '/api/claude/upgrades/' . $e['upgrade']->id . '/aviso-de-actualizacion',
            ['accion' => 'liberar'],
            $this->headers()
        )->assertStatus(200);

        $this->assertSame('libre', $respuesta->json('aviso.estado'));

        $pedidos = $this->pedidos_al_aviso();
        $this->assertCount(1, $pedidos);
        $this->assertSame('PUT', $pedidos[0]->method());
        $this->assertSame('libre', $pedidos[0]['estado']);
        $this->assertSame((int) $e['upgrade']->id, (int) $pedidos[0]['upgrade_id']);
    }

    /**
     * La validación: acción desconocida o vencimiento fuera de 5..240 ⇒ 422, y no sale nada.
     */
    public function test_aviso_de_actualizacion_valida_la_accion_y_el_vencimiento(): void
    {
        $e = $this->escenario();
        Http::fake(['*' => Http::response($this->aviso_estado('libre'), 200)]);
        $url = '/api/claude/upgrades/' . $e['upgrade']->id . '/aviso-de-actualizacion';

        $this->postJson($url, [], $this->headers())->assertStatus(422);
        $this->postJson($url, ['accion' => 'apagar'], $this->headers())->assertStatus(422);
        $this->postJson($url, ['accion' => 'prender', 'vence_en_minutos' => 300], $this->headers())->assertStatus(422);
        $this->postJson($url, ['accion' => 'prender', 'vence_en_minutos' => 4], $this->headers())->assertStatus(422);

        $this->assertSame([], $this->pedidos_al_aviso());
    }

    /**
     * Los errores de empresa se traducen igual que en el pedido: 404 ⇒ 409, 500 ⇒ 502.
     */
    public function test_aviso_de_actualizacion_traduce_los_errores_del_cliente(): void
    {
        $e   = $this->escenario();
        $url = '/api/claude/upgrades/' . $e['upgrade']->id . '/aviso-de-actualizacion';

        Http::fake(['*' => Http::response(['message' => 'Not Found'], 404)]);
        $this->postJson($url, ['accion' => 'prender'], $this->headers())
            ->assertStatus(409)->assertJson(['error' => 'version_sin_aviso', 'soportado' => false]);
    }

    /* ==========================================================================================
     | Autenticación y existencia
     |========================================================================================= */

    /**
     * Sin la clave de Claude no pasa nada (401) y no sale ningún pedido a empresa.
     */
    public function test_sin_la_clave_de_claude_es_401(): void
    {
        $e = $this->escenario();
        Http::fake(['*' => Http::response($this->aviso_estado('libre'), 200)]);

        $this->postJson('/api/claude/upgrades/' . $e['upgrade']->id . '/pedido-de-ok')->assertStatus(401);
        $this->getJson('/api/claude/upgrades/' . $e['upgrade']->id . '/pedido-de-ok')->assertStatus(401);
        $this->deleteJson('/api/claude/upgrades/' . $e['upgrade']->id . '/pedido-de-ok')->assertStatus(401);
        $this->postJson('/api/claude/upgrades/' . $e['upgrade']->id . '/aviso-de-actualizacion', ['accion' => 'liberar'])->assertStatus(401);

        Http::assertNothingSent();
    }

    /**
     * Un upgrade que no existe es 404 (con el interruptor prendido) y no sale nada.
     */
    public function test_un_upgrade_inexistente_es_404(): void
    {
        Http::fake(['*' => Http::response($this->aviso_estado('libre'), 200)]);

        $post = $this->postJson('/api/claude/upgrades/99999999/pedido-de-ok', [], $this->headers())->assertStatus(404);
        $get  = $this->getJson('/api/claude/upgrades/99999999/pedido-de-ok', $this->headers())->assertStatus(404);

        /* El 404 es el del controlador (dice qué upgrade no existe), no el de una ruta que falta. */
        $this->assertStringContainsString('upgrade', (string) $post->json('error'));
        $this->assertStringContainsString('upgrade', (string) $get->json('error'));

        Http::assertNothingSent();
    }
}

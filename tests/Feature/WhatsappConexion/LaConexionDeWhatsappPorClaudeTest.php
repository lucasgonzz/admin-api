<?php

namespace Tests\Feature\WhatsappConexion;

use App\Models\Client;
use App\Models\WhatsappConfig;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\CatalogoDelClientePorClaude\BaseDelCatalogoPorClaude;
use Tests\Fakes\HttpFactorySinSalida;

/**
 * `GET|PUT claude/clients/{id}/whatsapp-conexion` (misión `kapso-secretos-fuera-del-navegador`, 17/11/2026, contrato B).
 *
 * 🔴 Lo que se vigila en todo el archivo: ni la `clients.api_key`, ni la clave de Kapso del proyecto, ni el `webhook_secret` salen en
 * una respuesta ni en una línea de log; y la clave de Kapso solo viaja hacia el cliente, tomada de `whatsapp_config`, nunca desde Claude.
 *
 * Todo con `Http::fake()` sobre una fábrica que no deja salir ningún pedido real.
 */
class LaConexionDeWhatsappPorClaudeTest extends BaseDelCatalogoPorClaude
{
    const NOMBRE = 'Ferretotal de Prueba';

    /** @var string */
    protected $clave;

    /** @var string */
    protected $clave_kapso;

    /** @var string */
    protected $secreto;

    /** @var Client */
    protected $cliente;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->clave       = 'sk-cliente-' . Str::random(30);
        $this->clave_kapso = 'kapso-proyecto-' . Str::random(30);
        $this->secreto     = 'whsec-' . Str::random(30);
        $this->cliente     = $this->crear_cliente(self::NOMBRE, $this->clave);

        $this->crear_frente($this->cliente, 'ferretest', 'shared_hosting', null, true);

        WhatsappConfig::query()->delete();
        $this->crear_config_de_kapso('principal', $this->clave_kapso);
    }

    /**
     * @param string $proposito Propósito de la fila.
     * @param string $clave     Clave de Kapso.
     * @param bool   $activa    Si la fila está activa.
     *
     * @return WhatsappConfig
     */
    protected function crear_config_de_kapso(string $proposito, string $clave, bool $activa = true): WhatsappConfig
    {
        return WhatsappConfig::create([
            'proposito'       => $proposito,
            'kapso_api_key'   => $clave,
            'phone_number_id' => 'pn-' . Str::random(6),
            'webhook_secret'  => 'hook-' . Str::random(8),
            'is_active'       => $activa,
        ]);
    }

    /**
     * @param array<string, mixed> $stubs Lo que se le pasa a `Http::fake()`.
     *
     * @return void
     */
    protected function http_falso(array $stubs): void
    {
        Http::swap(new HttpFactorySinSalida());
        Http::fake($stubs);
    }

    /**
     * @param int                  $status Status.
     * @param array<string, mixed> $cuerpo Cuerpo.
     *
     * @return void
     */
    protected function el_cliente_contesta(int $status, array $cuerpo): void
    {
        $this->http_falso(['*' => Http::response($cuerpo, $status)]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function conexion_valida(): array
    {
        return [
            'ok'       => true,
            'conexion' => [
                'existe'                     => true,
                'phone_number_id'            => '123456789',
                'kapso_api_key_configurada'  => true,
                'webhook_secret_configurado' => true,
                'conexion_configurada'       => true,
                'is_active'                  => false,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $extra Campos a sumar o pisar.
     *
     * @return array<string, mixed>
     */
    protected function pedido_de_verdad(array $extra = []): array
    {
        return array_merge([
            'phone_number_id'     => '123456789',
            'webhook_secret'      => $this->secreto,
            'dry_run'             => false,
            'confirm_client_name' => self::NOMBRE,
        ], $extra);
    }

    /**
     * @param array<string, mixed> $pedido  Cuerpo.
     * @param Client|null          $cliente Cliente.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    protected function provisionar(array $pedido, Client $cliente = null)
    {
        $cliente = $cliente === null ? $this->cliente : $cliente;

        return $this->putJson('/api/claude/clients/' . $cliente->id . '/whatsapp-conexion', $pedido, $this->headers());
    }

    /**
     * 🔴 Ninguno de los tres secretos aparece en la respuesta ni en el log.
     *
     * @param \Illuminate\Testing\TestResponse $respuesta Respuesta.
     * @param \Psr\Log\AbstractLogger|null     $registro  Registro capturado.
     *
     * @return void
     */
    protected function assertSinSecretos($respuesta, $registro = null): void
    {
        foreach ([$this->clave, $this->clave_kapso, $this->secreto] as $secreto) {
            $this->assertSinLaClave($respuesta, $secreto);

            if ($registro !== null) {
                $this->assertLogSinLaClave($registro, $secreto);
            }
        }
    }

    /* ------------------------------------------------------------------------------------------
     | Secretos
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 El éxito del PUT y del GET no devuelve ni loguea secretos, ni recorriendo la respuesta ni el log; aunque el cliente repita los suyos.
     *
     * @return void
     */
    public function test_el_exito_no_devuelve_ni_loguea_secretos(): void
    {
        $registro = $this->capturar_el_log();
        $cuerpo   = $this->conexion_valida();

        $cuerpo['conexion']['kapso_api_key']  = $this->clave_kapso;
        $cuerpo['conexion']['webhook_secret'] = $this->secreto;
        $cuerpo['eco']                        = $this->clave;

        $this->el_cliente_contesta(200, $cuerpo);

        $put = $this->provisionar($this->pedido_de_verdad());

        $put->assertStatus(200)->assertJson(['estado' => 'ok']);
        $this->assertSinSecretos($put, $registro);
        $this->assertArrayNotHasKey('kapso_api_key', $put->json('conexion'));
        $this->assertArrayNotHasKey('webhook_secret', $put->json('conexion'));
        $this->assertArrayNotHasKey('eco', $put->json());
        $this->assertSame('123456789', $put->json('conexion.phone_number_id'));

        $get = $this->getJson('/api/claude/clients/' . $this->cliente->id . '/whatsapp-conexion', $this->headers());

        $get->assertStatus(200)->assertJson(['estado' => 'ok']);
        $this->assertSinSecretos($get, $registro);
        $this->assertNotEmpty($registro->lineas);
    }

    /**
     * 🔴 Un error del cliente que repite los secretos en su mensaje sale con los secretos tapados, y el log también.
     *
     * @return void
     */
    public function test_el_mensaje_de_error_del_cliente_sale_sin_secretos(): void
    {
        $registro = $this->capturar_el_log();

        $this->el_cliente_contesta(422, ['message' => 'Falta algo con ' . $this->clave_kapso . ' y ' . $this->secreto . ' y ' . $this->clave]);

        $respuesta = $this->provisionar($this->pedido_de_verdad());

        $respuesta->assertStatus(200)->assertJson(['estado' => 'rechazada']);
        $this->assertStringContainsString('[clave oculta]', $respuesta->json('mensaje'));
        $this->assertSinSecretos($respuesta, $registro);
    }

    /**
     * 🔴 Una excepción de red cuyo mensaje trae secretos no los deja salir ni en la respuesta ni en el log.
     *
     * @return void
     */
    public function test_un_timeout_con_secretos_en_el_mensaje_es_sin_conexion_y_no_los_filtra(): void
    {
        $registro = $this->capturar_el_log();
        $clave    = $this->clave;
        $kapso    = $this->clave_kapso;

        $this->http_falso(['*' => function () use ($clave, $kapso) {
            throw new ConnectionException('cURL error 28: timeout con ' . $clave . ' y ' . $kapso);
        }]);

        $respuesta = $this->provisionar($this->pedido_de_verdad());

        $respuesta->assertStatus(200)->assertJson(['estado' => 'sin_conexion']);
        $this->assertSinSecretos($respuesta, $registro);
        $this->assertNotEmpty($registro->lineas);
    }

    /**
     * 🔴 Un `kapso_api_key` en el body es 422, no llama a nadie ni repite el valor.
     *
     * @return void
     */
    public function test_rechaza_la_clave_de_kapso_en_el_body(): void
    {
        $registro = $this->capturar_el_log();

        $this->el_cliente_contesta(200, $this->conexion_valida());

        $respuesta = $this->provisionar($this->pedido_de_verdad(['kapso_api_key' => 'kapso-de-afuera-12345']));

        $respuesta->assertStatus(422)->assertJson(['error' => 'validacion']);
        $this->assertArrayHasKey('kapso_api_key', $respuesta->json('detalle'));
        $this->assertStringNotContainsString('kapso-de-afuera-12345', (string) $respuesta->getContent());
        $this->assertSinSecretos($respuesta, $registro);
        Http::assertNothingSent();
    }

    /* ------------------------------------------------------------------------------------------
     | Pedido
     |----------------------------------------------------------------------------------------- */

    /**
     * El PUT saliente lleva la clave de Kapso del proyecto (tomada de `whatsapp_config`) y el `X-Admin-Api-Key` del cliente, y ninguna
     * de las dos sale hacia Claude.
     *
     * @return void
     */
    public function test_el_put_saliente_lleva_la_clave_de_kapso_y_la_del_cliente_sin_que_salgan_hacia_claude(): void
    {
        $this->el_cliente_contesta(200, $this->conexion_valida());

        $respuesta = $this->provisionar($this->pedido_de_verdad(['is_active' => true]));

        $respuesta->assertStatus(200)->assertJson(['estado' => 'ok']);
        $this->assertSinSecretos($respuesta);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            $cuerpo = json_decode($request->body(), true);

            return $request->method() === 'PUT'
                && $request->url() === 'https://api-ferretest.ejemplo.test/public/api/admin-sync/whatsapp-bot/conexion'
                && $request->hasHeader('X-Admin-Api-Key', $this->clave)
                && $cuerpo['kapso_api_key'] === $this->clave_kapso
                && $cuerpo['webhook_secret'] === $this->secreto
                && $cuerpo['phone_number_id'] === '123456789'
                && $cuerpo['is_active'] === true;
        });
    }

    /**
     * `dry_run` (el default) no hace ninguna llamada saliente y muestra cliente, frente y qué cambiaría, sin repetir el secreto.
     *
     * @return void
     */
    public function test_dry_run_no_llama_al_cliente(): void
    {
        $registro = $this->capturar_el_log();

        $this->el_cliente_contesta(200, $this->conexion_valida());

        $respuesta = $this->provisionar(['phone_number_id' => '123456789', 'webhook_secret' => $this->secreto, 'is_active' => true]);

        $respuesta->assertStatus(200)->assertJson([
            'dry_run'   => true,
            'listo'     => true,
            'estado'    => 'ok',
            'cliente'   => ['id' => (int) $this->cliente->id],
            'frente'    => ['api_host' => 'api-ferretest.ejemplo.test'],
            'cambiaria' => ['phone_number_id' => '123456789', 'webhook_secret' => true, 'is_active' => true],
        ]);
        $this->assertSinSecretos($respuesta, $registro);
        Http::assertNothingSent();
    }

    /**
     * Un `confirm_client_name` equivocado o ausente (con `dry_run = false`) se rechaza antes de llamar al cliente, sin revelar el nombre.
     *
     * @return void
     */
    public function test_confirm_client_name_incorrecto_se_rechaza(): void
    {
        $this->el_cliente_contesta(200, $this->conexion_valida());

        $malo = $this->provisionar($this->pedido_de_verdad(['confirm_client_name' => 'Otro Negocio']));

        $malo->assertStatus(422)->assertJson(['error' => 'validacion']);
        $this->assertStringNotContainsString(self::NOMBRE, (string) $malo->getContent());

        $sin = $this->provisionar($this->pedido_de_verdad(['confirm_client_name' => null]));

        $sin->assertStatus(422);
        Http::assertNothingSent();
    }

    /**
     * Validaciones del pedido: sin nada para cambiar, parámetro de más, booleano ilegible y texto demasiado largo.
     *
     * @return void
     */
    public function test_valida_el_pedido(): void
    {
        $this->el_cliente_contesta(200, $this->conexion_valida());

        $this->provisionar(['dry_run' => true])->assertStatus(422);
        $this->provisionar(['phone_number_id' => '1', 'otro' => 'x'])->assertStatus(422);
        $this->provisionar(['is_active' => 'quizas'])->assertStatus(422);
        $this->provisionar(['phone_number_id' => str_repeat('9', 192)])->assertStatus(422);
        Http::assertNothingSent();
    }

    /**
     * Cliente inexistente: 404 estable y sin llamada.
     *
     * @return void
     */
    public function test_cliente_inexistente(): void
    {
        $this->el_cliente_contesta(200, $this->conexion_valida());

        $this->getJson('/api/claude/clients/99999999/whatsapp-conexion', $this->headers())
            ->assertStatus(404)->assertJson(['error' => 'cliente_inexistente']);

        Http::assertNothingSent();
    }

    /**
     * Sin la clave de ingesta no se entra.
     *
     * @return void
     */
    public function test_exige_la_clave_de_ingesta(): void
    {
        $this->getJson('/api/claude/clients/' . $this->cliente->id . '/whatsapp-conexion')->assertStatus(401);
    }

    /* ------------------------------------------------------------------------------------------
     | Estados
     |----------------------------------------------------------------------------------------- */

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public function estados_por_status(): array
    {
        return [
            '401 clave rechazada' => [401, 'clave_rechazada'],
            '404 versión vieja'   => [404, 'version_vieja'],
            '405 versión vieja'   => [405, 'version_vieja'],
            '409 rechazada'       => [409, 'rechazada'],
            '422 rechazada'       => [422, 'rechazada'],
            '500 sin conexión'    => [500, 'sin_conexion'],
            '302 sin conexión'    => [302, 'sin_conexion'],
        ];
    }

    /**
     * @dataProvider estados_por_status
     *
     * @param int    $status HTTP del cliente.
     * @param string $estado Estado esperado.
     *
     * @return void
     */
    public function test_mapea_el_status_del_cliente_a_un_estado(int $status, string $estado): void
    {
        $this->el_cliente_contesta($status, ['message' => 'Mensaje del cliente']);

        $respuesta = $this->provisionar($this->pedido_de_verdad());

        $respuesta->assertStatus(200)->assertJson(['estado' => $estado, 'conexion' => null]);
        $this->assertSinSecretos($respuesta);
    }

    /**
     * Un 422 trae el mensaje del cliente.
     *
     * @return void
     */
    public function test_un_422_trae_el_mensaje_del_cliente(): void
    {
        $this->el_cliente_contesta(422, ['message' => 'Para activar faltan las credenciales.']);

        $respuesta = $this->provisionar($this->pedido_de_verdad(['is_active' => true]));

        $respuesta->assertJson(['estado' => 'rechazada']);
        $this->assertStringContainsString('Para activar faltan las credenciales.', $respuesta->json('mensaje'));
    }

    /**
     * Un 200 que no confirma con `ok` y `conexion` (la página genérica del hosting) es sin_conexion.
     *
     * @return void
     */
    public function test_un_200_sin_cuerpo_valido_es_sin_conexion(): void
    {
        $this->el_cliente_contesta(200, ['hola' => 'mundo']);

        $this->provisionar($this->pedido_de_verdad())->assertJson(['estado' => 'sin_conexion']);
    }

    /**
     * Timeout sin secretos en el mensaje: sin_conexion.
     *
     * @return void
     */
    public function test_timeout_es_sin_conexion(): void
    {
        $this->http_falso(['*' => function () {
            throw new ConnectionException('cURL error 28: Operation timed out');
        }]);

        $this->provisionar($this->pedido_de_verdad())->assertJson(['estado' => 'sin_conexion']);
    }

    /**
     * Cliente sin clave de API: sin_clave y sin llamada.
     *
     * @return void
     */
    public function test_sin_clave_de_cliente(): void
    {
        $sin = $this->crear_cliente('Sin Clave SA', '');
        $this->crear_frente($sin, 'sinclave', 'shared_hosting', null, true);
        $this->el_cliente_contesta(200, $this->conexion_valida());

        $this->provisionar($this->pedido_de_verdad(['confirm_client_name' => 'Sin Clave SA']), $sin)
            ->assertJson(['estado' => 'sin_clave']);

        Http::assertNothingSent();
    }

    /**
     * Cliente sin frente activo: sin_frente y sin llamada.
     *
     * @return void
     */
    public function test_sin_frente(): void
    {
        $sin = $this->crear_cliente('Sin Frente SA', 'sk-sinfrente-' . Str::random(30));
        $this->el_cliente_contesta(200, $this->conexion_valida());

        $this->provisionar($this->pedido_de_verdad(['confirm_client_name' => 'Sin Frente SA']), $sin)
            ->assertJson(['estado' => 'sin_frente']);

        Http::assertNothingSent();
    }

    /**
     * Sin clave de Kapso en el admin: sin_kapso y sin llamada; el GET no la necesita.
     *
     * @return void
     */
    public function test_sin_clave_de_kapso(): void
    {
        WhatsappConfig::query()->delete();
        $this->crear_config_de_kapso('principal', '   ');
        $this->el_cliente_contesta(200, $this->conexion_valida());

        $this->provisionar($this->pedido_de_verdad())->assertJson(['estado' => 'sin_kapso']);
        Http::assertNothingSent();

        $this->getJson('/api/claude/clients/' . $this->cliente->id . '/whatsapp-conexion', $this->headers())
            ->assertJson(['estado' => 'ok']);
    }

    /**
     * Dos claves de Kapso distintas: no se adivina (sin_kapso); dos filas con LA MISMA clave sí valen; una fila apagada no cuenta.
     *
     * @return void
     */
    public function test_claves_de_kapso_ambiguas_no_se_adivinan(): void
    {
        $otra = 'kapso-otra-clave-' . Str::random(20);

        $this->crear_config_de_kapso('asistente', $otra);
        $this->el_cliente_contesta(200, $this->conexion_valida());

        $ambigua = $this->provisionar($this->pedido_de_verdad());

        $ambigua->assertJson(['estado' => 'sin_kapso']);
        $this->assertSinSecretos($ambigua);
        $this->assertStringNotContainsString($otra, (string) $ambigua->getContent());
        Http::assertNothingSent();

        WhatsappConfig::query()->delete();
        $this->crear_config_de_kapso('principal', $this->clave_kapso);
        $this->crear_config_de_kapso('asistente', $this->clave_kapso);
        $this->crear_config_de_kapso('asistente', 'kapso-apagada-' . Str::random(20), false);

        $this->provisionar($this->pedido_de_verdad())->assertJson(['estado' => 'ok']);
    }
}

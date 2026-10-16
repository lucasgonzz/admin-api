<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\EnvTemplate;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * POST env-template/probar-clave: prueba contra Anthropic la clave GUARDADA en la plantilla.
 *
 * Los cinco estados (valida, rechazada, no_concluyente, no_soportada, sin_valor) y, sobre todo, que
 * la clave nunca vuelva en la respuesta ni quede en un log. Anthropic está siempre simulado con
 * Http::fake: ninguna prueba sale a la red. Las claves son falsas.
 */
class ProbarClaveDePlantillaTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Clave falsa de prueba.
     *
     * @var string
     */
    private const CLAVE = 'sk-ant-test-0123456789ABCDEF';

    /**
     * Mensajes (y contexto) que se loguearon durante la prueba.
     *
     * @var array<int, string>
     */
    private $logs = [];

    /**
     * Qué contesta el Anthropic simulado: una función que devuelve la respuesta o lanza la excepción.
     *
     * Http::fake() se registra UNA vez por prueba (si se llama dos veces gana la primera), así que las
     * pruebas que recorren varios códigos de estado cambian esta función en lugar de volver a simular.
     *
     * @var callable
     */
    private $anthropic;

    protected function setUp(): void
    {
        parent::setUp();

        /* Hermético: sin depender de filas ajenas de env_templates (se revierte con la transacción). */
        EnvTemplate::query()->delete();

        $this->responder(200);
        Http::fake(function () {
            return call_user_func($this->anthropic);
        });

        /* Captura todo lo que se loguea para afirmar que la clave no aparece en ninguna parte. */
        $this->app['events']->listen(MessageLogged::class, function (MessageLogged $evento) {
            $this->logs[] = $evento->message . ' ' . json_encode($evento->context);
        });
    }

    public function test_una_clave_aceptada_es_valida_y_se_prueba_con_los_headers_de_anthropic(): void
    {
        $admin = $this->crear_admin('probar-valida@test.local');
        $this->crear_plantilla('ANTHROPIC_API_KEY', self::CLAVE);

        $response = $this->probar($admin, 'ANTHROPIC_API_KEY');

        $response->assertStatus(200);
        $this->assertSame('valida', $response->json('estado'));
        $this->assertSame(200, $response->json('http'));

        /* Un GET a /v1/models (no consume tokens) con la clave en el header, nunca en el body. */
        Http::assertSent(function (HttpRequest $request) {
            return $request->method() === 'GET'
                && $request->url() === 'https://api.anthropic.com/v1/models'
                && $request->header('x-api-key') === [self::CLAVE]
                && $request->header('anthropic-version') === ['2023-06-01'];
        });
        Http::assertSentCount(1);

        $this->assertClaveNoAparece($response);
    }

    public function test_una_clave_rechazada_con_401_o_403_es_rechazada(): void
    {
        $admin = $this->crear_admin('probar-rechazada@test.local');
        $this->crear_plantilla('ANTHROPIC_API_KEY', self::CLAVE);

        foreach ([401, 403] as $codigo) {
            $this->responder($codigo, ['error' => ['message' => 'invalid x-api-key'], 'echo' => self::CLAVE]);

            $response = $this->probar($admin, 'ANTHROPIC_API_KEY');

            $response->assertStatus(200);
            $this->assertSame('rechazada', $response->json('estado'));
            $this->assertSame($codigo, $response->json('http'));
            /* Aunque el proveedor devolviera la clave en el cuerpo de su error, no se repite. */
            $this->assertClaveNoAparece($response);
        }
    }

    public function test_red_caida_timeout_429_y_5xx_no_son_concluyentes(): void
    {
        $admin = $this->crear_admin('probar-noconcluyente@test.local');
        $this->crear_plantilla('ANTHROPIC_API_KEY', self::CLAVE);

        /* Red caída: la excepción incluso trae la clave en su mensaje, para probar que no se propaga. */
        $this->anthropic = function () {
            throw new ConnectionException('cURL error 28: Operation timed out x-api-key: ' . self::CLAVE);
        };
        $response = $this->probar($admin, 'ANTHROPIC_API_KEY');
        $response->assertStatus(200);
        $this->assertSame('no_concluyente', $response->json('estado'));
        $this->assertNull($response->json('http'));
        $this->assertClaveNoAparece($response);

        foreach ([429, 500, 503] as $codigo) {
            $this->responder($codigo, []);

            $response = $this->probar($admin, 'ANTHROPIC_API_KEY');
            $this->assertSame('no_concluyente', $response->json('estado'), "HTTP {$codigo}");
            $this->assertSame($codigo, $response->json('http'));
        }
    }

    public function test_cualquier_otra_clave_es_no_soportada_y_no_sale_a_la_red(): void
    {
        $admin = $this->crear_admin('probar-nosoportada@test.local');
        $this->crear_plantilla('DEEPSEEK_API_KEY', 'sk-ds-test-0123456789ABCDEF');

        $response = $this->probar($admin, 'DEEPSEEK_API_KEY');

        $response->assertStatus(200);
        $this->assertSame('no_soportada', $response->json('estado'));
        Http::assertNothingSent();
        $this->assertStringNotContainsString('0123456789ABCDEF', $response->getContent());
    }

    public function test_sin_valor_cargado_o_sin_fila_es_sin_valor_y_no_sale_a_la_red(): void
    {
        $admin = $this->crear_admin('probar-sinvalor@test.local');

        /* Sin fila. */
        $this->assertSame('sin_valor', $this->probar($admin, 'ANTHROPIC_API_KEY')->json('estado'));

        /* Con fila pero vacía. */
        EnvTemplate::create([
            'key' => 'ANTHROPIC_API_KEY', 'value' => null, 'group' => 'ia', 'scope' => 'empresa',
            'is_common' => false, 'is_manual_on_create' => false, 'sort_order' => 1,
        ]);
        $this->assertSame('sin_valor', $this->probar($admin, 'ANTHROPIC_API_KEY')->json('estado'));

        Http::assertNothingSent();
    }

    public function test_el_scope_elige_la_fila_y_el_nombre_se_normaliza_a_mayusculas(): void
    {
        $admin = $this->crear_admin('probar-scope@test.local');
        $this->crear_plantilla('ANTHROPIC_API_KEY', self::CLAVE, 'tienda');

        /* Sin fila en empresa: sin_valor. En tienda, con la clave en minúsculas, la prueba. */
        $this->assertSame('sin_valor', $this->probar($admin, 'ANTHROPIC_API_KEY')->json('estado'));
        $this->assertSame('valida', $this->probar($admin, 'anthropic_api_key', 'tienda')->json('estado'));
    }

    public function test_la_clave_no_queda_en_ningun_log(): void
    {
        $admin = $this->crear_admin('probar-logs@test.local');
        $this->crear_plantilla('ANTHROPIC_API_KEY', self::CLAVE);

        $this->responder(401, []);
        $this->probar($admin, 'ANTHROPIC_API_KEY');

        $this->anthropic = function () {
            throw new ConnectionException('fallo con ' . self::CLAVE);
        };
        $this->probar($admin, 'ANTHROPIC_API_KEY');

        $this->assertStringNotContainsString('0123456789ABCDEF', implode("\n", $this->logs));
    }

    public function test_la_clave_guardada_con_espacios_se_prueba_sin_espacios(): void
    {
        $admin = $this->crear_admin('probar-trim@test.local');
        $this->crear_plantilla('ANTHROPIC_API_KEY', self::CLAVE . " \t");

        $this->assertSame('valida', $this->probar($admin, 'ANTHROPIC_API_KEY')->json('estado'));

        /* El header que llega a Anthropic es exactamente la clave, sin espacios ni tabs al borde. */
        Http::assertSent(function (HttpRequest $request) {
            return $request->header('x-api-key') === [self::CLAVE];
        });
    }

    public function test_exige_estar_autenticado(): void
    {
        $this->postJson('/api/admin/env-template/probar-clave', ['key' => 'ANTHROPIC_API_KEY'])->assertStatus(401);
    }

    /**
     * Hace que el Anthropic simulado conteste con ese código y ese cuerpo.
     *
     * @param  int    $codigo
     * @param  array  $cuerpo
     * @return void
     */
    private function responder(int $codigo, array $cuerpo = ['data' => []]): void
    {
        $this->anthropic = function () use ($codigo, $cuerpo) {
            return Http::response($cuerpo, $codigo);
        };
    }

    /**
     * Llama al endpoint.
     *
     * @param  Admin   $admin
     * @param  string  $key
     * @param  string  $scope
     * @return \Illuminate\Testing\TestResponse
     */
    private function probar(Admin $admin, string $key, string $scope = 'empresa')
    {
        return $this->actingAs($admin, 'sanctum')->postJson('/api/admin/env-template/probar-clave', [
            'key'   => $key,
            'scope' => $scope,
        ]);
    }

    /**
     * Afirma que ni la clave ni su cuerpo cifrado aparecen en la respuesta ni en los logs.
     *
     * @param  \Illuminate\Testing\TestResponse  $response
     * @return void
     */
    private function assertClaveNoAparece($response): void
    {
        $this->assertStringNotContainsString('0123456789ABCDEF', $response->getContent());
        $this->assertStringNotContainsString('enc:v1:', $response->getContent());
        $this->assertStringNotContainsString('0123456789ABCDEF', implode("\n", $this->logs));
    }

    /**
     * Crea una variable de la plantilla por el modelo.
     *
     * @param  string  $key
     * @param  string  $value
     * @param  string  $scope
     * @return EnvTemplate
     */
    private function crear_plantilla(string $key, string $value, string $scope = 'empresa'): EnvTemplate
    {
        return EnvTemplate::create([
            'key' => $key, 'value' => $value, 'group' => 'ia', 'scope' => $scope,
            'is_common' => false, 'is_manual_on_create' => false, 'sort_order' => 1,
        ]);
    }

    /**
     * Crea un admin para autenticar las requests.
     *
     * @param  string  $email
     * @return Admin
     */
    private function crear_admin(string $email): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Admin de prueba';
        $admin->email    = $email;
        $admin->password = bcrypt('secret');
        $admin->save();

        return $admin;
    }
}

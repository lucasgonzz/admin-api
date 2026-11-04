<?php

namespace Tests\Unit;

use App\Services\RespaldoDeepSeek;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RespaldoDeepSeekTest extends TestCase
{
    private const URL = 'https://api.deepseek.com/anthropic/v1/messages';

    public function test_pro_agota_su_parte_del_plazo_y_flash_usa_solo_el_restante(): void
    {
        $intentos = [];
        $llamadas = 0;
        Http::fake(function () use (&$llamadas) {
            if (++$llamadas === 1) {
                usleep(20000);
                throw new ConnectionException('cURL error 28: Operation timed out');
            }

            return Http::response(['content' => [['type' => 'text', 'text' => 'Listo.']]]);
        });
        // El PendingRequest toma los fakes al construirse.
        $http = Http::withHeaders(['x-api-key' => 'clave-de-prueba'])->beforeSending(function ($request, $options) use (&$intentos) {
            $intentos[] = [$request->data(), $options['timeout']];
        });
        $payload = ['model' => 'deepseek-v4-pro', 'messages' => [['role' => 'user', 'content' => 'Hola']]];
        $respaldo = new RespaldoDeepSeek('prueba');

        $this->assertTrue($respaldo->enviar($http, self::URL, $payload, 20)->successful());
        $this->assertCount(2, $intentos);
        $this->assertSame($payload, $intentos[0][0]);
        $this->assertSame(15.0, $intentos[0][1]);
        $this->assertSame(array_replace($payload, ['model' => 'deepseek-flash']), $intentos[1][0]);
        $this->assertLessThan(19.99, $intentos[1][1]);
        $this->assertSame('deepseek-flash', $respaldo->modelo_efectivo());
    }

    public function test_flash_y_su_respaldo_fallan_una_vez_y_el_turno_no_repite_el_respaldo(): void
    {
        Http::fake(['*' => Http::sequence()->push([], 503)->push([], 504)->push([], 500)]);
        $respaldo = new RespaldoDeepSeek('prueba');
        $payload = ['model' => 'deepseek-v4-flash', 'messages' => []];
        $http = Http::withHeaders([]);

        $this->assertSame(504, $respaldo->enviar($http, self::URL, $payload, 25)->status());
        $this->assertSame(500, $respaldo->enviar($http, self::URL, $payload, 25)->status());
        $this->assertSame(['deepseek-v4-flash', 'deepseek-v4-pro', 'deepseek-v4-flash'],
            Http::recorded()->map(function ($par) { return $par[0]['model']; })->all());
    }

    public function test_el_modelo_que_respondio_continua_solo_dentro_del_turno(): void
    {
        Http::fake(['*' => Http::sequence()->push([], 429)->push([])->push([])->push([])]);
        $payload = ['model' => 'deepseek-flash'];
        $respaldo = new RespaldoDeepSeek('prueba');
        $http = Http::withHeaders([]);

        $respaldo->enviar($http, self::URL, $payload, 20);
        $respaldo->enviar($http, self::URL, $payload, 20);
        (new RespaldoDeepSeek('otro_turno'))->enviar($http, self::URL, $payload, 20);

        $this->assertSame(['deepseek-flash', 'deepseek-v4-pro', 'deepseek-v4-pro', 'deepseek-flash'],
            Http::recorded()->map(function ($par) { return $par[0]['model']; })->all());
    }

    public function test_flash_con_una_imagen_en_el_resultado_de_tool_no_prueba_pro(): void
    {
        Http::fake(['*' => Http::response([], 503)]);
        $payload = [
            'model' => 'deepseek-v4-flash-vision-exp',
            'messages' => [['role' => 'user', 'content' => [[
                'type' => 'tool_result', 'tool_use_id' => 'tool_1',
                'content' => [['type' => 'image', 'source' => ['type' => 'url', 'url' => 'https://imagen.test/a.png']]],
            ]]]],
        ];

        $this->assertSame(503, (new RespaldoDeepSeek('prueba'))->enviar(Http::withHeaders([]), self::URL, $payload, 25)->status());
        Http::assertSentCount(1);
    }

    /** @dataProvider estados_permanentes */
    public function test_no_repite_errores_de_credenciales_saldo_o_datos(int $estado): void
    {
        Http::fake(['*' => Http::response([], $estado)]);

        $this->assertSame($estado, (new RespaldoDeepSeek('prueba'))->enviar(Http::withHeaders([]), self::URL, ['model' => 'deepseek-v4-pro'], 25)->status());
        Http::assertSentCount(1);
    }

    public function estados_permanentes(): array
    {
        return [[400], [401], [402], [403], [404], [422]];
    }

    /** @dataProvider contratos_sin_respaldo */
    public function test_preserva_el_error_si_el_pedido_no_se_puede_repetir(array $payload): void
    {
        Http::fake(['*' => Http::response([], 503)]);

        $this->assertSame(503, (new RespaldoDeepSeek('prueba'))->enviar(Http::withHeaders([]), self::URL, $payload, 25)->status());
        Http::assertSentCount(1);
    }

    public function contratos_sin_respaldo(): array
    {
        return [
            'stream' => [['model' => 'deepseek-flash', 'stream' => true]],
            'herramienta_del_proveedor' => [[
                'model' => 'deepseek-v4-pro',
                'tools' => [['name' => 'web_search', 'type' => 'web_search_20250305']],
            ]],
            'modelo_desconocido' => [['model' => 'otro-modelo']],
        ];
    }

    public function test_una_falla_de_certificado_no_intenta_con_otro_modelo(): void
    {
        $intentos = 0;
        Http::fake(function () use (&$intentos) {
            $intentos++;
            throw new ConnectionException('cURL error 60: SSL certificate problem');
        });

        try {
            (new RespaldoDeepSeek('prueba'))->enviar(Http::withHeaders([]), self::URL, ['model' => 'deepseek-flash'], 25);
            $this->fail('La falla de certificado debía subir sin reintentos.');
        } catch (ConnectionException $error) {
            $this->assertSame(1, $intentos);
        }
    }
}

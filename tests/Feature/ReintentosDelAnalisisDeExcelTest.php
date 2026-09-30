<?php

namespace Tests\Feature;

use App\Models\Implementation;
use App\Services\ImplementationConversationService;
use App\Services\ImplementationImportService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Feature\AsistenteWhatsapp\BaseDelCanal;

/**
 * El análisis de Excel de la implementación no se paga dos veces (misión modelos-ia-por-cliente,
 * 30/9/2026).
 *
 * Cada llamada a `admin-sync/ai-excel-import/analyze` es una consulta paga a la IA del cliente
 * (DeepSeek Pro razonando) y deja un `excel_path` nuevo del otro lado. Lo que estos tests protegen:
 *
 *  1. 🔴 **Un timeout no se reintenta**: el cliente probablemente sigue analizando el primero.
 *  2. 🔴 **Una respuesta HTTP (500, 422) no se reintenta** y el admin recibe el código y el motivo del
 *     cliente, no el texto crudo de la `RequestException` de Laravel.
 *  3. **Una conexión rechazada sí se reintenta**: ese pedido nunca llegó.
 *  4. **El servicio usa `excel_analyze_timeout`** como techo de la llamada, no el genérico.
 *
 * Se llama a `analyze_files()` derecho, con el cliente y el owner ya resueltos, y con un espía del
 * aviso al admin asignado: es lo único que el operador ve cuando algo sale mal.
 */
class ReintentosDelAnalisisDeExcelTest extends BaseDelCanal
{
    /** URL del Excel en Kapso (fakeada). */
    const URL_KAPSO = 'https://kapso.test/media/lista.xlsx';

    /**
     * Espía del servicio de conversación: registra los avisos al admin asignado en vez de mandarlos.
     *
     * @return ImplementationConversationService
     */
    private function espia_de_avisos(): ImplementationConversationService
    {
        return new class extends ImplementationConversationService {
            /** @var array<int, string> Avisos que habrían salido al admin asignado. */
            public $avisos = [];

            public function notify_assigned_admin_for_implementation(Implementation $implementation, string $message): void
            {
                $this->avisos[] = $message;
            }
        };
    }

    /**
     * Corre el análisis de un archivo de artículos contra el stub dado para el endpoint de análisis.
     *
     * @param mixed                             $stub_analisis Stub de `*ai-excel-import/analyze*`.
     * @param ImplementationConversationService $espia         Espía de avisos.
     *
     * @return array<string, mixed>|null Lo que devuelve `analyze_files()`.
     */
    private function analizar($stub_analisis, ImplementationConversationService $espia): ?array
    {
        $this->fakear_http([
            '*kapso.test*'                  => Http::response('contenido-del-excel', 200),
            '*ai-excel-import/analyze*'     => $stub_analisis,
        ]);

        $client = $this->crear_cliente();

        $implementation            = new Implementation();
        $implementation->id        = 987654;
        $implementation->client_id = $client->id;

        $servicio = new ImplementationImportService(null, $espia);

        return $servicio->analyze_files(
            $implementation,
            'articles',
            [['url' => self::URL_KAPSO, 'filename' => 'lista.xlsx']],
            $client,
            5
        );
    }

    /**
     * Respuesta 200 del análisis, con la forma que devuelve el `empresa-api`.
     *
     * @return \GuzzleHttp\Promise\PromiseInterface
     */
    private function analisis_ok()
    {
        return Http::response([
            'column_mapping' => [['columna' => 'A', 'propiedad' => 'nombre']],
            'excel_path'     => 'imports/lista.xlsx',
            'row_count'      => 12,
        ], 200);
    }

    /**
     * 🔴 Un timeout se intenta UNA sola vez y el aviso lo dice en castellano, con el techo.
     *
     * @return void
     */
    public function test_un_timeout_no_se_reintenta(): void
    {
        config(['services.client_api.retries' => 3]);

        $intentos = 0;
        $espia    = $this->espia_de_avisos();

        $resultado = $this->analizar(function () use (&$intentos) {
            $intentos++;

            throw new ConnectionException('cURL error 28: Operation timed out after 180001 milliseconds');
        }, $espia);

        $this->assertNull($resultado);
        $this->assertSame(1, $intentos);
        $this->assertCount(1, $espia->avisos);
        $this->assertStringContainsString('no terminó de analizar', $espia->avisos[0]);
        $this->assertStringContainsString('180 s', $espia->avisos[0]);
    }

    /**
     * 🔴 Un 500 se intenta UNA sola vez y el aviso trae el código y el motivo del cliente, legible.
     *
     * @return void
     */
    public function test_un_500_no_se_reintenta_y_el_aviso_es_legible(): void
    {
        config(['services.client_api.retries' => 3]);

        $intentos = 0;
        $espia    = $this->espia_de_avisos();

        $resultado = $this->analizar(function () use (&$intentos) {
            $intentos++;

            return Http::response(['message' => 'La IA no contestó a tiempo.'], 500);
        }, $espia);

        $this->assertNull($resultado);
        $this->assertSame(1, $intentos);
        $this->assertCount(1, $espia->avisos);
        $this->assertStringContainsString('Error HTTP 500', $espia->avisos[0]);
        $this->assertStringContainsString('La IA no contestó a tiempo.', $espia->avisos[0]);
        // Ni rastro del texto crudo de la excepción de Laravel.
        $this->assertStringNotContainsString('HTTP request returned status code', $espia->avisos[0]);
    }

    /**
     * Un 422 tampoco se reintenta y el aviso trae los mensajes de `errors`, no el genérico.
     *
     * @return void
     */
    public function test_un_422_trae_el_motivo_del_cliente(): void
    {
        config(['services.client_api.retries' => 3]);

        $intentos = 0;
        $espia    = $this->espia_de_avisos();

        $this->analizar(function () use (&$intentos) {
            $intentos++;

            return Http::response([
                'message' => 'The given data was invalid.',
                'errors'  => ['excel_file' => ['El archivo no es un Excel válido.']],
            ], 422);
        }, $espia);

        $this->assertSame(1, $intentos);
        $this->assertStringContainsString('Error HTTP 422', $espia->avisos[0]);
        $this->assertStringContainsString('El archivo no es un Excel válido.', $espia->avisos[0]);
        $this->assertStringNotContainsString('HTTP request returned status code', $espia->avisos[0]);
    }

    /**
     * Una conexión rechazada SÍ se reintenta (el pedido no llegó) y, si después contesta, sirve.
     *
     * @return void
     */
    public function test_una_conexion_rechazada_se_reintenta(): void
    {
        config(['services.client_api.retries' => 3]);

        $intentos = 0;
        $espia    = $this->espia_de_avisos();
        $ok       = $this->analisis_ok();

        $resultado = $this->analizar(function () use (&$intentos, $ok) {
            $intentos++;

            if ($intentos < 3) {
                throw new ConnectionException('cURL error 7: Failed to connect to api-ferreteria-de-prueba.test port 443: Connection refused');
            }

            return $ok;
        }, $espia);

        $this->assertSame(3, $intentos);
        $this->assertNotNull($resultado);
        $this->assertSame('imports/lista.xlsx', $resultado['excel_path']);
        $this->assertSame([], $espia->avisos);
    }

    /**
     * 🔴 El techo de la llamada ES `services.client_api.excel_analyze_timeout`, no el genérico.
     *
     * @return void
     */
    public function test_la_llamada_usa_el_techo_del_analisis(): void
    {
        config([
            'services.client_api.timeout'               => 15,
            'services.client_api.excel_analyze_timeout' => 123,
        ]);

        $techo_visto = null;
        $ok          = $this->analisis_ok();

        $resultado = $this->analizar(function ($request, $options) use (&$techo_visto, $ok) {
            $techo_visto = isset($options['timeout']) ? $options['timeout'] : null;

            return $ok;
        }, $this->espia_de_avisos());

        $this->assertNotNull($resultado);
        $this->assertSame(123, $techo_visto);
    }
}

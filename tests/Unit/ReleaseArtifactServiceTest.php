<?php

namespace Tests\Unit;

use App\Services\ReleaseArtifactService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * El servicio que baja del release de GitHub los artefactos que reemplazan al build en el VPS.
 *
 * No hay una sola llamada real a GitHub acá: `Http::fake()` simula la API y el servidor de
 * archivos. Lo que se fija es lo que es nuestro y se descubre tarde si está mal:
 *
 *  1. Que "no hay release" y "no hay asset" sean `null` (vía vieja), y que cualquier otro error de
 *     GitHub sea una excepción con el status adentro (no se manda el build al VPS en silencio por
 *     un token vencido).
 *  2. 🔴 Que el token viaje en el header y NUNCA en la URL, y que NO cruce a
 *     `objects.githubusercontent.com` en la redirección: S3 rechaza con 400 un request con dos
 *     mecanismos de autenticación, y el pipeline entero fallaría en la descarga.
 *  3. Que la descarga vaya a archivo (`sink`) y que un tamaño distinto del declarado borre el
 *     archivo y lance: un zip a medias en `storage/app/deployments` es lo que `step_upload_spa()`
 *     tomaría como artefacto bueno.
 *
 * ⚠️ `Http::fake()` soporta `sink` desde Laravel 8.x (`PendingRequest::sinkStubHandler()`): escribe
 * el cuerpo de la respuesta simulada en el archivo, así que la escritura a disco se verifica de
 * verdad y no por reflexión.
 */
class ReleaseArtifactServiceTest extends TestCase
{
    /** Token de prueba: tiene que aparecer en el header y en ningún otro lado. */
    const TOKEN = 'ghp_token_de_prueba_123';

    /** URL del asset tal como la devuelve la API de GitHub. */
    const URL_ASSET = 'https://api.github.com/repos/lucasgonzz/empresa-spa/releases/assets/9001';

    /** URL firmada a la que GitHub redirige la descarga. */
    const URL_S3 = 'https://objects.githubusercontent.com/github-production-release-asset/9001?X-Amz-Signature=firma';

    /** @var string Directorio temporal de las descargas de cada test. */
    private $directorio;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.github.token'          => self::TOKEN,
            'services.github.releases_owner' => 'lucasgonzz',
            'services.anthropic.verify_ssl'  => true,
            'services.anthropic.ca_bundle'   => null,
        ]);

        $this->directorio = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cc-artefactos-' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directorio)) {
            foreach ((array) glob($this->directorio . DIRECTORY_SEPARATOR . '*') as $archivo) {
                @unlink($archivo);
            }
            @rmdir($this->directorio);
        }

        parent::tearDown();
    }

    /**
     * Cuerpo de un release de GitHub con los assets que se le pasan.
     *
     * @param array<int, array<string, mixed>> $assets Assets del release.
     *
     * @return array<string, mixed>
     */
    private function release(array $assets): array
    {
        return [
            'id'       => 77,
            'tag_name' => 'v4.0.23',
            'name'     => 'v4.0.23',
            'assets'   => $assets,
        ];
    }

    /**
     * Un asset del release.
     *
     * @param string $nombre Nombre del archivo.
     * @param int    $bytes  Tamaño declarado.
     *
     * @return array<string, mixed>
     */
    private function asset(string $nombre, int $bytes): array
    {
        return [
            'id'                   => 9001,
            'name'                 => $nombre,
            'size'                 => $bytes,
            'content_type'         => 'application/zip',
            'url'                  => self::URL_ASSET,
            'browser_download_url' => 'https://github.com/lucasgonzz/empresa-spa/releases/download/v4.0.23/' . $nombre,
        ];
    }

    /**
     * Contenido binario de mentira, de un tamaño conocido y con la firma de un zip adelante.
     *
     * @param int $bytes Tamaño total.
     *
     * @return string
     */
    private function contenido(int $bytes): string
    {
        return 'PK' . str_repeat("\x03\x04zip", intdiv($bytes - 2, 5)) . str_repeat('x', ($bytes - 2) % 5);
    }

    /**
     * Ruta local de descarga dentro del directorio temporal del test.
     *
     * @return string
     */
    private function destino(): string
    {
        return $this->directorio . DIRECTORY_SEPARATOR . 'dist_prueba.zip';
    }

    /* ==========================================================================================
     | find_asset
     |========================================================================================= */

    /** Con el asset en el release devuelve sus cuatro datos, y el token viajó en el header. */
    public function test_find_asset_devuelve_el_asset_del_release_y_manda_el_token_en_el_header(): void
    {
        Http::fake([
            'api.github.com/*' => Http::response($this->release([
                $this->asset('otro-archivo.txt', 10),
                $this->asset('empresa-spa-v4.0.23-dist.zip', 123456),
            ]), 200),
        ]);

        $asset = (new ReleaseArtifactService())->find_asset('empresa-spa', 'v4.0.23', 'empresa-spa-v4.0.23-dist.zip');

        $this->assertSame([
            'name'                 => 'empresa-spa-v4.0.23-dist.zip',
            'url'                  => self::URL_ASSET,
            'size'                 => 123456,
            'browser_download_url' => 'https://github.com/lucasgonzz/empresa-spa/releases/download/v4.0.23/empresa-spa-v4.0.23-dist.zip',
        ], $asset);

        Http::assertSent(function (Request $request) {
            $this->assertSame(
                'https://api.github.com/repos/lucasgonzz/empresa-spa/releases/tags/v4.0.23',
                $request->url()
            );
            $this->assertSame('Bearer ' . self::TOKEN, $request->header('Authorization')[0]);
            $this->assertSame('application/vnd.github+json', $request->header('Accept')[0]);
            $this->assertSame('ComercioCity-Admin/1.0', $request->header('User-Agent')[0]);
            $this->assertStringNotContainsString(self::TOKEN, $request->url());

            return true;
        });
    }

    /** El release existe pero no trae ese asset: null, vía vieja. */
    public function test_find_asset_sin_ese_asset_en_el_release_devuelve_null(): void
    {
        Http::fake([
            'api.github.com/*' => Http::response($this->release([$this->asset('otro-archivo.zip', 10)]), 200),
        ]);

        $this->assertNull(
            (new ReleaseArtifactService())->find_asset('empresa-api', 'v4.0.23', 'empresa-api-v4.0.23.zip')
        );
    }

    /** Sin release para ese tag (404): null, vía vieja. */
    public function test_find_asset_con_404_devuelve_null(): void
    {
        Http::fake([
            'api.github.com/*' => Http::response(['message' => 'Not Found'], 404),
        ]);

        $this->assertNull(
            (new ReleaseArtifactService())->find_asset('empresa-api', 'v4.0.22', 'empresa-api-v4.0.22.zip')
        );
    }

    /** 🔴 Cualquier otro error de GitHub lanza, con el status adentro: no se disfraza de "no hay artefacto". */
    public function test_find_asset_con_otro_error_lanza_con_el_status_y_el_cuerpo(): void
    {
        Http::fake([
            'api.github.com/*' => Http::response(['message' => 'Bad credentials'], 401),
        ]);

        try {
            (new ReleaseArtifactService())->find_asset('empresa-api', 'v4.0.23', 'empresa-api-v4.0.23.zip');
            $this->fail('Un 401 tenía que lanzar.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('401', $e->getMessage());
            $this->assertStringContainsString('Bad credentials', $e->getMessage());
            $this->assertStringContainsString('empresa-api', $e->getMessage());
        }
    }

    /** El owner sale de config, no está escrito en el código. */
    public function test_find_asset_usa_el_owner_de_config(): void
    {
        config(['services.github.releases_owner' => 'otro-owner']);

        Http::fake(['api.github.com/*' => Http::response($this->release([]), 200)]);

        (new ReleaseArtifactService())->find_asset('empresa-spa', 'v4.0.23', 'empresa-spa-v4.0.23-dist.zip');

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://api.github.com/repos/otro-owner/empresa-spa/releases/tags/v4.0.23';
        });
    }

    /** Sin token configurado no se manda `Authorization` (repos públicos), pero sí el `User-Agent`. */
    public function test_find_asset_sin_token_no_manda_authorization(): void
    {
        config(['services.github.token' => '']);

        Http::fake(['api.github.com/*' => Http::response($this->release([]), 200)]);

        (new ReleaseArtifactService())->find_asset('empresa-spa', 'v4.0.23', 'empresa-spa-v4.0.23-dist.zip');

        Http::assertSent(function (Request $request) {
            return ! $request->hasHeader('Authorization') && $request->hasHeader('User-Agent');
        });
    }

    /* ==========================================================================================
     | download_asset
     |========================================================================================= */

    /**
     * 🔴 La descarga sigue el 302 de GitHub al servidor de archivos, escribe el archivo entero a
     * disco y devuelve los bytes. El request al asset lleva el token y `Accept: octet-stream`; el
     * request redirigido NO lleva el token.
     */
    public function test_download_asset_sigue_la_redireccion_sin_el_token_y_escribe_el_archivo(): void
    {
        $contenido = $this->contenido(2048);

        Http::fake([
            self::URL_ASSET => Http::response('', 302, ['Location' => self::URL_S3]),
            'objects.githubusercontent.com/*' => Http::response($contenido, 200, [
                'Content-Type' => 'application/octet-stream',
            ]),
        ]);

        $asset = $this->asset('empresa-spa-v4.0.23-dist.zip', 2048);

        $bytes = (new ReleaseArtifactService())->download_asset($asset, $this->destino());

        $this->assertSame(2048, $bytes);
        $this->assertFileExists($this->destino());
        $this->assertSame($contenido, file_get_contents($this->destino()));

        Http::assertSent(function (Request $request) {
            if ($request->url() !== self::URL_ASSET) {
                return false;
            }

            $this->assertSame('application/octet-stream', $request->header('Accept')[0]);
            $this->assertSame('Bearer ' . self::TOKEN, $request->header('Authorization')[0]);

            return true;
        });

        Http::assertSent(function (Request $request) {
            if (strpos($request->url(), 'https://objects.githubusercontent.com/') !== 0) {
                return false;
            }

            $this->assertFalse(
                $request->hasHeader('Authorization'),
                'El token NO puede cruzar a objects.githubusercontent.com: S3 rechaza con 400 dos mecanismos de autenticación.'
            );

            return true;
        });
    }

    /**
     * 🔴 El ZIPBALL de un tag NO se pide con el Accept de un asset.
     *
     * Los dos endpoints se parecen —los dos redirigen a un binario en objects.githubusercontent.com—
     * pero el del zipball es de la API JSON. Con `application/octet-stream` responde **415**:
     * *"Unsupported 'Accept' header: 'application/octet-stream'. Must accept 'application/json'"*.
     *
     * Medido contra GitHub de verdad el 10/9/2026, instalando `pescamayorista`: con
     * `application/vnd.github+json` el zipball de `empresa-api` v4.0.23 baja con 200, pesa 9,25 MB
     * y trae `public/index.php` entre sus 2946 entradas. Con el otro Accept, 415 y 172 bytes de
     * error. La instalación de un cliente nuevo depende de ese zipball, porque el asset de la API
     * excluye `public/` a propósito.
     *
     * @return void
     */
    public function test_el_zipball_se_pide_con_el_accept_de_la_api_y_no_el_de_un_asset(): void
    {
        $contenido = $this->contenido(4096);
        $zipball   = 'https://api.github.com/repos/lucasgonzz/empresa-api/zipball/v4.0.23';

        Http::fake([
            $zipball => Http::response('', 302, ['Location' => self::URL_S3]),
            'objects.githubusercontent.com/*' => Http::response($contenido, 200),
        ]);

        $bytes = (new ReleaseArtifactService())->download_source_zip('empresa-api', 'v4.0.23', $this->destino());

        $this->assertSame(4096, $bytes);
        $this->assertSame($contenido, file_get_contents($this->destino()));

        Http::assertSent(function (Request $request) use ($zipball) {
            if ($request->url() !== $zipball) {
                return false;
            }

            $this->assertSame(
                'application/vnd.github+json',
                $request->header('Accept')[0],
                'El zipball se pidió con el Accept de un asset: GitHub responde 415 y la instalación '
                . 'de cualquier cliente nuevo se queda sin public/.'
            );

            return true;
        });
    }

    /** Sin redirección (GitHub Enterprise, o un mirror) también funciona: 200 directo con el binario. */
    public function test_download_asset_acepta_un_200_directo(): void
    {
        $contenido = $this->contenido(700);

        Http::fake([self::URL_ASSET => Http::response($contenido, 200)]);

        $bytes = (new ReleaseArtifactService())->download_asset($this->asset('x.zip', 700), $this->destino());

        $this->assertSame(700, $bytes);
        $this->assertSame($contenido, file_get_contents($this->destino()));
    }

    /** 🔴 Si el tamaño en disco no es el declarado, se borra el archivo y se lanza con los dos números. */
    public function test_download_asset_con_tamano_distinto_borra_el_archivo_y_lanza(): void
    {
        Http::fake([self::URL_ASSET => Http::response($this->contenido(2048), 200)]);

        try {
            (new ReleaseArtifactService())->download_asset($this->asset('x.zip', 4096), $this->destino());
            $this->fail('Un tamaño distinto del declarado tenía que lanzar.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('4096', $e->getMessage());
            $this->assertStringContainsString('2048', $e->getMessage());
        }

        $this->assertFileDoesNotExist($this->destino(), 'Un zip a medias no puede quedar en disco.');
    }

    /** Un error HTTP en la descarga lanza con el status y no deja el cuerpo del error como si fuera el zip. */
    public function test_download_asset_con_error_http_lanza_y_no_deja_archivo(): void
    {
        Http::fake([self::URL_ASSET => Http::response(['message' => 'Not Found'], 404)]);

        try {
            (new ReleaseArtifactService())->download_asset($this->asset('x.zip', 4096), $this->destino());
            $this->fail('Un 404 en la descarga tenía que lanzar.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('404', $e->getMessage());
        }

        $this->assertFileDoesNotExist($this->destino());
    }

    /** Un asset sin `url` o con 0 bytes declarados se rechaza antes de pedir nada. */
    public function test_download_asset_rechaza_un_asset_sin_url_o_vacio(): void
    {
        Http::fake();

        $service = new ReleaseArtifactService();

        try {
            $service->download_asset(['name' => 'x.zip', 'url' => '', 'size' => 10], $this->destino());
            $this->fail('Sin url tenía que lanzar.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('url', $e->getMessage());
        }

        try {
            $service->download_asset(['name' => 'x.zip', 'url' => self::URL_ASSET, 'size' => 0], $this->destino());
            $this->fail('Con 0 bytes tenía que lanzar.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('0 bytes', $e->getMessage());
        }

        Http::assertNothingSent();
        $this->assertFileDoesNotExist($this->destino());
    }

    /** Un archivo viejo en el destino se pisa: lo que queda es lo que se bajó ahora. */
    public function test_download_asset_pisa_un_archivo_viejo_en_el_destino(): void
    {
        mkdir($this->directorio, 0755, true);
        file_put_contents($this->destino(), str_repeat('viejo', 1000));

        $contenido = $this->contenido(512);
        Http::fake([self::URL_ASSET => Http::response($contenido, 200)]);

        (new ReleaseArtifactService())->download_asset($this->asset('x.zip', 512), $this->destino());

        $this->assertSame($contenido, file_get_contents($this->destino()));
    }
}

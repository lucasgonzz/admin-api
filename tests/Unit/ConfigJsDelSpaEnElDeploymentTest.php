<?php

namespace Tests\Unit;

use App\Services\DeploymentService;
use App\Services\SpaRuntimeConfig;
use ReflectionClass;
use Tests\TestCase;

/**
 * El `config.js` que `DeploymentService::step_upload_spa()` escribe en el hosting del cliente tiene
 * que llevar EXACTAMENTE las mismas variables que el `.env` con el que se compilaba la SPA en el VPS
 * de builds: mismas claves, mismos valores, mismo orden. Es el contrato de la misión
 * `actualizar-sin-el-vps` (9/9/2026): una SPA compilada por GitHub Actions sin `.env` lee de
 * `window.__CC_CONFIG__` lo que antes venía cocinado en el bundle, y si acá falta una variable el
 * frente queda sin ella en silencio.
 *
 * Se prueba por reflexión porque los métodos son privados y no dependen del pipeline SSH:
 * construir el service de verdad pediría un upgrade persistido y una credencial de hosting que
 * este cálculo no usa (mismo criterio que `DemoUpdateSpaBuildEnvTest`).
 */
class ConfigJsDelSpaEnElDeploymentTest extends TestCase
{
    /** URL de API de ejemplo, con el `/public` del hosting compartido. */
    const API_URL = 'https://api-x.comerciocity.com/public';

    /** URL del SPA de ejemplo. */
    const SPA_URL = 'https://x.comerciocity.com';

    /**
     * Invoca un método privado de DeploymentService sobre una instancia sin constructor.
     *
     * @param string       $metodo Nombre del método.
     * @param array<mixed> $args   Argumentos.
     *
     * @return mixed
     */
    private function invocar(string $metodo, array $args)
    {
        $reflection = new ReflectionClass(DeploymentService::class);
        $service    = $reflection->newInstanceWithoutConstructor();

        $method = $reflection->getMethod($metodo);
        $method->setAccessible(true);

        return $method->invokeArgs($service, $args);
    }

    /**
     * Parseo mínimo del formato del `.env` que escribe el service (una línea `CLAVE=valor` por
     * variable, con comillas dobles cuando el valor tiene espacios). Copiado de
     * `DemoUpdateSpaBuildEnvTest::parsear_env()`.
     *
     * @param string $contenido Contenido del archivo .env.
     *
     * @return array<string, string>
     */
    private function parsear_env(string $contenido): array
    {
        $variables = [];
        foreach (explode("\n", $contenido) as $linea) {
            if (trim($linea) === '') {
                continue;
            }
            $partes = explode('=', $linea, 2);
            $valor  = isset($partes[1]) ? $partes[1] : '';
            if (strlen($valor) >= 2 && substr($valor, 0, 1) === '"' && substr($valor, -1) === '"') {
                $valor = str_replace('\\"', '"', substr($valor, 1, -1));
            }
            $variables[$partes[0]] = $valor;
        }

        return $variables;
    }

    /**
     * Decodifica el objeto de un `config.js`.
     *
     * @param string $config_js Salida de `SpaRuntimeConfig::render()`.
     *
     * @return array<string, string>
     */
    private function objeto_del_config_js(string $config_js): array
    {
        $prefijo = 'window.__CC_CONFIG__ = ';
        $this->assertSame($prefijo, substr($config_js, 0, strlen($prefijo)));

        $objeto = json_decode(substr($config_js, strlen($prefijo), -2), true);
        $this->assertIsArray($objeto);

        return $objeto;
    }

    /**
     * 🔴 El contrato: `config.js` y `.env` salen del MISMO array (`build_spa_env_vars()`), así que
     * tienen las mismas claves, en el mismo orden, con los mismos valores.
     *
     * @return void
     */
    public function test_config_js_lleva_las_mismas_variables_que_el_env_en_el_mismo_orden(): void
    {
        $vars = $this->invocar('build_spa_env_vars', [self::API_URL, self::SPA_URL]);
        $env  = $this->parsear_env((string) $this->invocar('build_spa_env_file_content', [self::API_URL, self::SPA_URL]));

        $this->assertIsArray($vars);
        $this->assertSame($env, $vars, 'El .env y el array de variables tienen que ser el mismo conjunto, en el mismo orden.');

        $objeto = $this->objeto_del_config_js(SpaRuntimeConfig::render($vars));

        $this->assertSame(array_keys($env), array_keys($objeto), 'Las claves del config.js tienen que ser las del .env, en orden.');
        $this->assertSame($env, $objeto);

        /* Las dos que cambian por frente, primero y con el valor calculado. */
        $this->assertSame(['VUE_APP_API_URL', 'VUE_APP_APP_URL'], array_slice(array_keys($objeto), 0, 2));
        $this->assertSame(self::API_URL, $objeto['VUE_APP_API_URL']);
        $this->assertSame(self::SPA_URL, $objeto['VUE_APP_APP_URL']);
    }

    /** Las variables fijas de `config/services.php` llegan al config.js con su valor recortado. */
    public function test_config_js_trae_las_variables_fijas_de_config(): void
    {
        $vars = $this->invocar('build_spa_env_vars', [self::API_URL, self::SPA_URL]);

        $fijas = config('services.deploy.spa_build_env');
        $this->assertIsArray($fijas);
        $this->assertArrayHasKey('VUE_APP_HAS_EXTRA_CONFIG', $fijas);

        foreach ($fijas as $clave => $valor) {
            $this->assertArrayHasKey($clave, $vars, 'El config.js se escribe sin ' . $clave . '.');
            $this->assertSame(trim((string) $valor), $vars[$clave]);
        }

        $this->assertSame(trim((string) config('services.deploy.spa_pusher_cluster')), $vars['VUE_APP_PUSHER_CLUSTER']);
        $this->assertSame(trim((string) config('services.deploy.spa_pusher_key')), $vars['VUE_APP_PUSHER_KEY']);
    }

    /**
     * El formato del `.env` no cambió con el refactor: valores con espacios entre comillas dobles,
     * los demás pelados, una variable por línea, sin línea final vacía.
     *
     * @return void
     */
    public function test_el_env_conserva_su_formato(): void
    {
        $contenido = (string) $this->invocar('build_spa_env_file_content', [self::API_URL, self::SPA_URL]);
        $lineas    = explode("\n", $contenido);

        $this->assertSame('VUE_APP_API_URL=' . self::API_URL, $lineas[0]);
        $this->assertSame('VUE_APP_APP_URL=' . self::SPA_URL, $lineas[1]);
        $this->assertContains('VUE_APP_ATTEMPT_TEXT="numero de documento"', $lineas);
        $this->assertSame(substr($contenido, -1), substr(trim($contenido), -1), 'El .env no termina en salto de línea.');
        $this->assertStringNotContainsString("\n\n", $contenido);
    }

    /**
     * El comando remoto que escribe `config.js` en el hosting: `printf '%s'` con el contenido entre
     * comillas simples (las del contenido escapadas como `'\''`), redirigido a `<dir>/config.js`.
     * Mismo escapado que usa `step_compile_spa()` para el `.env` del VPS.
     *
     * @return void
     */
    public function test_el_comando_que_escribe_config_js_escapa_las_comillas_y_apunta_al_directorio_del_spa(): void
    {
        $contenido = "window.__CC_CONFIG__ = {\"VUE_APP_APP_NAME\":\"L'Artesano\"};\n";
        $dir       = 'domains/comerciocity.com/public_html/x/spa';

        $comando = (string) $this->invocar('build_spa_config_js_write_command', [$dir, $contenido]);

        $this->assertSame(
            "printf '%s' 'window.__CC_CONFIG__ = {\"VUE_APP_APP_NAME\":\"L'\\''Artesano\"};\n' > 'domains/comerciocity.com/public_html/x/spa/config.js'",
            $comando
        );
    }

    /** El nombre del archivo es fijo: es lo que `index.html` de la SPA pide como `<BASE_URL>config.js`. */
    public function test_el_archivo_se_llama_config_js(): void
    {
        $comando = (string) $this->invocar('build_spa_config_js_write_command', ['/home/x/htdocs/x.comerciocity.com', "window.__CC_CONFIG__ = {};\n"]);

        $this->assertStringEndsWith("> '/home/x/htdocs/x.comerciocity.com/config.js'", $comando);
    }
}

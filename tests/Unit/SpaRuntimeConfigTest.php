<?php

namespace Tests\Unit;

use App\Services\SpaRuntimeConfig;
use PHPUnit\Framework\TestCase;

/**
 * `config.js` es el contrato entre el admin (que lo escribe en el hosting del cliente) y
 * `empresa-spa` (que lo carga síncrono desde `index.html` y lee `window.__CC_CONFIG__[key]`).
 *
 * Lo que estos tests fijan es el formato exacto, byte a byte: si alguien cambia el nombre de la
 * global, escapa las barras de las URLs o convierte un `'true'` en booleano, la SPA deja de leer su
 * API URL y el cliente queda con el sistema caído sin ningún error del lado del admin.
 *
 * Extiende el TestCase de PHPUnit y no el de Laravel a propósito: la clase es pura y no necesita
 * bootear la aplicación.
 */
class SpaRuntimeConfigTest extends TestCase
{
    /**
     * Variables de ejemplo, con lo que un frente real lleva: URLs, un valor con espacios y tildes.
     *
     * @return array<string, string>
     */
    private function variables(): array
    {
        return [
            'VUE_APP_API_URL'        => 'https://api-x.comerciocity.com/public',
            'VUE_APP_APP_URL'        => 'https://x.comerciocity.com',
            'VUE_APP_PUSHER_KEY'     => '98f389f62ef4a392fc77',
            'VUE_APP_PUSHER_CLUSTER' => 'sa1',
            'VUE_APP_USE_HOME_PAGE'  => 'true',
            'VUE_APP_ATTEMPT_TEXT'   => 'número de documento',
        ];
    }

    /**
     * Decodifica el objeto JSON de una salida de `render()`, verificando primero el envoltorio.
     *
     * @param string $salida Lo que devolvió `render()`.
     *
     * @return array<string, mixed>
     */
    private function objeto_de(string $salida): array
    {
        $prefijo = 'window.__CC_CONFIG__ = ';
        $sufijo  = ";\n";

        $this->assertSame($prefijo, substr($salida, 0, strlen($prefijo)), 'El prefijo de la asignación cambió.');
        $this->assertSame($sufijo, substr($salida, -strlen($sufijo)), 'La salida tiene que cerrar con `;` y salto de línea.');

        $json      = substr($salida, strlen($prefijo), -strlen($sufijo));
        $decodificado = json_decode($json, true);

        $this->assertSame(JSON_ERROR_NONE, json_last_error(), 'Lo que va entre el `=` y el `;` tiene que ser JSON válido.');
        $this->assertIsArray($decodificado);

        return $decodificado;
    }

    /** El contenido exacto: una línea, la global, el objeto sin barras ni tildes escapadas. */
    public function test_renderiza_la_asignacion_exacta_a_la_global(): void
    {
        $esperado = 'window.__CC_CONFIG__ = {'
            . '"VUE_APP_API_URL":"https://api-x.comerciocity.com/public",'
            . '"VUE_APP_APP_URL":"https://x.comerciocity.com",'
            . '"VUE_APP_PUSHER_KEY":"98f389f62ef4a392fc77",'
            . '"VUE_APP_PUSHER_CLUSTER":"sa1",'
            . '"VUE_APP_USE_HOME_PAGE":"true",'
            . '"VUE_APP_ATTEMPT_TEXT":"número de documento"'
            . "};\n";

        $this->assertSame($esperado, SpaRuntimeConfig::render($this->variables()));
    }

    /** El objeto es JSON válido y devuelve las mismas claves, en el mismo orden, con los mismos valores. */
    public function test_el_objeto_es_json_valido_con_las_claves_intactas_y_en_orden(): void
    {
        $variables = $this->variables();
        $objeto    = $this->objeto_de(SpaRuntimeConfig::render($variables));

        $this->assertSame(array_keys($variables), array_keys($objeto), 'Las claves VUE_APP_* tienen que llegar tal cual.');
        $this->assertSame($variables, $objeto);
    }

    /** Las barras de las URLs no se escapan: la SPA las usa como base de cada request. */
    public function test_las_barras_de_las_urls_no_se_escapan(): void
    {
        $salida = SpaRuntimeConfig::render(['VUE_APP_API_URL' => 'https://api-x.comerciocity.com/public']);

        $this->assertStringContainsString('"https://api-x.comerciocity.com/public"', $salida);
        $this->assertStringNotContainsString('\\/', $salida);
    }

    /** Las tildes se escriben como tildes, no como `ú`. */
    public function test_las_tildes_no_se_escapan(): void
    {
        $salida = SpaRuntimeConfig::render(['VUE_APP_ATTEMPT_TEXT' => 'número de documento']);

        $this->assertStringContainsString('número de documento', $salida);
        $this->assertStringNotContainsString('\\u00', $salida);
    }

    /**
     * 🔴 Los valores son SIEMPRE strings: la SPA compara con `'true'`/`'false'`. Un entero o un
     * booleano PHP salen como string, que es lo mismo que haría un `.env`.
     */
    public function test_los_valores_salen_siempre_como_strings(): void
    {
        $objeto = $this->objeto_de(SpaRuntimeConfig::render([
            'VUE_APP_ENTERO' => 5,
            'VUE_APP_TEXTO'  => 'true',
            'VUE_APP_NULO'   => null,
        ]));

        $this->assertSame('5', $objeto['VUE_APP_ENTERO']);
        $this->assertSame('true', $objeto['VUE_APP_TEXTO']);
        $this->assertSame('', $objeto['VUE_APP_NULO']);
    }

    /** Sin variables sale un objeto vacío, no una lista: la SPA indexa por clave. */
    public function test_sin_variables_sale_un_objeto_vacio(): void
    {
        $this->assertSame("window.__CC_CONFIG__ = {};\n", SpaRuntimeConfig::render([]));
    }

    /** Una comilla simple en un valor queda escapada para el JSON y no rompe la asignación. */
    public function test_una_comilla_en_un_valor_no_rompe_el_javascript(): void
    {
        $objeto = $this->objeto_de(SpaRuntimeConfig::render(['VUE_APP_APP_NAME' => "L'Artesano \"Sur\""]));

        $this->assertSame("L'Artesano \"Sur\"", $objeto['VUE_APP_APP_NAME']);
    }

    /** Un array como valor no es una variable de entorno: se rechaza en vez de escribir "Array". */
    public function test_un_valor_que_no_es_escalar_se_rechaza(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SpaRuntimeConfig::render(['VUE_APP_LISTA' => ['a', 'b']]);
    }
}

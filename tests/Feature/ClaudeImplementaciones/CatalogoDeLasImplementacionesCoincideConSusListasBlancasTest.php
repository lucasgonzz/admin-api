<?php

namespace Tests\Feature\ClaudeImplementaciones;

use App\Http\Controllers\Api\ClaudeImplementationOpsController;
use Tests\TestCase;

/**
 * Que lo que el catálogo (`config/claude_catalog.php`) dice que acepta cada ruta de
 * `claude/implementations/*` sea lo que el controlador acepta de verdad.
 *
 * `CatalogoDeEndpointsDeClaudeTest` solo exige que cada escritura declare `frenos` y `parametros` con la forma
 * correcta; NO compara los NOMBRES de los parámetros con la lista blanca del controlador. Y es la
 * lista blanca la que manda: un parámetro que está en una y no en la otra es un parámetro que la skill no
 * encuentra en el catálogo (y manda de más, con 422) o uno que el catálogo promete y el controlador rechaza.
 * Cada vez que se suma un parámetro nuevo —`reintentar`, `conciliar`, `include`, `marcar_colgadas`— este test
 * obliga a tocar los dos lados.
 */
class CatalogoDeLasImplementacionesCoincideConSusListasBlancasTest extends TestCase
{
    /**
     * Cada ruta con la constante de su lista blanca.
     *
     * @return array<string, string>
     */
    private function rutas(): array
    {
        return [
            'GET api/claude/implementations/{id}'              => 'PARAMETROS_DE_LA_LECTURA',
            'GET api/claude/implementations'                   => 'PARAMETROS_DE_LA_BUSQUEDA',
            'POST api/claude/implementations'                  => 'PARAMETROS_DEL_ALTA',
            'POST api/claude/implementations/{id}/advance'     => 'PARAMETROS_DEL_AVANCE',
            'POST api/claude/implementations/{id}/actions'     => 'PARAMETROS_DE_LA_ACCION',
            'POST api/claude/implementations/{id}/install'     => 'PARAMETROS_DE_LA_INSTALACION',
            'POST api/claude/implementations/{id}/user-setup'  => 'PARAMETROS_DEL_USER_SETUP',
            'POST api/claude/implementations/{id}/mail'        => 'PARAMETROS_DEL_MAIL',
        ];
    }

    /**
     * Los parámetros del catálogo de una ruta, sin el segmento de la URL.
     *
     * @param string $clave La clave del catálogo.
     *
     * @return array<int, string>
     */
    private function declarados(string $clave): array
    {
        $endpoint = config('claude_catalog.endpoints.' . $clave);
        $this->assertIsArray($endpoint, 'El catálogo no tiene la entrada ' . $clave);

        $nombres = [];
        foreach ($endpoint['parametros'] as $parametro) {
            if (strpos($parametro['nombre'], '(en la ruta)') !== false) {
                continue;
            }

            $nombres[] = $parametro['nombre'];
        }

        sort($nombres);

        return $nombres;
    }

    /**
     * Los nombres declarados en el catálogo son EXACTAMENTE los de la lista blanca del controlador (como
     * conjunto: el orden no importa).
     *
     * @return void
     */
    public function test_los_parametros_del_catalogo_son_los_de_la_lista_blanca(): void
    {
        $constantes = (new \ReflectionClass(ClaudeImplementationOpsController::class))->getConstants();

        foreach ($this->rutas() as $clave => $constante) {
            $this->assertArrayHasKey($constante, $constantes, 'El controlador no tiene la constante ' . $constante);

            $aceptados = $constantes[$constante];
            sort($aceptados);

            $this->assertSame(
                $aceptados,
                $this->declarados($clave),
                'El catálogo de "' . $clave . '" no declara los mismos parámetros que acepta el controlador (' . $constante . ').'
            );
        }
    }

    /**
     * Y están todas las rutas del bloque: si se agrega una a `routes/api.php` sin sumarla acá, este test no la
     * ve, pero el del catálogo general sí la marca como indescripta; este asegura que no se pierda ninguna de
     * las ocho que ya tienen lista blanca.
     *
     * @return void
     */
    public function test_estan_las_ocho_rutas(): void
    {
        $this->assertCount(8, $this->rutas());

        foreach (array_keys($this->rutas()) as $clave) {
            $this->assertNotNull(config('claude_catalog.endpoints.' . $clave), 'Falta la entrada ' . $clave);
        }
    }
}

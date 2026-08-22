<?php

namespace Tests\Unit;

use App\Services\EnvSshService;
use PHPUnit\Framework\TestCase;

/**
 * Dos contratos de EnvSshService que no necesitan ni base ni servidor.
 *
 * 1. El servicio no conecta solo. Antes cargaba fija la credencial de hosting compartido en el
 *    constructor y abría la sesión al primer uso, así que ningún llamador declaraba contra qué
 *    servidor estaba trabajando — que es exactamente cómo un flujo de VPS terminaba escribiendo el
 *    .env en el hosting compartido.
 *
 * 2. Escribir y volver a leer devuelve el mismo valor. Si el parseo no es la inversa exacta del
 *    formateo, la previsualización muestra un valor con barras que el archivo no tiene y "este
 *    valor ya está puesto" no se detecta nunca.
 */
class EnvSshServiceExigeConexionExplicitaTest extends TestCase
{
    public function test_leer_sin_conectar_falla_en_vez_de_adivinar_el_servidor(): void
    {
        $service = new EnvSshService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No hay una sesión SSH abierta');

        $service->read_env('domains/comerciocity.com/public_html/cualquiera/api');
    }

    public function test_escribir_sin_conectar_falla_en_vez_de_adivinar_el_servidor(): void
    {
        $service = new EnvSshService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No hay una sesión SSH abierta');

        $service->write_env_vars('domains/comerciocity.com/public_html/cualquiera/api', ['FOO' => 'bar']);
    }

    /**
     * @dataProvider valores_que_dan_vuelta
     *
     * @param  string  $valor
     * @return void
     */
    public function test_lo_que_se_escribe_se_relee_identico(string $valor): void
    {
        $service = new EnvSshService();

        $linea = 'UNA_VARIABLE=' . $service->format_env_value($valor);

        $parseado = $service->parse_env_content($linea);

        $this->assertSame($valor, $parseado['UNA_VARIABLE'], 'El valor no sobrevivió el ida y vuelta.');
    }

    /**
     * Valores que rompen el ida y vuelta si el formateo y el parseo no son inversos.
     *
     * @return array<string, array<int, string>>
     */
    public function valores_que_dan_vuelta(): array
    {
        return [
            'simple'                  => ['sk-abcdef123456'],
            'con espacios'            => ['ComercioCity Sistemas'],
            'con comillas dobles'     => ['dice "hola"'],
            'con comilla simple'      => ["l'auberge"],
            'con las dos comillas'    => ['mezcla "doble" y \'simple\''],
            'con barra invertida'     => ['C:\\ruta\\de\\windows'],
            'con signo peso'          => ['clave$con$peso'],
            'con interpolacion falsa' => ['${APP_NAME}'],
            'con backtick'            => ['echo `whoami`'],
            'con pipe y ampersand'    => ['a|b&c'],
            'con numeral'             => ['color #ff0000'],
            'con igual adentro'       => ['base64==final'],
            'vacio'                   => [''],
        ];
    }
}

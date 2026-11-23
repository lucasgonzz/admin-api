<?php

namespace Tests\Feature\TextosDelEmbudo;

use App\Services\PruebaPlantillasService;
use Tests\TestCase;

/**
 * Enmienda 5 de la ronda 2 de E3 (misión embudo-juan-pipeline): dos textos que, una vez creados en Meta, no se pueden editar.
 *
 *  - `cc_embudo_conversando_dia_3` no puede sonar a «armado gratis»: la prueba gratis es por su cuenta; que se lo dejemos armado es el
 *    servicio de implementación (pago), que le cuenta Juan.
 *  - `cc_prueba_bienvenida_v4` no se contradice (pedir el Excel y a la vez «subilo vos»): dice cómo lo sube él con el video y que, si
 *    prefiere que lo haga el equipo, existe el servicio y lo llama Juan.
 * Tono de Martín, sin precios y sin hablar de demo.
 */
class TextosDeLasPlantillasDeMarketingTest extends TestCase
{
    /**
     * Las oraciones de un texto.
     *
     * @param string $texto
     *
     * @return array<int, string>
     */
    private function oraciones(string $texto): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[.!?]+/u', $texto))));
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function textos_provider(): array
    {
        return [
            'cc_embudo_conversando_dia_3' => ['cc_embudo_conversando_dia_3'],
            'cc_prueba_bienvenida_v4'     => ['cc_prueba_bienvenida_v4'],
        ];
    }

    /**
     * «gratis» nunca comparte oración con armado, cargar o la implementación; se menciona el servicio y a Juan; sin precios ni demo.
     *
     * @dataProvider textos_provider
     *
     * @param string $nombre
     *
     * @return void
     */
    public function test_no_suena_a_armado_gratis_y_cuenta_del_servicio(string $nombre): void
    {
        $texto = PruebaPlantillasService::cuerpo_de($nombre);
        $this->assertNotSame('', $texto);

        foreach ($this->oraciones($texto) as $oracion) {
            if (preg_match('/gratis/iu', $oracion) === 1) {
                $this->assertSame(0, preg_match('/armad|carg|implementaci/iu', $oracion), 'En «' . $nombre . '», «gratis» comparte oración con la carga: ' . $oracion);
            }
        }

        $this->assertStringContainsString('implementación', $texto, $nombre . ' menciona el servicio.');
        $this->assertStringContainsString('Juan', $texto, $nombre . ' dice que lo llama Juan.');
        $this->assertSame(0, preg_match('/\$|demo/iu', $texto), $nombre . ' sin precios ni demo.');
        $this->assertSame(0, preg_match('/sin costo|te cargamos|carguemos/iu', $texto));
    }

    /**
     * La bienvenida v4 no pide el Excel y a la vez dice «subilo vos».
     *
     * @return void
     */
    public function test_la_bienvenida_v4_no_se_contradice(): void
    {
        $texto = PruebaPlantillasService::cuerpo_de('cc_prueba_bienvenida_v4');

        $this->assertStringNotContainsString('mandame', mb_strtolower($texto));
        $this->assertStringContainsString('video', $texto);
        $this->assertStringContainsString('vos mismo', $texto);
    }
}

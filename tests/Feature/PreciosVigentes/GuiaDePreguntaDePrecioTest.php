<?php

namespace Tests\Feature\PreciosVigentes;

use App\Models\AdminSetting;
use App\Services\Prueba\PruebaSettings;
use App\Services\PreciosVigentesSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Guía «CUANDO EL LEAD PREGUNTA PRECIOS» del bloque de precios de Martín (Lucas, 21/11/2026): ante «quiero
 * saber el precio del sistema» no se contesta sólo la cifra; se presenta, cuenta los 3 productos, responde
 * el precio y cierra con un siguiente paso (la prueba gratis de 7 días; la demo sólo si el lead la pide, política del 21/11/2026).
 * Criterios CA-R1 a CA-R4 de `.mision/PLAN.md`.
 *
 * Las cifras salen de la fuente (no están escritas en la guía) y Martín nunca ve USD ni ganancia.
 *
 * La IA no interviene en este archivo.
 */
class GuiaDePreguntaDePrecioTest extends TestCase
{
    use DatabaseTransactions;

    /** Título con el que arranca la guía dentro del bloque. */
    const MARCA = 'CUANDO EL LEAD PREGUNTA PRECIOS';

    /**
     * Deja la base sin la fuente guardada (valores por defecto).
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        AdminSetting::query()->where('key', PreciosVigentesSettingsTest::CLAVE)->delete();
        AdminSetting::flush_memo();
    }

    /**
     * Devuelve sólo la guía: lo que hay del título en adelante. Falla con un mensaje claro si no existe.
     *
     * @return string
     */
    private function guia(): string
    {
        $bloque = PreciosVigentesSettings::bloque_para_el_agente();
        $posicion = strpos($bloque, self::MARCA);

        $this->assertNotFalse($posicion, 'El bloque de precios no trae la guía «' . self::MARCA . '».');

        return substr($bloque, (int) $posicion);
    }

    /**
     * CA-R1. Con los defaults, la guía trae las frases en el orden pedido por Lucas.
     *
     * @return void
     */
    public function test_la_guia_trae_las_frases_en_orden(): void
    {
        $guia = $this->guia();

        $frases = [
            self::MARCA,
            'Hola, soy Martín, de ComercioCity',
            '3 productos: el sistema de gestión, la tienda online y los agentes de IA',
            'automatizan la mayor parte de los procesos digitales del negocio',
            'cada producto se implementa por $628.400',
            '30 % de descuento ($1.319.640)',
            'invitalo a la prueba gratis de 7 días',
            'pasale el link de la prueba textual',
            'La demo guiada se ofrece sólo si el lead la pidió con sus palabras',
        ];

        $desde = 0;
        foreach ($frases as $frase) {
            $posicion = strpos($guia, $frase, $desde);
            $this->assertNotFalse($posicion, 'Falta (o está fuera de orden) la frase: ' . $frase);
            $desde = (int) $posicion;
        }
    }

    /**
     * CA-R2. Si los productos tienen precios distintos se nombra cada uno y no se dice «cada producto».
     *
     * @return void
     */
    public function test_con_precios_distintos_se_nombra_cada_producto(): void
    {
        $contrato = PreciosVigentesSettingsTest::contrato_por_defecto();

        foreach ($contrato['implementaciones']['items'] as $indice => $item) {
            if ($item['clave'] === 'tienda') {
                $contrato['implementaciones']['items'][$indice]['precio'] = 700000;
            }
        }

        AdminSetting::set(PreciosVigentesSettingsTest::CLAVE, json_encode($contrato, JSON_UNESCAPED_UNICODE));

        $guia = $this->guia();

        $this->assertStringContainsString(
            'el sistema de gestión $628.400, la tienda online $700.000 y los Agentes $628.400',
            $guia
        );
        $this->assertStringNotContainsString('cada producto se implementa por', $guia);
    }

    /**
     * CA-R3. Sin descuento por los tres juntos, la guía no habla de ellos.
     *
     * @return void
     */
    public function test_sin_descuento_la_guia_no_menciona_los_tres_juntos(): void
    {
        $contrato = PreciosVigentesSettingsTest::contrato_por_defecto();
        $contrato['implementaciones']['tres_juntos']['descuento_porcentaje'] = 0;

        AdminSetting::set(PreciosVigentesSettingsTest::CLAVE, json_encode($contrato, JSON_UNESCAPED_UNICODE));

        $guia = $this->guia();

        $this->assertStringNotContainsString('los tres juntos', $guia);
        $this->assertStringContainsString('cada producto se implementa por $628.400', $guia);
    }

    /**
     * CA-R4. La guía no trae dólares ni la ganancia de los tokens.
     *
     * @return void
     */
    public function test_la_guia_no_dice_usd_ni_ganancia(): void
    {
        $guia = $this->guia();

        $this->assertStringNotContainsString('USD', $guia);
        $this->assertStringNotContainsStringIgnoringCase('ganancia', $guia);
    }

    /**
     * Política del 21/11/2026. El paso 4 invita a la prueba gratis (con el link textual), la condiciona a que el
     * lead no tenga demo ni prueba, ofrece la demo sólo si el lead la pidió (con las reglas de la demo) y cede ante
     * el bloque de la oferta de la prueba o del flujo de entrada.
     *
     * @return void
     */
    public function test_el_paso_4_respeta_la_demo_y_la_oferta_de_prueba(): void
    {
        $guia = $this->guia();

        $this->assertStringNotContainsString('ofrecele la demo', $guia);
        $this->assertStringNotContainsString('alrededor de 1 hora', $guia);
        $this->assertStringNotContainsString('prueba gratuita guiada', $guia);
        $this->assertStringContainsString(
            'sólo si el lead todavía no tiene una demo agendada o hecha ni una prueba creada',
            $guia
        );
        $this->assertStringContainsString('seguí las reglas de la demo', $guia);
        $this->assertStringContainsString('Si más abajo hay un bloque de la oferta de la prueba o del flujo de entrada, ese bloque manda sobre este paso', $guia);
    }

    /**
     * Ronda 1 de revisión. Los días de la prueba gratis salen de `PruebaSettings`, no están fijos.
     *
     * @return void
     */
    public function test_los_dias_de_la_prueba_salen_de_la_configuracion(): void
    {
        AdminSetting::set(PruebaSettings::KEY_DIAS_DEFAULT, '14');

        $guia = $this->guia();

        $this->assertStringContainsString('prueba gratis de 14 días', $guia);
        $this->assertStringNotContainsString('prueba gratis de 7 días', $guia);
    }
}

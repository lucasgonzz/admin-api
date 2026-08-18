<?php

namespace Tests\Unit;

use App\Services\VersionNumberComparator;
use Tests\TestCase;

/**
 * `VersionNumberComparator`: el único lugar del repo que sabe comparar dos códigos de
 * versión por su valor NUMÉRICO, no por `id` de tabla ni por orden alfabético de string.
 *
 * Nace del bug de fondo de esta misión (18/8/2026): el rango de una actualización se
 * calculaba filtrando `versions` por `id`, y con hotfixes cargados fuera de orden el
 * `id` deja de reflejar el orden semántico del código. Esta clase es pura (sin
 * Eloquent), así que se prueba directo, sin tocar la base.
 */
class VersionNumberComparatorTest extends TestCase
{
    /**
     * `parse()` descompone en componentes enteros, sin rellenar con ceros.
     *
     * @return void
     */
    public function test_parse_descompone_en_componentes_enteros_de_3_y_4_o_mas(): void
    {
        $this->assertSame([3, 3, 1], VersionNumberComparator::parse('3.3.1'));
        $this->assertSame([3, 3, 1, 2], VersionNumberComparator::parse('3.3.1.2'));
        $this->assertSame([3, 3, 3, 4], VersionNumberComparator::parse('3.3.3.4'));
    }

    /**
     * `parse(null)` y `parse('')` devuelven array vacío, no un error ni `[0]`.
     *
     * @return void
     */
    public function test_parse_de_null_o_vacio_devuelve_array_vacio(): void
    {
        $this->assertSame([], VersionNumberComparator::parse(null));
        $this->assertSame([], VersionNumberComparator::parse(''));
        $this->assertSame([], VersionNumberComparator::parse('   '));
    }

    /**
     * Comparación NUMÉRICA, no de string: "3.3.10" > "3.3.9" aunque "1" < "9" como
     * caracter. Si esto compara como string, el bug de fondo no está arreglado.
     *
     * @return void
     */
    public function test_compare_es_numerico_no_alfabetico(): void
    {
        $this->assertSame(1, VersionNumberComparator::compare('3.3.10', '3.3.9'));
        $this->assertSame(-1, VersionNumberComparator::compare('3.3.9', '3.3.10'));
    }

    /**
     * Una versión troncal es menor que su propio hotfix ("3.3.1" < "3.3.1.1"), y un
     * hotfix de una versión anterior es menor que la siguiente troncal ("3.3.1.2" <
     * "3.3.2"). Es exactamente el caso que rompía el cálculo por `id`.
     *
     * @return void
     */
    public function test_compare_ordena_hotfixes_intercalados_correctamente(): void
    {
        $this->assertSame(-1, VersionNumberComparator::compare('3.3.1', '3.3.1.1'));
        $this->assertSame(1, VersionNumberComparator::compare('3.3.1.1', '3.3.1'));

        $this->assertSame(-1, VersionNumberComparator::compare('3.3.1.2', '3.3.2'));
        $this->assertSame(1, VersionNumberComparator::compare('3.3.2', '3.3.1.2'));
    }

    /**
     * Relleno con ceros: "3.3.3" y "3.3.3.0" son la misma versión a los fines de la
     * comparación.
     *
     * @return void
     */
    public function test_compare_rellena_con_ceros_para_distinta_cantidad_de_componentes(): void
    {
        $this->assertSame(0, VersionNumberComparator::compare('3.3.3', '3.3.3.0'));
        $this->assertSame(0, VersionNumberComparator::compare('3.3.3.0', '3.3.3'));
    }

    /**
     * `null` se trata como versión sin componentes: queda por debajo de cualquier
     * versión con al menos un componente, en los dos sentidos de la comparación.
     *
     * @return void
     */
    public function test_compare_con_null_lo_trata_como_menor_a_cualquier_version(): void
    {
        $this->assertSame(-1, VersionNumberComparator::compare(null, '3.3.1'));
        $this->assertSame(1, VersionNumberComparator::compare('3.3.1', null));
        $this->assertSame(0, VersionNumberComparator::compare(null, null));
    }

    /**
     * `isValid()`: al menos 3 componentes numéricos separados por punto.
     *
     * @return void
     */
    public function test_isValid_exige_al_menos_3_componentes_numericos(): void
    {
        $this->assertFalse(VersionNumberComparator::isValid('3.3'));
        $this->assertTrue(VersionNumberComparator::isValid('3.3.1'));
        $this->assertTrue(VersionNumberComparator::isValid('3.3.1.2'));
        $this->assertFalse(VersionNumberComparator::isValid('abc'));
        $this->assertFalse(VersionNumberComparator::isValid('3.3.x'));
        $this->assertFalse(VersionNumberComparator::isValid(''));
        $this->assertFalse(VersionNumberComparator::isValid(null));
    }

    /**
     * `isHotfix()`: regla literal de Lucas, más de 3 componentes ⇒ hotfix. 3 componentes
     * no es hotfix; 4 y 5 sí.
     *
     * @return void
     */
    public function test_isHotfix_segun_cantidad_de_componentes(): void
    {
        $this->assertFalse(VersionNumberComparator::isHotfix('3.3.1'));
        $this->assertTrue(VersionNumberComparator::isHotfix('3.3.1.2'));
        $this->assertTrue(VersionNumberComparator::isHotfix('3.3.1.2.1'));
    }
}

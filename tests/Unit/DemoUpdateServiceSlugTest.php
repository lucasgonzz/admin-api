<?php

namespace Tests\Unit;

use App\Services\DemoUpdateService;
use ReflectionClass;
use Tests\TestCase;

/**
 * El slug de una demo sale de su `erp_spa_url`, y con él se arman las rutas del hosting compartido
 * (`domains/comerciocity.com/public_html/{slug}/spa` y `/api`). O sea que un slug vacío no rompe
 * nada visible: sube el ZIP a un directorio equivocado.
 *
 * Es la parte más riesgosa del arreglo del 17/8/2026 y la que no tenía nada que la fijara. Se
 * prueba por reflexión porque `slug_from_url()` es privado y no depende de estado de la instancia:
 * exponerlo solo para el test sería agrandar la superficie pública de un service de despliegue.
 */
class DemoUpdateServiceSlugTest extends TestCase
{
    /**
     * Invoca el método privado sobre una instancia sin construir: el constructor real pide un
     * DemoUpdate y una credencial SSH que este cálculo no usa.
     *
     * @param string $url URL de la demo.
     *
     * @return string Slug calculado.
     */
    private function slug_de(string $url): string
    {
        $reflection = new ReflectionClass(DemoUpdateService::class);
        $service    = $reflection->newInstanceWithoutConstructor();

        $metodo = $reflection->getMethod('slug_from_url');
        $metodo->setAccessible(true);

        return $metodo->invoke($service, $url);
    }

    /**
     * El camino que ya andaba: una URL bien cargada da el mismo slug que antes del arreglo.
     *
     * @return void
     */
    public function test_una_url_absoluta_da_el_slug_de_siempre(): void
    {
        $this->assertSame('demo3', $this->slug_de('https://demo3.comerciocity.com'));
        $this->assertSame('demo3', $this->slug_de('https://demo3.comerciocity.com/'));
    }

    /**
     * 🔴 El caso que el arreglo vino a tapar: sin esquema y SIN PUERTO, `parse_url()` devuelve null
     * para el host y el slug quedaba vacío. Es la forma más común de cargar mal una demo de
     * producción a mano.
     *
     * @return void
     */
    public function test_una_url_sin_esquema_ya_no_da_slug_vacio(): void
    {
        $this->assertSame('demo3', $this->slug_de('demo3.comerciocity.com'));
        $this->assertNotSame('', $this->slug_de('demo3.comerciocity.com'));
    }

    /**
     * Con puerto `parse_url()` ya acertaba, así que este caso no cambia. Queda escrito para que se
     * note si alguna vez cambia: es la diferencia que hace angosto al bug de arriba.
     *
     * @return void
     */
    public function test_una_url_con_puerto_da_el_mismo_slug_que_antes(): void
    {
        $this->assertSame('empresa', $this->slug_de('empresa.local:8080'));
    }

    /**
     * Sin URL no hay slug, y eso tiene que seguir siendo visible en vez de convertirse en una ruta
     * plausible.
     *
     * @return void
     */
    public function test_sin_url_el_slug_queda_vacio(): void
    {
        $this->assertSame('', $this->slug_de(''));
    }
}
